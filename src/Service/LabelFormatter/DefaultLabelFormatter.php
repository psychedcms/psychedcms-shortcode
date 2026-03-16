<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Service\LabelFormatter;

final class DefaultLabelFormatter implements LabelFormatterInterface
{
    public function supports(string $contentType): bool
    {
        return true;
    }

    /**
     * @param array<string, mixed> $esDocument
     */
    public function format(string $contentType, array $esDocument): string
    {
        return $esDocument['name'] ?? $esDocument['title'] ?? $esDocument['_slug'] ?? '';
    }

    /**
     * @return array<string>
     */
    public function getRequiredFields(string $contentType): array
    {
        return ['name', 'title', '_slug'];
    }
}
