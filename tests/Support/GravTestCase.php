<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests;

use PHPUnit\Framework\TestCase;

/**
 * Shared bootstrapping for the end-to-end classes.
 */
abstract class GravTestCase extends TestCase
{
    /**
     * Build and start a fixture site, or skip the class when the environment
     * cannot host one.
     *
     * @param array<string, mixed> $pluginConfig
     */
    protected static function createSite(array $pluginConfig = [], int $bookItems = 0): GravSite
    {
        if (!extension_loaded('curl')) {
            self::markTestSkipped('The end-to-end suite needs ext-curl.');
        }

        if (GravSite::discover() === null) {
            self::markTestSkipped(
                'No Grav installation found. Set GRAV_ROOT to a Grav 2.x checkout to run the end-to-end suite.'
            );
        }

        return GravSite::create($pluginConfig, $bookItems);
    }

    /**
     * The document key the response is filed under, from its `ETag`.
     *
     * @param array{status: int, headers: array<string, string>, body: string, time: float} $response
     */
    protected static function keyOf(array $response): string
    {
        $etag = trim($response['headers']['etag'] ?? '', '"');

        self::assertNotSame('', $etag, 'The response carries no ETag, so there is no key to test with.');

        return $etag;
    }
}
