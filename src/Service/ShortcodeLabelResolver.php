<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Service;

use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Shortcode\Service\LabelFormatter\CompositeLabelFormatter;

/**
 * Service for resolving shortcode labels from Elasticsearch.
 *
 * Uses batch search to resolve all shortcodes efficiently.
 */
final class ShortcodeLabelResolver
{
    public function __construct(
        private readonly ElasticsearchClientInterface $client,
        private readonly ContentTypeRegistryInterface $registry,
        private readonly CompositeLabelFormatter $labelFormatter,
    ) {
    }

    /**
     * Resolve labels for an array of shortcodes.
     *
     * @param array<int, array{type: string, slug: string, customLabel: ?string, altText: ?string, raw: string}> $shortcodes
     * @return array<int, array{type: string, slug: string, label: string, customLabel: ?string, altText: ?string, exists: bool, raw: string}>
     */
    public function resolveLabels(array $shortcodes, string $locale): array
    {
        if (empty($shortcodes)) {
            return [];
        }

        // Build lookup index of unique type:slug combinations
        $lookupMap = [];
        foreach ($shortcodes as $shortcode) {
            $key = $shortcode['type'] . ':' . $shortcode['slug'];
            $lookupMap[$key] = $shortcode;
        }

        // Batch fetch all content from Elasticsearch
        $resolvedContent = $this->batchFetchContent(array_values($lookupMap), $locale);

        // Map results back to shortcodes
        $result = [];
        foreach ($shortcodes as $shortcode) {
            $key = $shortcode['type'] . ':' . $shortcode['slug'];
            $content = $resolvedContent[$key] ?? null;

            if ($content !== null) {
                $label = $this->labelFormatter->format($shortcode['type'], $content);
                $result[] = [
                    'type' => $shortcode['type'],
                    'slug' => $shortcode['slug'],
                    'label' => $label,
                    'customLabel' => $shortcode['customLabel'],
                    'altText' => $shortcode['altText'],
                    'exists' => true,
                    'raw' => $shortcode['raw'],
                ];
            } else {
                $result[] = [
                    'type' => $shortcode['type'],
                    'slug' => $shortcode['slug'],
                    'label' => $shortcode['slug'],
                    'customLabel' => $shortcode['customLabel'],
                    'altText' => $shortcode['altText'],
                    'exists' => false,
                    'raw' => $shortcode['raw'],
                ];
            }
        }

        return $result;
    }

    /**
     * Batch fetch content from Elasticsearch using a single query.
     *
     * @param array<array{type: string, slug: string}> $shortcodes
     * @return array<string, array<string, mixed>> Map of "type:slug" => content data
     */
    private function batchFetchContent(array $shortcodes, string $locale): array
    {
        if (empty($shortcodes)) {
            return [];
        }

        $shouldClauses = [];
        $indices = [];

        foreach ($shortcodes as $shortcode) {
            $esContentType = $this->registry->getEsContentType($shortcode['type']);

            $shouldClauses[] = [
                'bool' => [
                    'must' => [
                        ['term' => ['_content_type' => $esContentType]],
                        ['term' => ['_slug' => $shortcode['slug']]],
                    ],
                ],
            ];

            try {
                $indexName = $this->registry->getIndexName($shortcode['type']);
                if ($indexName !== '') {
                    $indices[$indexName] = true;
                }
            } catch (\RuntimeException) {
                continue;
            }
        }

        if (count($shouldClauses) === 0 || count($indices) === 0) {
            return [];
        }

        // Build _source fields from label formatter requirements + metadata
        $sourceFields = array_merge(
            $this->labelFormatter->getRequiredFields(),
            ['_content_type', '_slug', '_locale']
        );

        $query = [
            'size' => count($shortcodes),
            '_source' => array_values(array_unique($sourceFields)),
            'query' => [
                'bool' => [
                    'must' => [
                        ['term' => ['_locale' => $locale]],
                    ],
                    'should' => $shouldClauses,
                    'minimum_should_match' => 1,
                ],
            ],
        ];

        try {
            $response = $this->client->search(array_keys($indices), $query);
        } catch (\Throwable) {
            return [];
        }

        // Map results by type:slug
        $results = [];
        $hits = $response['hits']['hits'] ?? [];

        foreach ($hits as $hit) {
            $source = $hit['_source'] ?? [];
            $esContentType = $source['_content_type'] ?? '';
            $slug = $source['_slug'] ?? '';

            if ($esContentType === '' || $slug === '') {
                continue;
            }

            $typeSlug = $this->registry->getTypeFromEsContentType($esContentType);
            if ($typeSlug !== null) {
                $key = $typeSlug . ':' . $slug;
                $results[$key] = $source;
            }
        }

        return $results;
    }
}
