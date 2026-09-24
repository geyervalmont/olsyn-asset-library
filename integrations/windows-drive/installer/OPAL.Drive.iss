#define MyAppVersion GetEnv("OPAL_DRIVE_VERSION")
#define PublishRoot GetEnv("OPAL_DRIVE_PUBLISH")
#define ReleaseRoot GetEnv("OPAL_DRIVE_OUTPUT")
#define DriverInstaller GetEnv("OPAL_DRIVE_DRIVER")
[Setup]
AppId={{01AB9F99-885C-7B41-92D9-102A1882810B}
AppName=OPAL Drive
AppVersion={#MyAppVersion}
AppPublisher=Olsyn
AppPublisherURL=https://opal.olsyn.com
AppSupportURL=https://opal.olsyn.com/connect/it
DefaultDirName={autopf}\Olsyn\OPAL Drive
DefaultGroupName=Olsyn
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir={#ReleaseRoot}
OutputBaseFilename=OPAL-Drive-Setup
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
CloseApplications=yes
CloseApplicationsFilter=OPAL-Drive.exe
RestartApplications=no
UninstallDisplayName=OPAL Drive
VersionInfoVersion={#MyAppVersion}
VersionInfoCompany=Olsyn
VersionInfoDescription=OPAL material drive over HTTPS
[Files]
Source: "{#PublishRoot}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs
Source: "{#DriverInstaller}"; Flags: dontcopy
[Icons]
Name: "{commonprograms}\Olsyn\OPAL Drive"; Filename: "{app}\OPAL-Drive.exe"
[Registry]
Root: HKLM; Subkey: "Software\Microsoft\Windows\CurrentVersion\Run"; ValueType: string; ValueName: "OPAL Drive"; ValueData: """{app}\OPAL-Drive.exe"" --background"; Flags: uninsdeletevalue
[Run]
Filename: "{app}\OPAL-Drive.exe"; Description: "Open OPAL Drive and connect your account"; Flags: nowait postinstall skipifsilent runasoriginaluser
[Code]
function PrepareToInstall(var NeedsRestart: Boolean): String;
var
  Code: Integer;
begin
  Result := '';
  if not FileExists(ExpandConstant('{sys}\dokan2.dll')) then
  begin
    ExtractTemporaryFile('DokanSetup.exe');
    if not Exec(ExpandConstant('{tmp}\DokanSetup.exe'), '/install /quiet /norestart', '', SW_HIDE, ewWaitUntilTerminated, Code) then
      Result := 'The Windows filesystem component could not be installed. Ask IT to install Dokany 2.3.1, then run this installer again.'
    else if Code = 3010 then
      NeedsRestart := True
    else if (Code <> 0) and (Code <> 1638) then
      Result := 'The Windows filesystem component returned error ' + IntToStr(Code) + '. Ask IT to install Dokany 2.3.1, then run this installer again.';
  end;
end;
