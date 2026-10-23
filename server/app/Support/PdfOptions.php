<?php

namespace App\Support;

use Dompdf\Options;

/**
 * The same PDF settings everywhere (shift reports, exports, purchase orders…): nothing fetched from the network, a font
 * with ₵ and accented letters, and the app's own folder for the PDF engine's temporary files. The system temp folder
 * is not always usable (a Windows service, a locked-down server, PHP started without TEMP), and the engine then failed
 * with "Path must not be empty" while embedding the font.
 */
final class PdfOptions
{
    public static function make(): Options
    {
        $tmp = storage_path('app/pdf-tmp');
        if (! is_dir($tmp)) {
            @mkdir($tmp, 0770, true);
        }
        $options = new Options;
        $options->setIsRemoteEnabled(false);
        $options->setDefaultFont('DejaVu Sans');
        $options->setTempDir($tmp);

        return $options;
    }
}
