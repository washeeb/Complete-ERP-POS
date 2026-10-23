<?php

namespace App\Support\Exports;

use App\Domain\Branding\Branding;
use App\Domain\Licensing\LicenseManager;
use App\Domain\Licensing\Watermark;
use App\Domain\Support\Actor;
use App\Domain\Support\AuditLog;
use App\Domain\Support\DomainException;
use App\Models\Tenant;
use App\Support\PdfOptions;
use Dompdf\Dompdf;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Downloads of anything shown on screen: CSV (opens in Excel, Google Sheets, LibreOffice), Excel (.xlsx) and PDF (A4, ready to
 * print or e-mail). Every export is in the audit log (who, what, how many rows) and needs a licence that allows output, like
 * printing. CSV cells that a spreadsheet would run as a formula are neutralised (CSV injection).
 *
 * A "sheet" is one table: ['title' => string, 'headings' => list<string>, 'rows' => iterable<list<scalar|null>>,
 * 'numeric' => list<int> (column indexes aligned right in the PDF), 'note' => ?string].
 */
final class Exporter
{
    public const PDF_ROW_LIMIT = 5000;

    public function __construct(private readonly AuditLog $audit) {}

    /** One or more sheets as a single CSV file (several sheets are separated by a title row and a blank line). */
    public function csv(Actor $actor, string $name, array $sheets, array $filters = []): StreamedResponse
    {
        $this->guard();
        $filename = self::filename($name, 'csv');
        $count = 0;
        $response = new StreamedResponse(function () use ($sheets, &$count) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\u{FEFF}"); // UTF-8 byte-order mark: Excel then reads accents and ₵ correctly
            foreach ($sheets as $i => $sheet) {
                if (count($sheets) > 1) {
                    if ($i > 0) {
                        fputcsv($out, [], escape: '');
                    }
                    fputcsv($out, [$sheet['title']], escape: '');
                }
                fputcsv($out, $sheet['headings'], escape: '');
                foreach ($sheet['rows'] as $row) {
                    fputcsv($out, array_map([self::class, 'cell'], array_values($row)), escape: '');
                    $count++;
                }
            }
            fclose($out);
        }, 200, ['Content-Type' => 'text/csv; charset=UTF-8', 'Content-Disposition' => 'attachment; filename="'.$filename.'"', 'Cache-Control' => 'no-store']);
        $this->log($actor, $name, 'csv', $sheets, $filters);

        return $response;
    }

    /** One or more sheets as an Excel workbook (.xlsx), one worksheet each. */
    public function xlsx(Actor $actor, string $name, array $sheets, array $filters = []): StreamedResponse
    {
        $this->guard();
        $bytes = XlsxWriter::bytes($sheets);
        $this->log($actor, $name, 'xlsx', $sheets, $filters);

        return new StreamedResponse(function () use ($bytes) {
            echo $bytes;
        }, 200, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Content-Disposition' => 'attachment; filename="'.self::filename($name, 'xlsx').'"',
            'Cache-Control' => 'no-store']);
    }

    /**
     * The same files as bytes, for e-mail attachments (scheduled reports).
     *
     * @return array{bytes: string, filename: string, mime: string}
     */
    public function file(Actor $actor, string $format, string $name, array $sheets, array $filters = []): array
    {
        $this->guard();
        $bytes = match ($format) {
            'xlsx' => XlsxWriter::bytes($sheets),
            'csv' => (function () use ($sheets) {
                $out = fopen('php://temp', 'w+');
                fwrite($out, "\u{FEFF}");
                foreach ($sheets as $i => $sheet) {
                    if (count($sheets) > 1) {
                        if ($i > 0) {
                            fputcsv($out, [], escape: '');
                        }
                        fputcsv($out, [$sheet['title']], escape: '');
                    }
                    fputcsv($out, $sheet['headings'], escape: '');
                    foreach ($sheet['rows'] as $row) {
                        fputcsv($out, array_map([self::class, 'cell'], array_values($row)), escape: '');
                    }
                }
                rewind($out);

                return stream_get_contents($out);
            })(),
            default => $this->pdfBytes($actor, $name, $sheets, $filters, 'landscape')[0],
        };
        $this->log($actor, $name, $format, $sheets, $filters);

        return ['bytes' => $bytes, 'filename' => self::filename($name, $format), 'mime' => ['xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'csv' => 'text/csv', 'pdf' => 'application/pdf'][$format] ?? 'application/pdf'];
    }

    /** One or more sheets as an A4 PDF with the company name, filters, who printed it and page numbers. */
    public function pdf(Actor $actor, string $name, array $sheets, array $filters = [], string $orientation = 'landscape'): StreamedResponse
    {
        $this->guard();
        [$bytes, $prepared] = $this->pdfBytes($actor, $name, $sheets, $filters, $orientation);
        $this->log($actor, $name, 'pdf', $prepared, $filters);

        return new StreamedResponse(function () use ($bytes) {
            echo $bytes;
        }, 200, ['Content-Type' => 'application/pdf', 'Content-Disposition' => 'attachment; filename="'.self::filename($name, 'pdf').'"', 'Cache-Control' => 'no-store']);
    }

    /** @return array{0: string, 1: array} the PDF and the sheets as printed */
    private function pdfBytes(Actor $actor, string $name, array $sheets, array $filters, string $orientation): array
    {
        $prepared = [];
        $truncated = false;
        foreach ($sheets as $sheet) {
            $rows = [];
            foreach ($sheet['rows'] as $row) {
                if (count($rows) >= self::PDF_ROW_LIMIT) {
                    $truncated = true;
                    break;
                }
                $rows[] = array_map(fn ($v) => $v === null ? '' : (string) $v, array_values($row));
            }
            $prepared[] = ['rows' => $rows, 'numeric' => $sheet['numeric'] ?? self::guessNumeric($rows)] + $sheet;
        }
        $tenant = Tenant::find($actor->tenantId);
        $html = view('exports.table', ['title' => $name, 'sheets' => $prepared, 'filters' => $filters, 'truncated' => $truncated,
            'company' => Watermark::stamp($tenant?->name ?? ''), 'by' => $actor->name, 'at' => now()->setTimezone($tenant?->timezone ?: 'UTC'),
            'ref' => Watermark::reference(), 'logo' => Branding::logoDataUri($actor->tenantId, svgOk: false), 'powered' => Branding::poweredBy()])->render();

        $pdf = new Dompdf(PdfOptions::make()); // no network, ₵ font, the app's own temp folder
        $pdf->loadHtml($html, 'UTF-8');
        $pdf->setPaper('A4', $orientation);
        $pdf->render();
        $canvas = $pdf->getCanvas();
        $font = $pdf->getFontMetrics()->getFont('DejaVu Sans');
        $canvas->page_text($canvas->get_width() - 110, $canvas->get_height() - 24, 'Page {PAGE_NUM} of {PAGE_COUNT}', $font, 7, [0.45, 0.45, 0.45]);

        return [$pdf->output(), $prepared];
    }

    /** "Stock on hand" → "stock-on-hand-2026-10-22-1405.csv" */
    public static function filename(string $name, string $ext): string
    {
        return Str::slug($name ?: 'export').'-'.now()->format('Y-m-d-Hi').'.'.$ext;
    }

    /** A spreadsheet cell: text that would run as a formula gets a leading apostrophe; numbers stay numbers. */
    public static function cell(mixed $v): string
    {
        if ($v === null) {
            return '';
        }
        if (is_bool($v)) {
            return $v ? 'Yes' : 'No';
        }
        $s = (string) $v;
        if (preg_match('/^0\d+$/', $s) || preg_match('/^\d{12,}$/', $s)) {
            return '="'.$s.'"'; // Excel keeps it as text: leading zeros stay, long barcodes are not shortened to 6.00107E+12
        }
        if ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($s)) {
            return "'".$s;
        }

        return $s;
    }

    private static function guessNumeric(array $rows): array
    {
        $cols = [];
        foreach (array_slice($rows, 0, 50) as $row) {
            foreach ($row as $i => $v) {
                $plain = str_replace([',', ' ', '%'], '', preg_replace('/^[A-Z]{3}\s|^[^\d\-]+/u', '', $v));
                $cols[$i] = ($cols[$i] ?? true) && ($v === '' || is_numeric($plain));
            }
        }

        return array_keys(array_filter($cols));
    }

    private function guard(): void
    {
        $license = app(LicenseManager::class);
        if (! $license->canOutput()) {
            throw new DomainException($license->status()['message'] ?: 'Downloads are not allowed while the licence is read-only.', 'license_read_only');
        }
    }

    private function log(Actor $actor, string $name, string $format, array $sheets, array $filters): void
    {
        $rows = 0;
        foreach ($sheets as $s) {
            $rows += is_countable($s['rows']) ? count($s['rows']) : 0;
        }
        DB::transaction(fn () => $this->audit->record($actor, 'data.exported', 'export', ['export' => [null, $name], 'format' => [null, $format],
            'rows' => [null, $rows ?: 'streamed']] + ($filters ? ['filters' => [null, implode('; ', $filters)]] : [])));
    }
}
