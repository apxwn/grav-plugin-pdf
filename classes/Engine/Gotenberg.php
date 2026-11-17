<?php

namespace Grav\Plugin\Pdf\Engine;

use Grav\Common\Grav;
use Grav\Plugin\Pdf\Config;
use Grav\Plugin\Pdf\Engine\Gotenberg\Assets;
use Grav\Plugin\Pdf\Engine\Gotenberg\Client;
use Grav\Plugin\Pdf\Engine\Gotenberg\HtmlSanitizer;
use Grav\Plugin\Pdf\Engine\Gotenberg\Outline;
use Grav\Plugin\Pdf\Furniture;
use Grav\Plugin\Pdf\Generator;
use Grav\Plugin\PdfPlugin;

/**
 * Renders through an external Gotenberg service.
 *
 * Gotenberg converts HTML with headless Chromium, so the same document the
 * built-in engine sets is laid out by a browser engine instead: proper CJK and
 * emoji, real CSS tables, correct page breaks — at the cost of a round trip to
 * a service the site has to run itself. The plugin bundles nothing;
 * `gotenberg.url` points at a container the administrator started.
 *
 * Three things differ from the built-in path, all of them because Chromium is
 * not php-pdf:
 *
 *  - page geometry is passed to the service, not to a stylesheet, and the
 *    header and footer are separate documents that cannot see the page's CSS,
 *    its fonts or its files;
 *  - it cannot number the table of contents, because pages do not exist until
 *    after layout, so the contents links without numbers;
 *  - it makes a bookmark of every heading, so anything deeper than `toc_depth`
 *    is turned into a styled paragraph — the ids stay, the outline matches what
 *    the built-in engine produces. Chromium leaves the bookmarks panel closed;
 *    the plugin sets `/PageMode /UseOutlines` on the finished PDF so it opens
 *    the same way as one from the built-in engine.
 */
final class Gotenberg implements Engine
{
    /**
     * Paper names, which the two engines spell differently: the built-in engine
     * takes `A4` but also `letter`, Gotenberg wants `Letter` and knows nothing
     * else.
     */
    private const PAPER = [
        'a3' => 'A3',
        'a4' => 'A4',
        'a5' => 'A5',
        'a6' => 'A6',
        'letter' => 'Letter',
        'legal' => 'Legal',
        'tabloid' => 'Tabloid',
        'ledger' => 'Ledger',
    ];

    /**
     * Logo formats a running header can carry cheaply.
     *
     * A header is re-rendered on every page and it can only load a data URI.
     * Chromium re-emits the paths of a vector image in each page's content
     * stream: a 109 KB SVG cost 33.6 MB and 8.35 s on a 220-page book, against
     * 1.6 MB and 1.29 s for the same logo as a raster image. So a vector logo
     * is left on the cover, where it is drawn once.
     */
    private const RASTER = ['png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'bmp'];

    /** @var array<string, bool> warnings already logged, once per request */
    private static array $warned = [];

    public function __construct(private readonly Grav $grav, private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'gotenberg';
    }

    public function render(Document $document, string $file): void
    {
        $settings = $document->settings;
        $depth = min(6, max(1, (int) $settings['toc_depth']));
        $assets = new Assets();

        [$entries, $toc] = $this->prepare((array) $document->data['entries'], $depth);

        $html = $document->render('@pdf/gotenberg.html.twig', [
            'engine' => 'gotenberg',
            'entries' => $entries,
            'toc' => $toc,
            'font_css' => $this->fontCss($document, $assets),
            'language' => (string) $this->grav['language']->getLanguage(),
        ]);

        // The cover logo and the articles' images are paths on this server.
        // Chromium runs in a container and cannot see them, so they become
        // uploads and the markup points at the uploaded name.
        $html = $assets->images($html);

        $header = $this->furniture($document, true);
        $footer = $this->furniture($document, false);

        // Furniture is a separate Chromium document. Resolve its custom local
        // images through the same upload set; only its plugin-generated logo
        // may remain as a data URI.
        if ($header !== '') {
            $header = $assets->images($header, true);
        }

        if ($footer !== '') {
            $footer = $assets->images($footer, true);
        }

        $files = [];
        $written = [];

        try {
            $files['index.html'] = $this->write($file, 'index', $html, $written);

            if ($header !== '') {
                $files['header.html'] = $this->write($file, 'header', $header, $written);
            }

            if ($footer !== '') {
                $files['footer.html'] = $this->write($file, 'footer', $footer, $written);
            }

            $files += $assets->all();

            Client::fromOptions($this->config->gotenbergBlock())->render(
                $this->fields($document),
                $files,
                $file
            );

            $outline = Outline::apply($file);

            if ($outline !== null) {
                $this->grav['log']->warning(sprintf(
                    'PDF: the Gotenberg bookmarks panel could not be opened: %s',
                    $outline
                ));
            }
        } finally {
            foreach ($written as $path) {
                @unlink($path);
            }
        }
    }

    /**
     * Turn the entries into what Chromium should print, and describe the
     * contents while doing it.
     *
     * @param array<int, array<string, mixed>> $entries
     * @return array{0: array<int, array<string, mixed>>, 1: array<int, array{level: int, text: string, href: string}>}
     */
    private function prepare(array $entries, int $depth): array
    {
        $toc = [];
        $prepared = [];

        foreach (array_values($entries) as $index => $entry) {
            $anchor = (string) ($entry['anchor'] ?? 'pdf-entry-' . $index);

            [$content, $headings] = $this->headings(
                HtmlSanitizer::sanitize((string) ($entry['content'] ?? '')),
                $depth
            );

            $entry['content'] = $content;
            $entry['anchor'] = $anchor;

            $toc[] = ['level' => 0, 'text' => (string) ($entry['title'] ?? ''), 'href' => '#' . $anchor];

            foreach ($headings as $heading) {
                $toc[] = $heading;
            }

            $prepared[] = $entry;
        }

        return [$prepared, $toc];
    }

    /**
     * Give every linkable heading an id, and demote the ones Chromium would
     * bookmark but `toc_depth` says are not part of the structure.
     *
     * `nativeBookmarks` outlines every `<h1>`–`<h6>` it can find, where the
     * built-in engine obeys `toc_depth`. Demoting the deeper headings keeps the
     * two engines' outlines the same — and the id survives the demotion, so a
     * link into the text still lands where it should.
     *
     * @return array{0: string, 1: array<int, array{level: int, text: string, href: string}>}
     */
    private function headings(string $html, int $depth): array
    {
        $toc = [];
        $count = 0;

        $html = (string) preg_replace_callback(
            '#<h([1-6])\b([^>]*)>(.*?)</h\1\s*>#is',
            function (array $match) use ($depth, &$toc, &$count): string {
                $level = (int) $match[1];
                $attributes = $match[2];
                $inner = $match[3];
                $count++;

                if (!preg_match('#\bid\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $attributes, $id)) {
                    $attributes .= ' id="pdf-heading-' . $count . '"';
                    $id = [null, '', 'pdf-heading-' . $count];
                }

                if ($level > $depth) {
                    return '<div class="pdf-h' . $level . '"' . $attributes . '>' . $inner . '</div>';
                }

                // The contents lists the articles and, when `toc_depth` allows
                // it, their h2s — the same two levels the built-in engine
                // knows.
                if ($level === 2 && $depth >= 2) {
                    $toc[] = [
                        'level' => 1,
                        'text' => trim((string) html_entity_decode(
                            strip_tags($inner),
                            ENT_QUOTES | ENT_HTML5,
                            'UTF-8'
                        )),
                        'href' => '#' . ($id[2] !== '' ? $id[2] : ($id[3] ?? '')),
                    ];
                }

                return '<h' . $level . $attributes . '>' . $inner . '</h' . $level . '>';
            },
            $html
        );

        return [$html, $toc];
    }

    /**
     * The `@font-face` rules that give Chromium the same faces the built-in
     * engine would use.
     *
     * Chromium can only use a font it can load, and the service has none of the
     * plugin's: the files go with the request. `font-display: block` keeps the
     * fallback out of the first page while the file is being read.
     */
    private function fontCss(Document $document, Assets $assets): string
    {
        $families = array_values(array_unique(array_filter([
            (string) ($document->settings['font_body'] ?? ''),
            (string) ($document->settings['font_headings'] ?? ''),
        ], static fn (string $family): bool => $family !== '')));

        $css = '';

        foreach ($families as $family) {
            $files = (array) ($document->fonts[$family] ?? Generator::builtinFontFiles($family));

            foreach ($files as $style => $path) {
                $name = $assets->add((string) $path, 'font');

                if ($name === null) {
                    continue;
                }

                [$weight, $slant] = match (strtoupper((string) $style)) {
                    'B' => ['bold', 'normal'],
                    'I' => ['normal', 'italic'],
                    'BI' => ['bold', 'italic'],
                    default => ['normal', 'normal'],
                };

                $css .= sprintf(
                    "@font-face { font-family: \"%s\"; src: url(\"%s\") format(\"truetype\");"
                    . " font-weight: %s; font-style: %s; font-display: block; }\n",
                    $family,
                    $name,
                    $weight,
                    $slant
                );
            }
        }

        return $css;
    }

    /**
     * The running header or footer as a document of its own.
     *
     * Chromium renders the two in a page of their own, with no access to the
     * document's stylesheet, its fonts or its files — which is why the rules
     * are inline, the logo is a data URI and the type is whatever the service
     * has. Nothing else in the document is affected.
     */
    private function furniture(Document $document, bool $isHeader): string
    {
        $settings = $document->settings;

        $dialect = [
            'logo' => $this->logo($document),
            'page' => '<span class="pageNumber"></span>',
            'total' => '<span class="totalPages"></span>',
        ];

        $html = Furniture::expand(
            HtmlSanitizer::sanitize((string) ($isHeader ? $settings['header'] : $settings['footer'])),
            $settings,
            $document->title,
            $document->site,
            $document->today(),
            $dialect,
            $isHeader
        );

        if ($html === '') {
            return '';
        }

        // Validate the expanded token output too. The only data image allowed
        // here is the plugin-generated logo, which was not present in the
        // administrator-supplied HTML sanitized above.
        $html = HtmlSanitizer::sanitize($html, true);

        return $document->render('@pdf/gotenberg-furniture.html.twig', [
            'body' => $html,
            'is_header' => $isHeader,
        ]);
    }

    /**
     * The logo for a running header, or '' when it cannot be one.
     *
     * A data URI is the only image form a header can load, and only a raster
     * one is worth repeating on every page — see RASTER. A vector logo stays on
     * the cover, which is drawn once, and the log says why.
     */
    private function logo(Document $document): string
    {
        $file = $document->logoFile();

        if ($file === null) {
            return '';
        }

        $extension = strtolower((string) pathinfo($file, PATHINFO_EXTENSION));

        if (!in_array($extension, self::RASTER, true)) {
            if (!isset(self::$warned['logo'])) {
                self::$warned['logo'] = true;

                $this->grav['log']->warning(sprintf(
                    'PDF: the logo "%s" is a %s; Gotenberg cannot repeat a vector image in the running header '
                    . 'without inflating every page. The logo is printed on the cover only — use a PNG for the header.',
                    basename($file),
                    $extension !== '' ? strtoupper($extension) : 'vector image'
                ));
            }

            return '';
        }

        $raw = @file_get_contents($file);

        if ($raw === false) {
            return '';
        }

        $mime = $extension === 'jpg' ? 'jpeg' : $extension;

        return '<img class="pdf-logo" src="data:image/' . $mime . ';base64,' . base64_encode($raw) . '" alt="">';
    }

    /**
     * The form Gotenberg is asked to fill in.
     *
     * `paperSize` is always sent: the service's own default is Letter, and
     * Chromium's page setup is the one thing the stylesheet cannot correct.
     *
     * @return array<string, string>
     */
    private function fields(Document $document): array
    {
        $settings = $document->settings;

        [$paper, $landscape] = $this->paper($settings);

        $metadata = array_filter([
            'op' => 'set-metadata',
            'title' => $document->title,
            'author' => $document->site,
            'subject' => $document->title,
            'creator' => 'Grav PDF plugin ' . PdfPlugin::VERSION,
        ], static fn ($value): bool => $value !== '');

        return [
            'paperSize' => $paper,
            'landscape' => $landscape ? 'true' : 'false',
            'marginTop' => (int) $settings['margin_top'] . 'mm',
            'marginBottom' => (int) $settings['margin_bottom'] . 'mm',
            'marginLeft' => (int) $settings['margin_left'] . 'mm',
            'marginRight' => (int) $settings['margin_right'] . 'mm',
            // Backgrounds are part of the print stylesheet — table headers,
            // the rule above the footer — and Chromium leaves them out unless
            // asked.
            'printBackground' => 'true',
            // The stylesheet is written for paper, so the page is laid out for
            // it rather than for a screen.
            'emulatedMediaType' => 'print',
            'preferCssPageSize' => 'false',
            'nativeBookmarks' => 'true',
            'stages' => (string) json_encode([$metadata], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ];
    }

    /**
     * The paper's name and orientation, in Gotenberg's spelling.
     *
     * @return array{0: string, 1: bool} paper, landscape
     */
    private function paper(array $settings): array
    {
        $paper = strtolower(trim((string) $settings['paper']));
        $landscape = strtoupper((string) $settings['orientation']) === 'L';

        // A paper name may carry an orientation suffix ("A4-L"); the suffix is
        // the orientation here too.
        if (str_ends_with($paper, '-l')) {
            $paper = substr($paper, 0, -2);
            $landscape = true;
        }

        $known = self::PAPER[$paper] ?? null;

        if ($known === null) {
            if (!isset(self::$warned['paper'])) {
                self::$warned['paper'] = true;

                $this->grav['log']->warning(sprintf(
                    'PDF: the paper size "%s" is not one Gotenberg knows; using A4.',
                    (string) $settings['paper']
                ));
            }

            $known = 'A4';
        }

        return [$known, $landscape];
    }

    /**
     * Write a part of the request next to the document being built.
     *
     * It lives in the cache directory, where the caller has already made sure
     * it can write, and it is removed once the request is over.
     *
     * @param string[] $written collected so the caller can clean up
     */
    private function write(string $file, string $kind, string $contents, array &$written): string
    {
        $path = dirname($file) . '/.' . $kind . '-' . bin2hex(random_bytes(8)) . '.html';

        if (@file_put_contents($path, $contents) === false) {
            throw new \RuntimeException(sprintf(
                'PDF: cannot write the Gotenberg "%s" part to "%s". Make the PDF cache directory writable.',
                $kind,
                $path
            ));
        }

        $written[] = $path;

        return $path;
    }
}
