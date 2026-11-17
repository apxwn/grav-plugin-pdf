<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\E2e;

use Grav\Plugin\Pdf\Tests\GravSite;
use Grav\Plugin\Pdf\Tests\GravTestCase;

/**
 * One document, many requests.
 *
 * The build is the expensive part, and every concurrent hit after an edit used
 * to start one. These tests pin down what a request is entitled to instead: the
 * document that is already on disk, or an answer asking it to come back.
 *
 * The "somebody is building it right now" cases are deterministic: the test
 * takes the same lock the plugin uses, so no timing is involved. Only the burst
 * test depends on a build being slow enough to overlap, and it says so.
 */
final class ConcurrencyTest extends GravTestCase
{
    private static ?GravSite $site = null;

    public static function setUpBeforeClass(): void
    {
        self::$site = self::createSite([], 200);
    }

    public static function tearDownAfterClass(): void
    {
        self::$site?->stop();
        self::$site = null;
    }

    public function testTheDocumentOnDiskIsServedWhileAnotherRequestRebuildsIt(): void
    {
        $site = self::$site;
        $site->configure(['cache_ttl' => 1]);

        $document = $site->get('/de/book.pdf');
        self::assertSame(200, $document['status'], $site->serverLog());

        $key = self::keyOf($document);

        // Past the ttl, so the next request finds the document stale and would
        // rebuild it — if the lock were free.
        sleep(2);

        $site->holdLock($key);

        try {
            $response = $site->get('/de/book.pdf');
        } finally {
            $site->releaseLock();
        }

        self::assertSame(200, $response['status'], $site->serverLog());
        self::assertStringStartsWith('%PDF', $response['body']);
        self::assertSame('"' . $key . '"', $response['headers']['etag'] ?? null);
        self::assertSame('php-pdf', $response['headers']['x-pdf-engine'] ?? null);
        self::assertGreaterThan(
            0,
            $site->logCount('served ' . $key),
            'The response should have been logged as served while the document was being rebuilt.'
        );
    }

    public function testARequestWithNothingToServeIsToldToComeBack(): void
    {
        $site = self::$site;
        $site->configure(['cache_ttl' => 86400]);

        $key = self::keyOf($site->get('/de/book.pdf'));

        // The key exists, but there is no complete document to hand out: this is
        // what a request finds right after an edit, when the fingerprint — and
        // so the key — is new.
        foreach ($site->pdfFiles() as $file) {
            self::assertTrue(unlink($file));
        }

        $site->holdLock($key);

        try {
            $response = $site->get('/de/book.pdf');
        } finally {
            $site->releaseLock();
        }

        self::assertSame(503, $response['status'], $site->serverLog());
        self::assertSame('5', $response['headers']['retry-after'] ?? null);
        self::assertStringContainsString('wird gerade erzeugt', $response['body']);
        self::assertStringNotContainsString('being generated', $response['body'], 'The message is not translated.');

        $recovered = $site->get('/de/book.pdf');
        self::assertSame(200, $recovered['status'], $site->serverLog());
        self::assertStringStartsWith('%PDF', $recovered['body']);
    }

    public function testABurstOfRequestsBuildsTheDocumentOnce(): void
    {
        $site = self::$site;
        $site->configure(['cache_ttl' => 86400]);

        $buildsBefore = $site->logCount('PDF: generated ');
        $filesBefore = count($site->pdfFiles());

        // A new fingerprint, so no document is on disk for the key the burst
        // will ask for.
        $site->touchBookItem(1);

        $responses = $site->parallel(array_fill(0, 4, '/de/book.pdf'));
        $statuses = array_column($responses, 'status');

        self::assertContains(200, $statuses, $site->serverLog());

        foreach ($statuses as $index => $status) {
            self::assertContains(
                $status,
                [200, 503],
                sprintf('Request %d answered %d; only a document or a retry is acceptable.', $index, $status)
            );
        }

        self::assertSame(
            1,
            $site->logCount('PDF: generated ') - $buildsBefore,
            'Exactly one build may run for one document.'
        );
        self::assertSame([], $site->partFiles(), 'No half-written file may be left behind.');
        self::assertSame(
            $filesBefore + 1,
            count($site->pdfFiles()),
            'The burst must produce one document, not one per request.'
        );

        if (max(array_column($responses, 'time')) > 0.5) {
            self::assertContains(
                503,
                $statuses,
                'The build took long enough to overlap, so the other requests should have been asked to come back.'
            );
        }

        $cached = $site->get('/de/book.pdf');
        self::assertSame(200, $cached['status']);
        self::assertLessThan(1.0, $cached['time'], 'The document is cached now; the next request must not rebuild it.');
    }
}
