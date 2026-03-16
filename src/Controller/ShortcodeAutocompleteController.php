<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Controller;

use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Shortcode\Service\ContentTypeRegistryInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Controller for shortcode autocomplete in the WYSIWYG editor.
 *
 * Provides an endpoint to search content across all types for
 * inserting [[type:slug]] shortcodes.
 */
#[AsController]
final readonly class ShortcodeAutocompleteController
{
    private const MAX_SUGGESTIONS = 20;

    /**
     * Content type priority levels for sorting.
     * Lower number = higher priority (appears first).
     */
    private const TYPE_PRIORITY = [
        'bands' => 1,
        'festivals' => 1,
        'labels' => 1,
        'organizations' => 1,
        'venues' => 1,
        'releases' => 2,
        'events' => 2,
        'tours' => 2,
        'sets' => 3,
        'reviews' => 3,
        'event-reports' => 3,
        'set-reports' => 3,
        'day-reports' => 3,
        'posts' => 3,
    ];

    public function __construct(
        private ElasticsearchClientInterface $client,
        private ContentTypeRegistryInterface $registry,
    ) {
    }

    #[Route('/api/shortcode-autocomplete', name: 'shortcode_autocomplete', methods: ['GET'])]
    public function autocomplete(Request $request): JsonResponse
    {
        $query = trim($request->query->get('q', ''));
        $filterType = $request->query->get('type');

        if (mb_strlen($query) < 2) {
            return new JsonResponse(['results' => []]);
        }

        $indexNames = $this->resolveIndexNames($filterType);

        if ($indexNames === []) {
            return new JsonResponse(['results' => []]);
        }

        $esQuery = $this->buildQuery($query, $filterType);

        try {
            $response = $this->client->search($indexNames, $esQuery);
        } catch (\Throwable) {
            return new JsonResponse(['results' => []]);
        }

        $results = $this->parseResponse($response);

        // Sort by priority, type name, then score
        usort($results, function (array $a, array $b): int {
            $priorityA = self::TYPE_PRIORITY[$a['type']] ?? 99;
            $priorityB = self::TYPE_PRIORITY[$b['type']] ?? 99;
            if ($priorityA !== $priorityB) {
                return $priorityA <=> $priorityB;
            }

            $typeCompare = strcmp($a['type'], $b['type']);
            if ($typeCompare !== 0) {
                return $typeCompare;
            }

            return $b['score'] <=> $a['score'];
        });

        return new JsonResponse(['results' => $results]);
    }

    /**
     * @return array<string>
     */
    private function resolveIndexNames(?string $filterType): array
    {
        if ($filterType !== null && $filterType !== '') {
            $indexName = $this->registry->getIndexName($filterType);

            return $indexName !== '' ? [$indexName] : [];
        }

        return $this->registry->getAllIndexNames();
    }

    /**
     * @return array<string, mixed>
     */
    private function buildQuery(string $prefix, ?string $filterType): array
    {
        $shouldClauses = [];

        foreach (['name', 'title'] as $field) {
            $shouldClauses[] = [
                'match_phrase_prefix' => [
                    $field => [
                        'query' => $prefix,
                        'max_expansions' => 50,
                    ],
                ],
            ];

            $shouldClauses[] = [
                'match' => [
                    "{$field}.autocomplete" => [
                        'query' => $prefix,
                    ],
                ],
            ];
        }

        $shouldClauses[] = [
            'prefix' => [
                '_slug' => [
                    'value' => $prefix,
                    'case_insensitive' => true,
                ],
            ],
        ];

        $mustClauses = [];

        // Add type filter if specified
        if ($filterType !== null && $filterType !== '') {
            $esContentType = $this->registry->getEsContentType($filterType);
            $mustClauses[] = ['term' => ['_content_type' => $esContentType]];
        }

        $query = [
            'size' => self::MAX_SUGGESTIONS,
            '_source' => ['name', 'title', '_slug', '_content_type'],
            'query' => [
                'bool' => [
                    'should' => $shouldClauses,
                    'minimum_should_match' => 1,
                ],
            ],
            'sort' => [
                '_score' => ['order' => 'desc'],
            ],
        ];

        if ($mustClauses !== []) {
            $query['query']['bool']['must'] = $mustClauses;
        }

        return $query;
    }

    /**
     * @return array<array{type: string, slug: string, label: string, score: float}>
     */
    private function parseResponse(array $response): array
    {
        $hits = $response['hits']['hits'] ?? [];
        $results = [];
        $seenSlugs = [];

        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? [];
            $esContentType = $source['_content_type'] ?? '';
            $slug = $source['_slug'] ?? '';

            if ($esContentType === '' || $slug === '') {
                continue;
            }

            $typeSlug = $this->registry->getTypeFromEsContentType($esContentType);
            if ($typeSlug === null) {
                continue;
            }

            $dedupeKey = $typeSlug . ':' . $slug;
            if (isset($seenSlugs[$dedupeKey])) {
                continue;
            }
            $seenSlugs[$dedupeKey] = true;

            $label = $source['name'] ?? $source['title'] ?? $slug;

            $results[] = [
                'type' => $typeSlug,
                'slug' => $slug,
                'label' => $label,
                'score' => (float) ($hit['_score'] ?? 0.0),
            ];
        }

        return $results;
    }
}
