<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests;

use RuntimeException;

/** A local HTTP endpoint that captures a Gotenberg multipart conversion. */
final class FakeGotenberg
{
    /** @var resource|null */
    private $process = null;

    private readonly string $capture;
    private readonly int $port;

    public function __construct()
    {
        $this->capture = PDF_TESTS_DIR . '/.run/gotenberg-' . bin2hex(random_bytes(6)) . '.multipart';
        $this->port = self::freePort();

        $environment = getenv();
        $environment['PDFTEST_GOTENBERG_CAPTURE'] = $this->capture;
        $router = PDF_TESTS_DIR . '/fixtures/gotenberg/router.php';

        $this->process = proc_open(
            [PHP_BINARY, '-d', 'enable_post_data_reading=0', '-S', '127.0.0.1:' . $this->port, $router],
            [
                0 => ['pipe', 'r'],
                1 => ['file', '/dev/null', 'a'],
                2 => ['file', '/dev/null', 'a'],
            ],
            $pipes,
            PDF_TESTS_DIR,
            $environment
        );

        if (!is_resource($this->process)) {
            throw new RuntimeException('Could not start the fake Gotenberg endpoint.');
        }

        if (isset($pipes[0]) && is_resource($pipes[0])) {
            fclose($pipes[0]);
        }

        $this->waitUntilListening();
    }

    public function url(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    public function capturedRequest(): string
    {
        return is_file($this->capture) ? (string) file_get_contents($this->capture) : '';
    }

    public function stop(): void
    {
        if (is_resource($this->process)) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }

        if (getenv('PDFTEST_KEEP') !== '1' && is_file($this->capture)) {
            @unlink($this->capture);
        }
    }

    public function __destruct()
    {
        $this->stop();
    }

    private function waitUntilListening(): void
    {
        $deadline = microtime(true) + 5.0;

        do {
            $socket = @fsockopen('127.0.0.1', $this->port, $errno, $error, 0.1);
            if (is_resource($socket)) {
                fclose($socket);

                return;
            }

            usleep(20_000);
        } while (microtime(true) < $deadline);

        $this->stop();
        throw new RuntimeException('The fake Gotenberg endpoint did not start.');
    }

    private static function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);

        if ($socket === false) {
            throw new RuntimeException('Could not reserve a port for the fake Gotenberg endpoint: ' . $error);
        }

        $name = stream_socket_get_name($socket, false);
        fclose($socket);

        return (int) substr((string) $name, strrpos((string) $name, ':') + 1);
    }
}
