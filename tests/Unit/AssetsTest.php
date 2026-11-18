<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\Unit;

use Grav\Plugin\Pdf\Engine\Gotenberg\Assets;
use Grav\Plugin\Pdf\Paths;
use PHPUnit\Framework\TestCase;

final class AssetsTest extends TestCase
{
    private static string $root;
    private static string $outside;

    public static function setUpBeforeClass(): void
    {
        self::$root = sys_get_temp_dir() . '/pdf-assets-root-' . bin2hex(random_bytes(6));
        self::$outside = sys_get_temp_dir() . '/pdf-assets-outside-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir(self::$root . '/user/pages/article', 0777, true));
        self::assertTrue(mkdir(self::$outside, 0777, true));

        if (!defined('GRAV_ROOT')) {
            define('GRAV_ROOT', self::$root);
        }
    }

    public static function tearDownAfterClass(): void
    {
        self::removeTree(self::$root);
        self::removeTree(self::$outside);
    }

    public function testUploadsLocalRasterOnceAndRewritesItsSource(): void
    {
        $file = self::$root . '/user/pages/article/pixel.png';
        file_put_contents($file, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pWQAAAAASUVORK5CYII=', true));
        $assets = new Assets();

        $html = $assets->images('<p><img src="' . $file . '" alt="pixel"><img src="' . $file . '"></p>');

        self::assertSame(1, $assets->count(), 'Repeated paths should result in one upload.');
        self::assertCount(1, $assets->all());
        self::assertStringContainsString('src="img-1.png"', $html);
        self::assertStringNotContainsString($file, $html);
        $uploaded = $assets->all();
        self::assertSame(realpath($file), reset($uploaded));
    }

    public function testDropsRemoteDataAndUnsupportedImageFiles(): void
    {
        $svg = self::$root . '/user/pages/article/vector.svg';
        $text = self::$root . '/user/pages/article/not-an-image.png';
        file_put_contents($svg, '<svg><image href="https://evil.invalid/asset"/></svg>');
        file_put_contents($text, 'not a raster image');
        $assets = new Assets();

        $html = $assets->images('<img src="https://evil.invalid/x.png"><img src="data:image/png;base64,AA==">'
            . '<img src="' . $svg . '"><img src="' . $text . '">');

        self::assertSame('', $html);
        self::assertSame([], $assets->all());
    }

    public function testOnlyTrustedFurnitureCanKeepAValidRasterDataUri(): void
    {
        $data = 'data:image/png;base64,AA==';
        $assets = new Assets();

        self::assertSame('<img src="' . $data . '">', $assets->images('<img src="' . $data . '">', true));
        self::assertSame([], $assets->all());
        self::assertSame('', $assets->images('<img src="' . $data . '">'));
    }

    public function testRejectsAssetsOutsideTheGravRootIncludingEscapingSymlinks(): void
    {
        $outsideFile = self::$outside . '/outside.png';
        file_put_contents($outsideFile, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pWQAAAAASUVORK5CYII=', true));
        $link = self::$root . '/user/pages/article/escape.png';
        symlink($outsideFile, $link);
        $assets = new Assets();

        self::assertSame('', $assets->images('<img src="' . $outsideFile . '"><img src="' . $link . '">'));
        self::assertSame([], $assets->all());
    }

    public function testWithinGravAcceptsLocalFilesAndDirectories(): void
    {
        $file = self::$root . '/user/pages/article/local.png';
        file_put_contents($file, 'local');

        self::assertSame(realpath($file), Paths::withinGrav($file));
        self::assertSame(realpath(dirname($file)), Paths::withinGrav(dirname($file), true));
    }

    public function testWithinGravRejectsTraversalAndSiblingPrefixPaths(): void
    {
        $outsideFile = self::$outside . '/outside.txt';
        file_put_contents($outsideFile, 'outside');

        $sibling = self::$root . '-sibling';
        mkdir($sibling);
        $siblingFile = $sibling . '/sibling.txt';
        file_put_contents($siblingFile, 'sibling');

        try {
            self::assertNull(Paths::withinGrav(self::$root . '/../' . basename(self::$outside) . '/outside.txt'));
            self::assertNull(Paths::withinGrav($siblingFile));
            self::assertNull(Paths::withinGrav(self::$outside, true));
        } finally {
            self::removeTree($sibling);
        }
    }

    public function testWithinGravRejectsSymlinksToOutsideFilesAndDirectories(): void
    {
        $outsideFile = self::$outside . '/linked.txt';
        file_put_contents($outsideFile, 'outside');

        $fileLink = self::$root . '/user/pages/article/linked-file.txt';
        $directoryLink = self::$root . '/user/pages/article/linked-directory';

        self::assertTrue(symlink($outsideFile, $fileLink));
        self::assertTrue(symlink(self::$outside, $directoryLink));

        try {
            self::assertNull(Paths::withinGrav($fileLink));
            self::assertNull(Paths::withinGrav($directoryLink, true));
        } finally {
            unlink($fileLink);
            unlink($directoryLink);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path . '/' . $entry;
            if (is_link($child) || is_file($child)) {
                unlink($child);
            } else {
                self::removeTree($child);
            }
        }

        rmdir($path);
    }
}
