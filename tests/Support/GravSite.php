<?php

/**
 * A disposable Grav site for the end-to-end suite.
 *
 * The plugin is only observable through a real request: routing, the cache
 * headers, the 503 a waiting request gets and the lock behind it all live in
 * `pdf.php` and `Generator`, not in a function that can be called with a fake
 * page. So the tests run a real Grav, in a real `php -S` process, against a site
 * built in a temporary directory.
 *
 * The site is isolated by construction: the temporary root has its own `user/`,
 * `cache/` and `logs/`, and only `index.php`, `system/` and `vendor/` are
 * symlinked to the Grav checkout that was found. The developer's own site is
 * never read or written — its pages, its cache and its configuration are not
 * touched.
 *
 * Two details cost a debugging session and are worth keeping in mind:
 *
 *  - Grav reads the theme from `system.pages.theme`, not `system.theme`, and
 *    refuses to render anything when the theme is missing;
 *  - a bundle of article pages is a bundle of *subfolders*, one page each.
 *    Sibling `.md` files in one folder are one page in several languages.
 */

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests;

use RuntimeException;

final class GravSite
{
    /** Workers, so a burst of requests is actually served concurrently. */
    private const WORKERS = 4;

    /**
     * How long the site may take to answer its first request.
     *
     * Generous on purpose: a cold Grav compiles its configuration, blueprints
     * and pages on the first request, and a CI runner can spend far longer on
     * that than a development machine does.
     */
    private const READY_TIMEOUT = 120.0;

    /** How long one readiness attempt may take. */
    private const READY_REQUEST_TIMEOUT = 30.0;

    private const BOOK_ITEMS_VAR = 'PDFTEST_BOOK_ITEMS';

    /** @var resource|null */
    private $process = null;

    /** @var resource|null */
    private $lock = null;

    /** The transport error of the last request, for a failure message. */
    private string $lastError = '';

    /** The status the last readiness request answered with. */
    private int $lastStatus = 0;

    private function __construct(
        private readonly string $gravRoot,
        private readonly string $runDir,
        private readonly int $port
    ) {
    }

    /**
     * The Grav installation to run against: `GRAV_ROOT`, else the nearest
     * checkout above the plugin.
     */
    public static function discover(): ?string
    {
        $candidates = [];

        $configured = (string) (getenv('GRAV_ROOT') ?: '');
        if ($configured !== '') {
            $candidates[] = $configured;
        }

        // A plugin normally lives in <grav>/user/plugins/<slug>.
        $dir = PDF_PLUGIN_DIR;
        for ($depth = 0; $depth < 6; $depth++) {
            $candidates[] = $dir;
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        foreach ($candidates as $candidate) {
            $candidate = rtrim($candidate, '/');
            if (
                is_file($candidate . '/index.php')
                && is_file($candidate . '/system/router.php')
                && is_file($candidate . '/vendor/autoload.php')
            ) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Build the fixture site and start serving it.
     *
     * @param array<string, mixed> $pluginConfig written to user/config/plugins/pdf.yaml
     * @param int                  $bookItems    children generated for the book page
     */
    public static function create(array $pluginConfig = [], int $bookItems = 0): self
    {
        $gravRoot = self::discover();

        if ($gravRoot === null) {
            throw new RuntimeException(
                'No Grav installation found. Set GRAV_ROOT to a Grav 2.x checkout, or run the suite from '
                . 'user/plugins/pdf inside one.'
            );
        }

        $runDir = PDF_TESTS_DIR . '/.run/site-' . bin2hex(random_bytes(4));

        if ($bookItems <= 0) {
            $bookItems = self::bookItemsFromEnvironment();
        }

        try {
            self::buildTree($gravRoot, $runDir, $bookItems);
        } catch (\Throwable $e) {
            self::remove($runDir);

            throw $e;
        }

        $site = new self($gravRoot, $runDir, self::freePort());
        $site->configure($pluginConfig);

        try {
            $site->start();
        } catch (\Throwable $e) {
            $site->stop();

            throw $e;
        }

        return $site;
    }

    public function start(): void
    {
        $this->process = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . $this->port, 'system/router.php'],
            [
                0 => ['pipe', 'r'],
                1 => ['file', $this->runDir . '/server.log', 'a'],
                2 => ['file', $this->runDir . '/server.log', 'a'],
            ],
            $pipes,
            $this->runDir,
            $this->environment()
        );

        if (!is_resource($this->process)) {
            throw new RuntimeException('Could not start the fixture site.');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $this->waitFor(fn (): bool => $this->status('/de/home') === 200);

        // Grav's own caches (Twig, pages, config) are not safe against a cold
        // parallel burst: two workers compiling the same template produce a
        // race that answers 500 for reasons that have nothing to do with this
        // plugin. Warm them, and the plugin's print templates with them.
        $this->get('/de/home');
        $this->get('/de/article');
        $this->get('/de/article.pdf');
    }

    public function stop(): void
    {
        $this->releaseLock();

        if (is_resource($this->process)) {
            proc_terminate($this->process);

            $deadline = microtime(true) + 5.0;
            while (microtime(true) < $deadline) {
                $status = proc_get_status($this->process);
                if (!$status['running']) {
                    break;
                }
                usleep(50_000);
            }

            proc_terminate($this->process, 9);
            proc_close($this->process);
            $this->process = null;
        }

        if ((string) getenv('PDFTEST_KEEP') !== '1') {
            self::remove($this->runDir);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    public function url(string $path): string
    {
        return 'http://127.0.0.1:' . $this->port . '/' . ltrim($path, '/');
    }

    /** The disposable Grav root, for tests that need to add fixture files. */
    public function rootDir(): string
    {
        return $this->runDir;
    }

    /** Forget cached page data after a test edits one of its fixture pages. */
    public function clearPageCache(): void
    {
        self::remove($this->runDir . '/cache/pages');
    }

    /** Forget generated PDFs between cases that intentionally reuse a route. */
    public function clearPdfCache(): void
    {
        self::remove($this->runDir . '/cache/pdf');
    }

    /**
     * One request.
     *
     * @param string[] $headers extra request headers, e.g. `If-None-Match: "…"`
     * @return array{status: int, headers: array<string, string>, body: string, time: float}
     */
    public function get(string $path, array $headers = [], float $timeout = 120.0): array
    {
        $result = $this->request($path, $headers, $timeout);

        return $result ?? ['status' => 0, 'headers' => [], 'body' => '', 'time' => 0.0];
    }

    /**
     * Download a document and close the connection as soon as body bytes arrive.
     *
     * Returning `-1` from the write callback makes cURL abort the transfer, so
     * `CURLE_WRITE_ERROR` is the expected result here. What matters is what the
     * server leaves behind when it notices the disconnect.
     *
     * @param string[] $headers
     * @return array{errno: int, bytes: int}
     */
    public function abortDownload(string $path, array $headers = [], float $timeout = 120.0): array
    {
        $responseHeaders = new \ArrayObject();
        $handle = $this->handle($path, $headers, $responseHeaders, $timeout);
        $bytes = 0;

        curl_setopt_array($handle, [
            CURLOPT_BUFFERSIZE => 1024,
            CURLOPT_WRITEFUNCTION => static function ($handle, string $chunk) use (&$bytes): int {
                $bytes += strlen($chunk);

                return -1;
            },
        ]);

        curl_exec($handle);

        return ['errno' => curl_errno($handle), 'bytes' => $bytes];
    }

    /**
     * Several requests at once — the point of the concurrency tests.
     *
     * @param string[] $paths
     * @return array<int, array{status: int, headers: array<string, string>, body: string, time: float}>
     */
    public function parallel(array $paths): array
    {
        $multi = curl_multi_init();
        $handles = [];
        $responses = [];

        foreach ($paths as $index => $path) {
            $responses[$index] = new \ArrayObject();
            $handles[$index] = $this->handle($path, [], $responses[$index]);
            curl_multi_add_handle($multi, $handles[$index]);
        }

        do {
            $running = null;
            curl_multi_exec($multi, $running);
            if ($running > 0) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running > 0);

        $results = [];

        foreach ($handles as $index => $handle) {
            $results[$index] = [
                'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
                'headers' => $responses[$index]->getArrayCopy(),
                'body' => (string) curl_multi_getcontent($handle),
                'time' => (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME),
            ];
            curl_multi_remove_handle($multi, $handle);
        }

        return $results;
    }

    /**
     * Rewrite the PDF plugin configuration and forget Grav's compiled config.
     *
     * @param array<string, mixed> $config
     */
    public function configure(array $config): void
    {
        $this->configurePlugin('pdf', $config + ['engine' => 'php-pdf']);
    }

    /**
     * Rewrite one plugin's configuration and forget Grav's compiled config.
     *
     * The fixture site ships a stand-in for the Login plugin (see
     * `tests/fixtures/plugins/login`), disabled by default. Enabling it is a
     * configuration change like any other, so the compiled configuration has to
     * go or Grav keeps answering from the previous one.
     *
     * @param array<string, mixed> $config
     */
    public function configurePlugin(string $name, array $config): void
    {
        file_put_contents(
            $this->runDir . '/user/config/plugins/' . $name . '.yaml',
            '# Written by the test suite.' . "\n" . self::yaml($config)
        );

        self::remove($this->runDir . '/cache/compiled/config');
    }

    public function cacheDir(): string
    {
        return $this->runDir . '/cache/pdf';
    }

    public function logFile(): string
    {
        return $this->runDir . '/logs/grav.log';
    }

    public function logCount(string $needle): int
    {
        $file = $this->logFile();

        return is_file($file) ? substr_count((string) file_get_contents($file), $needle) : 0;
    }

    /** @return string[] */
    public function pdfFiles(): array
    {
        return array_values(array_filter((array) glob($this->cacheDir() . '/*.pdf'), 'is_string'));
    }

    /**
     * Every working file a build may leave behind: the scratch document, and
     * the request body and HTML parts a renderer derives from it.
     *
     * @return string[]
     */
    public function partFiles(): array
    {
        return array_values(array_filter((array) glob($this->cacheDir() . '/.*.part*'), 'is_string'));
    }

    /**
     * Pretend another request is building this document right now.
     *
     * The plugin locks `.<key>.lock` next to the documents, so holding the same
     * lock from the test makes "somebody else is building" a fact instead of a
     * race: no sleeping, no tuning.
     */
    public function holdLock(string $key): void
    {
        $file = $this->cacheDir() . '/.' . $key . '.lock';
        $handle = fopen($file, 'c');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not take the build lock for the test.');
        }

        $this->lock = $handle;
    }

    /** Pretend this account already has a private document build in progress. */
    public function holdAccountLock(string $username): void
    {
        $key = hash('sha256', "pdf-account\0" . mb_strtolower(trim($username), 'UTF-8'));
        $file = $this->cacheDir() . '/.account-' . $key . '.lock';
        $handle = fopen($file, 'c');

        if ($handle === false || !flock($handle, LOCK_EX)) {
            throw new RuntimeException('Could not take the account build lock for the test.');
        }

        $this->lock = $handle;
    }

    public function releaseLock(): void
    {
        if (is_resource($this->lock)) {
            flock($this->lock, LOCK_UN);
            fclose($this->lock);
        }

        $this->lock = null;
    }

    /** Change the fingerprint of the book without changing a setting. */
    public function touchBookItem(int $item = 1): void
    {
        $file = sprintf('%s/user/pages/03.book/%03d.item-%03d/item-%03d.de.md', $this->runDir, $item, $item, $item);

        if (!is_file($file)) {
            throw new RuntimeException(sprintf('No book item at "%s".', $file));
        }

        touch($file, time() + 1);
        clearstatcache(true, $file);
    }

    /** A short tail of the server log, for a failure message. */
    public function serverLog(int $lines = 12): string
    {
        $file = $this->runDir . '/server.log';

        if (!is_file($file)) {
            return '';
        }

        $all = explode("\n", trim((string) file_get_contents($file)));

        return implode("\n", array_slice($all, -$lines));
    }

    /** A tail of Grav's log, for a failure message. */
    public function gravLog(int $lines = 12): string
    {
        $file = $this->logFile();

        if (!is_file($file)) {
            return '';
        }

        $all = explode("\n", trim((string) file_get_contents($file)));

        return implode("\n", array_slice($all, -$lines));
    }

    private function status(string $path): int
    {
        $response = $this->get($path, [], self::READY_REQUEST_TIMEOUT);
        $this->lastStatus = $response['status'];

        return $response['status'];
    }

    /**
     * @param callable(): bool $ready
     */
    private function waitFor(callable $ready): void
    {
        $deadline = microtime(true) + self::READY_TIMEOUT;
        $attempts = 0;

        while (microtime(true) < $deadline) {
            $attempts++;

            if ($ready()) {
                return;
            }

            usleep(250_000);
        }

        throw new RuntimeException(sprintf(
            "The fixture site did not answer /de/home with 200 within %.0f seconds (%d attempts, last status %d).%s\n%s",
            self::READY_TIMEOUT,
            $attempts,
            $this->lastStatus,
            $this->lastError !== '' ? ' Last error: ' . $this->lastError . '.' : '',
            $this->serverLog()
        ));
    }

    /**
     * @param string[] $headers
     * @return array{status: int, headers: array<string, string>, body: string, time: float}|null
     */
    private function request(string $path, array $headers, float $timeout): ?array
    {
        $responseHeaders = new \ArrayObject();
        $handle = $this->handle($path, $headers, $responseHeaders, $timeout);
        $body = curl_exec($handle);

        if ($body === false) {
            $this->lastError = sprintf('%s (errno %d)', curl_error($handle), curl_errno($handle));

            return null;
        }

        $result = [
            'status' => (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE),
            'headers' => $responseHeaders->getArrayCopy(),
            'body' => (string) $body,
            'time' => (float) curl_getinfo($handle, CURLINFO_TOTAL_TIME),
        ];

        return $result;
    }

    /**
     * @param string[] $headers
     * @param \ArrayObject<string, string> $responseHeaders filled by the header callback
     * @param float $timeout seconds before cURL gives up
     * @return \CurlHandle
     */
    private function handle(string $path, array $headers, \ArrayObject $responseHeaders, float $timeout = 120.0)
    {
        $handle = curl_init($this->url($path));

        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT_MS => (int) round($timeout * 1000),
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use ($responseHeaders): int {
                $length = strlen($line);
                $trimmed = trim($line);

                if ($trimmed !== '' && !str_starts_with($trimmed, 'HTTP/')) {
                    $parts = explode(':', $trimmed, 2);
                    if (count($parts) === 2) {
                        $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                    }
                }

                return $length;
            },
        ]);

        return $handle;
    }

    /**
     * The environment the fixture server runs with.
     *
     * `GRAV_ROOT` is the one that matters: the test process needs it to find
     * the Grav checkout, but the server must not inherit it. If it does, Grav
     * boots *that* installation — its themes, its pages, its cache — and the
     * fixture site is never served at all. On CI, where the workflow exports
     * `GRAV_ROOT` for the test step, that is exactly what used to happen: every
     * readiness request answered 404 from the bare Grav skeleton.
     *
     * Every `GRAV_*` path override is therefore dropped and the root is set to
     * the temporary site itself.
     *
     * @return array<string, string>
     */
    private function environment(): array
    {
        $env = getenv();

        foreach (array_keys($env) as $name) {
            if (str_starts_with((string) $name, 'GRAV_')) {
                unset($env[$name]);
            }
        }

        $env['GRAV_ROOT'] = $this->runDir;
        $env['PHP_CLI_SERVER_WORKERS'] = (string) self::WORKERS;

        return $env;
    }

    private static function bookItemsFromEnvironment(): int
    {
        $configured = (string) (getenv(self::BOOK_ITEMS_VAR) ?: '');

        return $configured !== '' ? (int) $configured : 0;
    }

    /**
     * Lay out the temporary site.
     */
    private static function buildTree(string $gravRoot, string $runDir, int $bookItems): void
    {
        $fixture = PDF_TESTS_DIR . '/fixtures/site';

        foreach (
            [
                'user/config/plugins',
                'user/pages',
                'user/themes',
                'user/plugins',
                'cache',
                'logs',
                'tmp',
                'backup',
            ] as $dir
        ) {
            self::ensure($runDir . '/' . $dir);
        }

        // Only Grav itself is borrowed; the site around it is ours.
        foreach (['index.php', 'system', 'vendor'] as $link) {
            if (!symlink($gravRoot . '/' . $link, $runDir . '/' . $link)) {
                throw new RuntimeException(sprintf('Could not link "%s" into the fixture site.', $link));
            }
        }

        copy($fixture . '/user/config/system.yaml', $runDir . '/user/config/system.yaml');

        if (!symlink($fixture . '/user/themes/fixture', $runDir . '/user/themes/fixture')) {
            throw new RuntimeException('Could not link the fixture theme.');
        }

        self::copyTree($fixture . '/user/pages', $runDir . '/user/pages');
        self::writeRasterFixture($runDir . '/user/pages/02.article/pixel.png');
        symlink(PDF_PLUGIN_DIR, $runDir . '/user/plugins/pdf');

        // A fixture stand-in for the Login plugin. It is disabled in its own
        // configuration, so a site behaves as if it were not installed until a
        // test opts it in through `configurePlugin('login', ['enabled' => true])`.
        symlink(PDF_TESTS_DIR . '/fixtures/plugins/login', $runDir . '/user/plugins/login');

        if ($bookItems > 0) {
            self::writeBook($runDir, $bookItems);
        }
    }

    /**
     * A listing page and its articles, which is what a book is.
     *
     * Generated rather than committed: the point of the book is that it is big
     * enough to take a moment to render.
     */
    private static function writeBook(string $runDir, int $items): void
    {
        $book = $runDir . '/user/pages/03.book';
        self::ensure($book);

        file_put_contents($book . '/book.de.md', <<<'MD'
---
title: Predigten
content:
  items: '@self.children'
  order: {by: date, dir: desc}
pdf:
  enabled: true
---
Eine Sammlung von Predigten.
MD);

        for ($i = 1; $i <= $items; $i++) {
            $dir = sprintf('%s/%03d.item-%03d', $book, $i, $i);
            self::ensure($dir);

            $date = sprintf('2020-01-%02d 10:00', ($i % 28) + 1);
            $body = sprintf(
                "Artikel %03d\n\nAbsatz eins von Artikel %03d. Ein wenig Text, damit die Seite realistisch ist.\n\n"
                . "Absatz zwei mit **Fettdruck**, einer Liste und einem [Link](https://example.org/).\n\n"
                . "* Punkt eins\n* Punkt zwei\n* Punkt drei\n\n"
                . "Absatz drei schliesst den Artikel ab und gibt der Seite etwas Laenge.\n",
                $i,
                $i
            );

            file_put_contents(
                sprintf('%s/item-%03d.de.md', $dir, $i),
                "---\ntitle: Artikel " . sprintf('%03d', $i) . "\ndate: '$date'\n"
                . "pdf:\n  enabled: true\n---\n" . $body
            );
        }
    }

    /**
     * A local raster big enough that a download is still streaming when the
     * client goes away. Built without GD: the suite must not depend on it.
     */
    public function writeLargeImage(string $relative, int $size = 1200): string
    {
        $path = $this->runDir . '/' . ltrim($relative, '/');
        self::ensure(dirname($path));

        $raw = '';
        for ($row = 0; $row < $size; $row++) {
            $raw .= "\x00" . random_bytes($size * 3);
        }

        $png = "\x89PNG\r\n\x1a\n"
            . self::pngChunk('IHDR', pack('NNCCCCC', $size, $size, 8, 2, 0, 0, 0))
            . self::pngChunk('IDAT', (string) gzcompress($raw, 1))
            . self::pngChunk('IEND', '');

        if (file_put_contents($path, $png) === false) {
            throw new RuntimeException(sprintf('Could not write the large PNG fixture at "%s".', $path));
        }

        return $path;
    }

    private static function pngChunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    /** A tiny valid local raster used by the Gotenberg asset tests. */
    private static function writeRasterFixture(string $path): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pWQAAAAASUVORK5CYII=',
            true
        );

        if ($png === false || file_put_contents($path, $png) === false) {
            throw new RuntimeException('Could not write the local raster fixture.');
        }
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException('Could not reserve a port: ' . $error);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }

    private static function ensure(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0777, true) && !is_dir($dir)) {
            throw new RuntimeException(sprintf('Could not create "%s".', $dir));
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        self::ensure($to);

        foreach ((array) scandir($from) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $source = $from . '/' . $entry;
            $target = $to . '/' . $entry;

            if (is_dir($source)) {
                self::copyTree($source, $target);
                continue;
            }

            copy($source, $target);
        }
    }

    private static function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            self::remove($path . '/' . $entry);
        }

        @rmdir($path);
    }

    /**
     * A minimal YAML writer — enough for the plugin configuration, and it keeps
     * the test suite free of a dependency the plugin itself does not have.
     *
     * @param array<string|int, mixed> $data
     */
    private static function yaml(array $data, int $indent = 0): string
    {
        $pad = str_repeat('  ', $indent);
        $yaml = '';

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                if ($value === []) {
                    $yaml .= $pad . $key . ": []\n";
                    continue;
                }

                if (array_is_list($value)) {
                    $yaml .= $pad . $key . ":\n";
                    foreach ($value as $item) {
                        $yaml .= $pad . '  - ' . self::scalar($item) . "\n";
                    }
                    continue;
                }

                $yaml .= $pad . $key . ":\n" . self::yaml($value, $indent + 1);
                continue;
            }

            $yaml .= $pad . $key . ': ' . self::scalar($value) . "\n";
        }

        return $yaml;
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], (string) $value) . '"';
    }
}
