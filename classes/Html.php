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
 * so it is removed rather than left for the renderer to trip over. Images are
 * dropped unless the page opts in, and when they are kept they are pointed at
 * local files (a renderer reaching out over HTTP is exactly what breaks under a
 * single threaded dev server) and scaled down before embedding.
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
        $html = self::strip((string) $page->content(), $page, $config);

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
     * Remove the markup that can never print meaningfully.
     *
     * Shared by body() and inlineImages(), so the images the body will actually
     * print are the ones the leading block knows to leave alone: an <img> inside
     * a <noscript> or a stripped shortcode is not inline content, because
     * body() drops it.
     */
    private static function strip(string $html, PageInterface $page, Config $config): string
    {
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

        return $html;
    }

    /**
     * Make this entry's ids — and the links pointing at them — unique in a
     * document built from several entries.
     *
     * Each article starts its own numbering. Markdown Extra gives every article's
     * first footnote `id="fn:1"`, and a table-of-contents extension typically
     * names a heading after its text with no page identity, so two articles that
     * both carry a "Notes" heading both get `id="notes"`. A named destination
     * is keyed by name alone and a repeated `<a name="…">` simply overwrites
     * the earlier one, so every link to a repeated name resolves to whichever
     * article defined it last: a footnote halfway through a book jumped to the
     * last article's footnote.
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
     * Give every `id` a name the renderer can register as a link target.
     *
     * The built-in engine registers a named destination for a heading's `id`
     * and for an `<a name="…">`, and for nothing else: an element carrying only
     * `id="…"` is not a target. A `#fragment` link to such an id therefore
     * resolves to no page, and the link looks live but goes nowhere.
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
     * not hold an anchor at all, because the link's own target would be lost.
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
     * Inline body images are not returned: the body already prints them where
     * the author put them, so repeating them in the leading block would draw
     * each one twice. The block is for the lead picture and the gallery, which
     * have no place in the body. The result is capped, because an image-heavy
     * gallery page would otherwise embed the lot.
     *
     * @return string[] Local file paths.
     */
    public static function pageImages(PageInterface $page, Config $config, int $max): array
    {
        $files = [];
        $header = new Data((array) $page->header());

        $maxWidth = (int) $config->forPage($page, 'images_width', 1200);
        $quality = (int) $config->forPage($page, 'images_quality', 75);

        // The images the body already prints. They stay where they are, so the
        // leading block must not repeat them.
        $inline = self::inlineImages($page, $config);

        $add = static function ($file) use (&$files, $maxWidth, $quality, $inline): bool {
            if (!is_string($file) || $file === '') {
                return false;
            }

            $file = Paths::withinGrav($file);

            if ($file === null) {
                return false;
            }

            if (isset($inline[self::fileKey($file)])) {
                return false;
            }

            // Same treatment inline body images get, so `images_width` means the
            // same thing wherever the picture came from.
            $file = self::shrink($file, $maxWidth, $quality);

            if (in_array($file, $files, true)) {
                return false;
            }

            $files[] = $file;

            return true;
        };

        $addMedium = static function ($medium) use ($add): bool {
            if ($medium && method_exists($medium, 'path')) {
                // `false`: return the file as it is; resetting would throw away
                // any manipulation the page or the theme had already queued.
                return $add($medium->path(false));
            }

            return false;
        };

        $media = $page->media();
        $images = $media->images(); // a plain array, not a collection

        // The leading image: an explicit frontmatter key, else the first image
        // that is not the one `image_skip_field` names and is not already in the
        // body.
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
                if ($skip !== '' && $image->get('filename') === $skip) {
                    continue;
                }

                if ($addMedium($image)) {
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

        return $max > 0 ? array_slice($files, 0, $max) : $files;
    }

    /**
     * The local files the body's own <img> tags point at.
     *
     * Keyed by realpath, so a candidate resolved from page media and the same
     * file resolved from a body `src` compare equal even when the two paths are
     * spelled differently.
     *
     * @return array<string, true>
     */
    private static function inlineImages(PageInterface $page, Config $config): array
    {
        $html = self::strip((string) $page->content(), $page, $config);
        $files = [];

        if (preg_match_all('#<img\b[^>]*\bsrc\s*=\s*["\']([^"\']+)["\']#i', $html, $matches)) {
            foreach ($matches[1] as $src) {
                $file = self::localFile($src, $page);

                if ($file !== null) {
                    $files[self::fileKey($file)] = true;
                }
            }
        }

        return $files;
    }

    /**
     * A path that compares equal for the same file however it was spelled.
     */
    private static function fileKey(string $file): string
    {
        return (string) (realpath($file) ?: $file);
    }

    /**
     * Map a rendered `src` back to a file on disk.
     *
     * Grav emits site-absolute, percent-encoded paths
     * (`/<base>/user/pages/.../Some%20Photo.jpg`), which a renderer would try
     * to fetch over HTTP. Strip the base, decode, and check that it exists.
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

        // Resolve both root-relative and page-relative paths, then canonicalize
        // and confine them. This rejects traversal as well as symlinks escaping
        // the Grav installation.
        $file = null;

        if (str_starts_with($src, '/')) {
            $file = GRAV_ROOT . $src;
        } elseif ($page instanceof PageInterface) {
            // Page-relative, as an editor would have typed it.
            $file = $page->path() . '/' . $src;
        }

        if ($file !== null) {
            return Paths::withinGrav($file);
        }

        return null;
    }

    /**
     * Re-encode an oversized image into the plugin's working directory.
     *
     * Embedded at full size a photo-heavy article can be tens of megabytes;
     * the renderer embeds whatever it is given. Results are cached per source
     * file, so a book that reuses an image only pays for it once.
     *
     * The EXIF orientation is baked into the pixels on the way. GD ignores the
     * tag and the re-encoded copy carries none, so a photo stored sideways —
     * the usual case for a phone camera — would otherwise print rotated.
     * Neither engine reads the tag, so the correction has to happen here.
     */
    private static function shrink(string $file, int $maxWidth, int $quality): string
    {
        $info = @getimagesize($file);

        if (!$info) {
            return $file;
        }

        $orientation = self::orientation($file, $info[2]);

        // Orientations 5–8 turn the image a quarter turn, so the stored width
        // is the displayed height.
        [$width, $height] = $orientation >= 5 ? [$info[1], $info[0]] : [$info[0], $info[1]];

        $resize = $maxWidth > 0 && $width > $maxWidth;

        // Nothing to do: the file is already the right size and upright.
        if (!$resize && $orientation === 1) {
            return $file;
        }

        $quality = max(1, min(100, $quality));

        $grav = Grav::instance();
        $config = new Config($grav);
        $tmpDir = Paths::tmpDir($grav, $config);

        $target = $tmpDir . '/' . md5($file . '|' . filemtime($file) . '|' . filesize($file)
            . '|' . $maxWidth . '|' . $quality . '|' . $orientation) . '.jpg';

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

        $source = self::orient($source, $orientation);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);

        if ($maxWidth > 0 && $sourceWidth > $maxWidth) {
            $width = $maxWidth;
            $height = max(1, (int) round($sourceHeight * ($maxWidth / $sourceWidth)));
        } else {
            $width = $sourceWidth;
            $height = $sourceHeight;
        }

        $canvas = imagecreatetruecolor($width, $height);

        // JPEG has no alpha, so transparency becomes white rather than black.
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, 0, 0, $width, $height, $sourceWidth, $sourceHeight);

        $ok = imagejpeg($canvas, $target, $quality);

        imagedestroy($canvas);
        imagedestroy($source);

        return $ok ? $target : $file;
    }

    /**
     * The EXIF orientation of a JPEG, as the 1–8 value the spec defines.
     *
     * Only JPEG is read: it is the format cameras write the tag into, and the
     * one the plugin re-encodes. Anything else is upright as stored.
     */
    private static function orientation(string $file, int $type): int
    {
        if ($type !== IMAGETYPE_JPEG || !function_exists('exif_read_data')) {
            return 1;
        }

        $exif = @exif_read_data($file);

        if (!is_array($exif) || !isset($exif['Orientation'])) {
            return 1;
        }

        $orientation = (int) $exif['Orientation'];

        return $orientation >= 1 && $orientation <= 8 ? $orientation : 1;
    }

    /**
     * Turn a GD image the way its EXIF orientation says it should be seen.
     *
     * The mapping is the one Grav's own image library uses, so a photo prints
     * the same way here as it does in the article.
     *
     * @param \GdImage|resource $image
     * @return \GdImage|resource
     */
    private static function orient($image, int $orientation)
    {
        switch ($orientation) {
            case 2:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                break;
            case 3:
                $image = self::rotate($image, 180);
                break;
            case 4:
                imageflip($image, IMG_FLIP_VERTICAL);
                break;
            case 5:
                imageflip($image, IMG_FLIP_VERTICAL);
                $image = self::rotate($image, -90);
                break;
            case 6:
                $image = self::rotate($image, -90);
                break;
            case 7:
                imageflip($image, IMG_FLIP_HORIZONTAL);
                $image = self::rotate($image, -90);
                break;
            case 8:
                $image = self::rotate($image, 90);
                break;
        }

        return $image;
    }

    /**
     * Rotate a GD image, keeping the original if the rotation fails.
     *
     * @param \GdImage|resource $image
     * @return \GdImage|resource
     */
    private static function rotate($image, int $angle)
    {
        $rotated = imagerotate($image, $angle, 0);

        return $rotated === false ? $image : $rotated;
    }
}
