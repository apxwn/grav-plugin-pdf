<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\E2e;

use Grav\Plugin\Pdf\Tests\GravSite;
use Grav\Plugin\Pdf\Tests\GravTestCase;

/**
 * The cache as a visitor sees it: a miss is built, a hit is not, and a
 * conditional request is answered with 304.
 */
final class CacheTest extends GravTestCase
{
    private static ?GravSite $site = null;

    public static function setUpBeforeClass(): void
    {
        // An article, not a book: these tests are about the cache, and a book
        // would only make them slower.
        self::$site = self::createSite();
    }

    public static function tearDownAfterClass(): void
    {
        self::$site?->stop();
        self::$site = null;
    }

    public function testAMissIsBuiltOnceAndCarriesValidators(): void
    {
        $site = self::$site;
        $site->configure([]);

        $miss = $site->get('/de/article.pdf');

        self::assertSame(200, $miss['status'], $site->serverLog());
        self::assertStringStartsWith('%PDF', $miss['body']);
        self::assertSame('php-pdf', $miss['headers']['x-pdf-engine'] ?? null);
        self::assertStringContainsString('must-revalidate', $miss['headers']['cache-control'] ?? '');
        self::assertArrayHasKey('last-modified', $miss['headers']);

        $key = self::keyOf($miss);

        $files = $site->pdfFiles();
        self::assertCount(1, $files);
        $file = $files[0];
        clearstatcache(true, $file);
        $mtime = (int) filemtime($file);
        $builds = $site->logCount('PDF: generated ');

        $hit = $site->get('/de/article.pdf');

        self::assertSame(200, $hit['status']);
        self::assertSame('"' . $key . '"', $hit['headers']['etag'] ?? null);
        self::assertSame($builds, $site->logCount('PDF: generated '), 'A cache hit must not build the document again.');
        clearstatcache(true, $file);
        self::assertSame($mtime, (int) filemtime($file), 'A cache hit must not rewrite the document.');
    }

    public function testAConditionalRequestIsAnsweredWith304(): void
    {
        $site = self::$site;
        $first = $site->get('/de/article.pdf');
        $key = self::keyOf($first);

        $response = $site->get('/de/article.pdf', ['If-None-Match: "' . $key . '"']);

        self::assertSame(304, $response['status']);
        self::assertSame('', $response['body']);
        self::assertSame('"' . $key . '"', $response['headers']['etag'] ?? null);
        self::assertSame('php-pdf', $response['headers']['x-pdf-engine'] ?? null);
    }

    public function testAPageThatDidNotOptInIsNotFound(): void
    {
        $response = self::$site->get('/de/home.pdf');

        self::assertSame(404, $response['status']);
        self::assertStringNotContainsString('%PDF', $response['body']);
    }

    public function testTheCacheCanBeTurnedOff(): void
    {
        $site = self::$site;
        $site->configure(['cache' => false]);

        $builds = $site->logCount('PDF: generated ');

        $first = $site->get('/de/article.pdf');
        $second = $site->get('/de/article.pdf');

        self::assertSame(200, $first['status'], $site->serverLog());
        self::assertSame(200, $second['status'], $site->serverLog());
        self::assertSame(
            2,
            $site->logCount('PDF: generated ') - $builds,
            'With the cache off, every request builds its own document.'
        );

        $site->configure(['cache' => true]);
    }
}
