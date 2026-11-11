<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Grav;

/**
 * Where the plugin writes at runtime.
 *
 * Nothing is written inside the plugin folder any more: generated documents and
 * mPDF's working files both live under Grav's own cache stream, so a plugin
 * update, a read-only plugin directory or a `bin/grav clear-cache` behaves the
 * way an administrator expects. `cache_path` moves the document cache elsewhere
 * when a site wants it on another disk; the working directory always sits next
 * to it.
 *
 * `cache://` resolves through Grav's locator, so a site that has moved its cache
 * outside the document root, or renamed it with `GRAV_CACHE_PATH`, needs no
 * extra configuration here.
 */
final class Paths
{
    /** Default document cache, relative to Grav's cache stream. */
    public const DEFAULT_CACHE = 'cache://pdf';

    /**
     * The directory generated documents are written to.
     */
    public static function cacheDir(Grav $grav, Config $config): string
    {
        $configured = trim((string) $config->global('cache_path', ''));

        if ($configured !== '') {
            $resolved = self::resolve($grav, $configured);

            if ($resolved !== null) {
                return $resolved;
            }
        }

        return self::resolve($grav, self::DEFAULT_CACHE) ?? GRAV_ROOT . '/cache/pdf';
    }

    /**
     * mPDF's working directory: font metrics, temporary files, scaled images.
     */
    public static function tmpDir(Grav $grav, Config $config): string
    {
        return rtrim(self::cacheDir($grav, $config), '/') . '/tmp';
    }

    /**
     * Turn a configured path into an absolute one, or null.
     *
     * Accepts a stream (`cache://pdf`, `user-data://pdf/fonts`, `theme://…`), an
     * absolute path, or a path relative to the Grav root (`user/…`, as an Admin
     * file field stores it). The path need not exist yet.
     */
    public static function resolve(Grav $grav, string $path): ?string
    {
        $path = trim($path);

        if ($path === '') {
            return null;
        }

        if (preg_match('#^[a-z][a-z0-9+.-]*://#i', $path) || str_starts_with($path, '/')) {
            // `true, true`: absolute, and return the path even when it does not
            // exist yet — the caller creates it.
            $found = $grav['locator']->findResource($path, true, true);

            return is_string($found) && $found !== '' ? $found : null;
        }

        return GRAV_ROOT . '/' . $path;
    }

    /**
     * Create a directory, and report whether it is there afterwards.
     */
    public static function ensure(string $dir): bool
    {
        return is_dir($dir) || (mkdir($dir, 0755, true) === true) || is_dir($dir);
    }
}
