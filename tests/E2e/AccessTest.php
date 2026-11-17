<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\E2e;

use Grav\Plugin\Pdf\Tests\GravSite;
use Grav\Plugin\Pdf\Tests\GravTestCase;

/**
 * Who may receive a document.
 *
 * A `.pdf` request is answered on `onPagesInitialized`, before Grav asks the
 * page to render, so the Login plugin's own authorization on
 * `onPageInitialized` never runs for it. Without a check in the PDF plugin a
 * document would be easier to get than the page it prints, and a book would
 * carry entries the visitor may not open at all.
 *
 * The fixture Login plugin (see `tests/fixtures/plugins/login`) answers the
 * question; these tests are about what the PDF plugin does with the answer.
 * They deliberately use the fixture rather than the real Login plugin: CI runs
 * against a bare Grav, and the contract being tested is the narrow one this
 * plugin depends on, not Login's accounts, sessions and redirects.
 */
final class AccessTest extends GravTestCase
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
        // Every test starts from the default document settings and a working
        // fixture Login plugin, whatever the previous one changed.
        self::$site->configure([]);
        self::loginPlugin(true);
    }

    /**
     * @param array<string, mixed> $extra
     */
    private static function loginPlugin(bool $enabled, array $extra = []): void
    {
        self::$site->configurePlugin('login', ['enabled' => $enabled] + $extra);
    }

    /**
     * @param string[] $headers
     * @return string[]
     */
    private static function asMember(array $headers = []): array
    {
        return array_merge(['X-PDFTest-Viewer: member'], $headers);
    }

    public function testAGuestIsRefusedAProtectedArticle(): void
    {
        $response = self::$site->get('/de/access/protected.pdf');

        self::assertSame(403, $response['status'], self::$site->serverLog());
        self::assertStringContainsString('Dieses Dokument steht Ihnen nicht zur Verfügung.', $response['body']);
        self::assertStringNotContainsString('%PDF', $response['body']);
        self::assertStringContainsString('no-store', strtolower($response['headers']['cache-control'] ?? ''));
        self::assertArrayNotHasKey('etag', $response['headers']);
        self::assertGreaterThan(
            0,
            self::$site->logCount('PDF: refused /access/protected for an anonymous visitor.'),
            self::$site->gravLog()
        );
    }

    public function testASignedInVisitorGetsTheProtectedArticle(): void
    {
        $response = self::$site->get('/de/access/protected.pdf', self::asMember());

        self::assertSame(200, $response['status'], self::$site->serverLog());
        self::assertStringStartsWith('%PDF', $response['body']);
        self::assertSame('php-pdf', $response['headers']['x-pdf-engine'] ?? null);
        self::assertSame(
            0,
            self::$site->logCount('PDF: refused /access/protected for a signed-in visitor.'),
            'A served document must not also be logged as refused.'
        );
    }

    public function testAPageThatDidNotOptInIsStillNotFoundEvenWithAccessRules(): void
    {
        $response = self::$site->get('/de/access/notopted.pdf');

        self::assertSame(404, $response['status'], self::$site->serverLog());
        self::assertStringNotContainsString('%PDF', $response['body']);
    }

    public function testAnAncestorsRuleIsHonouredWhenThePageAsksForParentAcl(): void
    {
        $guest = self::$site->get('/de/access/parent/child.pdf');
        self::assertSame(403, $guest['status'], self::$site->serverLog());

        $member = self::$site->get('/de/access/parent/child.pdf', self::asMember());
        self::assertSame(200, $member['status'], self::$site->serverLog());
        self::assertStringStartsWith('%PDF', $member['body']);
    }

    public function testParentAclFromThePluginConfigurationReachesTheAuthorizer(): void
    {
        self::loginPlugin(true, ['parent_acl' => true]);

        $guest = self::$site->get('/de/access/parent/plugin-child.pdf');
        self::assertSame(403, $guest['status'], self::$site->serverLog());

        $member = self::$site->get('/de/access/parent/plugin-child.pdf', self::asMember());
        self::assertSame(200, $member['status'], self::$site->serverLog());
    }

    public function testThePageLoginHeaderOverridesThePluginDefault(): void
    {
        self::loginPlugin(true, ['parent_acl' => true]);

        $guest = self::$site->get('/de/access/parent/override.pdf');

        self::assertSame(
            200,
            $guest['status'],
            'The page sets parent_acl: false, so its parent rule must not apply. ' . self::$site->serverLog()
        );
        self::assertStringStartsWith('%PDF', $guest['body']);
    }

    public function testThePageLoginHeaderIsPassedForASignedInVisitorToo(): void
    {
        $guest = self::$site->get('/de/access/deny.pdf');
        self::assertSame(403, $guest['status'], self::$site->serverLog());

        $member = self::$site->get('/de/access/deny.pdf', self::asMember());
        self::assertSame(403, $member['status'], 'The authorizer denied this page for everyone.');
        self::assertStringNotContainsString('%PDF', $member['body']);
    }

    public function testAnAuthorizerThatThrowsFailsClosed(): void
    {
        $response = self::$site->get('/de/access/fail.pdf', self::asMember());

        self::assertSame(403, $response['status'], self::$site->serverLog());
        self::assertStringNotContainsString('%PDF', $response['body']);
        self::assertGreaterThan(
            0,
            self::$site->logCount('PDF: page access could not be resolved'),
            'A listener that cannot answer must be logged, not silently treated as a yes.'
        );
    }

    public function testWithoutTheLoginPluginDeclaredAccessIsRefused(): void
    {
        self::loginPlugin(false);

        self::assertSame(
            403,
            self::$site->get('/de/access/protected.pdf', self::asMember())['status'],
            'With no Login plugin, nothing would protect the page, so the document must be refused.'
        );

        self::assertSame(
            403,
            self::$site->get('/de/access/parent/child.pdf')['status'],
            'The rule sits on the parent; the fallback has to walk up.'
        );

        self::assertSame(200, self::$site->get('/de/access/open.pdf')['status']);
    }

    public function testAnExplicitBookWithNoEntriesStillRenders(): void
    {
        $response = self::$site->get('/de/access/empty-book.pdf');

        self::assertSame(
            200,
            $response['status'],
            'A page declared as a book but holding no entries is empty, not refused. ' . self::$site->serverLog()
        );
        self::assertStringStartsWith('%PDF', $response['body']);
    }

    public function testMaxItemsCountsOnlyTheEntriesThisVisitorMayRead(): void
    {
        self::$site->configure(['max_items' => 2]);

        $guest = self::$site->get('/de/access/book.pdf');
        self::assertSame(
            200,
            $guest['status'],
            'The guest may read two entries, which is exactly the limit. ' . self::$site->serverLog()
        );

        $member = self::$site->get('/de/access/book.pdf', self::asMember());
        self::assertSame(
            413,
            $member['status'],
            'The member may read three entries, which is over the limit. ' . self::$site->serverLog()
        );
        self::assertStringContainsString('too large', $member['body']);
        self::assertStringNotContainsString('%PDF', $member['body']);
    }

    public function testTheAdvertisedCountIsTheReadableCount(): void
    {
        self::$site->configure(['show_count' => true]);

        $guest = self::$site->get('/de/access/book');
        self::assertSame(200, $guest['status'], self::$site->serverLog());
        self::assertMatchesRegularExpression(
            '/id="pdf-count">2</',
            $guest['body'],
            'The guest may read two of the three entries.'
        );

        $member = self::$site->get('/de/access/book', self::asMember());
        self::assertSame(200, $member['status'], self::$site->serverLog());
        self::assertMatchesRegularExpression(
            '/id="pdf-count">3</',
            $member['body'],
            'The signed-in visitor may read all three entries.'
        );
    }
}