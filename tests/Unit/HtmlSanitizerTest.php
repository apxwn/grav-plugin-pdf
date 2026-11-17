<?php

declare(strict_types=1);

namespace Grav\Plugin\Pdf\Tests\Unit;

use DOMDocument;
use DOMElement;
use Grav\Plugin\Pdf\Engine\Gotenberg\HtmlSanitizer;
use PHPUnit\Framework\TestCase;

final class HtmlSanitizerTest extends TestCase
{
    public function testRemovesActiveContentResourceAttributesAndUnsafeLinks(): void
    {
        $html = HtmlSanitizer::sanitize(<<<'HTML'
<div class="print" id="section" onclick="alert(1)" style="background:url(https://evil.invalid/x)">
  <p>Visible<script>alert('script')</script><style>body{background:url(https://evil.invalid/css)}</style></p>
  <iframe src="https://evil.invalid/frame">frame text</iframe>
  <object data="https://evil.invalid/object">object text</object>
  <svg><image href="https://evil.invalid/vector" /></svg>
  <img src="https://evil.invalid/image.png" srcset="https://evil.invalid/2x.png 2x" onerror="alert(1)">
  <a href="javascript:alert(1)">unsafe link</a>
  <a href="file:///etc/passwd">local-file link</a>
  <a href="java&#x09;script:alert(1)">obfuscated link</a>
</div>
HTML);

        self::assertStringContainsString('class="print"', $html);
        self::assertStringContainsString('id="section"', $html);
        self::assertStringContainsString('Visible', $html);
        self::assertStringContainsString('unsafe link', $html);
        self::assertStringContainsString('local-file link', $html);
        self::assertStringContainsString('obfuscated link', $html);

        foreach ([
            'onclick', 'style=', 'script', 'evil.invalid', 'iframe', 'object', 'svg',
            'onerror', 'srcset', 'javascript:', 'file:',
        ] as $forbidden) {
            self::assertStringNotContainsString($forbidden, strtolower($html), $forbidden . ' must be removed.');
        }

        self::assertSame(3, substr_count($html, '<a>'), 'Unsafe links remain readable but lose their destinations.');
    }

    public function testPreservesPrintMarkupFragmentsAndSafeWebAndMailLinks(): void
    {
        $html = HtmlSanitizer::sanitize(<<<'HTML'
<article class="entry"><h2 id="notes">Notes</h2><p><strong>Emphasis</strong> <a href="#notes">jump</a> <a href="https://example.test/path">web</a> <a href="mailto:reader@example.test">mail</a></p><table><tbody><tr><th scope="col">Heading</th><td colspan="2">Cell</td></tr></tbody></table></article>
HTML);

        self::assertStringContainsString('<article class="entry">', $html);
        self::assertStringContainsString('<h2 id="notes">Notes</h2>', $html);
        self::assertStringContainsString('<strong>Emphasis</strong>', $html);
        self::assertStringContainsString('href="#notes"', $html);
        self::assertStringContainsString('href="https://example.test/path"', $html);
        self::assertStringContainsString('href="mailto:reader@example.test"', $html);
        self::assertStringContainsString('<th scope="col">Heading</th>', $html);
        self::assertStringContainsString('<td colspan="2">Cell</td>', $html);
    }

    public function testUnwrapsUnknownElementsButKeepsSanitizedChildren(): void
    {
        $html = HtmlSanitizer::sanitize('<p>before <custom-tag data-fetch="https://evil.invalid"><strong>kept</strong><img src="/user/pages/image.png" onload="bad()"></custom-tag> after</p>');

        self::assertStringContainsString('before', $html);
        self::assertStringContainsString('<strong>kept</strong>', $html);
        self::assertStringContainsString('<img src="/user/pages/image.png">', $html);
        self::assertStringContainsString('after', $html);
        self::assertStringNotContainsString('custom-tag', $html);
        self::assertStringNotContainsString('data-fetch', $html);
        self::assertStringNotContainsString('onload', $html);
    }

    public function testRejectsExternalAndDataImagesExceptTrustedRasterFurnitureDataUri(): void
    {
        $input = '<p><img src="/user/pages/local.png"><img src="//evil.invalid/x.png">'
            . '<img src="https://evil.invalid/x.png"><img src="file:///tmp/x.png">'
            . '<img src="data:image/png;base64,AA=="></p>';

        $ordinary = HtmlSanitizer::sanitize($input);
        self::assertStringContainsString('src="/user/pages/local.png"', $ordinary);
        self::assertSame(1, substr_count($ordinary, 'src='));

        $trusted = HtmlSanitizer::sanitize(
            '<div><img src="data:image/png;base64,AA==" onerror="bad()">'
                . '<img src="data:image/svg+xml;base64,PHN2Zy8+"></div>',
            true
        );
        self::assertStringContainsString('data:image/png;base64,AA==', $trusted);
        self::assertStringNotContainsString('onerror', $trusted);
        self::assertStringNotContainsString('data:image/svg+xml', $trusted);
    }

    public function testHandlesMalformedHtmlAndDoesNotTrustAnInputRootId(): void
    {
        $html = HtmlSanitizer::sanitize('<div id="pdf-sanitize-root"><p onclick="bad()">unfinished <b>markup');

        self::assertStringContainsString('unfinished', $html);
        self::assertStringContainsString('<b>markup</b>', $html);
        self::assertStringNotContainsString('onclick', $html);
    }
}
