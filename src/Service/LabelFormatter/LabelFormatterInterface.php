<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Service\LabelFormatter;

interface LabelFormatterInterface
{
    public function supports(string $contentType): bool;

    /**
     * @param array<string, mixed> $esDocument
     */
    public function format(string $contentType, array $esDocument): string;

    /**
     * @return array<string>
     */
    public function getRequiredFields(string $contentType): array;
}
