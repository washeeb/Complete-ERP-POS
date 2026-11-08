// The ZionEX loader: the Modules mark coming together (branding/product/loader.svg, copied here by npm run brand:loader).
// Inlined so the till's light/dark colours reach it; the words are what a screen reader announces.
import loader from '../assets/loader.svg?raw'

export function LogoLoader({ text = 'Loading…', size = 48, variant = 'block' }: { text?: string; size?: number; variant?: 'page' | 'block' | 'small' }) {
  const svg = loader.replace(/width="\d+" height="\d+"/, `width="${size}" height="${size}"`)
  return (
    <div className={`logo-loader ${variant === 'block' ? '' : variant}`} role="status">
      {/* A file of our own (no outside content): safe to inline. */}
      <span aria-hidden="true" dangerouslySetInnerHTML={{ __html: svg }} />
      <span>{text}</span>
    </div>
  )
}
