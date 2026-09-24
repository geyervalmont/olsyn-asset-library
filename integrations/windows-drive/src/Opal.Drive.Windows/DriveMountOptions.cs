using System.Security.AccessControl;
using DokanNet;
using DokanNet.Native;

namespace Opal.Drive.Windows;

public static class DriveMountOptions
{
    public static void Configure(DOKAN_OPTIONS options, string mount)
    {
        options.MountPoint = mount;
        // No MountManager fallback: silently choosing a letter breaks material references.
        options.Options = DokanOptions.CurrentSession;
        options.TimeOut = TimeSpan.FromMinutes(5);
        var descriptor = new RawSecurityDescriptor(MaterialFileSystem.OwnerSddl());
        // DokanNet marshals this as an inline 16 KiB array. The managed array
        // MUST have that size, while the length field describes the actual ACL.
        const int bufferSize = 16384;
        if (descriptor.BinaryLength > bufferSize) throw new InvalidOperationException("Volume security descriptor exceeds the driver buffer.");
        var buffer = new byte[bufferSize];
        descriptor.GetBinaryForm(buffer, 0);
        options.VolumeSecurityDescriptor = buffer;
        options.VolumeSecurityDescriptorLength = descriptor.BinaryLength;
    }
}
