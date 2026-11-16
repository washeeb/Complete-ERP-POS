import { useEffect, useState } from 'react'

/**
 * Load a list for the address's search and filters. A request replaced by a newer one (the person typed on, or changed
 * a filter) is cancelled and leaves "loading" and any error to the newer one, so the list never flashes empty.
 */
export function useList<T>(load: (query: string, signal: AbortSignal) => Promise<T>, query: string, refresh = 0) {
  const [data, setData] = useState<T | null>(null)
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState('')
  useEffect(() => {
    const ctl = new AbortController()
    setLoading(true)
    load(query, ctl.signal).then((d) => { setData(d); setError('') })
      .catch((e: unknown) => { if (!ctl.signal.aborted) setError(e instanceof Error ? e.message : String(e)) })
      .finally(() => { if (!ctl.signal.aborted) setLoading(false) })
    return () => ctl.abort()
  }, [query, refresh]) // eslint-disable-line react-hooks/exhaustive-deps
  return { data, loading, error }
}

/** Type, then a pause, then search: the search box's text reaches the address a moment after the last key. */
export function useDebounced(value: string, apply: (v: string) => void, ms = 300) {
  useEffect(() => {
    const t = setTimeout(() => apply(value), ms)
    return () => clearTimeout(t)
  }, [value]) // eslint-disable-line react-hooks/exhaustive-deps
}
