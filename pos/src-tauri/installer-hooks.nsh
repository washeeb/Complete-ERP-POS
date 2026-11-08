; Runs inside the installer (Tauri NSIS hooks). The app was renamed (owner 2026-11-07); Windows sees a new name as a
; new app, so the copy under its earlier name is removed first, silently. Settings live under the app's identifier,
; which did not change, so the server address and everything else are kept.
!macro ZX_REMOVE_OLD NAME
  ReadRegStr $R0 HKLM "Software\Microsoft\Windows\CurrentVersion\Uninstall\${NAME}" "UninstallString"
  ${If} $R0 == ""
    ReadRegStr $R0 HKCU "Software\Microsoft\Windows\CurrentVersion\Uninstall\${NAME}" "UninstallString"
  ${EndIf}
  ${If} $R0 != ""
    DetailPrint "Removing ${NAME} (the earlier name of this app)"
    ExecWait '$R0 /S'
  ${EndIf}
!macroend

!macro NSIS_HOOK_PREINSTALL
  !insertmacro ZX_REMOVE_OLD "SES Labs Till"
!macroend

; Windows keeps icons in a cache, so a new icon could still show the old one on the desktop, Start menu and taskbar
; (owner 2026-11-08). Tell Windows the icons changed and refresh its cache.
!macro NSIS_HOOK_POSTINSTALL
  System::Call 'shell32::SHChangeNotify(i 0x08000000, i 0, p 0, p 0)'
  nsExec::Exec '"$SYSDIR\ie4uinit.exe" -show'
!macroend
