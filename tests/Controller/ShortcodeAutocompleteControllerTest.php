<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Tests\Controller;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Shortcode\Controller\ShortcodeAutocompleteController;
use PsychedCms\Shortcode\Service\ContentTypeRegistryInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

class ShortcodeAutocompleteControllerTest extends TestCase
{
    private ShortcodeAutocompleteController $controller;
    private ElasticsearchClientInterface&MockObject $client;
    private ContentTypeRegistryInterface&MockObject $registry;

    protected function setUp(): void
    {
        $this->client = $this->createMock(ElasticsearchClientInterface::class);
        $this->registry = $this->createMock(ContentTypeRegistryInterface::class);

        $this->registry->method('getAllIndexNames')
            ->willReturn(['psychedcms_bands', 'psychedcms_releases', 'psychedcms_events']);

        $this->registry->method('getIndexName')
            ->willReturnCallback(fn (string $type) => 'psychedcms_' . $type);

        $this->registry->method('getEsContentType')
            ->willReturnCallback(fn (string $type) => match ($type) {
                'bands' => 'band',
                'releases' => 'release',
                'events' => 'event',
                'tours' => 'tour',
                default => $type,
            });

        $this->registry->method('getTypeFromEsContentType')
            ->willReturnCallback(fn (string $esType) => match ($esType) {
                'band' => 'bands',
                'release' => 'releases',
                'event' => 'events',
                'tour' => 'tours',
                default => null,
            });

        $this->controller = new ShortcodeAutocompleteController(
            $this->client,
            $this->registry,
        );
    }

    public function testAutocompleteReturnsResultsGroupedByContentType(): void
    {
        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                'name' => 'Graveyard',
                                '_slug' => 'graveyard',
                                '_content_type' => 'band',
                            ],
                            '_score' => 10.5,
                        ],
                        [
                            '_source' => [
                                'name' => 'Grave Pleasures',
                                '_slug' => 'grave-pleasures',
                                '_content_type' => 'band',
                            ],
                            '_score' => 8.2,
                        ],
                        [
                            '_source' => [
                                'title' => 'Graveyard Tour 2024',
                                '_slug' => 'graveyard-tour-2024',
                                '_content_type' => 'tour',
                            ],
                            '_score' => 6.1,
                        ],
                    ],
                ],
            ]);

        $request = new Request(['q' => 'grave']);
        $response = $this->controller->autocomplete($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertArrayHasKey('results', $data);

        $results = $data['results'];
        $this->assertCount(3, $results);

        // Bands should come first (priority 1), tours after (priority 2)
        $this->assertEquals('bands', $results[0]['type']);
        $this->assertEquals('bands', $results[1]['type']);
        $this->assertEquals('tours', $results[2]['type']);

        $this->assertArrayHasKey('type', $results[0]);
        $this->assertArrayHasKey('slug', $results[0]);
        $this->assertArrayHasKey('label', $results[0]);
        $this->assertArrayHasKey('score', $results[0]);
    }

    public function testAutocompleteResultsIncludeSlugField(): void
    {
        $this->client->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                'name' => 'Test Band',
                                '_slug' => 'test-band',
                                '_content_type' => 'band',
                            ],
                            '_score' => 10.0,
                        ],
                        [
                            '_source' => [
                                'title' => 'Test Event 2024',
                                '_slug' => 'test-event-2024',
                                '_content_type' => 'event',
                            ],
                            '_score' => 8.0,
                        ],
                    ],
                ],
            ]);

        $request = new Request(['q' => 'test']);
        $response = $this->controller->autocomplete($request);

        $data = json_decode($response->getContent(), true);
        $results = $data['results'];

        foreach ($results as $result) {
            $this->assertArrayHasKey('slug', $result);
            $this->assertNotEmpty($result['slug']);
        }

        $this->assertEquals('test-band', $results[0]['slug']);
        $this->assertEquals('test-event-2024', $results[1]['slug']);
    }

    public function testAutocompleteReturnsEmptyResultsForShortQuery(): void
    {
        $request = new Request(['q' => 'a']);
        $response = $this->controller->autocomplete($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(['results' => []], $data);
    }

    public function testAutocompleteHandlesElasticsearchError(): void
    {
        $this->client->method('search')
            ->willThrowException(new \RuntimeException('ES connection failed'));

        $request = new Request(['q' => 'test']);
        $response = $this->controller->autocomplete($request);

        $this->assertEquals(Response::HTTP_OK, $response->getStatusCode());

        $data = json_decode($response->getContent(), true);
        $this->assertEquals(['results' => []], $data);
    }
}
