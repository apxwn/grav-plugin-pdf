<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\E2e;

use Grav\Plugin\Pdf\Tests\FakeGotenberg;
use Grav\Plugin\Pdf\Tests\GravSite;
use Grav\Plugin\Pdf\Tests\GravTestCase;

/**
 * What a book is allowed to contain.
 *
 * The access test answers whether a page may be exported at all; this one is
 * about the collection a book is built from. The listing page being readable
 * says nothing about its children, so a book has to be assembled from the
 * entries *this* visitor may read — and must not quietly become a PDF of the
 * listing page when that leaves nothing.
 *
 * The document is inspected at the Gotenberg boundary: the fake endpoint
 * records the multipart body it received, so the entries that went into the
 * document can be checked without a PDF text extractor.
 */
final class AccessBookTest extends GravTestCase
{
    private static ?GravSite $site = null;
    private static ?FakeGotenberg $gotenberg = null;

    public static function setUpBeforeClass(): void
    {
        self::$site = self::createSite();
        self::$gotenberg = new FakeGotenberg();
    }

    public static function tearDownAfterClass(): void
    {
        self::$gotenberg?->stop();
        self::$gotenberg = null;
        self::$site?->stop();
        self::$site = null;
    }

    protected function setUp(): void
    {
        self::$site->configure([
            'engine' => 'gotenberg',
            'gotenberg' => [
                'url' => self::$gotenberg->url(),
                'fallback' => false,
                'timeout' => 10,
                'connect_timeout' => 2,
            ],
        ]);
        self::$site->configurePlugin('login', ['enabled' => true]);

        self::$gotenberg->clear();
    }

    public function testABookCarriesOnlyTheEntriesAGuestMayRead(): void
    {
        $response = self::$site->get('/de/access/book.pdf');

        self::assertSame(200, $response['status'], self::$site->serverLog() . "\n" . self::$site->gravLog());
        self::assertSame('gotenberg', $response['headers']['x-pdf-engine'] ?? null);

        $html = self::multipartHtml(self::$gotenberg->capturedRequest());
        self::assertArrayHasKey('index.html', $html, 'The endpoint received no document.');

        self::assertStringContainsString('PDFTEST-BOOK-PUBLIC-ONE', $html['index.html']);
        self::assertStringContainsString('PDFTEST-BOOK-PUBLIC-TWO', $html['index.html']);
        self::assertStringNotContainsString(
            'PDFTEST-BOOK-PROTECTED',
            $html['index.html'],
            'A protected entry must not reach a public book.'
        );
    }

    public function testASignedInVisitorGetsTheWholeBook(): void
    {
        $response = self::$site->get('/de/access/book.pdf', ['X-PDFTest-Viewer: member']);

        self::assertSame(200, $response['status'], self::$site->serverLog() . "\n" . self::$site->gravLog());

        $html = self::multipartHtml(self::$gotenberg->capturedRequest());
        self::assertArrayHasKey('index.html', $html, 'The endpoint received no document.');

        self::assertStringContainsString('PDFTEST-BOOK-PUBLIC-ONE', $html['index.html']);
        self::assertStringContainsString('PDFTEST-BOOK-PUBLIC-TWO', $html['index.html']);
        self::assertStringContainsString('PDFTEST-BOOK-PROTECTED', $html['index.html']);
    }

    public function testABookWithNothingReadableIsRefusedNotDowngraded(): void
    {
        $guest = self::$site->get('/de/access/locked.pdf');

        self::assertSame(403, $guest['status'], self::$site->serverLog());
        self::assertStringNotContainsString('%PDF', $guest['body']);
        self::assertSame(
            '',
            self::$gotenberg->capturedRequest(),
            'A refused book must not be rendered first and withheld afterwards.'
        );

        $member = self::$site->get('/de/access/locked.pdf', ['X-PDFTest-Viewer: member']);
        self::assertSame(200, $member['status'], self::$site->serverLog());
        self::assertStringStartsWith('%PDF', $member['body']);
    }
}