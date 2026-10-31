/**
 * The receipt's closing message as separate lines: each line typed in the setting, and each sentence on its own line
 * ("Thank you for shopping with us. Goods sold are not returnable." prints as two lines). A full stop followed by a
 * capital letter starts a new line, so "Tel. 024…" or "No. 5" stay together.
 */
export function footerLines(text: string | null | undefined): string[] {
  return (text ?? '')
    .split(/\r?\n/)
    .flatMap((line) => line.split(/(?<=[.!?])\s+(?=[A-Z])/))
    .map((line) => line.trim())
    .filter(Boolean)
}
