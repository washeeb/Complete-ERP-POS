/**
 * The till calculator: + − × ÷, brackets, percent and decimals, worked out without eval (a small recursive-descent
 * parser), so nothing typed can run as code. Results are rounded to 10 decimals to hide floating-point noise.
 */
export function calculate(input: string): number {
  const src = input.replace(/×/g, '*').replace(/÷/g, '/').replace(/−/g, '-').replace(/,/g, '').replace(/\s+/g, '')
  let i = 0
  const peek = () => src[i]
  const fail = (): never => { throw new Error('Check the sum') }

  function number(): number {
    const m = /^\d*\.?\d+|^\d+\./.exec(src.slice(i))
    if (!m) fail()
    i += m![0].length
    return Number(m![0])
  }
  function factor(): number {
    if (peek() === '-') { i++; return -factor() }
    if (peek() === '+') { i++; return factor() }
    let v: number
    if (peek() === '(') {
      i++
      v = expr()
      if (peek() !== ')') fail()
      i++
    } else v = number()
    while (peek() === '%') { i++; v = v / 100 }
    return v
  }
  function term(): number {
    let v = factor()
    while (peek() === '*' || peek() === '/') {
      const op = src[i++]
      const r = factor()
      if (op === '/' && r === 0) throw new Error('Cannot divide by zero')
      v = op === '*' ? v * r : v / r
    }
    return v
  }
  function expr(): number {
    let v = term()
    while (peek() === '+' || peek() === '-') {
      const op = src[i++]
      // "200 + 10%" means 10 % of 200 (as on a shop calculator)
      const start = i
      let r = term()
      if (src[i - 1] === '%' && !src.slice(start, i).includes('(')) r = v * r
      v = op === '+' ? v + r : v - r
    }
    return v
  }

  if (src === '') return 0
  const v = expr()
  if (i !== src.length || !Number.isFinite(v)) fail()
  return Math.round(v * 1e10) / 1e10
}

/** Change counted out in notes and coins (largest first), for a currency with 2 decimals (GHS notes and coins). */
export function changeBreakdown(minor: number, exponent = 2): { value: number; count: number }[] {
  if (exponent !== 2 || minor <= 0) return []
  const denominations = [20000, 10000, 5000, 2000, 1000, 500, 200, 100, 50, 20, 10]
  const out: { value: number; count: number }[] = []
  let left = minor
  for (const d of denominations) {
    const n = Math.floor(left / d)
    if (n > 0) { out.push({ value: d, count: n }); left -= n * d }
  }
  if (left > 0) out.push({ value: 1, count: left }) // pesewas no coin covers
  return out
}
