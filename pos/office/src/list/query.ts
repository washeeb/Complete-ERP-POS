import { useCallback, useEffect, useState } from 'react'

/**
 * A list's search, filters, sort and page live in the address (?q=…&period=today&page=2), so Back, a reload, a bookmark
 * or a link sent to someone open the list exactly as it was. Several values for one name (methods) are repeated.
 */
export type Params = Record<string, string | string[]>

export function parse(search: string): Params {
  const out: Params = {}
  for (const [k, v] of new URLSearchParams(search)) {
    const key = k.replace(/\[\]$/, '')
    if (k.endsWith('[]')) out[key] = [...((out[key] as string[] | undefined) ?? []), v]
    else out[key] = v
  }
  return out
}

/** The address part for these values; empty values are left out. Arrays go as name[]=… (what the server reads). */
export function serialize(p: Params): string {
  const u = new URLSearchParams()
  for (const [k, v] of Object.entries(p)) {
    if (Array.isArray(v)) v.filter(Boolean).forEach((x) => u.append(`${k}[]`, x))
    else if (v !== '' && v !== undefined && v !== null) u.set(k, v)
  }
  return u.toString()
}

/** Change some values; any change other than the page itself goes back to page 1. */
export function patch(p: Params, change: Params): Params {
  const next = { ...p, ...change }
  if (!('page' in change)) delete next.page
  for (const [k, v] of Object.entries(next)) if (v === '' || (Array.isArray(v) && v.length === 0)) delete next[k]
  return next
}

export function useQueryParams(): [Params, (change: Params) => void] {
  const [params, setParams] = useState<Params>(() => parse(location.search))
  useEffect(() => {
    const on = () => setParams(parse(location.search))
    window.addEventListener('popstate', on)
    return () => window.removeEventListener('popstate', on)
  }, [])
  const set = useCallback((change: Params) => {
    setParams((p) => {
      const next = patch(p, change)
      const s = serialize(next)
      history.replaceState(null, '', `${location.pathname}${s ? `?${s}` : ''}`)
      return next
    })
  }, [])
  return [params, set]
}

export const str = (p: Params, k: string): string => (typeof p[k] === 'string' ? (p[k] as string) : '')
export const list = (p: Params, k: string): string[] => (Array.isArray(p[k]) ? (p[k] as string[]) : typeof p[k] === 'string' ? [p[k] as string] : [])
