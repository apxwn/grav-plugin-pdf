<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Engine;

use Dskripchenko\PhpPdf\Document as AstDocument;
use Dskripchenko\PhpPdf\Element\BlockElement;
use Dskripchenko\PhpPdf\Element\Bookmark;
use Dskripchenko\PhpPdf\Element\Cell;
use Dskripchenko\PhpPdf\Element\Heading;
use Dskripchenko\PhpPdf\Element\ListItem;
use Dskripchenko\PhpPdf\Element\ListNode;
use Dskripchenko\PhpPdf\Element\Paragraph;
use Dskripchenko\PhpPdf\Element\Row;
use Dskripchenko\PhpPdf\Element\Table;
use Dskripchenko\PhpPdf\Html\HtmlParser;
use Dskripchenko\PhpPdf\Section;
use Dskripchenko\PhpPdf\Style\Orientation;
use Dskripchenko\PhpPdf\Style\PageMargins;
use Dskripchenko\PhpPdf\Style\PageSetup;
use Dskripchenko\PhpPdf\Style\PaperSize;
use Grav\Common\Grav;
use Grav\Plugin\Pdf\Config;
use Grav\Plugin\Pdf\Engine\PhpPdf\FontResolver;
use Grav\Plugin\Pdf\Engine\PhpPdf\HtmlElementHandler;
use Grav\Plugin\Pdf\Furniture;
use Grav\Plugin\PdfPlugin;

/**
 * The bundled renderer powered by dskripchenko/php-pdf.
 */
final class PhpPdf implements Engine
{
    private const PT_PER_MM = 72.0 / 25.4;

    private const PAPER = [
        'a3' => PaperSize::A3,
        'a4' => PaperSize::A4,
        'a5' => PaperSize::A5,
        'a6' => PaperSize::A6,
        'letter' => PaperSize::Letter,
        'legal' => PaperSize::Legal,
        'tabloid' => PaperSize::Tabloid,
        'executive' => PaperSize::Executive,
    ];

    /** @var array<string, bool> warnings already logged, once per request */
    private static array $warned = [];

    public function __construct(private readonly Grav $grav, private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'php-pdf';
    }

    public function render(Document $document, string $file): void
    {
        $settings = $document->settings;
        $depth = min(6, max(1, (int) $settings['toc_depth']));
        $baseFontSizePt = (float) ($settings['font_size'] ?? 11);
        $language = (string) $this->grav['language']->getLanguage();

        [$entries, $toc] = $this->prepare((array) $document->data['entries'], $depth);

        $html = $document->render('@pdf/php-pdf.html.twig', [
            'engine' => 'php-pdf',
            'entries' => $entries,
            'toc' => $toc,
            'language' => $language,
        ]);

        $html = $this->relocateListAnchors($html);

        $handler = new HtmlElementHandler();
        $parser = new HtmlParser(
            baseFontSizePt: $baseFontSizePt,
            elementHandlers: [$handler],
            localFiles: true,
        );

        $blocks = $this->promoteHeadings($parser->parse($html), $depth);

        $styleCss = $document->render('@pdf/php-pdf-style.html.twig');
        $headerBlocks = $this->furnitureBlocks($document, $styleCss, $baseFontSizePt, $handler, true);
        $footerBlocks = $this->furnitureBlocks($document, $styleCss, $baseFontSizePt, $handler, false);

        $cover = (bool) $settings['cover'];

        $section = new Section(
            body: $blocks,
            pageSetup: $this->pageSetup($settings),
            headerBlocks: $headerBlocks,
            footerBlocks: $footerBlocks,
            firstPageHeaderBlocks: $cover ? [] : null,
            firstPageFooterBlocks: $cover ? [] : null,
        );

        $astDoc = new AstDocument(
            section: $section,
            metadata: array_filter([
                'Title' => $document->title,
                'Subject' => $document->title,
                'Author' => $document->site,
                'Creator' => 'Grav PDF plugin ' . PdfPlugin::VERSION,
            ], static fn (string $value): bool => $value !== ''),
            lang: $language !== '' ? $language : null,
        );

        $resolver = new FontResolver($document->fonts);
        $layoutEngine = $resolver->engine((string) $settings['font_body'], $baseFontSizePt);

        $astDoc->toFile($file, $layoutEngine);
    }

    /**
     * Turn the entries into what php-pdf should print, and collect the linked TOC.
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

            [$content, $headings] = $this->headings((string) ($entry['content'] ?? ''), $depth);

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
     * Give every linkable heading an id, collect TOC entries up to `$depth`,
     * and demote deeper headings to styled `<div>`s so they do not pollute
     * the PDF outline.
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
     * Move `<a name="…"></a>` placed directly before `<li …>` into the list
     * item (and into a leading `<p>`/`<div>` when present) so php-pdf's list
     * parser does not drop it between `<li>` elements.
     */
    private function relocateListAnchors(string $html): string
    {
        return (string) preg_replace_callback(
            '#(<a\b[^>]*\bname\s*=\s*"[^"]+"[^>]*></a>)\s*(<li\b[^>]*>)(?:\s*(<(?:p|div)\b[^>]*>))?#i',
            static function (array $match): string {
                return $match[2] . ($match[3] ?? '') . $match[1];
            },
            $html
        );
    }

    /**
     * Convert `Heading` elements within `$depth` into `Paragraph` elements
     * carrying `headingLevel` so php-pdf registers PDF outline entries for them.
     *
     * @param list<BlockElement> $blocks
     * @return list<BlockElement>
     */
    private function promoteHeadings(array $blocks, int $depth): array
    {
        $out = [];

        foreach ($blocks as $block) {
            if ($block instanceof Heading && $block->level <= $depth) {
                $children = $block->children;

                if ($block->anchor !== null && $block->anchor !== '') {
                    array_unshift($children, new Bookmark($block->anchor));
                }

                $out[] = new Paragraph(
                    children: $children,
                    style: $block->style,
                    headingLevel: $block->level,
                );

                continue;
            }

            if ($block instanceof ListNode) {
                $out[] = $this->promoteListHeadings($block, $depth);
                continue;
            }

            if ($block instanceof Table) {
                $out[] = $this->promoteTableHeadings($block, $depth);
                continue;
            }

            $out[] = $block;
        }

        return $out;
    }

    private function promoteListHeadings(ListNode $list, int $depth): ListNode
    {
        $items = [];

        foreach ($list->items as $item) {
            $items[] = new ListItem(
                children: $this->promoteHeadings($item->children, $depth),
                nestedList: $item->nestedList !== null ? $this->promoteListHeadings($item->nestedList, $depth) : null,
                marker: $item->marker,
                value: $item->value,
                indentPt: $item->indentPt,
            );
        }

        return new ListNode(
            items: $items,
            format: $list->format,
            startAt: $list->startAt,
            spaceBeforePt: $list->spaceBeforePt,
            spaceAfterPt: $list->spaceAfterPt,
            indentPt: $list->indentPt,
        );
    }

    private function promoteTableHeadings(Table $table, int $depth): Table
    {
        $rows = [];

        foreach ($table->rows as $row) {
            $cells = [];

            foreach ($row->cells as $cell) {
                $cells[] = new Cell(
                    children: $this->promoteHeadings($cell->children, $depth),
                    style: $cell->style,
                    columnSpan: $cell->columnSpan,
                    rowSpan: $cell->rowSpan,
                );
            }

            $rows[] = new Row(
                cells: $cells,
                isHeader: $row->isHeader,
                heightPt: $row->heightPt,
                minHeightPt: $row->minHeightPt,
                cantSplit: $row->cantSplit,
            );
        }

        return new Table(
            rows: $rows,
            style: $table->style,
            columnWidthsPt: $table->columnWidthsPt,
            caption: $table->caption,
        );
    }

    /**
     * @return list<BlockElement>
     */
    private function furnitureBlocks(
        Document $document,
        string $styleCss,
        float $baseFontSizePt,
        HtmlElementHandler $handler,
        bool $isHeader
    ): array {
        $settings = $document->settings;

        $dialect = [
            'logo' => $this->logo($document),
            'page' => '<span class="pageNumber"></span>',
            'total' => '<span class="totalPages"></span>',
        ];

        $html = Furniture::expand(
            (string) ($isHeader ? $settings['header'] : $settings['footer']),
            $settings,
            $document->title,
            $document->site,
            $document->today(),
            $dialect,
            $isHeader
        );

        if ($html === '') {
            return [];
        }

        $parser = new HtmlParser(
            baseFontSizePt: $baseFontSizePt,
            elementHandlers: [$handler],
            localFiles: true,
        );

        return $parser->parse('<style>' . $styleCss . '</style>' . $html);
    }

    private function logo(Document $document): string
    {
        $file = $document->logoFile();

        return $file === null
            ? ''
            : '<img src="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '" alt="" class="pdf-logo" />';
    }

    /**
     * @param array<string, mixed> $settings
     */
    private function pageSetup(array $settings): PageSetup
    {
        $paper = strtolower(trim((string) ($settings['paper'] ?? 'A4')));
        $orientation = strtoupper((string) ($settings['orientation'] ?? 'P')) === 'L'
            ? Orientation::Landscape
            : Orientation::Portrait;

        if (str_ends_with($paper, '-l')) {
            $paper = substr($paper, 0, -2);
            $orientation = Orientation::Landscape;
        }

        $paperSize = self::PAPER[$paper] ?? null;

        if ($paperSize === null) {
            if (!isset(self::$warned['paper:' . $paper])) {
                self::$warned['paper:' . $paper] = true;

                $this->grav['log']->warning(sprintf(
                    'PDF: the paper size "%s" is not one php-pdf knows; using A4.',
                    (string) $settings['paper']
                ));
            }

            $paperSize = PaperSize::A4;
        }

        return new PageSetup(
            paperSize: $paperSize,
            orientation: $orientation,
            margins: PageMargins::fromMm(
                topMm: (float) $settings['margin_top'],
                rightMm: (float) $settings['margin_right'],
                bottomMm: (float) $settings['margin_bottom'],
                leftMm: (float) $settings['margin_left'],
            ),
            headerDistancePt: (float) $settings['margin_header'] * self::PT_PER_MM,
            footerDistancePt: (float) $settings['margin_footer'] * self::PT_PER_MM,
        );
    }
}
