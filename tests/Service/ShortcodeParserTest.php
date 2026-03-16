<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Tests\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PsychedCms\Shortcode\Service\ContentTypeRegistryInterface;
use PsychedCms\Shortcode\Service\ShortcodeParser;

class ShortcodeParserTest extends TestCase
{
    private ShortcodeParser $parser;
    private ContentTypeRegistryInterface&MockObject $registry;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ContentTypeRegistryInterface::class);

        $this->registry->method('isSupported')
            ->willReturnCallback(fn (string $type) => in_array($type, [
                'bands', 'releases', 'events', 'venues', 'tours', 'sets',
                'reviews', 'event-reports', 'organizations', 'festivals', 'labels', 'posts',
            ], true));

        $this->parser = new ShortcodeParser($this->registry);
    }

    public function testBasicSyntaxExtractsTypeAndSlug(): void
    {
        $html = 'Check out [[bands:graveyard]] live!';

        $result = $this->parser->parse($html);

        $this->assertCount(1, $result);
        $this->assertEquals('bands', $result[0]['type']);
        $this->assertEquals('graveyard', $result[0]['slug']);
        $this->assertNull($result[0]['customLabel']);
        $this->assertNull($result[0]['altText']);
        $this->assertEquals('[[bands:graveyard]]', $result[0]['raw']);
    }

    public function testCustomLabelExtraction(): void
    {
        $html = 'Check out [[bands:graveyard|The Swedish Doomsters]] at the show!';

        $result = $this->parser->parse($html);

        $this->assertCount(1, $result);
        $this->assertEquals('bands', $result[0]['type']);
        $this->assertEquals('graveyard', $result[0]['slug']);
        $this->assertEquals('The Swedish Doomsters', $result[0]['customLabel']);
        $this->assertNull($result[0]['altText']);
    }

    public function testLabelAndAltTextExtraction(): void
    {
        $html = 'Check out [[bands:graveyard|The Swedish Doomsters|Doom metal band from Sweden]] live!';

        $result = $this->parser->parse($html);

        $this->assertCount(1, $result);
        $this->assertEquals('bands', $result[0]['type']);
        $this->assertEquals('graveyard', $result[0]['slug']);
        $this->assertEquals('The Swedish Doomsters', $result[0]['customLabel']);
        $this->assertEquals('Doom metal band from Sweden', $result[0]['altText']);
    }

    public function testDefaultLabelWithAltTextExtraction(): void
    {
        $html = 'Check out [[bands:graveyard||Swedish doom legends]] live!';

        $result = $this->parser->parse($html);

        $this->assertCount(1, $result);
        $this->assertEquals('bands', $result[0]['type']);
        $this->assertEquals('graveyard', $result[0]['slug']);
        $this->assertNull($result[0]['customLabel']);
        $this->assertEquals('Swedish doom legends', $result[0]['altText']);
    }

    public function testEscapedShortcodesAreIgnored(): void
    {
        $html = 'Use this syntax: \[[bands:graveyard]] to link to bands.';

        $result = $this->parser->parse($html);

        $this->assertCount(0, $result);
    }

    public function testShortcodesInCodeBlocksAreIgnored(): void
    {
        $html = 'Some text <code>[[bands:graveyard]]</code> more text';

        $result = $this->parser->parse($html);

        $this->assertCount(0, $result);
    }

    public function testShortcodesInPreBlocksAreIgnored(): void
    {
        $html = 'Some text <pre>[[bands:graveyard]]</pre> more text';

        $result = $this->parser->parse($html);

        $this->assertCount(0, $result);
    }

    public function testMultipleShortcodesExtracted(): void
    {
        $html = '[[bands:graveyard]] and [[releases:hisingen-blues]] are great!';

        $result = $this->parser->parse($html);

        $this->assertCount(2, $result);
        $this->assertEquals('bands', $result[0]['type']);
        $this->assertEquals('graveyard', $result[0]['slug']);
        $this->assertEquals('releases', $result[1]['type']);
        $this->assertEquals('hisingen-blues', $result[1]['slug']);
    }

    public function testAllSupportedContentTypes(): void
    {
        $types = [
            'bands', 'releases', 'events', 'venues', 'tours', 'sets',
            'reviews', 'event-reports', 'organizations', 'festivals',
        ];

        foreach ($types as $type) {
            $html = "[[{$type}:test-slug]]";
            $result = $this->parser->parse($html);

            $this->assertCount(1, $result, "Failed for type: {$type}");
            $this->assertEquals($type, $result[0]['type'], "Type mismatch for: {$type}");
        }
    }

    public function testInvalidTypesAreIgnored(): void
    {
        $html = '[[invalid:test-slug]]';

        $result = $this->parser->parse($html);

        $this->assertCount(0, $result);
    }

    public function testComplexHtmlWithMultipleShortcodes(): void
    {
        $html = '<p>Check out [[bands:graveyard|Graveyard]] at <code>[[events:hellfest]]</code>.</p>
                 <pre>Example: [[tours:european-tour]]</pre>
                 <p>Also see [[venues:bikini]].</p>';

        $result = $this->parser->parse($html);

        // Only bands:graveyard and venues:bikini should be extracted
        $this->assertCount(2, $result);
        $this->assertEquals('bands', $result[0]['type']);
        $this->assertEquals('venues', $result[1]['type']);
    }

    public function testEmptyHtmlReturnsEmptyArray(): void
    {
        $result = $this->parser->parse('');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testNestedCodeBlocksHandledCorrectly(): void
    {
        $html = '<pre><code>[[bands:test]]</code></pre> [[bands:valid]]';

        $result = $this->parser->parse($html);

        $this->assertCount(1, $result);
        $this->assertEquals('valid', $result[0]['slug']);
    }
}
