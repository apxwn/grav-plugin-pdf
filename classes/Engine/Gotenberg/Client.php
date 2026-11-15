<?php

namespace Grav\Plugin\Pdf\Engine\Gotenberg;

/**
 * The HTTP side of the Gotenberg engine.
 *
 * One class, so that the engine, the connection test and the CLI command all
 * talk to the service the same way: the same address normalisation, the same
 * timeouts, the same reading of an error.
 *
 * The multipart body is assembled by hand rather than with cURL's array form,
 * because `files` is a *repeated* field — one entry per uploaded file, each
 * carrying the file's own name — which `CURLOPT_POSTFIELDS` cannot express as
 * an array. Those names matter: Gotenberg recognises `index.html`,
 * `header.html` and `footer.html` by name and silently ignores anything else,
 * so the name is part of the request, not a label on it.
 *
 * The body streams from a file on disk. A 110-article book carries its whole
 * HTML plus three font files, which is small enough to hold in memory — but
 * the same code also has to survive a site that turned images on, and a
 * document that has to fit in one PHP request should not also have to fit in
 * one PHP string.
 */
final class Client
{
    /** What `api-timeout` means in Gotenberg's own vocabulary, in seconds. */
    public const DEFAULT_TIMEOUT = 30;

    /**
     * Time to wait for the host to answer at all.
     *
     * This is the number that bounds a fallback: an address that is refused
     * costs nothing, but one that is silently dropped (a firewall, a container
     * that no longer has an IP) costs exactly this much before mPDF takes over.
     */
    public const DEFAULT_CONNECT_TIMEOUT = 2;

    /** The form route the engine posts to. */
    public const ROUTE = '/forms/chromium/convert/html';

    private function __construct(
        private readonly string $base,
        private readonly int $timeout,
        private readonly int $connectTimeout,
        private readonly string $username,
        private readonly string $password,
        private readonly bool $verifyTls,
    ) {
    }

    /**
     * @param array<string, mixed> $options the plugin's `gotenberg` block
     */
    public static function fromOptions(array $options): self
    {
        return new self(
            self::normaliseUrl((string) ($options['url'] ?? '')),
            max(1, (int) ($options['timeout'] ?? self::DEFAULT_TIMEOUT)),
            max(1, (int) ($options['connect_timeout'] ?? self::DEFAULT_CONNECT_TIMEOUT)),
            (string) ($options['username'] ?? ''),
            (string) ($options['password'] ?? ''),
            (bool) ($options['verify_tls'] ?? true),
        );
    }

    /**
     * An address with a scheme and without a trailing slash.
     *
     * "127.0.0.1:3000" and "gotenberg.example.com" are what people type into a
     * settings field; cURL refuses both without a scheme, and a doubled slash
     * turns the route into "//forms/…".
     */
    public static function normaliseUrl(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return '';
        }

        if (!preg_match('#^[a-z][a-z0-9+.-]*://#i', $url)) {
            $url = 'http://' . $url;
        }

        return rtrim($url, '/');
    }

    public function base(): string
    {
        return $this->base;
    }

    /**
     * Is there an address to talk to at all?
     */
    public function configured(): bool
    {
        return $this->base !== '';
    }

    /**
     * Build the document and write it to $out.
     *
     * @param array<string, scalar> $fields form fields, in the order Gotenberg should see them
     * @param array<string, string> $files  uploaded name => absolute path
     *
     * @throws Unavailable
     */
    public function render(array $fields, array $files, string $out): void
    {
        if (!$this->configured()) {
            throw new Unavailable('no Gotenberg address is configured', false);
        }

        if ($files === []) {
            throw new Unavailable('no files were prepared for Gotenberg', false);
        }

        $body = $out . '.multipart';
        $boundary = '----GravPdf' . bin2hex(random_bytes(12));

        try {
            $this->multipart($body, $boundary, $fields, $files);
            $this->send(self::ROUTE, $body, $boundary, $out);
        } finally {
            if (is_file($body)) {
                @unlink($body);
            }
        }
    }

    /**
     * Ask the service who it is.
     *
     * Deliberately not a rendering request: a test that builds a PDF is slow
     * and can fail for reasons that have nothing to do with the connection.
     * `/version` answers with the version string and a `Gotenberg-Version`
     * header, which together are what makes the answer *Gotenberg's* — a
     * plain "200 OK" from whatever else happens to listen on that port is the
     * one failure mode that looks like success.
     *
     * @return array{ok: bool, url: string, version: string, status: int, ms: int, error: string, hint: string, modules: array<string, string>}
     */
    public function probe(): array
    {
        $result = [
            'ok' => false,
            'url' => $this->base,
            'version' => '',
            'status' => 0,
            'ms' => 0,
            'error' => '',
            'hint' => '',
            'modules' => [],
        ];

        if (!$this->configured()) {
            $result['error'] = 'No address is configured.';
            $result['hint'] = 'Enter the address of your Gotenberg service, for example 127.0.0.1:3000.';

            return $result;
        }

        $response = $this->get('/version', min($this->timeout, 10));
        $result['status'] = $response['status'];
        $result['ms'] = $response['ms'];

        if ($response['errno'] !== 0) {
            $result['error'] = $response['error'];
            $result['hint'] = self::hint($response['errno'], $this->connectTimeout);

            return $result;
        }

        $version = trim($response['headers']['gotenberg-version'] ?? '');

        if ($version === '') {
            $body = trim($response['body']);

            if (preg_match('/^\d+\.\d+(\.\d+)?/', $body) === 1) {
                $version = $body;
            }
        }

        if ($version === '') {
            $result['error'] = sprintf(
                'Something answered at %s with HTTP %d, but it is not Gotenberg.',
                $this->base,
                $response['status']
            );
            $result['hint'] = 'Check the port: another service is listening on it, or a proxy is in front of Gotenberg '
                . 'and swallowing its headers.';

            return $result;
        }

        $result['version'] = $version;

        // Only the major version decides anything: the plugin speaks the v9
        // form API, and v8 rejects two of the fields with a 400.
        $major = (int) strtok($version, '.');

        if ($major < 9) {
            $result['error'] = sprintf('Gotenberg %s is too old.', $version);
            $result['hint'] = 'This plugin needs Gotenberg 9 or newer; v8 renamed or removed the fields it sends. '
                . 'Upgrade the service (the image is gotenberg/gotenberg:9).';

            return $result;
        }

        $health = $this->get('/health', 5);

        if ($health['errno'] === 0 && $health['status'] === 200) {
            $decoded = json_decode($health['body'], true);

            foreach ((array) ($decoded['details'] ?? []) as $module => $state) {
                if (is_array($state) && isset($state['status'])) {
                    $result['modules'][(string) $module] = (string) $state['status'];
                }
            }
        }

        $result['ok'] = true;
        $result['hint'] = sprintf('Gotenberg %s is reachable.', $version);

        return $result;
    }

    /**
     * A single GET, reported rather than thrown.
     *
     * @return array{status: int, headers: array<string, string>, body: string, errno: int, error: string, ms: int}
     */
    private function get(string $path, int $timeout): array
    {
        $ch = curl_init($this->base . $path);

        if ($ch === false) {
            return ['status' => 0, 'headers' => [], 'body' => '', 'errno' => -1, 'error' => 'curl_init failed', 'ms' => 0];
        }

        $headers = [];

        $this->options($ch, $timeout);

        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPGET, true);
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, static function ($ch, string $line) use (&$headers): int {
            $parts = explode(':', $line, 2);

            if (count($parts) === 2) {
                $headers[strtolower(trim($parts[0]))] = trim($parts[1]);
            }

            return strlen($line);
        });

        $start = microtime(true);
        $result = curl_exec($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $response = [
            'status' => (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE),
            'headers' => $headers,
            'body' => is_string($result) ? $result : '',
            'errno' => curl_errno($ch),
            'error' => curl_error($ch),
            'ms' => $ms,
        ];

        curl_close($ch);

        return $response;
    }

    /**
     * Assemble the multipart body on disk.
     *
     * @param array<string, scalar> $fields
     * @param array<string, string> $files
     */
    private function multipart(string $file, string $boundary, array $fields, array $files): void
    {
        $handle = @fopen($file, 'wb');

        if ($handle === false) {
            throw new Unavailable(sprintf('cannot write "%s"', $file), false);
        }

        try {
            foreach ($fields as $name => $value) {
                fwrite($handle, sprintf(
                    "--%s\r\nContent-Disposition: form-data; name=\"%s\"\r\n\r\n%s\r\n",
                    $boundary,
                    $name,
                    is_bool($value) ? ($value ? 'true' : 'false') : (string) $value
                ));
            }

            foreach ($files as $name => $path) {
                $source = @fopen($path, 'rb');

                if ($source === false) {
                    throw new Unavailable(sprintf('cannot read "%s" for upload', $path), false);
                }

                fwrite($handle, sprintf(
                    "--%s\r\nContent-Disposition: form-data; name=\"files\"; filename=\"%s\"\r\n"
                    . "Content-Type: %s\r\n\r\n",
                    $boundary,
                    $name,
                    self::mime($name)
                ));

                stream_copy_to_stream($source, $handle);
                fclose($source);
                fwrite($handle, "\r\n");
            }

            fwrite($handle, '--' . $boundary . "--\r\n");
        } finally {
            fclose($handle);
        }
    }

    /**
     * POST the assembled body and check that a PDF came back.
     */
    private function send(string $path, string $body, string $boundary, string $out): void
    {
        $size = (int) filesize($body);
        $input = @fopen($body, 'rb');
        $output = @fopen($out, 'wb');

        if ($input === false || $output === false) {
            if (is_resource($input)) {
                fclose($input);
            }

            throw new Unavailable(sprintf('cannot write the document to "%s"', $out), false);
        }

        $headers = [];
        $ch = curl_init($this->base . $path);

        if ($ch === false) {
            fclose($input);
            fclose($output);

            throw new Unavailable('could not start a cURL handle', false);
        }

        $this->options($ch, $this->timeout);

        // A POST whose body comes from a file. `CURLOPT_UPLOAD` is what makes
        // cURL read the size bytes from the stream instead of taking them from
        // a string; the custom method keeps the verb at POST. There is no
        // `CURLOPT_POSTFIELDSIZE` in PHP 8.5 to do it any other way.
        curl_setopt($ch, CURLOPT_UPLOAD, true);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_INFILE, $input);
        curl_setopt($ch, CURLOPT_INFILESIZE, $size);
        curl_setopt($ch, CURLOPT_FILE, $output);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: multipart/form-data; boundary=' . $boundary,
            // Suppress the 100-continue round trip: it would add a full network
            // leg to every request for no benefit on a trusted link.
            'Expect:',
        ]);

        $start = microtime(true);
        $ok = curl_exec($ch);
        $ms = (int) round((microtime(true) - $start) * 1000);

        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $type = strtolower((string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE));

        curl_close($ch);
        fclose($input);
        fclose($output);

        if ($ok === false || $errno !== 0) {
            @unlink($out);

            throw new Unavailable(sprintf(
                'the request failed after %d ms: %s',
                $ms,
                $error !== '' ? $error : 'unknown cURL error ' . $errno
            ));
        }

        if ($status !== 200) {
            $message = self::message($out);
            @unlink($out);

            throw new Unavailable(
                sprintf('Gotenberg answered HTTP %d%s', $status, $message !== '' ? ': ' . $message : ''),
                // A rejected *form* is our own mistake — a renamed field, a
                // value it will not take — and must be reported on every
                // attempt. Anything else is the host.
                !in_array($status, [400, 413, 415, 422], true),
                $status
            );
        }

        if (!str_contains($type, 'application/pdf')) {
            $message = self::message($out);
            @unlink($out);

            throw new Unavailable(sprintf(
                'Gotenberg answered with "%s" instead of a PDF%s',
                $type !== '' ? $type : 'no content type',
                $message !== '' ? ': ' . $message : ''
            ), true, $status);
        }

        $magic = (string) @file_get_contents($out, false, null, 0, 5);

        if ($magic !== '%PDF-') {
            @unlink($out);

            throw new Unavailable('the response was not a PDF', true, $status);
        }
    }

    /**
     * The settings every request shares: where the credentials are, how long to
     * wait, whether the certificate has to be valid.
     */
    private function options(\CurlHandle $ch, int $timeout): void
    {
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $this->connectTimeout * 1000);
        curl_setopt($ch, CURLOPT_TIMEOUT, max(1, $timeout));
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->verifyTls);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->verifyTls ? 2 : 0);
        // The service is reached over a network that may be a private one, and
        // a proxy in between is the site's business, not ours: cURL's
        // environment defaults are left in place.
        curl_setopt($ch, CURLOPT_ENCODING, '');

        if ($this->username !== '') {
            curl_setopt($ch, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
            curl_setopt($ch, CURLOPT_USERPWD, $this->username . ':' . $this->password);
        }
    }

    /**
     * The first useful words of an error body, as one line.
     */
    private static function message(string $file): string
    {
        $raw = (string) @file_get_contents($file, false, null, 0, 2000);
        $raw = trim((string) preg_replace('/\s+/', ' ', $raw));

        // Gotenberg's own errors are short and to the point; anything longer is
        // a proxy's HTML error page, and quoting it helps nobody.
        return strlen($raw) > 300 ? substr($raw, 0, 300) . '…' : $raw;
    }

    /**
     * What to do about a failed connection, in the words of whoever has to fix
     * it.
     */
    private static function hint(int $errno, int $connectTimeout): string
    {
        return match ($errno) {
            5, 6 => 'The host name could not be resolved. Check the address, and the DNS of this server.',
            7 => 'Nothing is listening on that host and port. Is the Gotenberg service running, and is the port published?',
            28 => sprintf(
                'The host did not answer within %d s. Check that it is reachable from this server, and raise the '
                . 'connect timeout only if the network is genuinely that slow.',
                $connectTimeout
            ),
            35, 51, 58, 60 => 'The TLS certificate was not accepted. Check the certificate, or turn off '
                . '"Verify TLS" for a self-signed one.',
            default => 'Check the address and that the service is running.',
        };
    }

    /**
     * The content type to claim for an uploaded file.
     *
     * Chromium sniffs a document's own resources by content, not by this
     * header, but a stylesheet that arrives as `text/plain` is refused by some
     * proxies, so the common ones are named properly.
     */
    private static function mime(string $name): string
    {
        return match (strtolower((string) pathinfo($name, PATHINFO_EXTENSION))) {
            'html', 'htm' => 'text/html',
            'css' => 'text/css',
            'js' => 'text/javascript',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'avif' => 'image/avif',
            'svg' => 'image/svg+xml',
            'bmp' => 'image/bmp',
            'ttf' => 'font/ttf',
            'otf' => 'font/otf',
            'woff' => 'font/woff',
            'woff2' => 'font/woff2',
            default => 'application/octet-stream',
        };
    }
}
