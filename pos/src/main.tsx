import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { applyTheme, getTheme } from './theme'
import { watchErrors } from './problems'
import { startUpdates } from './swUpdate'

document.documentElement.dataset.build = __BUILD_STAMP__ // traceable copy (release builds)
applyTheme(getTheme(), false) // before the first paint: no flash of the wrong theme
watchErrors() // remembered for "Report a problem"
startUpdates() // a new till version takes over at the sign-in screen

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
