import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import { App } from './App'
import { SignInApp, TwoFactorSetupApp } from './auth/SignIn'
import './global.css'

// Signing in and setting up two-factor sign-in open on their own (part 41): nobody is signed in yet, or the back office
// waits for two-factor sign-in.
const path = location.pathname.replace(/\/+$/, '')
const Root = path === '/office/sign-in' ? SignInApp : path === '/office/two-factor-setup' ? TwoFactorSetupApp : App
createRoot(document.getElementById('root')!).render(<StrictMode><Root /></StrictMode>)
