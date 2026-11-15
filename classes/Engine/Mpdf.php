<?php

namespace Grav\Plugin\Pdf\Engine;

use Grav\Common\Grav;
use Grav\Plugin\Pdf\Config;
use Grav\Plugin\Pdf\Furniture;
use Grav\Plugin\Pdf\Paths;
use Grav\Plugin\PdfPlugin;
use Mpdf\HTMLParserMode;
use Mpdf\Output\Destination;

/**
 * The bundled renderer.
 *
 * mPDF is driven through CSS page boxes rather than SetHTMLHeader(), because
 * the imperative API silently loses the running header on the page that
 * follows the table of contents. `@page { … }` plus `@page :first { … }` gives
 * a clean cover and a reliable header on every other page.
 */
final class Mpdf implements Engine
{
    public function __construct(private readonly Grav $grav, private readonly Config $config)
    {
    }

    public function name(): string
    {
        return 'mpdf';
    }

    public function render(Document $document, string $file): void
    {
        $settings = $document->settings;
        $tmp = Paths::tmpDir($this->grav, $this->config);

        if (!Paths::ensure($tmp)) {
            throw new \RuntimeException(sprintf(
                'Cannot create the PDF working directory "%s". Make the Grav cache directory writable, or set '
                . '"cache_path" in the plugin settings.',
                $tmp
            ));
        }

        // mPDF assembles its list of usable families in the constructor, so a
        // site's own fonts have to arrive with the configuration rather than be
        // added to the instance afterwards — otherwise `available_unifonts`
        // would not contain them and a custom `default_font` would be
        // substituted silently.
        $config = [
            'tempDir' => $tmp,
            'mode' => 'utf-8',
            'format' => (string) $settings['paper']
                . (strtoupper((string) $settings['orientation']) === 'L' ? '-L' : ''),
            'default_font' => (string) $settings['font_body'],
            'default_font_size' => (int) $settings['font_size'],
            'margin_top' => (int) $settings['margin_top'],
            'margin_bottom' => (int) $settings['margin_bottom'],
            'margin_left' => (int) $settings['margin_left'],
            'margin_right' => (int) $settings['margin_right'],
            'margin_header' => (int) $settings['margin_header'],
            'margin_footer' => (int) $settings['margin_footer'],
            // A header that outgrows `margin_top - margin_header` would be
            // printed over the body. 'stretch' grows the top margin to the
            // header's own measured height, and leaves the configured margin
            // exactly as it is whenever the header fits inside it. The height is
            // measured when the header is defined, which is why the stylesheet
            // is loaded before that happens — see below.
            'setAutoTopMargin' => 'stretch',
            // mPDF marks text up by script, which is what keeps a mixed
            // document together, but the face itself is the one configured
            // here: autoLangToFont is off, because it would otherwise apply
            // mPDF's own language table on top of the stylesheet and set
            // Cyrillic and Greek runs in DejaVu Sans Condensed — whichever
            // face was chosen. All four bundled faces cover Latin, Greek and
            // Cyrillic.
            'autoScriptToLang' => true,
            'autoLangToFont' => false,
        ];

        if ($document->fonts !== []) {
            $fontVariables = new \Mpdf\Config\FontVariables();
            $configVariables = new \Mpdf\Config\ConfigVariables();
            $config['fontdata'] = (array) ($fontVariables->getDefaults()['fontdata'] ?? [])
                + $this->fontData($document->fonts);
            $config['fontDir'] = array_values(array_unique(array_merge(
                (array) ($configVariables->getDefaults()['fontDir'] ?? []),
                $this->fontDirs($document->fonts)
            )));
        }

        $mpdf = new \Mpdf\Mpdf($config);

        $title = $document->title;
        $site = $document->site;

        $mpdf->SetTitle($title);
        $mpdf->SetSubject($title);
        $mpdf->SetAuthor($site);
        $mpdf->SetCreator('Grav PDF plugin ' . PdfPlugin::VERSION);

        $depth = max(1, (int) $settings['toc_depth']);
        $tocMap = ['H1' => 0];
        $bookmarkMap = ['H1' => 0];

        if ($depth >= 2) {
            $tocMap['H2'] = 1;
            $bookmarkMap['H2'] = 1;
        }

        if ($settings['toc']) {
            $mpdf->h2toc = $tocMap;
        }
        $mpdf->h2bookmarks = $bookmarkMap;

        // The stylesheet has to be in the document before the header is defined:
        // mPDF measures an html_* header the moment DefHTMLHeaderByName() sees
        // it, and setAutoTopMargin uses that measurement. Loaded afterwards, a
        // logo in the header would be measured at its natural size and every
        // page would reserve a top margin tall enough to hold it. HEADER_CSS
        // parses the rules without writing content or starting a page.
        $mpdf->WriteHTML($document->render('@pdf/print-style.html.twig'), HTMLParserMode::HEADER_CSS);

        $dialect = [
            'logo' => $this->logo($document),
            'page' => '{PAGENO}',
            'total' => '{nbpg}',
        ];

        $header = Furniture::expand(
            (string) $settings['header'],
            $settings,
            $title,
            $site,
            $document->today(),
            $dialect,
            true
        );
        $footer = Furniture::expand(
            (string) $settings['footer'],
            $settings,
            $title,
            $site,
            $document->today(),
            $dialect,
            false
        );

        $style = $this->pageCss($mpdf, $header, $footer, (bool) $settings['cover']);

        if ($settings['cover']) {
            $mpdf->WriteHTML($style . $document->render('@pdf/cover.html.twig'));
            $mpdf->AddPage();

            if ($settings['toc'] && $document->data['entries']) {
                $mpdf->TOCpagebreakByArray([
                    'toc_preHTML' => $document->render('@pdf/toc-heading.html.twig'),
                    'toc_bookmarkText' => (string) $this->grav['language']->translate('PLUGIN_PDF.TOC'),
                    'links' => true,
                ]);
            }

            $mpdf->WriteHTML($document->render('@pdf/book.html.twig', ['engine' => 'mpdf']));
        } else {
            $mpdf->WriteHTML($style . $document->render('@pdf/book.html.twig', ['engine' => 'mpdf']));
        }

        $mpdf->Output($file, Destination::FILE);
    }

    /**
     * The logo as an inline image pointing at a local file.
     */
    private function logo(Document $document): string
    {
        $file = $document->logoFile();

        return $file === null
            ? ''
            : '<img src="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '" alt="" class="pdf-logo" />';
    }

    /**
     * The `fontdata` entries for the site's own families.
     *
     * mPDF needs the file name per style; `useOTL` and `useKashida` are the
     * same defaults it gives its own faces, and are what make accented and
     * Arabic-script text render from a font that supports them. The directories
     * the files live in are added to `fontDir` by fontDirs().
     *
     * @param array<string, array<string, string>> $fonts family => style => absolute path
     * @return array<string, array<string, mixed>>
     */
    private function fontData(array $fonts): array
    {
        $data = [];

        foreach ($fonts as $family => $styles) {
            $data[$family] = [];

            foreach ($styles as $style => $path) {
                $data[$family][$style] = basename($path);
            }

            $data[$family] += ['useOTL' => 255, 'useKashida' => 75];
        }

        return $data;
    }

    /**
     * Every directory a configured font file lives in.
     *
     * mPDF concatenates each entry of `fontDir` with the file name and takes
     * the first that exists, so listing the files' own directories is enough
     * for a font anywhere on disk.
     *
     * @param array<string, array<string, string>> $fonts family => style => absolute path
     * @return string[]
     */
    private function fontDirs(array $fonts): array
    {
        $dirs = [];

        foreach ($fonts as $styles) {
            foreach ($styles as $path) {
                $dirs[dirname($path)] = true;
            }
        }

        return array_keys($dirs);
    }

    /**
     * Register the running header/footer as named page boxes and return the CSS
     * that binds them. `:first` exists only for a book, so a single article
     * keeps its running header and numbering on page one.
     */
    private function pageCss(\Mpdf\Mpdf $mpdf, string $header, string $footer, bool $cover): string
    {
        if ($header === '' && $footer === '') {
            return '';
        }

        $mpdf->DefHTMLHeaderByName('pdfmain', $header !== '' ? $header : '<div></div>');
        $mpdf->DefHTMLFooterByName('pdfmain', $footer !== '' ? $footer : '<div></div>');

        $css = '<style>@page { header: html_pdfmain; footer: html_pdfmain; }';

        if ($cover) {
            $mpdf->DefHTMLHeaderByName('pdffirst', '<div></div>');
            $mpdf->DefHTMLFooterByName('pdffirst', '<div></div>');
            $css .= ' @page :first { header: html_pdffirst; footer: html_pdffirst; }';
        }

        return $css . '</style>';
    }
}
