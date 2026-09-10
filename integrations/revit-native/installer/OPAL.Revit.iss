#define MyAppVersion GetEnv("OPAL_VERSION")
#define ArtifactRoot GetEnv("OPAL_ARTIFACT_ROOT")
#define ReleaseOutput GetEnv("OPAL_RELEASE_OUTPUT")

[Setup]
AppId={{B80F8B2F-A85A-4C55-A1C0-94D0E6CB41D9}
AppName=OPAL for Revit
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
UninstallDisplayName=OPAL for Revit
VersionInfoVersion={#MyAppVersion}
VersionInfoCompany=Olsyn
VersionInfoDescription=OPAL native Revit connector

[Files]
Source: "{#ArtifactRoot}\revit\*"; DestDir: "{app}\revit"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#ArtifactRoot}\supported-versions.txt"; Flags: dontcopy
Source: "{#ArtifactRoot}\supported-versions.txt"; DestDir: "{app}\revit"; Flags: ignoreversion

[InstallDelete]
; Remove the pre-matrix 2027 layout after the replacement files are staged.
Type: files; Name: "{app}\current.json"
Type: files; Name: "{app}\pending.json"
Type: filesandordirs; Name: "{app}\updates"
Type: filesandordirs; Name: "{app}\versions"
Type: filesandordirs; Name: "{app}\bootstrap"

[UninstallDelete]
Type: files; Name: "{app}\config.json"
Type: filesandordirs; Name: "{app}\revit"
Type: dirifempty; Name: "{app}"

[Code]
var
  RevitPage: TInputOptionWizardPage;
  RevitVersions: TArrayOfString;

function IsRevitInstalled(Year: String): Boolean;
begin
  Result :=
    FileExists(ExpandConstant('{autopf}\Autodesk\Revit ' + Year + '\Revit.exe')) or
    DirExists(ExpandConstant('{userappdata}\Autodesk\Revit\Addins\' + Year));
end;

procedure InitializeWizard;
var
  I: Integer;
  AnyDetected: Boolean;
begin
  ExtractTemporaryFile('supported-versions.txt');
  if not LoadStringsFromFile(ExpandConstant('{tmp}\supported-versions.txt'), RevitVersions) then
    RaiseException('The supported Revit version list is missing.');

  RevitPage := CreateInputOptionPage(
    wpSelectDir,
    'Choose Revit versions',
    'Select every Revit release that should load OPAL.',
    'Installed versions are selected automatically. You can also select a custom installation.',
    False,
    True);

  AnyDetected := False;
  for I := 0 to GetArrayLength(RevitVersions) - 1 do
  begin
    RevitPage.Add('Revit ' + RevitVersions[I]);
    RevitPage.Values[I] := IsRevitInstalled(RevitVersions[I]);
    AnyDetected := AnyDetected or RevitPage.Values[I];
  end;

  if not AnyDetected then
    for I := 0 to GetArrayLength(RevitVersions) - 1 do
      RevitPage.Values[I] := True;
end;

procedure WriteRevitManifest(Year: String);
var
  DirectoryName: String;
  ManifestPath: String;
  AssemblyPath: String;
  Contents: String;
begin
  DirectoryName := ExpandConstant('{userappdata}\Autodesk\Revit\Addins\' + Year);
  ForceDirectories(DirectoryName);
  ManifestPath := DirectoryName + '\OPAL.addin';
  AssemblyPath := ExpandConstant('{app}\revit\' + Year + '\bootstrap\Opal.Revit.Bootstrap.dll');
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
var
  I: Integer;
begin
  if CurStep = ssPostInstall then
    for I := 0 to GetArrayLength(RevitVersions) - 1 do
      if RevitPage.Values[I] then
        WriteRevitManifest(RevitVersions[I])
      else
        DeleteFile(ExpandConstant('{userappdata}\Autodesk\Revit\Addins\' + RevitVersions[I] + '\OPAL.addin'));
end;

function InitializeUninstall: Boolean;
var
  I: Integer;
  InstalledVersions: TArrayOfString;
begin
  if LoadStringsFromFile(ExpandConstant('{app}\revit\supported-versions.txt'), InstalledVersions) then
    for I := 0 to GetArrayLength(InstalledVersions) - 1 do
      DeleteFile(ExpandConstant('{userappdata}\Autodesk\Revit\Addins\' + InstalledVersions[I] + '\OPAL.addin'));
  Result := True;
end;
