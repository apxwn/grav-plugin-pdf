<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\E2e;

use Grav\Plugin\Pdf\Tests\FakeGotenberg;
use Grav\Plugin\Pdf\Tests\GravSite;
use Grav\Plugin\Pdf\Tests\GravTestCase;

final class GotenbergSanitizationTest extends GravTestCase
{
    private static ?GravSite $site = null;
    private static ?FakeGotenberg $gotenberg = null;

    public static function setUpBeforeClass(): void
    {
        self::$site = self::createSite();
        self::$gotenberg = new FakeGotenberg();

        self::$site->configure([
            'engine' => 'gotenberg',
            'gotenberg' => [
                'url' => self::$gotenberg->url(),
                'fallback' => false,
            ],
            'images' => true,
            'header' => '<div class="test-header" onclick="bad()" style="color:red">'
                . '<a href="https://safe.example.test/header">header link</a>'
                . '<img src="/user/pages/02.article/pixel.png" alt="header pixel"></div>',
            'footer' => '<div class="test-footer" onmouseover="bad()">footer text</div>',
        ]);
    }

    public static function tearDownAfterClass(): void
    {
        self::$gotenberg?->stop();
        self::$gotenberg = null;
        self::$site?->stop();
        self::$site = null;
    }

    public function testSanitizedPageAndFurnitureAreUploadedToTheConfiguredEndpoint(): void
    {
        $site = self::$site;
        $response = $site->get('/de/article.pdf');
        $request = self::$gotenberg->capturedRequest();

        self::assertSame(200, $response['status'], $site->serverLog() . "\n" . $site->gravLog());
        self::assertStringStartsWith('%PDF', $response['body']);
        self::assertSame('gotenberg', $response['headers']['x-pdf-engine'] ?? null);
        self::assertNotSame('', $request, 'The configured endpoint must receive a conversion request.');

        $htmlParts = [];
        preg_match_all(
            '/Content-Disposition: form-data; name="files"; filename="([^"]+)"\r\n'
            . 'Content-Type: text\/html\r\n\r\n(.*?)\r\n--/s',
            $request,
            $matches,
            PREG_SET_ORDER
        );
        foreach ($matches as $match) {
            $htmlParts[$match[1]] = $match[2];
        }

        self::assertArrayHasKey('index.html', $htmlParts);
        self::assertArrayHasKey('header.html', $htmlParts);
        self::assertArrayHasKey('footer.html', $htmlParts);

        $uploadedHtml = implode("\n", $htmlParts);
        foreach ($htmlParts as $filename => $part) {
            $dom = new \DOMDocument();
            $dom->loadHTML($part, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
            $xpath = new \DOMXPath($dom);

            self::assertSame(0, $xpath->query('//@*[starts-with(translate(name(), "ON", "on"), "on") or name()="style"]')->length, $filename . ' contains active attributes.');
            self::assertSame(0, $xpath->query('//script | //iframe | //svg | //object | //embed')->length, $filename . ' contains active/embedded elements.');
            self::assertSame(0, $xpath->query('//a[starts-with(translate(@href, "JAVASCRIPT:", "javascript:"), "javascript:")]')->length, $filename . ' contains an unsafe link.');
            self::assertSame(0, $xpath->query('//img[starts-with(@src, "http:") or starts-with(@src, "https:") or starts-with(@src, "//") or starts-with(@src, "data:image/svg+xml")]')->length, $filename . ' contains an external or SVG image.');
        }
        self::assertStringNotContainsString('data:image/svg+xml', $uploadedHtml);

        self::assertStringContainsString('href="#fixture-anchor"', $htmlParts['index.html']);
        self::assertStringContainsString('href="https://safe.example.test/content"', $htmlParts['index.html']);
        self::assertStringContainsString('href="https://safe.example.test/header"', $htmlParts['header.html']);
        self::assertStringContainsString('Fixture content', $htmlParts['index.html']);
        self::assertStringContainsString('header link', $htmlParts['header.html']);
        self::assertStringContainsString('footer text', $htmlParts['footer.html']);
        self::assertMatchesRegularExpression('/filename="img-[0-9]+\.png"/', $request);
        self::assertMatchesRegularExpression('/src="img-[0-9]+\.png"/', $htmlParts['index.html']);
    }
}
