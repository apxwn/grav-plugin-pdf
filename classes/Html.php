<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Data\Data;
use Grav\Common\Grav;
use Grav\Common\Page\Interfaces\PageInterface;

/**
 * Turns a page's rendered HTML into something a printed page can carry.
 *
 * The PDF is text by default. Plenty of the source markup is meaningless on
 * paper — embeds, scripts, gallery mounts — and some of it is actively harmful,
 * so it is removed rather than left for mPDF to trip over. Images are dropped
 * unless the page opts in, and when they are kept they are pointed at local
 * files (mPDF reaching out over HTTP is exactly what breaks under a single
 * threaded dev server) and scaled down before embedding.
 */
class Html
{
    /**
     * The printable body of a page.
     *
     * `$namespace` is passed only when the body is one of several entries of a
     * book; see namespaceIds().
     */
    public static function body(PageInterface $page, Config $config, bool $images, string $namespace = ''): string
    {
        $html = (string) $page->content();

        // Paired tags whose contents can never print meaningfully.
        $html = (string) preg_replace(
            '#<(script|style|iframe|form|video|audio|object|embed|svg|canvas|map|noscript)\b[^>]*>.*?</\1\s*>#is',
            '',
            $html
        );

        // Same, for any that were left unclosed.
        $html = (string) preg_replace(
            '#</?(script|style|iframe|form|video|audio|object|embed|svg|canvas|map|noscript)\b[^>]*>#i',
            '',
            $html
        );

        $html = (string) preg_replace('#<(input|source|track|link|meta|base)\b[^>]*>#i', '', $html);

        // Shortcodes whose plugin is not installed render as literal text.
        // They are not content, so they do not belong in a book.
        $shortcodes = (array) $config->forPage($page, 'strip_shortcodes', []);

        foreach ($shortcodes as $shortcode) {
            $shortcode = trim((string) $shortcode);

            if ($shortcode === '') {
                continue;
            }

            $html = (string) preg_replace(
                '#\[\s*' . preg_quote($shortcode, '#') . '\b[^\]]*\]#i',
                '',
                $html
            );
        }

        if ($images) {
            $html = self::images($html, $page, $config);
        } else {
            $html = (string) preg_replace('#<img\b[^>]*>#i', '', $html);
        }

        // <picture>/<figure> wrappers are noise once the images inside are gone.
        $html = (string) preg_replace('#</?picture\b[^>]*>#i', '', $html);

        if ($namespace !== '') {
            $html = self::namespaceIds($html, $namespace);
        }

        return trim(self::anchors($html));
    }

    /**
     * Make this entry's ids — and the links pointing at them — unique in a
     * document built from several entries.
     *
     * Each article starts its own numbering. Markdown Extra gives every article's
     * first footnote `id="fn:1"`, and a table-of-contents extension typically
     * names a heading after its text with no page identity, so two articles that
     * both carry a "Notes" heading both get `id="notes"`. mPDF keys its anchors
     * by name alone and a repeated `<a name="…">` simply overwrites the earlier
     * one, so every link to a repeated name resolves to whichever article
     * defined it last: a footnote halfway through a book jumped to the last
     * article's footnote.
     *
     * The prefix is the entry's position. It ends in a colon, which a slug of
     * the kind heading anchors are built from — lower-case letters, digits and
     * hyphens — can never contain, and the footnote ids it has to stay clear of
     * are always `fn:N` and `fnrefN:N`.
     */
    private static function namespaceIds(string $html, string $namespace): string
    {
        // A hand-written anchor. Leaving it behind would leave the name it
        // shares with another article in place.
        $html = (string) preg_replace_callback(
            '#<a\b([^>]*?)\bname\s*=\s*"([^"]+)"([^>]*)>#i',
            static function (array $match) use ($namespace): string {
                return '<a' . $match[1] . 'name="' . $namespace . $match[2] . '"' . $match[3] . '>';
            },
            $html
        );

        $html = (string) preg_replace_callback(
            '#\sid\s*=\s*"([^"]+)"#i',
            static function (array $match) use ($namespace): string {
                return ' id="' . $namespace . $match[1] . '"';
            },
            $html
        );

        // The links that point at them. A bare `#` names nothing here — it only
        // appears in theme chrome, which never reaches the PDF — so it is left
        // as it is.
        return (string) preg_replace_callback(
            '~\shref\s*=\s*"#([^"]+)"~i',
            static function (array $match) use ($namespace): string {
                return ' href="#' . $namespace . $match[1] . '"';
            },
            $html
        );
    }

    /**
     * Give every `id` a name mPDF can register as a link target.
     *
     * A `#fragment` link is resolved only against an anchor written as
     * `<a name="…">`; an element carrying `id="…"` is invisible to mPDF (Tag/A.php
     * is the only place a target is registered at all). A link to an id
     * therefore resolves to no page: mPDF writes the destination as
     * `[%d 0 R …]` with `1 + 2 * $l[0]` and `$l` still `[0, 0]`, which is object
     * 1 — the page-tree root, not a page. Viewers cannot follow that, so the
     * link looks live and goes nowhere.
     *
     * The targets in real content are ids: a table-of-contents extension puts
     * them on headings and builds its list from them, Markdown Extra footnotes
     * use `id="fn:1"` and `id="fnref1:1"`, and hand-written section anchors use
     * a bare `<a id="…"></a>`.
     *
     * An anchor is inline content, and empty inline content in front of a block
     * claims a line of its own. Dropped in front of a heading that follows a
     * paragraph it pushes the rest of the document down a line, which renumbers
     * every page of a book — so the anchor normally goes *just inside* the
     * element it names, where it joins that element's first line. A cell needs it
     * there anyway: an anchor between a row and its cells is ignored.
     *
     * Two elements get it in front instead. A list item may open with a block,
     * and an anchor inside would stand on a line of its own above it; a link may
     * not hold an anchor at all, because mPDF's `A::close()` clears HREF and the
     * link itself would be lost.
     */
    private static function anchors(string $html): string
    {
        $html = (string) preg_replace_callback(
            '#<(li|a)\b([^>]*?)\sid\s*=\s*"([^"]+)"([^>]*)>#i',
            static function (array $match): string {
                return '<a name="' . $match[3] . '"></a>' . $match[0];
            },
            $html
        );

        return (string) preg_replace_callback(
            '#<(?!(?:li|a)\b)([a-z][a-z0-9]*)\b([^>]*?)\sid\s*=\s*"([^"]+)"([^>]*)>#i',
            static function (array $match): string {
                return $match[0] . '<a name="' . $match[3] . '"></a>';
            },
            $html
        );
    }

    /**
     * Rewrite every inline image to a local file, and shrink it on the way.
     */
    private static function images(string $html, PageInterface $page, Config $config): string
    {
        $maxWidth = (int) $config->forPage($page, 'images_width', 1200);
        $quality = (int) $config->forPage($page, 'images_quality', 75);

        return (string) preg_replace_callback(
            '#<img\b[^>]*>#i',
            static function (array $match) use ($page, $maxWidth, $quality) {
                if (!preg_match('#\bsrc\s*=\s*["\']([^"\']+)["\']#i', $match[0], $src)) {
                    return '';
                }

                $file = self::localFile($src[1], $page);

                if ($file === null) {
                    return ''; // remote or missing: better gone than broken
                }

                $file = self::shrink($file, $maxWidth, $quality);

                return '<img src="' . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . '" />';
            },
            $html
        );
    }

    /**
     * The images a page contributes when it opts into images.
     *
     * Frontmatter keys are settings, not assumptions, because no two themes
     * agree on them:
     *
     *   image_field       a header key naming one image to lead with
     *                     (an unset or unknown value falls back to the first
     *                     page image that is not `image_skip_field`)
     *   image_skip_field  a header key naming an image to leave out — an
     *                     author avatar is the usual case
     *   gallery_field     a header key that, when truthy, means "add every page
     *                     image, honouring `media_order`"
     *
     * Inline body images are always added last. The result is capped, because an
     * image-heavy gallery page would otherwise embed the lot.
     *
     * @return string[] Local file paths.
     */
    public static function pageImages(PageInterface $page, Config $config, int $max): array
    {
        $files = [];
        $header = new Data((array) $page->header());

        $maxWidth = (int) $config->forPage($page, 'images_width', 1200);
        $quality = (int) $config->forPage($page, 'images_quality', 75);

        $add = static function ($file) use (&$files, $maxWidth, $quality): void {
            if (!is_string($file) || $file === '' || !is_file($file)) {
                return;
            }

            // Same treatment inline body images get, so `images_width` means the
            // same thing wherever the picture came from.
            $file = self::shrink($file, $maxWidth, $quality);

            if (!in_array($file, $files, true)) {
                $files[] = $file;
            }
        };

        $addMedium = static function ($medium) use ($add): void {
            if ($medium && method_exists($medium, 'path')) {
                // `false`: return the file as it is; resetting would throw away
                // any manipulation the page or the theme had already queued.
                $add($medium->path(false));
            }
        };

        $media = $page->media();
        $images = $media->images(); // a plain array, not a collection

        // The leading image: an explicit frontmatter key, else the first image
        // that is not the one `image_skip_field` names.
        $field = trim((string) $config->forPage($page, 'image_field', ''));
        $featured = $field !== '' ? $header->get($field) : null;

        if (is_array($featured)) {
            $featured = $featured['name'] ?? null;
        }

        if (is_string($featured) && $featured !== '' && isset($media[$featured])) {
            $addMedium($media[$featured]);
        } else {
            $skipField = trim((string) $config->forPage($page, 'image_skip_field', ''));
            $skip = $skipField !== '' ? (string) $header->get($skipField) : '';

            foreach ($images as $image) {
                if ($skip === '' || $image->get('filename') !== $skip) {
                    $addMedium($image);
                    break;
                }
            }
        }

        // The gallery, when the page declares one.
        $galleryField = trim((string) $config->forPage($page, 'gallery_field', 'gallery'));

        if ($galleryField !== '' && $header->get($galleryField)) {
            $order = $header->get('media_order');

            if (is_string($order) && $order !== '') {
                foreach (explode(',', $order) as $filename) {
                    $filename = trim($filename);

                    if ($filename !== '' && isset($media[$filename])) {
                        $addMedium($media[$filename]);
                    }
                }
            } else {
                foreach ($images as $image) {
                    $addMedium($image);
                }
            }
        }

        // Inline body images.
        $content = (string) $page->content();
        if (preg_match_all('#<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $content, $matches)) {
            foreach ($matches[1] as $src) {
                $add(self::localFile($src, $page));
            }
        }

        return $max > 0 ? array_slice($files, 0, $max) : $files;
    }

    /**
     * Map a rendered `src` back to a file on disk.
     *
     * Grav emits site-absolute, percent-encoded paths
     * (`/<base>/user/pages/.../Some%20Photo.jpg`), which mPDF would try to fetch
     * over HTTP. Strip the base, decode, and check that it exists.
     */
    public static function localFile(string $src, ?PageInterface $page = null): ?string
    {
        $src = trim($src);

        if ($src === '' || str_starts_with($src, 'data:')) {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $src)) {
            // Only http(s) is worth salvaging; anything else is not a path.
            if (!preg_match('#^https?://#i', $src)) {
                return null;
            }
            $src = (string) (parse_url($src, PHP_URL_PATH) ?: '');
        }

        $src = rawurldecode($src);
        $src = (string) preg_replace('~[?#].*$~', '', $src);

        // Drop the site's base path so it becomes relative to GRAV_ROOT.
        $base = rtrim((string) Grav::instance()['base_url_relative'], '/');

        if ($base !== '' && str_starts_with($src, $base . '/')) {
            $src = substr($src, strlen($base));
        }

        if (str_starts_with($src, '/')) {
            $file = GRAV_ROOT . $src;

            return is_file($file) ? $file : null;
        }

        // Page-relative, as an editor would have typed it.
        if ($page instanceof PageInterface) {
            $file = $page->path() . '/' . $src;

            return is_file($file) ? $file : null;
        }

        return null;
    }

    /**
     * Re-encode an oversized image into the plugin's working directory.
     *
     * Embedded at full size a photo-heavy article can be tens of megabytes;
     * mPDF embeds whatever it is given. Results are cached per source file, so
     * a book that reuses an image only pays for it once.
     */
    private static function shrink(string $file, int $maxWidth, int $quality): string
    {
        if ($maxWidth <= 0) {
            return $file;
        }

        $info = @getimagesize($file);

        if (!$info || $info[0] <= $maxWidth) {
            return $file;
        }

        $info['mime'] ??= '';
        $quality = max(1, min(100, $quality));

        $grav = Grav::instance();
        $config = new Config($grav);
        $tmpDir = Paths::tmpDir($grav, $config);

        $target = $tmpDir . '/' . md5($file . '|' . filemtime($file) . '|' . filesize($file)
            . '|' . $maxWidth . '|' . $quality) . '.jpg';

        if (is_file($target)) {
            return $target;
        }

        if (!Paths::ensure($tmpDir)) {
            return $file;
        }

        switch ($info[2]) {
            case IMAGETYPE_JPEG:
                $source = @imagecreatefromjpeg($file);
                break;
            case IMAGETYPE_PNG:
                $source = @imagecreatefrompng($file);
                break;
            case IMAGETYPE_GIF:
                $source = @imagecreatefromgif($file);
                break;
            case IMAGETYPE_WEBP:
                $source = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($file) : false;
                break;
            default:
                return $file;
        }

        if (!$source) {
            return $file;
        }

        $width = $maxWidth;
        $height = max(1, (int) round($info[1] * ($maxWidth / $info[0])));
        $canvas = imagecreatetruecolor($width, $height);

        // JPEG has no alpha, so transparency becomes white rather than black.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $info[0], $info[1]);

        $ok = imagejpeg($canvas, $target, $quality);

        imagedestroy($canvas);
        imagedestroy($source);

        return $ok ? $target : $file;
    }
}
