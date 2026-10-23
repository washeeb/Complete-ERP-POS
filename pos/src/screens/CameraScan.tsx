import { useEffect, useRef, useState } from 'react'
import { CAMERA_FORMATS, cameraScanSupported, scanFeedback } from '../scanner'

type Detector = { detect: (source: HTMLVideoElement) => Promise<{ rawValue: string }[]> }
type DetectorClass = { new (o: { formats: string[] }): Detector; getSupportedFormats?: () => Promise<string[]> }

/**
 * Scan with the camera (phones and tablets without a built-in scanner): the back camera opens, and the first barcode it
 * reads is used. Uses the browser's own barcode reader (Chrome on Android), nothing is sent anywhere.
 */
export function CameraScan({ onScan, onClose }: { onScan: (code: string) => void; onClose: () => void }) {
  const video = useRef<HTMLVideoElement>(null)
  const [error, setError] = useState(cameraScanSupported() ? '' : 'This browser cannot read barcodes with the camera. Use Chrome on Android, a scanner, or type the code.')

  useEffect(() => {
    if (!cameraScanSupported()) return
    let stream: MediaStream | null = null
    let timer: ReturnType<typeof setTimeout> | null = null
    let stopped = false
    const start = async () => {
      try {
        stream = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false })
        if (stopped || !video.current) return
        video.current.srcObject = stream
        await video.current.play()
        const Ctor = (window as unknown as { BarcodeDetector: DetectorClass }).BarcodeDetector
        const supported = (await Ctor.getSupportedFormats?.()) ?? CAMERA_FORMATS
        const detector = new Ctor({ formats: CAMERA_FORMATS.filter((f) => supported.includes(f)) })
        const look = async () => {
          if (stopped || !video.current) return
          const found = await detector.detect(video.current).catch(() => [])
          if (found[0]?.rawValue) {
            scanFeedback(true)
            onScan(found[0].rawValue.trim())
            return
          }
          timer = setTimeout(look, 200)
        }
        void look()
      } catch (e) {
        setError(e instanceof DOMException && e.name === 'NotAllowedError'
          ? 'The camera is not allowed for this site: allow it in the browser (padlock beside the address), then try again.'
          : 'The camera could not be opened.')
      }
    }
    void start()
    return () => {
      stopped = true
      if (timer) clearTimeout(timer)
      stream?.getTracks().forEach((t) => t.stop())
    }
  }, []) // eslint-disable-line react-hooks/exhaustive-deps

  return (
    <div className="modal" role="dialog" aria-modal="true" aria-label="Scan with the camera">
      <div className="card camera-scan">
        {error ? <p className="error" role="alert">{error}</p> : <>
          <video ref={video} muted playsInline className="camera-view" aria-label="Camera" />
          <p className="small muted">Hold the barcode steady inside the frame.</p>
        </>}
        <button type="button" className="big" onClick={onClose}>Close</button>
      </div>
    </div>
  )
}
