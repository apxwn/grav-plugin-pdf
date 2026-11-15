<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Engine\PhpPdf;

use Dskripchenko\PhpPdf\Element\Bookmark;
use Dskripchenko\PhpPdf\Element\Field;
use Dskripchenko\PhpPdf\Element\Image;
use Dskripchenko\PhpPdf\Element\SvgElement;
use Dskripchenko\PhpPdf\Html\ElementHandler;
use Dskripchenko\PhpPdf\Html\FlowBlocks;
use Dskripchenko\PhpPdf\Html\HtmlContext;
use Dskripchenko\PhpPdf\Image\PdfImage;
use Dskripchenko\PhpPdf\Style\Alignment;

/**
 * Bridges plugin-specific HTML elements into php-pdf AST elements:
 *
 *  - `<span class="pageNumber">` / `<span class="totalPages">` → Field::page() / Field::totalPages()
 *  - `<a name="…">` (without `href`) → named Bookmark destination
 *  - `<img src="….svg">` → SvgElement (php-pdf's Image loader accepts PNG/JPEG only)
 *  - content `<img src="…">` → block Image (so Engine scales oversized images to contentWidth)
 */
final class HtmlElementHandler implements ElementHandler
{
    private const PT_PER_MM = 72.0 / 25.4;

    public function handle(\DOMElement $element, HtmlContext $context): ?array
    {
        $tag = strtolower($element->nodeName);

        if ($tag === 'span') {
            $classes = $this->classes($element);

            if (in_array('pageNumber', $classes, true)) {
                return [Field::page($context->style)];
            }

            if (in_array('totalPages', $classes, true)) {
                return [Field::totalPages($context->style)];
            }
        }

        if ($tag === 'a') {
            if (!$element->hasAttribute('href') && $element->hasAttribute('name')) {
                $name = trim($element->getAttribute('name'));

                if ($name !== '') {
                    $children = $context->inline ? $context->inlines($element) : [];

                    return [new Bookmark($name, $children)];
                }
            }

            // Drop empty links (e.g. page-toc's `<a class="toc-anchor" href="#…"></a>`
            // or links whose inner SVG/icon was stripped); otherwise php-pdf falls
            // back to printing the raw `#href` string as visible link text.
            if ($element->hasAttribute('href') && (!$element->hasChildNodes() || $context->inlines($element) === [])) {
                return [];
            }
        }

        if ($tag === 'img') {
            return $this->handleImage($element, $context);
        }

        return null;
    }

    /**
     * @return list<\Dskripchenko\PhpPdf\Element\BlockElement|\Dskripchenko\PhpPdf\Element\InlineElement>|null
     */
    private function handleImage(\DOMElement $element, HtmlContext $context): ?array
    {
        $src = trim($element->getAttribute('src'));

        if ($src === '') {
            return null;
        }

        $classes = $this->classes($element);
        $isSvg = strtolower((string) pathinfo($src, PATHINFO_EXTENSION)) === 'svg';

        // Keep running header logo inline beside header text (raster only,
        // matching Gotenberg — repeating an SVG on every page bloats output
        // and complex SVGO paths are unsupported by php-pdf's SvgRenderer).
        if (in_array('pdf-logo', $classes, true)) {
            return $isSvg ? [] : null;
        }

        if ($isSvg) {
            if (!is_file($src)) {
                return null;
            }

            $svg = @file_get_contents($src);

            if (!is_string($svg) || $svg === '') {
                return null;
            }

            [$widthPt, $heightPt, $alignment, $spaceAfterPt] = $this->svgDimensions($svg, $classes, $context->alignment);

            return [new SvgElement(
                svgXml: $svg,
                widthPt: $widthPt,
                heightPt: $heightPt,
                alignment: $alignment,
                spaceAfterPt: $spaceAfterPt,
            )];
        }

        if (!is_readable($src)) {
            return null;
        }

        try {
            $pdfImage = PdfImage::fromPath($src);
        } catch (\Throwable) {
            return null;
        }

        if (in_array('pdf-cover-logo', $classes, true)) {
            $block = new Image(
                source: $pdfImage,
                heightPt: 22.0 * self::PT_PER_MM,
                alignment: Alignment::Center,
                spaceAfterPt: 12.0 * self::PT_PER_MM,
            );

            return $context->inline ? [new FlowBlocks([$block])] : [$block];
        }

        // Content and gallery images render as blocks so php-pdf's Layout\Engine
        // scales them down proportionally to fit the page content width and height.
        $block = new Image(
            source: $pdfImage,
            alignment: $context->alignment === Alignment::Center ? Alignment::Center : Alignment::Start,
            spaceBeforePt: 2.0 * self::PT_PER_MM,
            spaceAfterPt: 2.0 * self::PT_PER_MM,
        );

        return $context->inline ? [new FlowBlocks([$block])] : [$block];
    }

    /**
     * @return list<string>
     */
    private function classes(\DOMElement $element): array
    {
        $raw = trim($element->getAttribute('class'));

        return $raw === '' ? [] : (preg_split('/\s+/', $raw) ?: []);
    }

    /**
     * @param list<string> $classes
     * @return array{0: float, 1: float, 2: Alignment, 3: float} [widthPt, heightPt, alignment, spaceAfterPt]
     */
    private function svgDimensions(string $svg, array $classes, Alignment $fallbackAlignment): array
    {
        $targetHeightPt = null;
        $alignment = $fallbackAlignment;
        $spaceAfterPt = 0.0;

        if (in_array('pdf-cover-logo', $classes, true)) {
            $targetHeightPt = 22.0 * self::PT_PER_MM;
            $alignment = Alignment::Center;
            $spaceAfterPt = 12.0 * self::PT_PER_MM;
        } elseif (in_array('pdf-logo', $classes, true)) {
            $targetHeightPt = 8.0 * self::PT_PER_MM;
        }

        $ratio = $this->aspectRatio($svg);
        $heightPt = $targetHeightPt ?? 40.0;
        $widthPt = $heightPt * $ratio;

        return [$widthPt, $heightPt, $alignment, $spaceAfterPt];
    }

    private function aspectRatio(string $svg): float
    {
        if (preg_match('/\bviewBox\s*=\s*["\']\s*[-\d.]+\s+[-\d.]+\s+([\d.]+)\s+([\d.]+)\s*["\']/i', $svg, $m)) {
            $w = (float) $m[1];
            $h = (float) $m[2];

            if ($w > 0 && $h > 0) {
                return $w / $h;
            }
        }

        $w = preg_match('/\bwidth\s*=\s*["\']\s*([\d.]+)/i', $svg, $mw) ? (float) $mw[1] : 0.0;
        $h = preg_match('/\bheight\s*=\s*["\']\s*([\d.]+)/i', $svg, $mh) ? (float) $mh[1] : 0.0;

        if ($w > 0 && $h > 0) {
            return $w / $h;
        }

        return 1.0;
    }
}
