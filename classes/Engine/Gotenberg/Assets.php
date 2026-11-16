<?php

namespace Grav\Plugin\Pdf\Engine\Gotenberg;

use Grav\Plugin\Pdf\Paths;

/**
 * The files a Gotenberg request carries.
 *
 * Chromium cannot read this server's disk, so everything the document points at
 * has to travel with it: the images, the fonts, and the page itself. Each file
 * is uploaded under a name of its own, and the HTML is rewritten to refer to
 * that name — a local path in `src` would simply not resolve inside the
 * container.
 *
 * Names are generated rather than borrowed, because a filename is also the
 * request's addressing scheme: Gotenberg treats `index.html`, `header.html` and
 * `footer.html` specially, so a page image that happened to be called
 * `header.html` would be mistaken for the running header. A counter prefixed
 * with the kind of file makes that collision impossible.
 */
final class Assets
{
    /** @var array<string, string> uploaded name => absolute path */
    private array $files = [];

    /** @var array<string, string> absolute path => uploaded name */
    private array $names = [];

    /**
     * Register a file and return the name it travels under.
     *
     * The same file is only added once, so a logo that appears in the header of
     * every page — or an image used twice in an article — costs one upload.
     */
    public function add(string $path, string $kind = 'asset'): ?string
    {
        $path = trim($path);

        $path = $path !== '' ? Paths::withinGrav($path) : null;

        if ($path === null || !is_readable($path)) {
            return null;
        }

        if ($kind === 'img' && !self::isRasterImage($path)) {
            return null;
        }

        $key = (string) realpath($path);

        if (isset($this->names[$key])) {
            return $this->names[$key];
        }

        $extension = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));
        $extension = (string) preg_replace('/[^a-z0-9]/', '', $extension);
        $name = $kind . '-' . (count($this->files) + 1) . ($extension !== '' ? '.' . $extension : '');

        $this->files[$name] = $key;
        $this->names[$key] = $name;

        return $name;
    }

    /**
     * Point every image in $html at an uploaded copy.
     *
     * Anything that is not a local file is dropped rather than left behind:
     * Chromium should only receive images that the plugin resolved to local
     * files and uploaded with the job. The separate furniture path may retain
     * the plugin-generated base64 logo.
     */
    public function images(string $html, bool $allowTrustedDataImages = false): string
    {
        return (string) preg_replace_callback(
            '#<img\b[^>]*>#i',
            function (array $match) use ($allowTrustedDataImages): string {
                // `(?<![-\w])` keeps `data-src` out: it also ends in "src=".
                if (!preg_match('#(?<![-\w])src\s*=\s*("([^"]*)"|\'([^\']*)\')#i', $match[0], $src, PREG_OFFSET_CAPTURE)) {
                    return $match[0];
                }

                $raw = $src[2][0] !== '' ? $src[2][0] : $src[3][0];
                $value = html_entity_decode((string) $raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');

                $name = $this->add($value, 'img');

                if ($allowTrustedDataImages && HtmlSanitizer::isSafeImageDataUri($value)) {
                    return $match[0];
                }

                if ($name === null) {
                    return '';
                }

                $offset = (int) $src[0][1];

                return substr($match[0], 0, $offset)
                    . 'src="' . $name . '"'
                    . substr($match[0], $offset + strlen((string) $src[0][0]));
            },
            $html
        );
    }

    /**
     * Everything to upload, keyed by the name the HTML refers to.
     *
     * @return array<string, string>
     */
    public function all(): array
    {
        return $this->files;
    }

    public function count(): int
    {
        return count($this->files);
    }

    /** Only upload passive raster images; SVG can carry its own resource URLs. */
    private static function isRasterImage(string $path): bool
    {
        $image = @getimagesize($path);

        if (!is_array($image) || !isset($image[2])) {
            return false;
        }

        $allowed = [IMAGETYPE_GIF, IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_BMP];

        if (defined('IMAGETYPE_WEBP')) {
            $allowed[] = IMAGETYPE_WEBP;
        }

        if (defined('IMAGETYPE_AVIF')) {
            $allowed[] = IMAGETYPE_AVIF;
        }

        return in_array((int) $image[2], $allowed, true);
    }
}
