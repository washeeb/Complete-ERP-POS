## Settings and modules in the new back office
- **The server checks who may change each setting.** The current page greyed them out; the new one does too, and the server refuses a change to a setting this person may not change (for example one only SyncLytics support changes), naming it. Settings in hidden groups (an industry not running here, an add-on not licensed) and unknown keys are ignored, as before.
- **Only what changed is sent and saved,** one audit entry per setting, with the reason. Each value is still checked by Settings (type, minimum, maximum, choices).
- **Changing when payments need "confirm it's you" asks for it first,** as now.
- **Modules: the rules stay in ModuleService** (for example a branch runs one industry); the screen shows its message. Changes are applied enabling first, then turning off, as now.
- **Searching opens the matching groups,** as on the current page.
