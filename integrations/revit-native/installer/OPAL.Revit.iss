#define MyAppVersion GetEnv("OPAL_VERSION")
#define ArtifactRoot GetEnv("OPAL_ARTIFACT_ROOT")
#define ReleaseOutput GetEnv("OPAL_RELEASE_OUTPUT")

[Setup]
AppId={{B80F8B2F-A85A-4C55-A1C0-94D0E6CB41D9}
AppName=OPAL for Revit 2027
AppVersion={#MyAppVersion}
AppPublisher=Olsyn
AppPublisherURL=https://opal.olsyn.com
AppSupportURL=https://opal.olsyn.com/settings/revit
DefaultDirName={localappdata}\Olsyn\OPAL
DefaultGroupName=OPAL
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir={#ReleaseOutput}
OutputBaseFilename=OPAL-Revit-Setup
Compression=lzma2/ultra64
SolidCompression=yes
WizardStyle=modern
CloseApplications=yes
CloseApplicationsFilter=Revit.exe
RestartApplications=no
UninstallDisplayName=OPAL for Revit 2027
VersionInfoVersion={#MyAppVersion}
VersionInfoCompany=Olsyn
VersionInfoDescription=OPAL native Revit connector

[Files]
Source: "{#ArtifactRoot}\bootstrap\Opal.Revit.Bootstrap.dll"; DestDir: "{app}\bootstrap"; Flags: ignoreversion
Source: "{#ArtifactRoot}\version\*"; DestDir: "{app}\versions\{#MyAppVersion}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#ArtifactRoot}\current.json"; DestDir: "{app}"; Flags: ignoreversion

[UninstallDelete]
Type: files; Name: "{userappdata}\Autodesk\Revit\Addins\2027\OPAL.addin"
Type: files; Name: "{app}\config.json"
Type: files; Name: "{app}\current.json"
Type: files; Name: "{app}\pending.json"
Type: files; Name: "{app}\client.log"
Type: filesandordirs; Name: "{app}\updates"
Type: filesandordirs; Name: "{app}\versions"
Type: filesandordirs; Name: "{app}\bootstrap"
Type: dirifempty; Name: "{app}"

[Code]
procedure WriteRevitManifest;
var
  DirectoryName: String;
  ManifestPath: String;
  AssemblyPath: String;
  Contents: String;
begin
  DirectoryName := ExpandConstant('{userappdata}\Autodesk\Revit\Addins\2027');
  ForceDirectories(DirectoryName);
  ManifestPath := DirectoryName + '\OPAL.addin';
  AssemblyPath := ExpandConstant('{app}\bootstrap\Opal.Revit.Bootstrap.dll');
  StringChangeEx(AssemblyPath, '&', '&amp;', True);
  StringChangeEx(AssemblyPath, '<', '&lt;', True);
  StringChangeEx(AssemblyPath, '>', '&gt;', True);
  Contents := '<?xml version="1.0" encoding="utf-8"?>' + #13#10 +
    '<RevitAddIns>' + #13#10 +
    '  <AddIn Type="Application">' + #13#10 +
    '    <Name>OPAL</Name>' + #13#10 +
    '    <Assembly>' + AssemblyPath + '</Assembly>' + #13#10 +
    '    <AddInId>675EFD43-78C8-4B71-93FD-F99AF687E41A</AddInId>' + #13#10 +
    '    <FullClassName>Opal.Revit.Bootstrap.BootstrapApplication</FullClassName>' + #13#10 +
    '    <VendorId>OLSN</VendorId>' + #13#10 +
    '    <VendorDescription>Olsyn</VendorDescription>' + #13#10 +
    '  </AddIn>' + #13#10 +
    '</RevitAddIns>' + #13#10;
  SaveStringToFile(ManifestPath, Contents, False);
end;

procedure CurStepChanged(CurStep: TSetupStep);
begin
  if CurStep = ssPostInstall then
    WriteRevitManifest;
end;
