<?php

namespace App\Domain\Fiscal;

/**
 * A tax authority's e-invoicing service behind one shape (spec §11: "pluggable fiscal adapter"). Ghana: GRA E-VAT.
 * A document is the array built by FiscalService::document().
 */
interface FiscalAdapter
{
    /** @return array{reference: string, qr: ?string, raw: array} GRA's number/signature for the document and what its QR code holds */
    public function submit(array $document): array;

    /**
     * Several documents in one call (daily batch). One result per document, in the same order.
     *
     * @return list<array{ok: bool, reference: ?string, qr: ?string, error: ?string, raw: array}>
     */
    public function submitBatch(array $documents): array;
}
