<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Tests\Service;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Shortcode\Service\ContentTypeRegistryInterface;
use PsychedCms\Shortcode\Service\LabelFormatter\CompositeLabelFormatter;
use PsychedCms\Shortcode\Service\LabelFormatter\DefaultLabelFormatter;
use PsychedCms\Shortcode\Service\ShortcodeLabelResolver;

class ShortcodeLabelResolverTest extends TestCase
{
    private ShortcodeLabelResolver $resolver;
    private ElasticsearchClientInterface&MockObject $client;
    private ContentTypeRegistryInterface&MockObject $registry;
    private CompositeLabelFormatter $labelFormatter;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ElasticsearchClientInterface::class);
        $this->registry = $this->createMock(ContentTypeRegistryInterface::class);

        $this->registry->method('getIndexName')
            ->willReturnCallback(fn (string $type) => 'psychedcms_' . $type);

        $this->registry->method('getEsContentType')
            ->willReturnCallback(fn (string $type) => match ($type) {
                'bands' => 'band',
                'releases' => 'release',
                'events' => 'event',
                'venues' => 'venue',
                'tours' => 'tour',
                'sets' => 'set',
                'reviews' => 'review',
                'event-reports' => 'eventreport',
                'organizations' => 'organization',
                'festivals' => 'festival',
                'labels' => 'label',
                default => $type,
            });

        $this->registry->method('getTypeFromEsContentType')
            ->willReturnCallback(fn (string $esType) => match ($esType) {
                'band' => 'bands',
                'release' => 'releases',
                'event' => 'events',
                'venue' => 'venues',
                'tour' => 'tours',
                'set' => 'sets',
                'review' => 'reviews',
                'eventreport' => 'event-reports',
                'organization' => 'organizations',
                'festival' => 'festivals',
                'label' => 'labels',
                default => null,
            });

        // Use real CompositeLabelFormatter with DefaultLabelFormatter
        $this->labelFormatter = new CompositeLabelFormatter([new DefaultLabelFormatter()]);

        $this->resolver = new ShortcodeLabelResolver(
            $this->client,
            $this->registry,
            $this->labelFormatter,
        );
    }

    public function testBandLabelReturnsName(): void
    {
        $shortcodes = [
            ['type' => 'bands', 'slug' => 'graveyard', 'customLabel' => null, 'altText' => null, 'raw' => '[[bands:graveyard]]'],
        ];

        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'band',
                                '_slug' => 'graveyard',
                                'name' => 'Graveyard',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->resolver->resolveLabels($shortcodes, 'en');

        $this->assertCount(1, $result);
        $this->assertEquals('Graveyard', $result[0]['label']);
        $this->assertTrue($result[0]['exists']);
    }

    public function testReleaseLabelReturnsTitle(): void
    {
        $shortcodes = [
            ['type' => 'releases', 'slug' => 'hisingen-blues', 'customLabel' => null, 'altText' => null, 'raw' => '[[releases:hisingen-blues]]'],
        ];

        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'release',
                                '_slug' => 'hisingen-blues',
                                'title' => 'Hisingen Blues',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->resolver->resolveLabels($shortcodes, 'en');

        $this->assertCount(1, $result);
        $this->assertEquals('Hisingen Blues', $result[0]['label']);
        $this->assertTrue($result[0]['exists']);
    }

    public function testNonExistentContentReturnsExistsFalse(): void
    {
        $shortcodes = [
            ['type' => 'bands', 'slug' => 'non-existent-band', 'customLabel' => null, 'altText' => null, 'raw' => '[[bands:non-existent-band]]'],
        ];

        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [],
                ],
            ]);

        $result = $this->resolver->resolveLabels($shortcodes, 'en');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['exists']);
        $this->assertEquals('non-existent-band', $result[0]['label']);
    }

    public function testMultipleSupportedContentTypes(): void
    {
        $shortcodes = [
            ['type' => 'venues', 'slug' => 'bikini', 'customLabel' => null, 'altText' => null, 'raw' => '[[venues:bikini]]'],
            ['type' => 'organizations', 'slug' => 'live-nation', 'customLabel' => null, 'altText' => null, 'raw' => '[[organizations:live-nation]]'],
            ['type' => 'festivals', 'slug' => 'hellfest', 'customLabel' => null, 'altText' => null, 'raw' => '[[festivals:hellfest]]'],
        ];

        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'venue',
                                '_slug' => 'bikini',
                                'name' => 'Le Bikini',
                            ],
                        ],
                        [
                            '_source' => [
                                '_content_type' => 'organization',
                                '_slug' => 'live-nation',
                                'name' => 'Live Nation',
                            ],
                        ],
                        [
                            '_source' => [
                                '_content_type' => 'festival',
                                '_slug' => 'hellfest',
                                'name' => 'Hellfest',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->resolver->resolveLabels($shortcodes, 'en');

        $this->assertCount(3, $result);
        $this->assertEquals('Le Bikini', $result[0]['label']);
        $this->assertEquals('Live Nation', $result[1]['label']);
        $this->assertEquals('Hellfest', $result[2]['label']);
    }

    public function testEventLabelReturnsTitle(): void
    {
        $shortcodes = [
            ['type' => 'events', 'slug' => 'hellfest-2025', 'customLabel' => null, 'altText' => null, 'raw' => '[[events:hellfest-2025]]'],
        ];

        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'event',
                                '_slug' => 'hellfest-2025',
                                'title' => 'Hellfest 2025',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->resolver->resolveLabels($shortcodes, 'en');

        $this->assertCount(1, $result);
        $this->assertEquals('Hellfest 2025', $result[0]['label']);
    }

    public function testTourLabelReturnsTitle(): void
    {
        $shortcodes = [
            ['type' => 'tours', 'slug' => 'european-tour-2025', 'customLabel' => null, 'altText' => null, 'raw' => '[[tours:european-tour-2025]]'],
        ];

        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'tour',
                                '_slug' => 'european-tour-2025',
                                'title' => 'European Tour 2025',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->resolver->resolveLabels($shortcodes, 'en');

        $this->assertCount(1, $result);
        $this->assertEquals('European Tour 2025', $result[0]['label']);
    }

    public function testLabelShortcodeReturnsName(): void
    {
        $shortcodes = [
            ['type' => 'labels', 'slug' => 'nuclear-blast', 'customLabel' => null, 'altText' => null, 'raw' => '[[labels:nuclear-blast]]'],
        ];

        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'label',
                                '_slug' => 'nuclear-blast',
                                'name' => 'Nuclear Blast',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->resolver->resolveLabels($shortcodes, 'en');

        $this->assertCount(1, $result);
        $this->assertEquals('Nuclear Blast', $result[0]['label']);
        $this->assertTrue($result[0]['exists']);
    }

    public function testCustomLabelAndAltTextPreserved(): void
    {
        $shortcodes = [
            ['type' => 'bands', 'slug' => 'graveyard', 'customLabel' => 'The Swedish Doomsters', 'altText' => 'Doom metal band', 'raw' => '[[bands:graveyard|The Swedish Doomsters|Doom metal band]]'],
        ];

        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'band',
                                '_slug' => 'graveyard',
                                'name' => 'Graveyard',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->resolver->resolveLabels($shortcodes, 'en');

        $this->assertCount(1, $result);
        $this->assertEquals('Graveyard', $result[0]['label']);
        $this->assertEquals('The Swedish Doomsters', $result[0]['customLabel']);
        $this->assertEquals('Doom metal band', $result[0]['altText']);
    }

    public function testEmptyShortcodesReturnsEmptyArray(): void
    {
        $result = $this->resolver->resolveLabels([], 'en');

        $this->assertIsArray($result);
        $this->assertCount(0, $result);
    }

    public function testElasticsearchErrorReturnsSlugFallback(): void
    {
        $shortcodes = [
            ['type' => 'bands', 'slug' => 'graveyard', 'customLabel' => null, 'altText' => null, 'raw' => '[[bands:graveyard]]'],
        ];

        $this->client->method('search')
            ->willThrowException(new \RuntimeException('ES connection failed'));

        $result = $this->resolver->resolveLabels($shortcodes, 'en');

        $this->assertCount(1, $result);
        $this->assertFalse($result[0]['exists']);
        $this->assertEquals('graveyard', $result[0]['label']);
    }
}
