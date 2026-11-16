<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;
use Grav\Plugin\Pdf\Engine\Document;
use Grav\Plugin\Pdf\Engine\Gotenberg\Unavailable;
use Grav\Plugin\Pdf\Engine\Router;
use Grav\Plugin\PdfPlugin;

/**
 * Builds and serves a document.
 *
 * The Generator knows what goes into a document — the settings, the entries,
 * the fonts — and nothing about how it is drawn: that is the engine's job. Two
 * are available, the php-pdf engine bundled with the plugin and an external
 * Gotenberg service (see Engine\Router).
 *
 * Nothing here knows anything about a theme or a particular site: the fonts are
 * the ones the plugin ships (or the ones a site points at), the logo is
 * whatever `logo` names, and every frontmatter key the document reads is a
 * setting with a generic default.
 */
class Generator
{
    /**
     * The typefaces the plugin ships, each with its bold, italic and
     * bold-italic companion. Latin, Greek and Cyrillic are covered by all of
     * them, and none of them has to be downloaded or installed. A site can add
     * families of its own with `fonts` and `font_dir`.
     */
    private const FONTS = [
        'dejavusans',
        'dejavusanscondensed',
        'dejavuserif',
        'dejavuserifcondensed',
        'dejavusansmono',
    ];

    /**
     * How the built-in faces are named in the Admin dropdowns.
     *
     * A font name is a proper noun, so these are not translated.
     */
    private const FONT_LABELS = [
        'dejavusans' => 'DejaVu Sans',
        'dejavusanscondensed' => 'DejaVu Sans Condensed',
        'dejavuserif' => 'DejaVu Serif',
        'dejavuserifcondensed' => 'DejaVu Serif Condensed',
        'dejavusansmono' => 'DejaVu Sans Mono',
    ];

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
                $this->grav['log']->warning(sprintf(
                    'PDF: collection export rejected because it contains %d items; the configured maximum is %d.',
                    count($items),
                    $max
                ));

                $this->plain(413, "This collection is too large to export as a PDF.\n");

                return;
            }
        }

        $store = new Store($this->grav, $this->config);
        $key = $this->fingerprint($page, $items, $mode);
        $router = new Router($this->grav, $this->config);
        $engine = $router->plan();

        $file = $store->path($key . '.' . $engine);

        if (!$store->isFresh($file)) {
            $store->prepare($file);

            try {
                [$file, $engine] = $this->produce($router, $this->document($page, $items, $mode), $store, $key, $engine);
            } catch (Unavailable $e) {
                $this->grav['log']->error(sprintf(
                    'PDF: Gotenberg conversion failed with fallback disabled (service %s): %s',
                    self::safeServiceAddress($router->url()),
                    self::safeLogMessage($e->getMessage())
                ));

                $this->plain(503, "The PDF could not be generated. Please try again later.\n");
            }

            $store->prune($file);
        }

        $this->send($page, $file, $key, $engine);
    }

    /**
     * Build to a temporary file, then give it the name of the engine that
     * actually produced it.
     *
     * The temporary file is what makes a half-written document impossible to
     * serve, and building before the name is known is what keeps a fallback out
     * of the Gotenberg cache entry: a built-in document is stored under
     * `…php-pdf`, so the next request after Gotenberg recovers builds a
     * Gotenberg one instead of reusing the substitute for a whole `cache_ttl`.
     *
     * @return array{0: string, 1: string} the file to serve, and the engine that produced it
     */
    private function produce(Router $router, Document $document, Store $store, string $key, string $engine): array
    {
        $temp = $store->temp($key);

        try {
            $used = $router->render($document, $temp);

            if (!is_file($temp) || (int) filesize($temp) === 0) {
                throw new \RuntimeException(sprintf('PDF: the %s engine produced no document.', $used));
            }

            $target = $store->path($key . '.' . $used);

            // A rename within the cache directory is atomic; the copy is for a
            // cache directory on another mount, where it is not.
            if (!@rename($temp, $target) && !@copy($temp, $target)) {
                throw new \RuntimeException(sprintf(
                    'PDF: cannot write "%s". Make the PDF cache directory writable.',
                    $target
                ));
            }

            $this->grav['log']->info(sprintf(
                'PDF: generated %s (%s, %d items, %s engine)',
                $key,
                $document->mode,
                count($document->data['entries']),
                $used
            ));

            return [$target, $used];
        } finally {
            if (is_file($temp)) {
                @unlink($temp);
            }
        }
    }

    /**
     * Everything that feeds the document, resolved once for whichever engine
     * ends up drawing it.
     */
    private function document(PageInterface $page, array $items, string $mode): Document
    {
        $settings = $this->settings($page, $mode, $items);
        $title = $this->title($page, $settings);
        $site = (string) $this->grav['config']->get('site.title', '');

        return new Document(
            $this->grav,
            $page,
            $mode,
            $settings,
            $this->data($page, $items, $mode, $settings, $title, $site),
            $this->fontPaths($settings),
            $title,
            $site
        );
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

        // The engine and the address it talks to change the document — one
        // renders it differently and the other decides which server does it.
        // The password is left out: it does not change the output, and this
        // string is not a place to keep a secret.
        $gotenberg = $this->config->gotenbergBlock();
        unset($gotenberg['password']);

        $parts[] = 'engine=' . $this->config->engine();
        $parts[] = 'gotenberg=' . json_encode($gotenberg);

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
     * The style words a font file name may end in, and the style key each one
     * maps to.
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
     * fontPaths(), which resolves those before the renderer sees them.
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
     * The font files a site's settings resolve to, as absolute paths.
     *
     * `font_dir` is where bare file names are looked up; a value in `fonts` may
     * also point at a file directly — an absolute path, a stream, or a path
     * relative to the Grav root. Both engines get the same answer, so a custom
     * family is the same font whichever of them prints it.
     *
     * @return array<string, array<string, string>> family => style => absolute path
     */
    private function fontPaths(array $settings): array
    {
        $dirs = $this->fontDirs($settings);
        $paths = [];

        foreach ((array) ($settings['fonts'] ?? []) as $family => $styles) {
            foreach ((array) $styles as $style => $file) {
                $file = trim((string) $file);

                if ($file === '') {
                    continue;
                }

                $path = preg_match('#^[A-Za-z]:[\\\\/]#', $file)
                    ? $file
                    : Paths::resolve($this->grav, $file);

                // A bare name is a file inside one of the `font_dir` folders.
                $path = $path !== null ? Paths::withinGrav($path) : null;

                if ($path === null) {
                    $path = null;

                    foreach ($dirs as $dir) {
                        $resolved = Paths::withinGrav($dir . '/' . $file);

                        if ($resolved !== null) {
                            $path = $resolved;
                            break;
                        }
                    }
                }

                if ($path === null) {
                    throw new \RuntimeException(sprintf(
                        'PDF: the font file "%s" for "%s" was not found. Put it in a "font_dir" folder, '
                        . 'or correct the path in the "fonts" setting.',
                        $file,
                        $family
                    ));
                }

                $paths[$family][$style] = $path;
            }
        }

        return $paths;
    }

    /**
     * The `.ttf` files behind one of the faces the plugin ships.
     *
     * The bundled renderer knows these by family name; an external one needs
     * the files themselves to embed, which is the only way it can set the same
     * text in the same face. Empty for a family the plugin does not ship —
     * the caller falls back to whatever the Gotenberg host has installed.
     *
     * @return array<string, string> style => absolute path
     */
    public static function builtinFontFiles(string $family): array
    {
        $files = [
            'dejavusans' => 'DejaVuSans',
            'dejavusanscondensed' => 'DejaVuSansCondensed',
            'dejavuserif' => 'DejaVuSerif',
            'dejavuserifcondensed' => 'DejaVuSerifCondensed',
            'dejavusansmono' => 'DejaVuSansMono',
        ];

        $base = $files[strtolower(trim($family))] ?? null;

        if ($base === null) {
            return [];
        }

        $dir = dirname(__DIR__) . '/fonts/';
        $styles = [
            'R' => $base . '.ttf',
            'B' => $base . '-Bold.ttf',
            // DejaVu calls the slanted faces "Oblique" on the Sans families and
            // "Italic" on the Serif ones; the bundled font set makes the same
            // distinction.
            'I' => [ $base . '-Italic.ttf', $base . '-Oblique.ttf' ],
            'BI' => [ $base . '-BoldItalic.ttf', $base . '-BoldOblique.ttf' ],
        ];

        $found = [];

        foreach ($styles as $style => $candidates) {
            foreach ((array) $candidates as $candidate) {
                if (is_file($dir . $candidate)) {
                    $found[$style] = $dir . $candidate;
                    break;
                }
            }
        }

        return $found;
    }

    /**
     * Every family a site can choose, as value => label, for the Admin
     * dropdowns.
     *
     * The built-in faces come first, then whatever `font_dir` holds and
     * whatever `fonts` names — alphabetically, so the list does not shuffle
     * about. A family found in a directory is labelled by the name its file
     * names imply; one named in `fonts` keeps the spelling the administrator
     * wrote, because that is what they will recognise.
     *
     * A value already saved in `font_body` or `font_headings` is always in the
     * list, even when the family is gone from disk: a dropdown that showed
     * nothing at all would hide both the problem and the thing to correct.
     *
     * @return array<string, string>
     */
    public function fontChoices(): array
    {
        $custom = [];
        $settings = $this->fontSettings();

        foreach (array_keys($this->scanFonts($settings)) as $family) {
            $custom[$family] = self::fontLabel($family);
        }

        foreach (array_keys((array) $settings['fonts']) as $family) {
            $normalized = strtolower((string) preg_replace('/[^A-Za-z0-9]+/', '', (string) $family));

            if ($normalized !== '') {
                $custom[$normalized] = trim((string) $family) !== ''
                    ? trim((string) $family)
                    : self::fontLabel($normalized);
            }
        }

        foreach (['font_body', 'font_headings'] as $key) {
            $value = strtolower(trim((string) $this->config->global($key, '')));

            if ($value !== '' && !isset($custom[$value])) {
                $custom[$value] = self::fontLabel($value);
            }
        }

        ksort($custom);

        return self::FONT_LABELS + $custom;
    }

    /**
     * The `font_dir` and `fonts` settings, in the shape the font helpers read.
     *
     * @return array{font_dir: array<int, string>, fonts: array<string, mixed>}
     */
    private function fontSettings(): array
    {
        $dirs = $this->config->global('font_dir', []);
        $fonts = $this->config->global('fonts', []);

        return [
            'font_dir' => is_string($dirs) ? [$dirs] : (array) $dirs,
            'fonts' => is_array($fonts) ? $fonts : [],
        ];
    }

    /**
     * A readable label for a family name that has none of its own.
     */
    private static function fontLabel(string $family): string
    {
        return ucfirst($family);
    }

    /**
     * One of the known faces — or a family found in `font_dir` — else the
     * default.
     *
     * The renderer substitutes a family it does not know, silently, following
     * the first entry of its own sans/serif list. Rejecting an unknown name
     * here is what makes a typo visible instead of printing the document in
     * some other face.
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

            $dir = $dir !== null ? Paths::withinGrav($dir, true) : null;

            if ($dir !== null) {
                $dirs[] = $dir;
            }
        }

        return array_values(array_unique($dirs));
    }

    /**
     * The family and style a font file name implies.
     *
     * The renderer needs a family name and a file name per style; the file
     * names are the only thing a site has to supply, so the convention is the
     * mapping:
     *
     *     MyFont-Regular.ttf   MyFont-Bold.ttf
     *     MyFont-Italic.ttf    MyFont-BoldItalic.ttf
     *
     * The part before the style word is the family, lower-cased and stripped of
     * anything that is not a letter or a digit; the word after it is the style.
     * A file with no recognised style word is the regular face of a family
     * named after the whole file, which is reported with a null style so the
     * caller can decide.
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
     * Anything that is not a `.ttf` is ignored, since the renderer cannot use
     * it. The first file to name a given family and style wins, so directories
     * are searched in the order they are configured and a site can shadow a
     * family by putting its own copy in a directory listed earlier.
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

            if (is_string($file)) {
                $file = Paths::withinGrav($file);

                if ($file !== null) {
                    return $file;
                }
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
                // An id a table of contents can link to. The built-in engine
                // builds its contents from these, and so does the external one.
                'anchor' => 'pdf-entry-' . count($entries),
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
     * Send the document and stop. Grav's own response is never used, so no page
     * is rendered and no theme template is involved.
     *
     * `X-PDF-Engine` says which renderer produced what is being sent — the one
     * that was configured, or the substitute that stood in for it. It is on
     * every response, cache hit or not, because a fallback has to be visible
     * without reading the log.
     */
    private function send(PageInterface $page, string $file, string $key, string $engine): void
    {
        $etag = '"' . $key . '"';

        if (trim((string) ($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) {
            header('HTTP/1.1 304 Not Modified');
            header('ETag: ' . $etag);
            header('X-PDF-Engine: ' . $engine);
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
        header('X-PDF-Engine: ' . $engine);

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

    /**
     * The configured service's network address, without credentials or URL
     * components that are not needed to identify the host in a server log.
     */
    private static function safeServiceAddress(string $url): string
    {
        $parts = parse_url($url);
        $scheme = is_array($parts) ? strtolower((string) ($parts['scheme'] ?? '')) : '';
        $host = is_array($parts) ? (string) ($parts['host'] ?? '') : '';

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            return 'configured service';
        }

        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $host = '[' . $host . ']';
        } elseif (!filter_var($host, FILTER_VALIDATE_IP)
            && preg_match('/\A(?=.{1,253}\z)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)(?:\.(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?))*\.?\z/i', $host) !== 1
        ) {
            return 'configured service';
        }

        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';

        return $scheme . '://' . $host . $port;
    }

    /** Keep untrusted service text on one bounded line in the server log. */
    private static function safeLogMessage(string $message): string
    {
        $message = preg_replace('/[\x00-\x1F\x7F]+/', ' ', $message) ?? '';
        $message = trim((string) preg_replace('/\s+/', ' ', $message));

        return mb_strlen($message, 'UTF-8') > 500
            ? mb_substr($message, 0, 500, 'UTF-8') . '…'
            : $message;
    }

    private function plain(int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/plain; charset=utf-8');

        echo $message;
        exit;
    }
}
