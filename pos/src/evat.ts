import type { SdcSignature } from './types'

/**
 * GRA E-VAT signature block for the receipt (VSDC answer): the SDC ID and receipt number, the date and time GRA signed
 * it, the internal data, the signature and the MRC. Short labels so each fits one line of an 80 mm receipt.
 */
export function sdcLines(sdc: SdcSignature | null | undefined): string[] {
  if (!sdc) return []
  return [
    `SDC ID: ${sdc.sdc_id}  Rec: ${sdc.receipt}`,
    sdc.time && `SDC time: ${sdc.time}`,
    sdc.internal && `Int. data: ${sdc.internal}`,
    `Signature: ${sdc.signature}`,
    sdc.mrc && `MRC: ${sdc.mrc}`,
  ].filter((l): l is string => !!l)
}
