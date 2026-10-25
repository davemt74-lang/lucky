#define AppVersion "5.29.1"
#ifndef StageDir
  #error StageDir must be passed with /DStageDir
#endif
#ifndef OutputDir
  #error OutputDir must be passed with /DOutputDir
#endif

[Setup]
AppId={{E0F67E44-96F8-4058-B408-7CE0FD36A651}
AppName=Lucky Game Hub HomeServer
AppVersion={#AppVersion}
AppVerName=Lucky Game Hub HomeServer V5Q.29A
AppPublisher=Lucky Game Hub
DefaultDirName={localappdata}\Programs\LuckyGameHub
DefaultGroupName=Lucky Game Hub
DisableProgramGroupPage=yes
PrivilegesRequired=lowest
ArchitecturesAllowed=x64compatible
ArchitecturesInstallIn64BitMode=x64compatible
OutputDir={#OutputDir}
OutputBaseFilename=Lucky_Game_Hub_HomeServer_V5Q29A_Setup
Compression=lzma2/normal
SolidCompression=yes
WizardStyle=modern
UninstallDisplayIcon={app}\runtime\caddy.exe
CloseApplications=force
RestartApplications=no
SetupLogging=yes
ChangesEnvironment=no
DisableReadyPage=yes
LicenseFile={#StageDir}\app\homeserver\OFFLINE_NOTICE.txt

[Languages]
Name: "english"; MessagesFile: "compiler:Default.isl"

[Tasks]
Name: "autostart"; Description: "Launch HomeServer automatically when I sign in"; GroupDescription: "HomeServer startup:"; Flags: checkedonce

[Files]
Source: "{#StageDir}\*"; DestDir: "{app}"; Flags: ignoreversion recursesubdirs createallsubdirs

[Icons]
Name: "{autoprograms}\Lucky Game Hub HomeServer"; Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\homeserver\start.ps1"""; WorkingDir: "{app}"
Name: "{userdesktop}\Lucky Game Hub HomeServer"; Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\homeserver\start.ps1"""; WorkingDir: "{app}"
Name: "{userstartup}\Lucky Game Hub HomeServer"; Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\homeserver\start.ps1"" -NoBrowser"; WorkingDir: "{app}"; Tasks: autostart

[Run]
Filename: "{sys}\WindowsPowerShell\v1.0\powershell.exe"; Parameters: "-NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File ""{app}\homeserver\start.ps1"""; Description: "Start Lucky Game Hub HomeServer"; Flags: postinstall nowait skipifsilent unchecked
