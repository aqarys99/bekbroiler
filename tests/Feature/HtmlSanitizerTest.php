<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\HtmlSanitizer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\AuthTestCase;
use Tests\Support\NewsHelpers;

/** Белый список HtmlSanitizer: XSS-векторы, ссылки, img, идемпотентность (I-30). */
final class HtmlSanitizerTest extends AuthTestCase
{
    use NewsHelpers;

    private function clean(string $h): string
    {
        return HtmlSanitizer::clean($h);
    }

    // ---------------------------------------------------------------- теги

    public static function droppedTags(): array
    {
        $out = [];
        foreach (['script', 'style', 'iframe', 'object', 'embed', 'video', 'audio', 'form', 'textarea', 'select', 'button', 'svg', 'math', 'template', 'noscript'] as $t) {
            $out[$t] = [$t];
        }
        return $out;
    }

    #[DataProvider('droppedTags')]
    public function testDroppedTagsRemovedWithContent(string $tag): void
    {
        $out = $this->clean("<p>ok</p><$tag>SECRET-CONTENT</$tag><p>end</p>");
        $this->assertSame('<p>ok</p><p>end</p>', $out);
    }

    public function testVoidDroppedTags(): void
    {
        $out = $this->clean('<p>a</p><input name="x" value="SECRET"><meta http-equiv="refresh" content="0;url=//evil"><link rel="stylesheet" href="//evil"><p>b</p>');
        $this->assertSame('<p>a</p><p>b</p>', $out);
    }

    public function testCommentsAndConditionalCommentsRemoved(): void
    {
        $out = $this->clean('<p>a<!-- hidden --></p><!--[if IE]><script>alert(1)</script><![endif]--><p>b</p>');
        $this->assertSame('<p>a</p><p>b</p>', $out);
    }

    public function testUnknownTagsUnwrappedTextKept(): void
    {
        $out = $this->clean('<p><span style="color:red">a</span> <font color="red">b</font> <u>c</u> <table><tr><td>d</td></tr></table></p>');
        foreach (['span', 'font', '<u', 'table', '<td', 'style'] as $bad) {
            $this->assertStringNotContainsString($bad, $out);
        }
        foreach (['a', 'b', 'c', 'd'] as $text) {
            $this->assertStringContainsString($text, $out);
        }
    }

    public function testRenames(): void
    {
        $this->assertSame('<p><strong>x</strong> <em>y</em></p>', $this->clean('<p><b>x</b> <i>y</i></p>'));
        $this->assertSame('<p>t</p>', $this->clean('<div>t</div>'));
        foreach (['h1', 'h3', 'h4', 'h5', 'h6'] as $h) {
            $this->assertSame('<h2>t</h2>', $this->clean("<$h>t</$h>"), $h);
        }
        $this->assertSame('<h2>t</h2>', $this->clean('<h2>t</h2>'));
    }

    public function testListsAndBrAllowed(): void
    {
        $this->assertSame('<ul><li>a</li><li>b</li></ul>', $this->clean('<ul><li>a</li><li>b</li></ul>'));
        $this->assertSame('<ol><li>a</li></ol>', $this->clean('<ol><li>a</li></ol>'));
        $this->assertStringContainsString('<br>', $this->clean('<p>a<br>b</p>'));
    }

    // ---------------------------------------------------------------- атрибуты

    public function testEventHandlersAndAllAttributesStripped(): void
    {
        $out = $this->clean('<p onclick="alert(1)" style="x:y" class="c" id="i" data-x="1" onmouseover="a()">t</p>'
            . '<strong onfocus=alert(1) autofocus>s</strong><em ONCLICK="a()">e</em><li onclick=1>l</li>');
        $this->assertDoesNotMatchRegularExpression('/\son\w+\s*=/i', $out);
        foreach (['style', 'class', 'id=', 'data-x', 'autofocus'] as $bad) {
            $this->assertStringNotContainsString($bad, $out);
        }
        $this->assertStringStartsWith('<p>t</p><strong>s</strong><em>e</em>', $out);
    }

    public function testAnchorKeepsOnlyHref(): void
    {
        $out = $this->clean('<a href="https://a.kz/x" onclick="1" style="x" class="y" title="t" target="_self" rel="opener">l</a>');
        $this->assertSame('<a href="https://a.kz/x" target="_blank" rel="noopener noreferrer">l</a>', $out);
    }

    // ---------------------------------------------------------------- ссылки

    public static function badHrefs(): array
    {
        return [
            'javascript' => ['javascript:alert(1)'],
            'javascript upper' => ['JaVaScRiPt:alert(1)'],
            'tab inside' => ["java\tscript:alert(1)"],
            'newline inside' => ["java\nscript:alert(1)"],
            'entity tab' => ['java&#9;script:alert(1)'],
            'entity newline' => ['java&#10;script:alert(1)'],
            'entity colon' => ['javascript&colon;alert(1)'],
            'entity letters' => ['&#106;avascript:alert(1)'],
            'hex entity letters' => ['&#x6A;avascript:alert(1)'],
            'leading control' => ["\x01javascript:alert(1)"],
            'leading spaces' => ['   javascript:alert(1)'],
            'nul' => ["java\0script:alert(1)"],
            'data html' => ['data:text/html,<script>alert(1)</script>'],
            'data base64' => ['data:text/html;base64,PHNjcmlwdD5hbGVydCgxKTwvc2NyaXB0Pg=='],
            'vbscript' => ['vbscript:msgbox(1)'],
            'file' => ['file:///etc/passwd'],
            'ftp' => ['ftp://example.com/x'],
            'relative' => ['/admin/users'],
            'protocol relative' => ['//evil.example/x'],
            'anchor' => ['#top'],
            'empty' => [''],
            'tel' => ['tel:+77001234567'],
        ];
    }

    #[DataProvider('badHrefs')]
    public function testBadHrefUnwrapsLink(string $href): void
    {
        $out = $this->clean('<p>x <a href="' . $href . '">link text</a> y</p>');
        $this->assertStringNotContainsString('<a', $out);
        $this->assertStringNotContainsString('href', $out);
        $this->assertStringNotContainsStringIgnoringCase('javascript', $out);
        $this->assertStringContainsString('link text', $out);
    }

    public function testAnchorWithoutHrefUnwrapped(): void
    {
        $this->assertSame('<p>t</p>', $this->clean('<p><a name="x">t</a></p>'));
    }

    public function testGoodHrefs(): void
    {
        $http = $this->clean('<a href="http://a.kz">a</a>');
        $this->assertSame('<a href="http://a.kz" target="_blank" rel="noopener noreferrer">a</a>', $http);
        $https = $this->clean('<a href="HTTPS://A.KZ/p?x=1&y=2">a</a>');
        $this->assertStringContainsString('target="_blank"', $https);
        $this->assertStringContainsString('rel="noopener noreferrer"', $https);
        $mail = $this->clean('<a href="mailto:info@sadik.kz">m</a>');
        $this->assertSame('<a href="mailto:info@sadik.kz">m</a>', $mail);
        $this->assertStringNotContainsString('target', $mail);
    }

    public function testHrefWithSpacesAndQuotesIsEscaped(): void
    {
        $out = $this->clean('<a href="https://a.kz/&quot;onmouseover=&quot;alert(1)">x</a>');
        $this->assertDoesNotMatchRegularExpression('/\sonmouseover\s*=/i', $out);
    }

    public function testNestedLinkWithImageInside(): void
    {
        $id = $this->photo();
        $out = $this->clean('<a href="javascript:alert(1)"><img data-media-id="' . $id . '" src="x" onerror="alert(1)"></a>');
        $this->assertStringNotContainsString('<a', $out);
        $this->assertStringContainsString('data-media-id="' . $id . '"', $out);
        $this->assertStringNotContainsString('onerror', $out);
    }

    // ---------------------------------------------------------------- XSS-векторы

    public static function xssVectors(): array
    {
        return [
            'script' => ['<script>alert(1)</script>'],
            'script src' => ['<script src="//evil/x.js"></script>'],
            'img onerror' => ['<img src=x onerror=alert(1)>'],
            'svg onload' => ['<svg onload=alert(1)>'],
            'svg script' => ['<svg><script>alert(1)</script></svg>'],
            'svg use' => ['<svg><use href="data:image/svg+xml,<svg id=x xmlns=http://www.w3.org/2000/svg><script>alert(1)</script></svg>#x"/></svg>'],
            'iframe srcdoc' => ['<iframe srcdoc="<script>alert(1)</script>"></iframe>'],
            'iframe js' => ['<iframe src="javascript:alert(1)"></iframe>'],
            'object data' => ['<object data="javascript:alert(1)"></object>'],
            'embed' => ['<embed src="javascript:alert(1)">'],
            'body onload' => ['<body onload=alert(1)>'],
            'math' => ['<math><mi xlink:href="javascript:alert(1)">x</mi></math>'],
            'style tag' => ['<style>*{background:url(javascript:alert(1))}</style>'],
            'style attr' => ['<p style="background:url(javascript:alert(1))">x</p>'],
            'nested script' => ['<scr<script>ipt>alert(1)</scr</script>ipt>'],
            'uppercase script' => ['<SCRIPT>alert(1)</SCRIPT>'],
            'script with newline' => ["<script\n>alert(1)</script\n>"],
            'form action' => ['<form action="javascript:alert(1)"><button>x</button></form>'],
            'button formaction' => ['<button formaction="javascript:alert(1)">x</button>'],
            'meta refresh' => ['<meta http-equiv="refresh" content="0;url=javascript:alert(1)">'],
            'base' => ['<base href="javascript:alert(1)//">'],
            'a onclick nested' => ['<a href="https://a.kz" onclick="alert(1)">x</a>'],
            'entity-encoded tag' => ['&lt;script&gt;alert(1)&lt;/script&gt;'],
            'details ontoggle' => ['<details open ontoggle=alert(1)>x</details>'],
            'video onerror' => ['<video><source onerror="alert(1)"></video>'],
            'audio' => ['<audio src=x onerror=alert(1)>'],
            'noscript' => ['<noscript><p title="</noscript><img src=x onerror=alert(1)>"></noscript>'],
            'template' => ['<template><script>alert(1)</script></template>'],
            'comment break' => ['<!--><script>alert(1)</script>-->'],
            'cdata' => ['<![CDATA[<script>alert(1)</script>]]>'],
            'xml pi' => ['<?xml version="1.0"?><script>alert(1)</script>'],
            'xxe' => ['<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><p>&xxe;</p>'],
            'mxss backtick' => ['<p title="`onmouseover=alert(1)">x</p>'],
            'attribute breakout' => ['<p title="x" onclick="alert(1)" a=">">x</p>'],
        ];
    }

    #[DataProvider('xssVectors')]
    public function testXssVectorsNeverSurvive(string $vector): void
    {
        $out = $this->clean('<p>safe</p>' . $vector);
        $this->assertDoesNotMatchRegularExpression('/<\s*(script|iframe|object|embed|svg|math|style|form|button|meta|link|base|video|audio|details|template|noscript)\b/i', $out);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+\s*=/i', $out);
        $this->assertDoesNotMatchRegularExpression('/\bstyle\s*=/i', $out);
        $this->assertStringNotContainsStringIgnoringCase('javascript:', $out);
        $this->assertStringNotContainsString('root:', $out);
        $this->assertStringContainsString('safe', $out);
        // повторная очистка ничего не меняет, а результат не содержит ни одного запрещённого элемента
        $this->assertSame($out, $this->clean($out));
    }

    public function testEncodedTagsStayText(): void
    {
        $out = $this->clean('<p>&lt;script&gt;alert(1)&lt;/script&gt;</p>');
        $this->assertStringNotContainsString('<script', $out);
        $this->assertStringContainsString('&lt;script&gt;', $out);
    }

    public function testNoExternalEntityResolved(): void
    {
        $out = $this->clean('<!DOCTYPE foo [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><p>&xxe;</p>');
        $this->assertStringNotContainsString('root:', $out);
    }

    // ---------------------------------------------------------------- img

    public function testImgOnlyWithExistingImageId(): void
    {
        $id = $this->photo();
        $doc = $this->photo('file');
        $out = $this->clean('<p>a</p><img data-media-id="' . $id . '" src="http://evil/x.png" alt="a" style="x" onerror="1" width="9">');
        $this->assertSame('<p>a</p><img data-media-id="' . $id . '">', $out);
        $this->assertSame('<p>a</p>', $this->clean('<p>a</p><img data-media-id="9999">'), 'чужой/несуществующий id');
        $this->assertSame('<p>a</p>', $this->clean('<p>a</p><img data-media-id="' . $doc . '">'), 'не фото');
        foreach (['<img src="http://evil/x.png">', '<img data-media-id="0">', '<img data-media-id="-1">', '<img data-media-id="abc">',
            '<img data-media-id="">', '<img data-media-id="1e3">', '<img data-media-id="' . $id . ' OR 1=1">', '<img src="data:image/png;base64,AAAA">'] as $bad) {
            $this->assertSame('<p>a</p>', $this->clean('<p>a</p>' . $bad), $bad);
        }
    }

    public function testImgSrcAlwaysDroppedEvenWhenIdValid(): void
    {
        $id = $this->photo();
        $out = $this->clean('<img src="javascript:alert(1)" data-media-id="' . $id . '" srcset="x 1x" onload="1">');
        $this->assertStringNotContainsString('src', $out);
        $this->assertStringNotContainsString('onload', $out);
    }

    // ---------------------------------------------------------------- пустое и идемпотентность

    public function testEmptyBodies(): void
    {
        foreach (['', '   ', "\n", '<p></p>', '<p><br></p>', '<p> </p><p>&nbsp;</p>', '<script>1</script>', '<!-- c -->', '<iframe src="x"></iframe>', '<div><br></div>'] as $e) {
            $this->assertSame('', $this->clean($e), var_export($e, true));
        }
    }

    public function testTrailingEmptyParagraphsTrimmed(): void
    {
        $this->assertSame('<p>a</p>', $this->clean('<p>a</p><p></p><p><br></p>'));
    }

    public function testBodyWithOnlyImageIsNotEmpty(): void
    {
        $id = $this->photo();
        $this->assertSame('<p><img data-media-id="' . $id . '"></p>', $this->clean('<p><img data-media-id="' . $id . '"></p>'));
    }

    public function testNulBytesRemoved(): void
    {
        $this->assertStringNotContainsString("\0", $this->clean("<p>a\0b</p>"));
    }

    public function testUnicodeSurvives(): void
    {
        $this->assertSame('<p>Қазақша әғқңөұүһі — тест «ёж»</p>', $this->clean('<p>Қазақша әғқңөұүһі — тест «ёж»</p>'));
    }

    public function testBrokenMarkupIsRepaired(): void
    {
        $out = $this->clean('<p>a<strong>b<em>c</p><li>x');
        $this->assertSame($out, $this->clean($out));
        $this->assertSame(substr_count($out, '<strong>'), substr_count($out, '</strong>'));
    }

    public function testIdempotentOnMixedInput(): void
    {
        $id = $this->photo();
        $in = '<div><b>x</b> <i>y</i> <a href="https://a.kz?a=1&b=2">l</a> <a href="mailto:a@b.kz">m</a><h1>h</h1><span>s</span>'
            . '<ul><li>1</li><li>2<br>3</li></ul><img data-media-id="' . $id . '" src=x><script>1</script></div><p><br></p>';
        $once = $this->clean($in);
        $this->assertSame($once, $this->clean($once));
        $this->assertSame($once, $this->clean($this->clean($once)));
    }

    /** div->p с блочным содержимым внутри: вторая очистка не должна менять результат. */
    public function testIdempotentWithBlocksInsideDiv(): void
    {
        $once = $this->clean('<div>a<h1>h</h1><ul><li>1</li></ul></div>');
        $this->assertSame($once, $this->clean($once));
    }

    public function testLargeInputDoesNotFail(): void
    {
        $out = $this->clean(str_repeat('<p>абв <b>x</b></p>', 5000));
        $this->assertStringStartsWith('<p>абв <strong>x</strong></p>', $out);
    }
}
