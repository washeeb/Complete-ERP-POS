import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.tsx'
import { applyTheme, getTheme } from './theme'
import { watchErrors } from './problems'

document.documentElement.dataset.build = __BUILD_STAMP__ // traceable copy (release builds)
applyTheme(getTheme(), false) // before the first paint: no flash of the wrong theme
watchErrors() // remembered for "Report a problem"

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <App />
  </StrictMode>,
)
