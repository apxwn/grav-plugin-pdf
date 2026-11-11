<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\PdfPlugin;
use Mpdf\HTMLParserMode;
use Mpdf\Output\Destination;

/**
 * Builds and serves a document.
 *
 * mPDF is driven through CSS page boxes rather than SetHTMLHeader(), because the
 * imperative API silently loses the running header on the page that follows the
 * table of contents. `@page { … }` plus `@page :first { … }` gives a clean
 * cover and a reliable header on every other page.
 *
 * Nothing here knows anything about a theme or a particular site: the fonts are
 * mPDF's own, the logo is whatever `logo` names, and every frontmatter key the
 * document reads is a setting with a generic default.
 */
class Generator
{
    /**
     * The typefaces mPDF ships, each with its bold, italic and bold-italic
     * companion. Latin, Greek and Cyrillic are covered by all four, and none of
     * them has to be downloaded or installed. A site can add families of its own
     * with `fonts` and `font_dir`.
     */
    private const FONTS = ['dejavusans', 'dejavusanscondensed', 'dejavuserif', 'dejavuserifcondensed'];

    /** The parts a dateline can be built from, in the default order. */
    private const META_DEFAULTS = ['date', 'category', 'author'];

    /** Warnings already logged, so a book does not repeat one per entry. */
    private static array $fontWarnings = [];

    private Grav $grav;
    private Config $config;

    public function __construct(Grav $grav, Config $config)
    {
        $this->grav = $grav;
        $this->config = $config;
    }

    /**
     * Is this page allowed to be exported?
     *
     * The plugin ships enabled but inert: `plugins.pdf.enabled` is the switch
     * that loads the plugin at all, and a page still has to opt in with
     * `pdf: { enabled: true }` in its own frontmatter — unless `all_pages` is on.
     */
    public function isEnabled(PageInterface $page): bool
    {
        if (!$this->config->global('enabled', true)) {
            return false;
        }

        if (!$page->exists() || !$page->published() || !$page->routable()) {
            return false;
        }

        if (!$this->config->optIn($page)) {
            return false;
        }

        return Items::inLanguage($page);
    }

    /**
     * The PDF URL for a page, or null when it is not exportable here.
     */
    public function urlFor($page = null): ?string
    {
        $page = $page instanceof PageInterface ? $page : ($this->grav['page'] ?? null);

        if (!$page instanceof PageInterface || !$this->isEnabled($page)) {
            return null;
        }

        return $page->url() . '.pdf';
    }

    /**
     * Build (or reuse) the document for a request and stream it. Never returns.
     */
    public function respond(PageInterface $page): void
    {
        $items = Items::resolve($page, $this->config);
        $mode = Items::mode($page, $this->config, $items);

        if ($mode === 'book') {
            $max = (int) $this->config->forPage($page, 'max_items', 500);

            if ($max > 0 && count($items) > $max) {
                $this->plain(413, sprintf(
                    "This collection holds %d pages, more than the configured maximum of %d.\n"
                    . "Raise plugins.pdf.max_items (or set it to 0 for no limit) to export it.\n",
                    count($items),
                    $max
                ));

                return;
            }
        }

        $store = new Store($this->grav, $this->config);
        $key = $this->fingerprint($page, $items, $mode);
        $file = $store->path($key);

        if (!$store->isFresh($file)) {
            $store->prepare($file);
            $this->build($page, $items, $mode, $file);
            $store->prune($file);

            $this->grav['log']->info(sprintf('PDF: generated %s (%s, %d items)', $key, $mode, count($items)));
        }

        $this->send($page, $file, $key);
    }

    /**
     * A fingerprint of everything that feeds the document.
     *
     * Any edit to any source page changes it, so the cached file name changes
     * with it and a stale document can never be served.
     */
    private function fingerprint(PageInterface $page, array $items, string $mode): string
    {
        $settings = $this->settings($page, $mode, $items);

        $parts = [
            'v' . PdfPlugin::VERSION,
            $mode,
            (string) $this->grav['language']->getLanguage(),
            (string) $page->route(),
            json_encode($settings),
        ];

        // The logo is a file on disk whose contents can change without any
        // setting changing, so fingerprint what actually resolved.
        $logo = $this->logoFile($settings);
        $parts[] = $logo === null ? 'no-logo' : $logo . ':' . (string) filemtime($logo);

        foreach ($items as $item) {
            $parts[] = $item->route() . ':' . $item->modified();
        }

        return md5(implode("\n", $parts));
    }

    /**
     * The style words a font file name may end in, and the mPDF style key each
     * one maps to.
     */
    private const FONT_STYLES = [
        'regular' => 'R',
        'book' => 'R',
        'normal' => 'R',
        'roman' => 'R',
        'bold' => 'B',
        'italic' => 'I',
        'oblique' => 'I',
        'bolditalic' => 'BI',
        'boldoblique' => 'BI',
        'semibold' => 'B',
    ];

    /**
     * Families named explicitly in the `fonts` setting.
     *
     * Only needed when a site's file names do not follow the
     * `<Family>-<Style>.ttf` convention, or when a font lives outside
     * `font_dir`. Values are file names relative to `font_dir`, or paths — see
     * fontFiles(), which resolves those before mPDF sees them.
     *
     * @return array<string, array<string, string>>
     */
    private function declaredFonts(array $settings): array
    {
        $fonts = [];

        foreach ((array) ($settings['fonts'] ?? []) as $family => $styles) {
            $family = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $family));

            if ($family === '' || !is_array($styles)) {
                continue;
            }

            foreach ($styles as $style => $file) {
                $style = strtoupper(trim((string) $style));
                $file = trim((string) $file);

                // `regular` and friends are accepted for readability.
                if (isset(self::FONT_STYLES[strtolower($style)])) {
                    $style = self::FONT_STYLES[strtolower($style)];
                }

                // A plain list (`fonts: { myfont: [Regular.ttf, Bold.ttf] }`)
                // has no style keys; fall back to what the file name says.
                if (!in_array($style, ['R', 'B', 'I', 'BI'], true)) {
                    [$impliedFamily, $impliedStyle] = self::fontStyleFromName($file);

                    if ($impliedFamily !== '' && $impliedStyle !== null) {
                        $style = $impliedStyle;
                    } else {
                        continue;
                    }
                }

                if ($file !== '') {
                    $fonts[$family][$style] = $file;
                }
            }
        }

        return $fonts;
    }

    /**
     * The `fontDir` and `fontdata` values mPDF needs.
     *
     * mPDF resolves a `fontdata` value as `<fontDir entry>/<value>`, so a bare
     * file name means "in a `font_dir`" and needs no help. The `fonts` setting
     * may also point at a file directly — an absolute path, a stream, or a path
     * relative to the Grav root — which mPDF cannot find on its own. Those are
     * resolved here and expressed relative to the first font directory, with
     * `..` where the file lies outside it, so the name still resolves to that
     * exact file. When no font directory is configured, the file's own folder
     * is added.
     *
     * @return array{0: string[], 1: array<string, array<string, string>>} directories, fonts
     */
    private function fontFiles(array $settings): array
    {
        $dirs = $this->fontDirs($settings);
        $fonts = (array) ($settings['fonts'] ?? []);

        // The directory names are compared as mPDF concatenates them, so they
        // are made absolute once, here.
        if ($dirs !== []) {
            $dirs[0] = self::realPath($dirs[0]);
        }

        foreach ($fonts as $family => $styles) {
            foreach ($styles as $style => $file) {
                $file = (string) $file;

                // A bare name is already relative to `font_dir`.
                if (strpbrk($file, '/\\') === false && !preg_match('#^[A-Za-z]:#', $file)) {
                    continue;
                }

                $path = preg_match('#^[A-Za-z]:[\\\\/]#', $file)
                    ? $file
                    : Paths::resolve($this->grav, $file);

                if ($path === null || !is_file($path)) {
                    throw new \RuntimeException(sprintf(
                        'PDF: the font file "%s" for "%s" was not found. Put it in a "font_dir" folder, '
                        . 'or correct the path in the "fonts" setting.',
                        $file,
                        $family
                    ));
                }

                $path = self::realPath($path);

                if ($dirs === []) {
                    $dirs[] = dirname($path);
                }

                $fonts[$family][$style] = self::relativePath($dirs[0], $path);
            }
        }

        return [array_values(array_unique($dirs)), $fonts];
    }

    /**
     * An absolute path with the last `..` and symbolic link removed, for paths
     * that exist; the input is returned unchanged otherwise.
     */
    private static function realPath(string $path): string
    {
        $real = realpath($path);

        return $real === false ? $path : $real;
    }

    /**
     * How to reach $to from inside $from. Both are absolute.
     *
     * A plain `..` walk, because that is all mPDF needs: it concatenates the
     * font directory and the name and hands the result to `file_exists()`.
     */
    private static function relativePath(string $from, string $to): string
    {
        $from = array_values(array_filter(explode('/', str_replace('\\', '/', $from)), 'strlen'));
        $to = array_values(array_filter(explode('/', str_replace('\\', '/', $to)), 'strlen'));

        while ($from !== [] && $to !== [] && $from[0] === $to[0]) {
            array_shift($from);
            array_shift($to);
        }

        return str_repeat('../', count($from)) . implode('/', $to);
    }

    /**
     * One of the known faces — or a family found in `font_dir` — else the
     * default.
     *
     * mPDF substitutes a family it does not know, silently, following the first
     * entry of its own sans/serif list. Rejecting an unknown name here is what
     * makes a typo visible instead of printing the document in some other face.
     */
    private function font($value, array $custom = []): string
    {
        $value = strtolower(trim((string) $value));

        if (in_array($value, array_merge(self::FONTS, $custom), true)) {
            return $value;
        }

        // A typo would otherwise print the document in some other face with no
        // hint as to why, so it is said out loud. Once per request is enough for
        // a book that renders many entries.
        if ($value !== '' && !isset(self::$fontWarnings[$value])) {
            self::$fontWarnings[$value] = true;

            $this->grav['log']->warning(sprintf(
                'PDF: unknown font "%s" in the plugin settings; using %s instead.',
                $value,
                self::FONTS[0]
            ));
        }

        return self::FONTS[0];
    }

    /**
     * Every directory `font_dir` points at that actually exists.
     *
     * `font_dir` accepts one path or a list of them, and each may be a stream
     * (`user-data://pdf/fonts`), an absolute path, or a Grav-root-relative one.
     *
     * @return string[]
     */
    private function fontDirs(array $settings): array
    {
        $dirs = [];

        foreach ((array) ($settings['font_dir'] ?? []) as $configured) {
            if (!is_string($configured) || trim($configured) === '') {
                continue;
            }

            $dir = Paths::resolve($this->grav, $configured);

            if ($dir !== null && is_dir($dir)) {
                $dirs[] = rtrim($dir, '/');
            }
        }

        return array_values(array_unique($dirs));
    }

    /**
     * The family and style a font file name implies.
     *
     * mPDF needs a family name and a file name per style; the file names are the
     * only thing a site has to supply, so the convention is the mapping:
     *
     *     MyFont-Regular.ttf   MyFont-Bold.ttf
     *     MyFont-Italic.ttf    MyFont-BoldItalic.ttf
     *
     * The part before the style word is the family, lower-cased and stripped of
     * anything mPDF would choke on; the word after it is the style. A file with
     * no recognised style word is the regular face of a family named after the
     * whole file, which is reported with a null style so the caller can decide.
     *
     * @return array{0: string, 1: string|null} family, style
     */
    private static function fontStyleFromName(string $file): array
    {
        $base = (string) pathinfo(trim($file), PATHINFO_FILENAME);
        $family = $base;
        $style = null;

        // Every separator in the name is a possible split, tried left to right
        // so that the longest style word wins: "MyFont-Bold-Italic" is the
        // bold-italic face of MyFont, not the italic face of "MyFont-Bold".
        // Separators may be a dash, an underscore or a space, and a style may
        // itself be two words ("Bold Italic").
        if (preg_match_all('/[-_\s]+/', $base, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as $match) {
                $offset = (int) $match[1];
                $candidate = strtolower((string) preg_replace('/[-_\s]+/', '', substr($base, $offset)));

                if ($offset > 0 && isset(self::FONT_STYLES[$candidate])) {
                    $family = substr($base, 0, $offset);
                    $style = self::FONT_STYLES[$candidate];
                    break;
                }
            }
        }

        return [strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', $family)), $style];
    }

    /**
     * Read the font directories and describe what is in them.
     *
     * Anything that is not a `.ttf` is ignored, since mPDF cannot use it. The
     * first file to name a given family and style wins, so directories are
     * searched in the order they are configured and a site can shadow a family
     * by putting its own copy in a directory listed earlier.
     *
     * @return array<string, array<string, string>> family => style => filename
     */
    private function scanFonts(array $settings): array
    {
        $fonts = [];

        foreach ($this->fontDirs($settings) as $dir) {
            foreach ((array) glob($dir . '/*.[tT][tT][fF]') as $path) {
                if (!is_string($path) || !is_file($path)) {
                    continue;
                }

                [$family, $style] = self::fontStyleFromName((string) pathinfo($path, PATHINFO_FILENAME));

                if ($family === '') {
                    continue;
                }

                $fonts[$family][$style ?? 'R'] ??= basename($path);
            }
        }

        return $fonts;
    }

    /**
     * Every setting that can change the rendered document.
     *
     * @return array<string, mixed>
     */
    private function settings(PageInterface $page, string $mode, array $items): array
    {
        $defaults = [
            'cover' => true,
            'toc' => true,
            'toc_depth' => 1,
            'page_numbers' => true,
            'date_format' => 'Y-m-d',
            'title' => '',
            'header' => '',
            'footer' => '',
            'logo' => '',
            'images' => false,
            'images_max' => 20,
            'images_width' => 1200,
            'images_quality' => 75,
            'image_field' => '',
            'image_skip_field' => '',
            'gallery_field' => 'gallery',
            'meta' => self::META_DEFAULTS,
            'meta_category_taxonomy' => 'category',
            'meta_author_field' => 'author',
            'strip_shortcodes' => [],
            'paper' => 'A4',
            'orientation' => 'P',
            'font_size' => 11,
            'font_body' => 'dejavusans',
            'font_headings' => 'dejavusans',
            'font_dir' => [],
            'fonts' => [],
            'margin_top' => 25,
            'margin_bottom' => 20,
            'margin_left' => 18,
            'margin_right' => 18,
            'margin_header' => 12,
            'margin_footer' => 8,
        ];

        $settings = ['mode' => $mode, 'count' => count($items)];

        foreach ($defaults as $key => $default) {
            $settings[$key] = $this->config->forPage($page, $key, $default);
        }

        $settings['images'] = (bool) $settings['images'];
        $settings['page_numbers'] = (bool) $settings['page_numbers'];
        $settings['toc'] = (bool) $settings['toc'] && $mode === 'book';
        $settings['cover'] = (bool) $settings['cover'] && $mode === 'book';
        $settings['strip_shortcodes'] = array_values(array_filter(
            array_map(static fn ($item) => (string) $item, (array) $settings['strip_shortcodes']),
            static fn (string $item): bool => $item !== ''
        ));
        $settings['meta'] = self::metaParts($settings['meta']);
        $settings['font_dir'] = is_string($settings['font_dir'])
            ? [$settings['font_dir']]
            : (array) $settings['font_dir'];

        // Files found in `font_dir` first, then anything named explicitly in
        // `fonts` — an explicit entry wins over what the file names imply.
        $settings['fonts'] = array_replace_recursive(
            $this->scanFonts($settings),
            $this->declaredFonts($settings)
        );

        $custom = (array) array_keys($settings['fonts']);
        $settings['font_body'] = $this->font($settings['font_body'], $custom);
        $settings['font_headings'] = $this->font($settings['font_headings'], $custom);

        return $settings;
    }

    /**
     * Render with mPDF.
     */
    private function build(PageInterface $page, array $items, string $mode, string $file): void
    {
        $settings = $this->settings($page, $mode, $items);

        $tmp = Paths::tmpDir($this->grav, $this->config);

        if (!Paths::ensure($tmp)) {
            throw new \RuntimeException(sprintf(
                'Cannot create the PDF working directory "%s". Make the Grav cache directory writable, or set '
                . '"cache_path" in the plugin settings.',
                $tmp
            ));
        }

        [$fontDirs, $fonts] = $this->fontFiles($settings);

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
            // is loaded before that happens — see build().
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

        if ($fonts !== []) {
            $fontVariables = new \Mpdf\Config\FontVariables();
            $configVariables = new \Mpdf\Config\ConfigVariables();
            $config['fontdata'] = (array) ($fontVariables->getDefaults()['fontdata'] ?? [])
                + $this->fontData($fonts);
            $config['fontDir'] = array_values(array_unique(array_merge(
                (array) ($configVariables->getDefaults()['fontDir'] ?? []),
                $fontDirs
            )));
        }

        $mpdf = new \Mpdf\Mpdf($config);

        $title = $this->title($page, $settings);
        $site = (string) $this->grav['config']->get('site.title', '');
        $data = $this->data($page, $items, $mode, $settings, $title, $site);

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
        $mpdf->WriteHTML($this->render('@pdf/print-style.html.twig', $data), HTMLParserMode::HEADER_CSS);

        $header = $this->furniture((string) $settings['header'], $settings, $title, true);
        $footer = $this->furniture((string) $settings['footer'], $settings, $title, false);
        $style = $this->pageCss($mpdf, $header, $footer, (bool) $settings['cover']);

        if ($settings['cover']) {
            $mpdf->WriteHTML($style . $this->render('@pdf/cover.html.twig', $data));
            $mpdf->AddPage();

            if ($settings['toc'] && $items) {
                $mpdf->TOCpagebreakByArray([
                    'toc_preHTML' => $this->render('@pdf/toc-heading.html.twig', $data),
                    'toc_bookmarkText' => (string) $this->grav['language']->translate('PLUGIN_PDF.TOC'),
                    'links' => true,
                ]);
            }

            $mpdf->WriteHTML($this->render('@pdf/book.html.twig', $data));
        } else {
            $mpdf->WriteHTML($style . $this->render('@pdf/book.html.twig', $data));
        }

        $mpdf->Output($file, Destination::FILE);
    }

    /**
     * The `fontdata` entries for the site's own families.
     *
     * mPDF needs the file name per style; `useOTL` and `useKashida` are the
     * same defaults it gives its own faces, and are what make accented and
     * Arabic-script text render from a font that supports them.
     *
     * @param array<string, array<string, string>> $fonts family => style => file
     * @return array<string, array<string, mixed>>
     */
    private function fontData(array $fonts): array
    {
        $data = [];

        foreach ($fonts as $family => $styles) {
            $data[$family] = $styles + ['useOTL' => 255, 'useKashida' => 75];
        }

        return $data;
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

    /**
     * Expand the tokens a header or footer may use.
     *
     * `{PAGENO}` and `{nbpg}` are handed to mPDF; `{TODAY}` is ours precisely
     * because mPDF already claims `{DATE …}`, and it follows `date_format` so
     * the printed date matches the dateline under the article titles.
     */
    private function furniture(string $html, array $settings, string $title, bool $isHeader): string
    {
        // `page_numbers` supplies the default footer; it never edits one the
        // author wrote, so a custom footer keeps its {PAGENO} either way.
        if (!$isHeader && $settings['page_numbers'] && trim($html) === '') {
            $html = '<div class="pdf-footer">{PAGENO} / {NBPG}</div>';
        }

        $replace = [
            '{NBPG}' => '{nbpg}',
            '{SITE}' => htmlspecialchars((string) $this->grav['config']->get('site.title', ''), ENT_QUOTES, 'UTF-8'),
            '{TITLE}' => htmlspecialchars($title, ENT_QUOTES, 'UTF-8'),
            '{TODAY}' => $this->today($settings),
            '{LOGO}' => $this->logo($settings),
        ];

        $html = str_replace(array_keys($replace), array_values($replace), $html);

        // A header that is nothing but an absent logo should not reserve space,
        // but one that holds an image does count as content.
        $probe = trim(strip_tags((string) preg_replace('#<img\b[^>]*>#i', ' . ', $html)));
        $probe = trim(str_replace(['{PAGENO}', '{nbpg}'], '.', $probe));

        if ($probe === '') {
            return '';
        }

        // The header is wrapped so the stylesheet's .pdf-header gives it its
        // size, colour and alignment, and so its measured height — the thing
        // setAutoTopMargin reserves room for — is that of one block. The footer
        // brings its own class with it in the default markup above. The wrap
        // happens after the probe, so an absent logo still reserves nothing.
        return $isHeader ? '<div class="pdf-header">' . $html . '</div>' : $html;
    }

    /**
     * The current date in the configured format.
     */
    private function today(array $settings): string
    {
        return date($this->dateFormat($settings));
    }

    /**
     * The `date()` format for anything the plugin prints as a date.
     *
     * Separate from `system.pages.dateformat.default`, which is the format Grav
     * *parses* frontmatter dates with and reads badly on paper.
     */
    private function dateFormat(array $settings): string
    {
        $format = trim((string) ($settings['date_format'] ?? ''));

        return $format !== '' ? $format : 'Y-m-d';
    }

    /**
     * The logo as an inline image pointing at a local file.
     */
    private function logo(array $settings): string
    {
        $file = $this->logoFile($settings);

        return $file === null
            ? ''
            : '<img src="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '" alt="" class="pdf-logo" />';
    }

    /**
     * Resolve the logo to a file on disk, or null.
     *
     * Only the `logo` setting is consulted — a path written by hand
     * (`theme://images/logo.svg`, `user://…`, `user/themes/…`) or the
     * descriptor list an Admin `type: file` field stores, whose `path` is
     * user-root relative. Null when nothing is configured or nothing exists,
     * which leaves the header without a logo rather than with an empty image.
     */
    private function logoFile(array $settings): ?string
    {
        foreach ($this->logoSources($settings) as $candidate) {
            if (($file = $this->resolveLogo($candidate)) !== null) {
                return $file;
            }
        }

        return null;
    }

    /**
     * Every path the logo setting might name, in order of preference.
     *
     * @param array<string, mixed> $settings
     * @return string[]
     */
    private function logoSources(array $settings): array
    {
        $sources = [];

        foreach (self::flattenFileValue($settings['logo'] ?? '') as $entry) {
            if (!is_array($entry)) {
                $sources[] = $entry;
                continue;
            }

            // `path` is user-root relative and is the descriptor's authority.
            if (isset($entry['path']) && is_string($entry['path']) && $entry['path'] !== '') {
                $sources[] = $entry['path'];
                continue;
            }

            // A bare filename can only mean the folder the field uploads to,
            // or the page media it was picked from.
            foreach (['full_path', 'name'] as $key) {
                if (isset($entry[$key]) && is_string($entry[$key]) && $entry[$key] !== '') {
                    $sources[] = $entry[$key];
                    $sources[] = 'plugins/' . \Grav\Plugin\PdfPlugin::SLUG . '/logo/' . basename($entry[$key]);
                    break;
                }
            }
        }

        return $sources;
    }

    /**
     * Normalise a file setting into a list of entries.
     *
     * A hand-written setting is a plain string; an Admin file field stores a
     * list of `{name, path, size, type, url}` descriptors. Both are accepted
     * everywhere a file is configured.
     *
     * @param mixed $value
     * @return array<int, string|array<string, mixed>>
     */
    private static function flattenFileValue($value): array
    {
        if (is_string($value)) {
            $value = trim($value);

            return $value === '' ? [] : [$value];
        }

        if (!is_array($value)) {
            return [];
        }

        $entries = [];

        foreach ($value as $entry) {
            if (is_string($entry) && trim($entry) !== '') {
                $entries[] = trim($entry);
            } elseif (is_array($entry) && $entry !== []) {
                $entries[] = $entry;
            }
        }

        return $entries;
    }

    /**
     * One configured path to an absolute file, or null.
     */
    private function resolveLogo(string $candidate): ?string
    {
        $candidate = trim($candidate);

        if ($candidate === '') {
            return null;
        }

        // `user/plugins/pdf/logo/x.png` (what Admin stores) is relative to the
        // Grav root; `theme://…` and `user://…` are locator streams, and a
        // leading slash is a path from the root.
        $locatorPaths = [];

        if (preg_match('#^(/|[a-z][a-z0-9+.-]*://)#i', $candidate)) {
            $locatorPaths[] = $candidate;
        } else {
            if (str_starts_with($candidate, 'user/')) {
                $locatorPaths[] = 'user://' . substr($candidate, strlen('user/'));
            }

            $locatorPaths[] = 'user://' . ltrim($candidate, '/');
        }

        foreach ($locatorPaths as $locatorPath) {
            $file = $this->grav['locator']->findResource($locatorPath, true, true);

            if (is_string($file) && is_file($file)) {
                return $file;
            }
        }

        // Also accept a plain path that Html knows how to resolve.
        return Html::localFile($candidate);
    }

    /**
     * The value of `meta_author_field`, as a printable name.
     *
     * `author: { name: '…' }` and a plain `author: '…'` are both read; any
     * dotted frontmatter path works, so a site that keeps the name somewhere
     * else can point at it. A *list* is deliberately ignored — a list at that
     * key usually holds the account that created the page
     * (`author: ['admin']`), which is not a name to print.
     */
    private function authorName(PageInterface $page, array $settings): string
    {
        if (!$page->exists()) {
            return '';
        }

        $field = trim((string) ($settings['meta_author_field'] ?? ''));
        $author = $field !== '' ? (new Data((array) $page->header()))->get($field) : null;

        if (is_object($author)) {
            $author = (array) $author;
        }

        if (is_array($author)) {
            $author = isset($author['name']) && is_string($author['name']) ? $author['name'] : '';
        }

        return is_string($author) ? trim($author) : '';
    }

    /**
     * Normalise the `meta` setting into an ordered list of known parts.
     *
     * Three shapes have to be read, because the same setting is written by hand
     * in the shipped YAML, typed into a page, and generated by Admin:
     *
     *     [date, category, author]        a list, written by hand in frontmatter
     *     {date: 1, author: 1}            Admin's checkboxes with `use: keys`
     *     'date, category'               a comma- or space-separated string
     *
     * The configured order is kept — it is the order the parts print in — and
     * anything the plugin does not know is dropped rather than printed as an
     * empty field.
     *
     * @param mixed $value
     * @return string[]
     */
    private static function metaParts($value): array
    {
        if (is_string($value)) {
            $value = preg_split('#[\s,]+#', $value, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        }

        $parts = [];

        foreach ((array) $value as $key => $entry) {
            if (is_string($key)) {
                // A map: the key names the part and the value says whether it
                // is on. `use: keys` only stores the checked ones, so an entry
                // that is present is normally on already.
                if (!$entry) {
                    continue;
                }

                $part = $key;
            } else {
                $part = is_scalar($entry) ? (string) $entry : '';
            }

            $part = strtolower(trim($part));

            if (in_array($part, self::META_DEFAULTS, true) && !in_array($part, $parts, true)) {
                $parts[] = $part;
            }
        }

        return $parts;
    }

    /**
     * Upper-case the first letter, and leave the rest alone.
     *
     * Taxonomy values are usually stored lower-case, and only the first letter
     * is touched so a name is not flattened and intentional casing survives.
     * mb_* keeps non-Latin values working.
     */
    private static function capitalize(string $word): string
    {
        if ($word === '') {
            return '';
        }

        return mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_substr($word, 1, null, 'UTF-8');
    }

    /**
     * The document title: an explicit `pdf.title`, else the page's own.
     */
    private function title(PageInterface $page, array $settings): string
    {
        $title = trim((string) $settings['title']);

        return $title !== '' ? $title : (string) $page->title();
    }

    /**
     * The dateline under an article title, built from the configured parts.
     */
    private function meta(PageInterface $page, array $settings): string
    {
        $parts = [];

        foreach ((array) $settings['meta'] as $part) {
            switch ($part) {
                case 'date':
                    $stamp = (int) $page->date();
                    $parts[] = $stamp > 0 ? date($this->dateFormat($settings), $stamp) : '';
                    break;

                case 'category':
                    $taxonomy = trim((string) ($settings['meta_category_taxonomy'] ?? ''));
                    $categories = $taxonomy !== ''
                        ? array_map('strval', (array) ($page->taxonomy()[$taxonomy] ?? []))
                        : [];
                    $parts[] = implode(', ', array_map([self::class, 'capitalize'], $categories));
                    break;

                case 'author':
                    $parts[] = $this->authorName($page, $settings);
                    break;
            }
        }

        // Each part is dropped when it has nothing to say.
        return trim(implode(' · ', array_filter($parts)));
    }

    /**
     * Everything the templates render from.
     *
     * @return array<string, mixed>
     */
    private function data(PageInterface $page, array $items, string $mode, array $settings, string $title, string $site): array
    {
        $images = (bool) $settings['images'];
        $sources = $mode === 'book' ? $items : [$page];

        // A book holds the articles of the collection in one document, and each
        // of them numbers its own footnotes and names its own headings, so ids
        // repeat. Every entry gets a namespace of its own; one entry needs none,
        // which keeps a single article exactly as it was.
        $namespace = count($sources) > 1 ? 'pdf%d:' : '';

        $entries = [];

        foreach ($sources as $source) {
            $entries[] = [
                'title' => (string) $source->title(),
                'meta' => $this->meta($source, $settings),
                'images' => $images ? Html::pageImages($source, $this->config, (int) $settings['images_max']) : [],
                'content' => Html::body(
                    $source,
                    $this->config,
                    $images,
                    $namespace === '' ? '' : sprintf($namespace, count($entries))
                ),
                'url' => (string) $source->url(),
            ];
        }

        return [
            'settings' => $settings,
            'entries' => $entries,
            'logo' => $this->logoFile($settings),
            'document' => [
                'title' => $title,
                'site' => $site,
                'date' => $this->today($settings),
                'count' => count($entries),
                'mode' => $mode,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function render(string $template, array $data): string
    {
        return $this->grav['twig']->twig()->render($template, $data);
    }

    /**
     * Send the document and stop. Grav's own response is never used, so no page
     * is rendered and no theme template is involved.
     */
    private function send(PageInterface $page, string $file, string $key): void
    {
        $etag = '"' . $key . '"';

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            header('HTTP/1.1 304 Not Modified');
            header('ETag: ' . $etag);
            exit;
        }

        $name = $this->filename($page);

        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . $name . '"; filename*=UTF-8\'\'' . rawurlencode($name));
        header('Content-Length: ' . (string) filesize($file));
        header('ETag: ' . $etag);
        header('Last-Modified: ' . gmdate('D, d M Y H:i:s', (int) filemtime($file)) . ' GMT');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('X-Robots-Tag: noindex, nofollow');

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        readfile($file);
        exit;
    }

    private function filename(PageInterface $page): string
    {
        $slug = (string) preg_replace('/[^\p{L}\p{N}._-]+/u', '-', (string) $page->slug());
        $slug = trim($slug, '-_');

        return ($slug !== '' ? $slug : 'document') . '.pdf';
    }

    private function plain(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');

        echo $message;
        exit;
    }
}
