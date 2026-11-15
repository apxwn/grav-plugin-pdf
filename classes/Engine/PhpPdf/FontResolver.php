<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Engine\PhpPdf;

use Dskripchenko\PhpPdf\Font\FontProvider;
use Dskripchenko\PhpPdf\Font\Ttf\TtfFile;
use Dskripchenko\PhpPdf\Layout\Engine;
use Dskripchenko\PhpPdf\Pdf\PdfFont;
use Grav\Plugin\Pdf\Generator;

/**
 * Resolves font families and style variants to TtfFile instances for php-pdf.
 *
 * Maps Grav PDF plugin family names (`dejavusans`, `dejavuserifcondensed`,
 * custom families from `font_dir`/`fonts`, and generic CSS aliases) to their
 * `.ttf` files, handling DejaVu's `-Oblique` naming as well as standard
 * `-Italic` / `-BoldItalic` suffixes.
 */
final class FontResolver implements FontProvider
{
    /** @var array<string, array<string, string>> normalized family => style (R|B|I|BI) => path */
    private array $families = [];

    /** @var array<string, string> normalized alias => normalized target family */
    private const ALIASES = [
        'sansserif' => 'dejavusans',
        'helvetica' => 'dejavusans',
        'arial' => 'dejavusans',
        'serif' => 'dejavuserif',
        'times' => 'dejavuserif',
        'timesnewroman' => 'dejavuserif',
        'monospace' => 'dejavusansmono',
        'courier' => 'dejavusansmono',
        'couriernew' => 'dejavusansmono',
    ];

    /**
     * @param array<string, array<string, string>> $customFonts family => style => absolute path
     */
    public function __construct(array $customFonts = [])
    {
        foreach (['dejavusans', 'dejavusanscondensed', 'dejavuserif', 'dejavuserifcondensed', 'dejavusansmono'] as $builtin) {
            $files = Generator::builtinFontFiles($builtin);

            if ($files !== []) {
                $this->families[$builtin] = $files;
            }
        }

        foreach ($customFonts as $family => $styles) {
            $normalized = self::normalizeFamily((string) $family);

            if ($normalized === '' || !is_array($styles)) {
                continue;
            }

            foreach ($styles as $style => $path) {
                $style = strtoupper(trim((string) $style));
                $path = (string) $path;

                if (in_array($style, ['R', 'B', 'I', 'BI'], true) && is_file($path)) {
                    $this->families[$normalized][$style] = $path;
                }
            }
        }
    }

    public function resolve(string $fontName): ?TtfFile
    {
        [$family, $style] = self::splitFontRequest($fontName);
        $family = self::ALIASES[$family] ?? $family;

        $styles = $this->families[$family] ?? null;

        if ($styles === null) {
            return null;
        }

        $path = $this->pickStyle($styles, $style);

        return $path !== null ? TtfFile::fromFile($path) : null;
    }

    /**
     * Build a php-pdf Layout\Engine configured with the requested body face.
     */
    public function engine(string $bodyFamily, float $fontSizePt): Engine
    {
        $regular = $this->pdfFont($bodyFamily, 'R') ?? $this->pdfFont('dejavusans', 'R');
        $bold = $this->pdfFont($bodyFamily, 'B');
        $italic = $this->pdfFont($bodyFamily, 'I');
        $boldItalic = $this->pdfFont($bodyFamily, 'BI');

        $fallbacks = [];

        if (self::normalizeFamily($bodyFamily) !== 'dejavusans') {
            $dejavu = $this->pdfFont('dejavusans', 'R');

            if ($dejavu !== null) {
                $fallbacks[] = $dejavu;
            }
        }

        return new Engine(
            defaultFont: $regular,
            defaultFontSizePt: $fontSizePt,
            defaultLineHeightMult: 1.45,
            boldFont: $bold,
            italicFont: $italic,
            boldItalicFont: $boldItalic,
            fontProvider: $this,
            fallbackFonts: $fallbacks,
        );
    }

    private function pdfFont(string $family, string $style): ?PdfFont
    {
        $normalized = self::normalizeFamily($family);
        $normalized = self::ALIASES[$normalized] ?? $normalized;
        $styles = $this->families[$normalized] ?? null;

        if ($styles === null) {
            return null;
        }

        $path = $style === 'R' ? ($styles['R'] ?? null) : ($styles[$style] ?? null);

        if ($path === null || !is_file($path)) {
            return null;
        }

        return new PdfFont(TtfFile::fromFile($path));
    }

    /**
     * @param array<string, string> $styles
     */
    private function pickStyle(array $styles, string $style): ?string
    {
        $order = match ($style) {
            'BI' => ['BI', 'B', 'I', 'R'],
            'B' => ['B', 'R'],
            'I' => ['I', 'R'],
            default => ['R', 'B', 'I', 'BI'],
        };

        foreach ($order as $candidate) {
            if (isset($styles[$candidate]) && is_file($styles[$candidate])) {
                return $styles[$candidate];
            }
        }

        return null;
    }

    /**
     * Parse PdfFontResolver-style names (`dejavusans-BoldItalic`, `myfont-Italic`, `serif`).
     *
     * @return array{0: string, 1: string} [normalized family, style code]
     */
    private static function splitFontRequest(string $fontName): array
    {
        $fontName = trim($fontName);
        $style = 'R';

        $dash = strrpos($fontName, '-');

        if ($dash !== false && $dash > 0) {
            $suffix = strtolower(substr($fontName, $dash + 1));

            $mapped = match ($suffix) {
                'bolditalic', 'boldoblique' => 'BI',
                'bold', 'semibold' => 'B',
                'italic', 'oblique' => 'I',
                'regular', 'normal', 'book', 'roman' => 'R',
                default => null,
            };

            if ($mapped !== null) {
                $style = $mapped;
                $fontName = substr($fontName, 0, $dash);
            }
        }

        return [self::normalizeFamily($fontName), $style];
    }

    private static function normalizeFamily(string $family): string
    {
        return strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $family));
    }
}
