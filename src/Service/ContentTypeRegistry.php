<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Service;

use PsychedCms\Elasticsearch\Index\IndexNameResolver;
use PsychedCms\Elasticsearch\Indexing\EntityMetadataReader;

final class ContentTypeRegistry implements ContentTypeRegistryInterface
{
    /** @var array<string, string>|null type slug -> entity class */
    private ?array $typeToClass = null;

    /** @var array<string, string>|null type slug -> ES index name */
    private ?array $typeToIndex = null;

    /** @var array<string, string>|null type slug -> ES _content_type */
    private ?array $typeToEsContentType = null;

    /** @var array<string, string>|null ES _content_type -> type slug */
    private ?array $esContentTypeToType = null;

    public function __construct(
        private readonly EntityMetadataReader $metadataReader,
        private readonly IndexNameResolver $nameResolver,
    ) {
    }

    public function isSupported(string $type): bool
    {
        $this->build();

        return isset($this->typeToClass[$type]);
    }

    public function getEntityClass(string $type): ?string
    {
        $this->build();

        return $this->typeToClass[$type] ?? null;
    }

    public function getEsContentType(string $type): string
    {
        $this->build();

        return $this->typeToEsContentType[$type] ?? $type;
    }

    public function getIndexName(string $type): string
    {
        $this->build();

        return $this->typeToIndex[$type] ?? '';
    }

    /**
     * @return array<string>
     */
    public function getAllIndexNames(): array
    {
        $this->build();

        return array_values(array_unique($this->typeToIndex));
    }

    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array
    {
        $this->build();

        return array_keys($this->typeToClass);
    }

    public function getTypeFromEsContentType(string $esContentType): ?string
    {
        $this->build();

        return $this->esContentTypeToType[$esContentType] ?? null;
    }

    private function build(): void
    {
        if ($this->typeToClass !== null) {
            return;
        }

        $this->typeToClass = [];
        $this->typeToIndex = [];
        $this->typeToEsContentType = [];
        $this->esContentTypeToType = [];

        foreach ($this->metadataReader->getIndexedEntities() as $entityClass) {
            $indexed = $this->metadataReader->getIndexedAttribute($entityClass);
            if ($indexed === null) {
                continue;
            }

            // The shortcode type slug is the indexName from the attribute (e.g., 'bands')
            // or the lowercased short class name
            $shortName = $this->getShortName($entityClass);
            $typeSlug = $indexed->indexName ?? $shortName;

            // ES _content_type is the lowercased short class name (e.g., 'band')
            $esContentType = $shortName;

            $indexName = $this->nameResolver->resolve($entityClass);

            $this->typeToClass[$typeSlug] = $entityClass;
            $this->typeToIndex[$typeSlug] = $indexName;
            $this->typeToEsContentType[$typeSlug] = $esContentType;
            $this->esContentTypeToType[$esContentType] = $typeSlug;
        }
    }

    private function getShortName(string $entityClass): string
    {
        $parts = explode('\\', $entityClass);

        return strtolower(end($parts));
    }
}
