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
    /**
     * How old a scratch file has to be before it counts as abandoned.
     *
     * A working file belongs to a request that is still running, and PHP does
     * not run `finally` when it kills a script for exceeding `max_execution_time`
     * or when the whole process is killed, so a build that died leaves one
     * behind. This is deliberately longer than any build a site is expected to
     * allow, because deleting the scratch file of a live build would break it.
     */
    private const PART_MAX_AGE = 3600;

    /**
     * The working files a build leaves behind when PHP kills it.
     *
     * The scratch document is `.<key>.<rand>.part`; what a renderer derives
     * from it keeps that name and appends a suffix — the assembled Gotenberg
     * request body, the outline rewrite. The suffix is why the base pattern
     * alone is not enough: `glob()` anchors at the end of the name, so
     * `.*.part` never sees `.<key>.<rand>.part.multipart`.
     *
     * Neither pattern can reach a finished document: those are named
     * `<key>.<engine>.pdf`, and a key carries no `.part` to match.
     */
    private const SCRATCH = ['.*.part', '.*.part.*'];

    /** How long a lock file may sit unused before it is removed. */
    private const LOCK_MAX_AGE = 86400;

    private string $dir;
    private int $ttl;
    private bool $enabled;

    /**
     * The cache is just a directory and two policies: it holds no Grav state,
     * which is what lets a test build one without booting the CMS.
     */
    public function __construct(string $dir, int $ttl = 86400, bool $enabled = true)
    {
        $this->dir = $dir;
        $this->ttl = $ttl;
        $this->enabled = $enabled;
    }

    /**
     * The cache as this site configures it.
     */
    public static function fromConfig(Grav $grav, Config $config): self
    {
        return new self(
            Paths::cacheDir($grav, $config),
            (int) $config->global('cache_ttl', 86400),
            (bool) $config->global('cache', true)
        );
    }

    public function path(string $key): string
    {
        return rtrim($this->dir, '/') . '/' . $key . '.pdf';
    }

    /**
     * A scratch name for a document that is still being written.
     *
     * The engine writes here and the file is renamed once it is known to be
     * whole, so a request that arrives mid-build — or a build that dies halfway
     * — can never be served a truncated document. The name starts with a dot
     * and does not end in `.pdf`, which keeps it out of everything that globs
     * for cached documents.
     */
    public function temp(string $key): string
    {
        return rtrim($this->dir, '/') . '/.' . $key . '.' . bin2hex(random_bytes(8)) . '.part';
    }

    /**
     * Is the shared disk cache in use at all?
     *
     * With `cache: false` every request is meant to build its own copy, so one
     * cannot be answered from a neighbour's build either.
     */
    public function cacheEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Take the build lock for a key, or null when another request holds it.
     *
     * One lock per key, not per file: a key is one document, whichever engine
     * happens to print it, so a request that is waiting must not start a second
     * build under the other engine's name.
     */
    public function lock(string $key): ?Lock
    {
        return Lock::acquire(rtrim($this->dir, '/') . '/.' . $key . '.lock');
    }

    /**
     * Take the build lock for one signed-in account, regardless of document.
     *
     * The account identifier is hashed so usernames are not written into cache
     * filenames. This lock coordinates private builds only; it never makes a
     * signed-in document reusable by another request.
     */
    public function accountLock(string $account): ?Lock
    {
        $key = hash('sha256', "pdf-account\0" . mb_strtolower(trim($account), 'UTF-8'));

        return Lock::acquireRequired(rtrim($this->dir, '/') . '/.account-' . $key . '.lock');
    }

    /**
     * The newest complete document on disk for a key, and what produced it.
     *
     * Used while another request is building that same key: a file under a
     * final name is always whole (see temp()), and the key it is filed under is
     * the fingerprint of what went into it, so it can be served even when
     * `cache_ttl` has run out.
     *
     * @return array{0: string, 1: string}|null file, engine
     */
    public function latest(string $key): ?array
    {
        if (!is_dir($this->dir)) {
            return null;
        }

        $found = null;
        $newest = -1;

        foreach ((array) glob(rtrim($this->dir, '/') . '/' . $key . '.*.pdf') as $file) {
            if (!is_string($file) || !is_file($file)) {
                continue;
            }

            $modified = (int) filemtime($file);

            if ($modified > $newest) {
                $newest = $modified;
                $found = $file;
            }
        }

        if ($found === null) {
            return null;
        }

        // `…/<key>.<engine>.pdf`, and the engine is part of the response.
        $engine = basename($found, '.pdf');
        $engine = $engine === $key ? '' : substr($engine, strlen($key) + 1);

        return [$found, $engine !== '' ? $engine : 'php-pdf'];
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
     * Drop expired documents and the working files of requests that died.
     *
     * Called on write only, so a busy site pays for it rarely, and never on a
     * cache hit.
     */
    public function prune(string $keep): void
    {
        if (!is_dir($this->dir)) {
            return;
        }

        $dir = rtrim($this->dir, '/');

        if ($this->enabled) {
            $edge = $this->ttl > 0 ? time() - $this->ttl : null;

            foreach ((array) glob($dir . '/*.pdf') as $file) {
                if (!is_string($file) || $file === $keep) {
                    continue;
                }

                if ($edge === null || (int) filemtime($file) < $edge) {
                    @unlink($file);
                }
            }
        }

        // Abandoned builds and locks go whether or not the cache is on: they are
        // written by a request that is no longer running, and would otherwise
        // stay until somebody cleared the cache by hand.
        foreach (self::SCRATCH as $pattern) {
            $this->collect($dir, $pattern, self::PART_MAX_AGE);
        }

        $this->collect($dir, '.*.lock', self::LOCK_MAX_AGE, true);
    }

    /**
     * Remove working files older than `$age` seconds.
     *
     * The scratch file of a live build is younger than any sane `PART_MAX_AGE`,
     * but a lock file is created once and kept, so age alone would delete locks
     * that are in use. When `$lock` is set such a file is only taken away while
     * it can be locked exclusively — nothing holds it, nothing is waiting on it.
     */
    private function collect(string $dir, string $pattern, int $age, bool $lock = false): void
    {
        $edge = time() - $age;

        foreach ((array) glob($dir . '/' . $pattern) as $file) {
            if (!is_string($file) || !is_file($file) || (int) filemtime($file) >= $edge) {
                continue;
            }

            if (!$lock) {
                @unlink($file);

                continue;
            }

            $handle = @fopen($file, 'c');

            if ($handle === false) {
                continue;
            }

            if (flock($handle, LOCK_EX | LOCK_NB)) {
                @unlink($file);
                flock($handle, LOCK_UN);
            }

            fclose($handle);
        }
    }
}
