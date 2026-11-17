<?php

namespace Grav\Plugin\Pdf\Engine\Gotenberg;

use Dskripchenko\PhpPdf\Pdf\Incremental\IncrementalUpdate;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfDictionary;
use Dskripchenko\PhpPdf\Pdf\Reader\PdfName;

/**
 * Make the outline Chromium created visible when the document opens.
 *
 * With `nativeBookmarks` on, Chromium writes a real PDF outline, but leaves
 * `/PageMode /UseOutlines` out of the catalog. A viewer then keeps its bookmarks
 * panel closed, so an outline that is present looks like one that is missing.
 * The bundled engine sets the same flag whenever it emits an outline.
 *
 * The catalog is changed with an incremental update (ISO 32000-1 §7.5.6): the
 * Gotenberg PDF is kept byte for byte and only the catalog object is replaced.
 * A document that cannot be read, or that has no outline, is left alone — the
 * bookmarks panel is a convenience, not a reason to throw away a valid PDF.
 */
final class Outline
{
    /**
     * @return string|null an operational message when the flag could not be set
     */
    public static function apply(string $file): ?string
    {
        $raw = @file_get_contents($file);

        if ($raw === false || $raw === '') {
            return 'the converted document could not be read';
        }

        try {
            $update = IncrementalUpdate::fromBytes($raw);
            $catalog = $update->catalog();

            if (!$catalog->has('Outlines')) {
                return 'the converted document has no outline';
            }

            $items = $catalog->all();
            $mode = $items['PageMode'] ?? null;

            if ($mode instanceof PdfName && $mode->value === 'UseOutlines') {
                return null;
            }

            $items['PageMode'] = new PdfName('UseOutlines');
            $update->replace($update->rootReference()->number, new PdfDictionary($items));
            $bytes = $update->toBytes();
        } catch (\Throwable $e) {
            return 'the PDF catalog could not be updated: ' . $e->getMessage();
        }

        $temp = $file . '.outline';

        if (@file_put_contents($temp, $bytes) === false) {
            return 'the updated PDF catalog could not be written';
        }

        if (!@rename($temp, $file)) {
            @unlink($temp);

            return 'the updated PDF catalog could not be installed';
        }

        return null;
    }
}
