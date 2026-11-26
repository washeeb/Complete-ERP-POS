/**
 * Home's arrangement (Personalize and Layout): which panels show, in which column and order. Kept per person on the
 * server; null there = the standard Home below.
 */
export const PANELS = ['reminders', 'shortcuts', 'tiles', 'kpis', 'trend', 'meter', 'top_items'] as const
export type PanelId = (typeof PANELS)[number]
export type Placed = { id: PanelId; col: number; hidden: boolean }
export type Tile = { url: string; label: string }
export type HomeLayout = { cols: 2 | 3; panels: Placed[]; tiles: Tile[]; meter: string | null }

export const PANEL_NAMES: Record<PanelId, string> = {
  reminders: 'Reminders', shortcuts: 'Shortcuts', tiles: 'Action Centre', kpis: 'Key performance indicators', trend: 'Monthly trend',
  meter: 'KPI meter', top_items: 'Top items sold',
}

/** The standard Home: reminders and shortcuts on the left, the figures in the middle, the meter and best sellers on the right. */
export function standardLayout(cols: 2 | 3 = 3): HomeLayout {
  const right = cols === 3 ? 2 : 1
  return {
    cols,
    panels: [
      { id: 'reminders', col: 0, hidden: false }, { id: 'shortcuts', col: 0, hidden: false },
      { id: 'tiles', col: 1, hidden: false }, { id: 'kpis', col: 1, hidden: false }, { id: 'trend', col: 1, hidden: false },
      { id: 'meter', col: right, hidden: false }, { id: 'top_items', col: right, hidden: false },
    ],
    tiles: [],
    meter: null,
  }
}

/** What the server kept, made whole: unknown panels dropped, missing ones added where the standard Home has them. */
export function normalize(saved: Partial<HomeLayout> | null | undefined): HomeLayout {
  const cols: 2 | 3 = saved?.cols === 2 ? 2 : 3
  const std = standardLayout(cols)
  const seen = new Set<string>()
  const panels: Placed[] = []
  for (const p of saved?.panels ?? []) {
    if (!(PANELS as readonly string[]).includes(p.id) || seen.has(p.id)) continue
    seen.add(p.id)
    panels.push({ id: p.id, col: Math.max(0, Math.min(cols - 1, p.col | 0)), hidden: !!p.hidden })
  }
  for (const p of std.panels) if (!seen.has(p.id)) panels.push(p)
  return { cols, panels, tiles: (saved?.tiles ?? []).slice(0, 8), meter: saved?.meter ?? null }
}

/** The panels of one column, in order (hidden ones left out). */
export function column(layout: HomeLayout, col: number): Placed[] {
  return layout.panels.filter((p) => p.col === col && !p.hidden)
}

/** Move a panel to a column, before another panel (or to the end of that column). */
export function move(layout: HomeLayout, id: PanelId, col: number, before: PanelId | null): HomeLayout {
  const moving = layout.panels.find((p) => p.id === id)
  if (!moving) return layout
  const rest = layout.panels.filter((p) => p.id !== id)
  const placed = { ...moving, col: Math.max(0, Math.min(layout.cols - 1, col)) }
  let at = before ? rest.findIndex((p) => p.id === before) : -1
  if (at < 0) { // the end of that column: after its last panel
    const last = rest.map((p, i) => [p, i] as const).filter(([p]) => p.col === placed.col).pop()
    at = last ? last[1] + 1 : rest.length
  }
  return { ...layout, panels: [...rest.slice(0, at), placed, ...rest.slice(at)] }
}

/** One step for the keyboard: up or down within the column, or to the next column left or right. */
export function nudge(layout: HomeLayout, id: PanelId, dir: 'up' | 'down' | 'left' | 'right'): HomeLayout {
  const p = layout.panels.find((x) => x.id === id)
  if (!p) return layout
  if (dir === 'left' || dir === 'right') return move(layout, id, p.col + (dir === 'left' ? -1 : 1), null)
  const col = column(layout, p.col)
  const i = col.findIndex((x) => x.id === id)
  if (dir === 'up') return i > 0 ? move(layout, id, p.col, col[i - 1].id) : layout
  return i < col.length - 1 ? move(layout, id, p.col, col[i + 2]?.id ?? null) : layout
}

/** Two or three columns: panels in a column that is no longer there go to the last one. */
export function withCols(layout: HomeLayout, cols: 2 | 3): HomeLayout {
  return { ...layout, cols, panels: layout.panels.map((p) => ({ ...p, col: Math.min(p.col, cols - 1) })) }
}
