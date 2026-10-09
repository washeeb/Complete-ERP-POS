; The business server's Windows installer (setup.exe). Build with Inno Setup 6:
;   iscc /DReleaseZip=C:\path\ervanta-<customer>-<build>.zip /DAppVersion=0.26.0 installer\windows\server-setup.iss
; The product name comes from /DBrand (default Ervanta); the icon is app.ico (npm run brand).
; The installer copies the release and install.ps1, asks a few questions, then runs install.ps1 in a visible window
; (it downloads PostgreSQL, PHP and Composer from their official sites, so the PC needs internet during installation).

#ifndef Brand
  #define Brand "Ervanta"
#endif
#ifndef AppVersion
  #define AppVersion "0.0.0"
#endif
#ifndef ReleaseZip
  #error Pass /DReleaseZip=<path to the release ZIP>
#endif

[Setup]
AppId={{6B1C4E5A-3C7D-4F51-9A0B-5E2D7C9A1F33}
AppName={#Brand} server
AppVersion={#AppVersion}
AppPublisher={#Brand}
DefaultDirName=C:\{#Brand}
DisableDirPage=yes
DisableProgramGroupPage=yes
PrivilegesRequired=admin
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputBaseFilename={#Brand}-server-setup-{#AppVersion}
SetupIconFile=app.ico
UninstallDisplayIcon={app}\installer\app.ico
Compression=lzma2
SolidCompression=yes
WizardStyle=modern
SetupLogging=yes
MinVersion=10.0

[Files]
Source: "{#ReleaseZip}"; DestDir: "{app}\installer"; DestName: "release.zip"; Flags: ignoreversion
Source: "install.ps1"; DestDir: "{app}\installer"; Flags: ignoreversion
Source: "app.ico"; DestDir: "{app}\installer"; Flags: ignoreversion

[Run]
Filename: "powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -File ""{app}\installer\install.ps1"" -Release ""{app}\installer\release.zip"" -Dir ""{app}"" -Setup {code:SetupType} -Role {code:Role} -NodeId {code:NodeId} -Brand ""{#Brand}"""; \
  StatusMsg: "Installing the database, PHP and the server (this takes several minutes)…"; Flags: waituntilterminated

[UninstallRun]
Filename: "powershell.exe"; Parameters: "-NoProfile -Command ""'{#Brand} Web','{#Brand} Scheduler','{#Brand} Live' | ForEach-Object {{ Unregister-ScheduledTask -TaskName $_ -Confirm:$false -ErrorAction SilentlyContinue }; sc.exe stop {#Brand}DB; sc.exe delete {#Brand}DB"""; \
  Flags: runhidden; RunOnceId: "StopServices"

[Messages]
FinishedLabel=The {#Brand} server is installed.%n%nOpen the back office at the address shown in the installation window (for example http://localhost:8010/admin). The database files and backups in C:\{#Brand} are kept if you uninstall.

[Code]
var
  TypePage: TInputOptionWizardPage;
  RolePage: TInputQueryWizardPage;

procedure InitializeWizard;
begin
  TypePage := CreateInputOptionPage(wpWelcome, 'How will this server be used?', 'Choose the setup (installation guide, Chapter 2).',
    'Setup A: one PC that is also the till. Setup B: a shop server that tills on the network connect to.', True, False);
  TypePage.Add('Setup A — this PC only (the till runs on this PC)');
  TypePage.Add('Setup B — shop server for tills on the network');
  TypePage.SelectedValueIndex := 1;
  RolePage := CreateInputQueryPage(TypePage.ID, 'Head office or branch server?', 'Leave as HQ unless you are installing a branch server (Chapter 10).',
    'For a branch server, type its id given by head office (for example B01).');
  RolePage.Add('Server id:', False);
  RolePage.Values[0] := 'HQ';
end;

function SetupType(Param: String): String;
begin
  if TypePage.SelectedValueIndex = 0 then Result := 'A' else Result := 'B';
end;

function NodeId(Param: String): String;
begin
  Result := Uppercase(Trim(RolePage.Values[0]));
  if Result = '' then Result := 'HQ';
end;

function Role(Param: String): String;
begin
  if NodeId('') = 'HQ' then Result := 'hq' else Result := 'branch';
end;
