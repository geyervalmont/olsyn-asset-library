using System.Net;
using System.Security.AccessControl;
using System.Security.Principal;
using DokanNet;
using Opal.Drive;
using Access = DokanNet.FileAccess;

namespace Opal.Drive.Windows;

/// <summary>Real Windows callbacks. Published files are immutable; writes are confined to authorized Incoming batches.</summary>
public sealed class MaterialFileSystem(DriveSession session, IntakeStaging intake) : IDokanOperations
{
    private static readonly DateTime PublishedTime = new(2026, 1, 1, 0, 0, 0, DateTimeKind.Utc);
    private const Access WriteAccess = Access.WriteData | Access.AppendData | Access.Delete | Access.GenericWrite | Access.GenericAll;
    public bool IsMounted { get; private set; }
    public event Action? MountChanged;
    private DriveNode? Find(string path) => session.Tree.Find(path) ?? intake.Find(path);
    private static FileInformation Information(DriveNode node) => new()
    {
        FileName = node.Name, Length = node.File?.Bytes ?? 0,
        Attributes = node.IsDirectory ? FileAttributes.Directory : node.Path.StartsWith("\\Incoming\\", StringComparison.OrdinalIgnoreCase) ? FileAttributes.Archive : FileAttributes.ReadOnly | FileAttributes.Archive,
        CreationTime = PublishedTime, LastAccessTime = PublishedTime, LastWriteTime = PublishedTime,
    };
    private static NtStatus Error(Exception error) => error switch
    {
        UnauthorizedAccessException => NtStatus.AccessDenied,
        FileNotFoundException or DirectoryNotFoundException => NtStatus.ObjectNameNotFound,
        HttpRequestException { StatusCode: HttpStatusCode.Unauthorized or HttpStatusCode.Forbidden } => NtStatus.AccessDenied,
        HttpRequestException { StatusCode: HttpStatusCode.NotFound } => NtStatus.ObjectNameNotFound,
        InvalidDataException => NtStatus.DataError,
        ArgumentException or OverflowException => NtStatus.InvalidParameter,
        OperationCanceledException or HttpRequestException => NtStatus.IoTimeout,
        _ => NtStatus.Unsuccessful,
    };
    private static NtStatus Run(Action action) { try { action(); return NtStatus.Success; } catch (Exception error) { return Error(error); } }

    public NtStatus CreateFile(string fileName, Access access, FileShare share, FileMode mode, FileOptions options, FileAttributes attributes, IDokanFileInfo info)
    {
        try
        {
            var path = DrivePath.Normalize(fileName);
            var node = Find(path);
            var upload = session.Tree.UploadFolder(path);
            var write = (access & WriteAccess) != 0 || mode is FileMode.Create or FileMode.CreateNew or FileMode.Truncate or FileMode.Append;
            if (upload is null && (write || mode != FileMode.Open)) return NtStatus.AccessDenied;
            if (node is { IsDirectory: true })
            {
                if (mode == FileMode.CreateNew) return NtStatus.ObjectNameCollision;
                info.IsDirectory = true;
                return NtStatus.Success;
            }
            if (info.IsDirectory)
            {
                if (node is not null) return NtStatus.NotADirectory;
                if (upload is null || mode is not (FileMode.CreateNew or FileMode.OpenOrCreate or FileMode.Create)) return NtStatus.ObjectNameNotFound;
                intake.CreateDirectory(path); return NtStatus.Success;
            }
            if (upload is not null)
            {
                if (node is null && Find(DrivePath.Parent(path)) is not { IsDirectory: true }) return NtStatus.ObjectPathNotFound;
                info.Context = intake.Open(path, mode, write);
                return node is not null && mode is FileMode.Create or FileMode.OpenOrCreate ? NtStatus.ObjectNameCollision : NtStatus.Success;
            }
            if (node?.File is null) return NtStatus.ObjectNameNotFound;
            info.Context = (access & (Access.ReadData | Access.GenericRead | Access.GenericAll)) != 0
                ? session.OpenAsync(node.File).GetAwaiter().GetResult() : node.File;
            return NtStatus.Success;
        }
        catch (Exception error) { return Error(error); }
    }
    public void Cleanup(string fileName, IDokanFileInfo info)
    {
        if (info.Context is IntakeStaging.StageHandle staged)
        {
            staged.Dispose(); info.Context = null;
        }
        if (info.DeletePending) { try { intake.Delete(fileName); } catch { /* Delete callback already validated; keep recoverable data on failure. */ } }
    }
    public void CloseFile(string fileName, IDokanFileInfo info) { (info.Context as IDisposable)?.Dispose(); info.Context = null; }
    public NtStatus ReadFile(string fileName, byte[] buffer, out int bytesRead, long offset, IDokanFileInfo info)
    {
        bytesRead = 0;
        try
        {
            if (info.Context is DriveSession.ReadHandle handle) bytesRead = handle.ReadAsync(buffer, offset).GetAwaiter().GetResult();
            else if (info.Context is IntakeStaging.StageHandle local) bytesRead = local.Read(buffer, offset);
            else if (session.Tree.UploadFolder(fileName) is not null)
            {
                using var stage = intake.Open(fileName, FileMode.Open, false); bytesRead = stage.Read(buffer, offset);
            }
            else
            {
                // Paging I/O may arrive without the original create context.
                var file = info.Context as RemoteFile ?? session.Tree.Find(fileName)?.File ?? throw new FileNotFoundException();
                using var read = session.OpenAsync(file).GetAwaiter().GetResult(); bytesRead = read.ReadAsync(buffer, offset).GetAwaiter().GetResult();
            }
            return NtStatus.Success;
        }
        catch (Exception error) { return Error(error); }
    }
    public NtStatus WriteFile(string fileName, byte[] buffer, out int bytesWritten, long offset, IDokanFileInfo info)
    {
        bytesWritten = 0;
        if (info.Context is not IntakeStaging.StageHandle local) return NtStatus.AccessDenied;
        var status = Run(() => local.Write(buffer, info.WriteToEndOfFile ? -1 : offset));
        if (status == NtStatus.Success) bytesWritten = buffer.Length;
        return status;
    }
    public NtStatus FlushFileBuffers(string fileName, IDokanFileInfo info) => Run(() => { if (info.Context is IntakeStaging.StageHandle local) local.Flush(); else session.RequireOnline(); });
    public NtStatus GetFileInformation(string fileName, out FileInformation fileInfo, IDokanFileInfo info)
    {
        fileInfo = default;
        try
        {
            var node = info.Context is DriveSession.ReadHandle read ? new DriveNode(read.File.Path, read.File) : Find(fileName);
            session.RequireOnline();
            if (node is null) return NtStatus.ObjectNameNotFound;
            fileInfo = Information(node); return NtStatus.Success;
        }
        catch (Exception error) { return Error(error); }
    }
    public NtStatus FindFiles(string fileName, out IList<FileInformation> files, IDokanFileInfo info)
    {
        files = [];
        try
        {
            if (Find(fileName) is not { IsDirectory: true }) return NtStatus.NotADirectory;
            var nodes = session.Tree.List(fileName).Concat(session.Tree.UploadFolder(fileName) is null ? [] : intake.List(fileName));
            files = nodes.DistinctBy(n => n.Path, StringComparer.OrdinalIgnoreCase).Select(Information).ToList(); return NtStatus.Success;
        }
        catch (Exception error) { return Error(error); }
    }
    public NtStatus FindFilesWithPattern(string fileName, string searchPattern, out IList<FileInformation> files, IDokanFileInfo info)
    {
        var status = FindFiles(fileName, out files, info);
        if (status == NtStatus.Success) files = files.Where(f => DokanHelper.DokanIsNameInExpression(searchPattern, f.FileName, true)).ToArray();
        return status;
    }
    public NtStatus SetEndOfFile(string fileName, long length, IDokanFileInfo info) => info.Context is IntakeStaging.StageHandle local ? Run(() => local.SetLength(length)) : NtStatus.AccessDenied;
    public NtStatus SetAllocationSize(string fileName, long length, IDokanFileInfo info) => info.Context is IntakeStaging.StageHandle local
        ? (length < local.Length ? Run(() => local.SetLength(length)) : length <= IntakeStaging.FileLimit ? NtStatus.Success : NtStatus.DiskFull) : NtStatus.AccessDenied;
    public NtStatus SetFileAttributes(string fileName, FileAttributes attributes, IDokanFileInfo info) => Run(() => { if (session.Tree.UploadFolder(fileName) is null) throw new UnauthorizedAccessException(); });
    public NtStatus SetFileTime(string fileName, DateTime? creationTime, DateTime? lastAccessTime, DateTime? lastWriteTime, IDokanFileInfo info) => SetFileAttributes(fileName, 0, info);
    public NtStatus DeleteFile(string fileName, IDokanFileInfo info) => NtStatus.AccessDenied;
    public NtStatus DeleteDirectory(string fileName, IDokanFileInfo info) => NtStatus.AccessDenied;
    public NtStatus MoveFile(string oldName, string newName, bool replace, IDokanFileInfo info) => NtStatus.AccessDenied;
    public NtStatus LockFile(string fileName, long offset, long length, IDokanFileInfo info) => NtStatus.Success;
    public NtStatus UnlockFile(string fileName, long offset, long length, IDokanFileInfo info) => NtStatus.Success;
    public NtStatus GetDiskFreeSpace(out long freeBytesAvailable, out long totalNumberOfBytes, out long totalNumberOfFreeBytes, IDokanFileInfo info)
    {
        totalNumberOfBytes = ContentCache.DefaultCapacity;
        freeBytesAvailable = totalNumberOfFreeBytes = Math.Min(IntakeStaging.TotalLimit, new DriveInfo(Path.GetPathRoot(Environment.GetFolderPath(Environment.SpecialFolder.LocalApplicationData))!).AvailableFreeSpace);
        return NtStatus.Success;
    }
    public NtStatus GetVolumeInformation(out string volumeLabel, out FileSystemFeatures features, out string fileSystemName, out uint maximumComponentLength, IDokanFileInfo info)
    {
        volumeLabel = "OPAL Materials"; fileSystemName = "NTFS"; maximumComponentLength = 200;
        features = FileSystemFeatures.CasePreservedNames | FileSystemFeatures.UnicodeOnDisk | FileSystemFeatures.PersistentAcls;
        return NtStatus.Success;
    }
    public NtStatus GetFileSecurity(string fileName, out FileSystemSecurity? security, AccessControlSections sections, IDokanFileInfo info)
    {
        security = null;
        try
        {
            var directory = Find(fileName)?.IsDirectory ?? info.IsDirectory;
            security = directory ? new DirectorySecurity() : new FileSecurity();
            security.SetSecurityDescriptorSddlForm(OwnerSddl()); return NtStatus.Success;
        }
        catch (Exception error) { return Error(error); }
    }
    public static string OwnerSddl()
    {
        var sid = WindowsIdentity.GetCurrent().User!.Value;
        return $"O:{sid}G:{sid}D:P(A;;FA;;;{sid})(A;;FA;;;SY)";
    }
    public NtStatus SetFileSecurity(string fileName, FileSystemSecurity security, AccessControlSections sections, IDokanFileInfo info) => NtStatus.AccessDenied;
    public NtStatus FindStreams(string fileName, out IList<FileInformation> streams, IDokanFileInfo info) { streams = []; return NtStatus.NotImplemented; }
    public NtStatus Mounted(string mountPoint, IDokanFileInfo info) { IsMounted = true; MountChanged?.Invoke(); return NtStatus.Success; }
    public NtStatus Unmounted(IDokanFileInfo info) { IsMounted = false; MountChanged?.Invoke(); return NtStatus.Success; }
}
