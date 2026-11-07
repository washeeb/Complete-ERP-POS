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
  !insertmacro ZX_REMOVE_OLD "ZionEX Back Office"
!macroend
