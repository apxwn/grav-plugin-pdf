<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\E2e;

use Grav\Plugin\Pdf\Tests\GravSite;
use Grav\Plugin\Pdf\Tests\GravTestCase;

/**
 * The guards around a build: `build_timeout` and the sweep that removes what a
 * killed request could not.
 */
final class GuardsTest extends GravTestCase
{
    private static ?GravSite $site = null;

    public static function setUpBeforeClass(): void
    {
        // Big enough that a one-second limit actually bites. On this machine a
        // 120-item book renders in about half a second.
        self::$site = self::createSite(['max_items' => 0], 600);
    }

    public static function tearDownAfterClass(): void
    {
        self::$site?->stop();
        self::$site = null;
    }

    public function testABuildThatRunsTooLongIsCutShortWithoutADocument(): void
    {
        $site = self::$site;
        $site->configure(['build_timeout' => 1, 'max_items' => 0]);

        $before = $site->pdfFiles();

        $response = $site->get('/de/book.pdf');

        self::assertNotSame(200, $response['status'], 'The build should have been killed by its time limit.');
        self::assertStringNotContainsString('%PDF', $response['body']);
        self::assertStringContainsString(
            'Maximum execution time',
            $site->gravLog(),
            'The request should have been killed by build_timeout, not rejected for another reason.'
        );
        self::assertCount(
            count($before),
            $site->pdfFiles(),
            'A build that was cut short must not leave a document behind.'
        );
    }

    public function testTheNextRequestIsNotBlockedAndSweepsTheLeftovers(): void
    {
        $site = self::$site;
        $site->configure(['build_timeout' => 0, 'max_items' => 0]);

        // A scratch file from a request that died an hour ago. The one the test
        // above left behind is younger than the sweep's one-hour threshold and
        // stays until it is old enough; this is about the sweep itself.
        $abandoned = $site->cacheDir() . '/.deadbeef.part';
        file_put_contents($abandoned, 'partial');
        touch($abandoned, time() - 2 * 3600);

        $response = $site->get('/de/book.pdf');

        self::assertSame(200, $response['status'], $site->serverLog() . "\n" . $site->gravLog());
        self::assertStringStartsWith('%PDF', $response['body']);
        self::assertFileDoesNotExist($abandoned, 'The sweep after a build removes abandoned scratch files.');
    }
}
