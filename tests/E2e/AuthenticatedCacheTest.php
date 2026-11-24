<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\E2e;

use Grav\Plugin\Pdf\Tests\GravSite;
use Grav\Plugin\Pdf\Tests\GravTestCase;

/**
 * The shared document cache and the visitors it may be shared with.
 *
 * Page content is rendered for whoever asked: Twig and shortcodes can show one
 * visitor something another must not see, and Grav's own content cache refuses
 * those renders for exactly that reason. A guest document is therefore the only
 * one that may be handed to somebody else, and these tests pin down that a
 * signed-in request neither reads nor writes the disk cache — and that doing so
 * leaves the guest's document untouched.
 */
final class AuthenticatedCacheTest extends GravTestCase
{
    private static ?GravSite $site = null;

    public static function setUpBeforeClass(): void
    {
        self::$site = self::createSite();
    }

    public static function tearDownAfterClass(): void
    {
        self::$site?->stop();
        self::$site = null;
    }

    protected function setUp(): void
    {
        self::$site->configure([]);
        self::$site->configurePlugin('login', ['enabled' => true]);
    }

    /**
     * @param string[] $headers
     * @return string[]
     */
    private static function asMember(array $headers = []): array
    {
        return array_merge(['X-PDFTest-Viewer: member'], $headers);
    }

    /** @return string[] */
    private static function asAccount(string $username): array
    {
        return ['X-PDFTest-Viewer: member:' . $username];
    }

    public function testAGuestDocumentIsCachedAndCarriesValidators(): void
    {
        $response = self::$site->get('/de/access/open.pdf');

        self::assertSame(200, $response['status'], self::$site->serverLog());
        self::assertStringStartsWith('%PDF', $response['body']);
        self::assertArrayHasKey('etag', $response['headers']);
        self::assertArrayHasKey('last-modified', $response['headers']);
        self::assertStringContainsString('must-revalidate', $response['headers']['cache-control'] ?? '');
        self::assertNotSame([], self::$site->pdfFiles(), 'A guest document belongs in the shared cache.');
    }

    public function testASignedInRequestIsNotAnsweredFromTheSharedCache(): void
    {
        $guest = self::$site->get('/de/access/open.pdf');
        $key = self::keyOf($guest);
        $files = count(self::$site->pdfFiles());

        $member = self::$site->get(
            '/de/access/open.pdf',
            self::asMember(['If-None-Match: "' . $key . '"'])
        );

        self::assertSame(
            200,
            $member['status'],
            'A signed-in request must not be answered with 304 from a guest document.'
        );
        self::assertStringStartsWith('%PDF', $member['body']);
        self::assertArrayNotHasKey('etag', $member['headers']);
        self::assertArrayNotHasKey('last-modified', $member['headers']);
        self::assertStringContainsString('no-store', strtolower($member['headers']['cache-control'] ?? ''));
        self::assertSame('php-pdf', $member['headers']['x-pdf-engine'] ?? null);
        self::assertCount($files, self::$site->pdfFiles(), 'A signed-in request must not write a document.');
        self::assertSame(
            [],
            self::$site->partFiles(),
            'The temporary document must be removed after it has been streamed.'
        );
    }

    public function testASignedInRequestBuildsEveryTime(): void
    {
        $builds = self::$site->logCount('PDF: generated ');

        self::$site->get('/de/access/open.pdf', self::asMember());
        self::$site->get('/de/access/open.pdf', self::asMember());

        self::assertSame(
            2,
            self::$site->logCount('PDF: generated ') - $builds,
            'There is nothing on disk for this visitor to reuse.'
        );
        self::assertSame([], self::$site->partFiles());
        self::assertGreaterThan(
            0,
            self::$site->logCount('not cached for a signed-in visitor'),
            'The log should say that the document was not cached.'
        );
    }

    public function testAnAccountCanOnlyHaveOnePrivateBuildAtATimeEvenWhenCacheIsDisabled(): void
    {
        self::$site->configure(['cache' => false]);
        $builds = self::$site->logCount('PDF: generated ');
        self::$site->holdAccountLock('alice');

        try {
            // The lock is account-wide: an in-progress build of any document
            // prevents this account from starting a book build as well.
            $alice = self::$site->get('/de/article.pdf', self::asAccount('Alice'));
        } finally {
            self::$site->releaseLock();
        }

        self::assertSame(503, $alice['status'], self::$site->serverLog());
        self::assertSame('5', $alice['headers']['retry-after'] ?? null);
        self::assertSame($builds, self::$site->logCount('PDF: generated '));

        // A different account has a different lock and can proceed in parallel.
        $bob = self::$site->get('/de/access/open.pdf', self::asAccount('bob'));
        self::assertSame(200, $bob['status'], self::$site->serverLog());
        self::assertStringStartsWith('%PDF', $bob['body']);
        self::assertStringContainsString('no-store', strtolower($bob['headers']['cache-control'] ?? ''));
        self::assertSame([], self::$site->partFiles());
    }

    public function testTheGuestDocumentSurvivesASignedInRequestUnchanged(): void
    {
        $guest = self::$site->get('/de/access/open.pdf');
        $key = self::keyOf($guest);

        $files = (array) glob(self::$site->cacheDir() . '/' . $key . '.*.pdf');
        self::assertNotSame([], $files, 'The guest document should be in the shared cache.');
        $cached = (string) reset($files);
        clearstatcache(true, $cached);
        $modified = (int) filemtime($cached);

        self::$site->get('/de/access/open.pdf', self::asMember());

        clearstatcache(true, $cached);
        self::assertSame($modified, (int) filemtime($cached), 'A signed-in build must not rewrite the guest document.');

        $again = self::$site->get('/de/access/open.pdf', ['If-None-Match: "' . $key . '"']);

        self::assertSame(304, $again['status'], 'The guest document is still there and still valid.');
        self::assertSame('"' . $key . '"', $again['headers']['etag'] ?? null);
    }

    public function testAnAbortedSignedInDownloadLeavesNoPrivateFile(): void
    {
        $article = self::$site->rootDir() . '/user/pages/02.article/article.de.md';
        $original = (string) file_get_contents($article);
        $image = self::$site->writeLargeImage('user/pages/02.article/abort.png');

        try {
            // The raster makes the PDF big enough that the client is gone while
            // the server is still writing it, rather than after readfile() has
            // already returned and its normal cleanup has run.
            file_put_contents($article, $original . "\n\n![large](/user/pages/02.article/abort.png)\n");
            self::$site->clearPageCache();
            self::$site->clearPdfCache();

            $builds = self::$site->logCount('PDF: generated ');
            $files = count(self::$site->pdfFiles());
            $result = self::$site->abortDownload('/de/article.pdf', self::asMember());

            self::assertSame(CURLE_WRITE_ERROR, $result['errno'], self::$site->serverLog());
            self::assertGreaterThan(0, $result['bytes'], 'The client must have received body bytes before aborting.');
            self::assertGreaterThan(
                $builds,
                self::$site->logCount('PDF: generated '),
                'The aborted request must have reached the private build.'
            );

            $deadline = microtime(true) + 10.0;

            do {
                $left = self::$site->partFiles();

                if ($left === []) {
                    break;
                }

                usleep(100_000);
            } while (microtime(true) < $deadline);

            self::assertSame(
                [],
                $left,
                'An aborted private download must not leave its render in the cache. ' . self::$site->serverLog()
            );
            self::assertCount($files, self::$site->pdfFiles(), 'An aborted download must not write a shared document.');
        } finally {
            file_put_contents($article, $original);
            @unlink($image);
            self::$site->clearPageCache();
        }
    }
}
