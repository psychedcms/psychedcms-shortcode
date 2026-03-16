<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Service;

interface ContentTypeRegistryInterface
{
    public function isSupported(string $type): bool;

    public function getEntityClass(string $type): ?string;

    public function getEsContentType(string $type): string;

    public function getIndexName(string $type): string;

    /**
     * @return array<string>
     */
    public function getAllIndexNames(): array;

    /**
     * @return array<string>
     */
    public function getSupportedTypes(): array;

    public function getTypeFromEsContentType(string $esContentType): ?string;
}
