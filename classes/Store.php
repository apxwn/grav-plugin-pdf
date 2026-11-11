<?php

namespace Grav\Plugin\Pdf;

use Grav\Common\Grav;

/**
 * Disk cache for generated documents.
 *
 * A document's file name is a fingerprint of everything that went into it, so an
 * edit to any of the source pages produces a different name and the old file
 * simply stops being requested — no invalidation pass needed. `cache_ttl` is
 * then only a backstop for changes the fingerprint cannot see, such as a
 * template edit.
 *
 * The directory is Grav's own cache stream by default (see Paths), so
 * `bin/grav clear-cache` empties it along with everything else and nothing has
 * to be writable inside the plugin folder.
 */
class Store
{
    private string $dir;
    private int $ttl;
    private bool $enabled;

    public function __construct(Grav $grav, Config $config)
    {
        $this->dir = Paths::cacheDir($grav, $config);
        $this->ttl = (int) $config->global('cache_ttl', 86400);
        $this->enabled = (bool) $config->global('cache', true);
    }

    public function path(string $key): string
    {
        return rtrim($this->dir, '/') . '/' . $key . '.pdf';
    }

    /**
     * Is the cached document still usable?
     */
    public function isFresh(string $file): bool
    {
        if (!$this->enabled || !is_file($file)) {
            return false;
        }

        return $this->ttl <= 0 || (time() - (int) filemtime($file)) <= $this->ttl;
    }

    /**
     * Create the directory the document is about to be written to.
     */
    public function prepare(string $file): void
    {
        $dir = dirname($file);

        if (!Paths::ensure($dir)) {
            throw new \RuntimeException(sprintf(
                'Cannot create the PDF cache directory "%s". Make the Grav cache directory writable, or point '
                . '"cache_path" in the plugin settings at a directory that is.',
                $dir
            ));
        }
    }

    /**
     * Drop expired documents. Called on write only, so a busy site pays for it
     * rarely, and never on a cache hit.
     */
    public function prune(string $keep): void
    {
        if (!$this->enabled || !is_dir($this->dir)) {
            return;
        }

        $edge = $this->ttl > 0 ? time() - $this->ttl : null;

        foreach ((array) glob(rtrim($this->dir, '/') . '/*.pdf') as $file) {
            if (!is_string($file) || $file === $keep) {
                continue;
            }

            if ($edge === null || (int) filemtime($file) < $edge) {
                @unlink($file);
            }
        }
    }
}
