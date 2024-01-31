[Setup]
AppName=SQLSETUP1
AppVersion=1.0
DefaultDirName=C:\xampp\htdocs\sqlsetupxampp
DefaultGroupName=SQLSETUP
UninstallDisplayIcon={app}\SQLSETUP.exe
OutputDir=Output

[Files]
Source: "C:\xampp\htdocs\sqlsetupxampp\*"; DestDir: "{app}\"; Flags: recursesubdirs
