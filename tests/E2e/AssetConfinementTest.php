<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\E2e;

use Grav\Plugin\Pdf\Tests\FakeGotenberg;
use Grav\Plugin\Pdf\Tests\GravSite;
use Grav\Plugin\Pdf\Tests\GravTestCase;

final class AssetConfinementTest extends GravTestCase
{
    private static ?GravSite $site = null;
    private static ?FakeGotenberg $gotenberg = null;
    private static string $outsideDir;
    private static string $outsideImage;
    private static string $article;
    private static string $originalArticle;

    public static function setUpBeforeClass(): void
    {
        self::$site = self::createSite();
        self::$gotenberg = new FakeGotenberg();

        self::$outsideDir = dirname(self::$site->rootDir()) . '/pdf-assets-outside-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir(self::$outsideDir));
        self::$outsideImage = self::$outsideDir . '/outside.png';
        self::writeRaster(self::$outsideImage);
        self::assertNotFalse(file_put_contents(self::$outsideDir . '/outside.ttf', 'test font placeholder'));

        self::$article = self::$site->rootDir() . '/user/pages/02.article/article.de.md';
        self::$originalArticle = (string) file_get_contents(self::$article);

        self::configure();
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$article, self::$originalArticle)) {
            file_put_contents(self::$article, self::$originalArticle);
            self::$site?->clearPageCache();
        }

        self::$gotenberg?->stop();
        self::$gotenberg = null;
        self::$site?->stop();
        self::$site = null;
        self::removeTree(self::$outsideDir);
    }

    public function testLocalFileRejectsLiteralAndEncodedTraversalFromPageContent(): void
    {
        $body = file_get_contents(self::$article);
        self::assertIsString($body);
        $relativeOutside = '/../' . basename(self::$outsideDir) . '/outside.png';
        $encodedOutside = '/' . rawurlencode('..') . '/' . basename(self::$outsideDir) . '/outside.png';
        $body .= "\n<img src=\"" . $relativeOutside . "\" alt=\"literal escape\">";
        $body .= "\n<img src=\"" . $encodedOutside . "\" alt=\"encoded escape\">\n";
        file_put_contents(self::$article, $body);
        self::$site->clearPageCache();
        self::$site->clearPdfCache();
        self::$gotenberg->clear();

        $response = self::$site->get('/de/article.pdf');
        $request = self::$gotenberg->capturedRequest();

        self::assertSame(200, $response['status'], self::$site->serverLog() . "\n" . self::$site->gravLog());
        self::assertNotSame('', $request, 'The safe document should still reach Gotenberg.');
        self::assertStringNotContainsString('literal escape', $request);
        self::assertStringNotContainsString('encoded escape', $request);
        self::assertStringNotContainsString(self::$outsideImage, $request);
        self::assertSame(1, self::imageUploadCount($request), 'Only the article’s known local image should be uploaded.');

        file_put_contents(self::$article, self::$originalArticle);
        self::$site->clearPageCache();
        self::$site->clearPdfCache();
    }

    public function testPageMediaSymlinkToOutsideFileIsNotUploaded(): void
    {
        $pageDir = dirname(self::$article);
        $link = $pageDir . '/escape.png';
        self::assertTrue(symlink(self::$outsideImage, $link));

        try {
            $body = str_replace("  images: true\n", "  images: true\n  gallery: true\n", self::$originalArticle);
            file_put_contents(self::$article, $body);
            self::$site->clearPageCache();
            self::$site->clearPdfCache();
            self::configure(['title' => 'Media symlink confinement']);
            self::$gotenberg->clear();

            $response = self::$site->get('/de/article.pdf');
            $request = self::$gotenberg->capturedRequest();

            self::assertSame(200, $response['status'], self::$site->serverLog() . "\n" . self::$site->gravLog());
            self::assertNotSame('', $request);
            self::assertStringNotContainsString(self::$outsideImage, $request);
            self::assertStringNotContainsString('escape.png', $request);
            self::assertSame(1, self::imageUploadCount($request), 'Only the article’s known local image should be uploaded.');
        } finally {
            @unlink($link);
            file_put_contents(self::$article, self::$originalArticle);
            self::$site->clearPageCache();
            self::$site->clearPdfCache();
        }
    }

    public function testOutsideConfiguredLogoIsNotUploaded(): void
    {
        self::configure([
            'logo' => self::$outsideImage,
            'header' => '{LOGO}',
            'title' => 'Outside logo confinement',
        ]);
        self::$site->clearPdfCache();
        self::$gotenberg->clear();

        $response = self::$site->get('/de/article.pdf');
        $request = self::$gotenberg->capturedRequest();

        self::assertSame(200, $response['status'], self::$site->serverLog() . "\n" . self::$site->gravLog());
        self::assertNotSame('', $request);
        self::assertStringNotContainsString(self::$outsideImage, $request);
        self::assertStringNotContainsString('data:image/png;base64,', $request);
        self::assertSame(1, self::imageUploadCount($request), 'Only the article’s known local image should be uploaded.');
    }

    public function testOutsideCustomFontIsRejectedBeforeRendering(): void
    {
        self::configure([
            'font_body' => 'EscapeFont',
            'fonts' => ['EscapeFont' => ['R' => self::$outsideDir . '/outside.ttf']],
            'title' => 'Outside font confinement',
        ]);
        self::$site->clearPdfCache();
        self::$gotenberg->clear();

        $response = self::$site->get('/de/article.pdf');

        self::assertNotSame(200, $response['status']);
        self::assertStringNotContainsString('%PDF', $response['body']);
        self::assertSame('', self::$gotenberg->capturedRequest(), 'Rejected custom fonts must not be uploaded.');
    }

    private static function writeRaster(string $path): void
    {
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+/pWQAAAAASUVORK5CYII=',
            true
        );

        self::assertNotFalse($png);
        self::assertNotFalse(file_put_contents($path, $png));
    }

    /** Keep the fake renderer endpoint present while varying test settings. */
    private static function configure(array $overrides = []): void
    {
        self::$site->configure(array_replace([
            'engine' => 'gotenberg',
            'gotenberg' => [
                'url' => self::$gotenberg->url(),
                'fallback' => false,
            ],
            'images' => true,
            'header' => '',
            'footer' => '',
        ], $overrides));
    }

    private static function imageUploadCount(string $request): int
    {
        preg_match_all('/Content-Disposition: form-data; name="files"; filename="img-[0-9]+\.png"/', $request, $matches);

        return count($matches[0]);
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
