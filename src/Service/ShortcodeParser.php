<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Service;

/**
 * Service for parsing content link shortcodes from HTML content.
 *
 * Supports the following syntax variants:
 * - [[type:slug]] - basic (default label)
 * - [[type:slug|label]] - custom label
 * - [[type:slug|label|alt]] - custom label + alt text
 * - [[type:slug||alt]] - default label + alt text
 */
final class ShortcodeParser
{
    /**
     * Regex pattern to match shortcodes.
     * Captures: type, slug, and optional pipe-delimited parts.
     */
    private const SHORTCODE_PATTERN = '/(?<!\\\\)\[\[([a-z-]+):([a-z0-9-]+)(\|[^\]]*?)?\]\]/';

    public function __construct(
        private readonly ContentTypeRegistryInterface $registry,
    ) {
    }

    /**
     * Parse HTML content and extract all shortcode matches.
     *
     * @return array<int, array{type: string, slug: string, customLabel: ?string, altText: ?string, raw: string}>
     */
    public function parse(string $html): array
    {
        if ($html === '') {
            return [];
        }

        // First, mask content inside <code> and <pre> blocks
        $maskedHtml = $this->maskCodeBlocks($html);

        // Find all shortcode matches
        preg_match_all(self::SHORTCODE_PATTERN, $maskedHtml, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);

        $shortcodes = [];

        foreach ($matches as $match) {
            $type = $match[1][0];
            $slug = $match[2][0];
            $raw = $match[0][0];

            // Skip unsupported content types
            if (! $this->registry->isSupported($type)) {
                continue;
            }

            // Parse the optional parts (after the pipe)
            $customLabel = null;
            $altText = null;

            if (isset($match[3])) {
                $parts = $match[3][0];
                // Remove the leading pipe
                $parts = mb_substr($parts, 1);
                $segments = explode('|', $parts, 2);

                // Count segments to determine format
                if (count($segments) === 1) {
                    // Single segment: custom label only
                    $customLabel = $segments[0] !== '' ? $segments[0] : null;
                } else {
                    // Two segments: label and alt text
                    $customLabel = $segments[0] !== '' ? $segments[0] : null;
                    $altText = $segments[1] !== '' ? $segments[1] : null;
                }
            }

            $shortcodes[] = [
                'type' => $type,
                'slug' => $slug,
                'customLabel' => $customLabel,
                'altText' => $altText,
                'raw' => $raw,
            ];
        }

        return $shortcodes;
    }

    /**
     * Mask content inside <code> and <pre> blocks to prevent shortcode parsing.
     */
    private function maskCodeBlocks(string $html): string
    {
        // Pattern to match <code>...</code> blocks (including nested)
        $codePattern = '/<code[^>]*>.*?<\/code>/is';
        $html = preg_replace_callback($codePattern, function ($match) {
            return str_repeat('X', mb_strlen($match[0]));
        }, $html);

        // Pattern to match <pre>...</pre> blocks (including nested)
        $prePattern = '/<pre[^>]*>.*?<\/pre>/is';
        $html = preg_replace_callback($prePattern, function ($match) {
            return str_repeat('X', mb_strlen($match[0]));
        }, $html);

        return $html;
    }
}
