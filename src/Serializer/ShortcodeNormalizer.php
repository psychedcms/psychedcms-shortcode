<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Serializer;

use PsychedCms\Core\Attribute\Field\FieldAttributeInterface;
use PsychedCms\Core\Content\ContentInterface;
use PsychedCms\Shortcode\Service\ShortcodeLabelResolver;
use PsychedCms\Shortcode\Service\ShortcodeParser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Normalizer that processes shortcodes in HTML fields and adds contentLinks metadata.
 */
final class ShortcodeNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED = 'SHORTCODE_NORMALIZER_ALREADY_CALLED';
    private const DEFAULT_LOCALE = 'fr';

    /** @var array<string, array<string>> Cache of HTML field names per entity class */
    private array $htmlFieldsCache = [];

    public function __construct(
        private readonly ShortcodeParser $parser,
        private readonly ShortcodeLabelResolver $resolver,
        private readonly RequestStack $requestStack,
    ) {
    }

    /**
     * @param ContentInterface $object
     */
    public function normalize(mixed $object, ?string $format = null, array $context = []): array
    {
        $context[self::ALREADY_CALLED] = true;

        $data = $this->normalizer->normalize($object, $format, $context);

        if (! is_array($data)) {
            return [];
        }

        // Discover HTML fields via reflection
        $htmlFields = $this->getHtmlFields($object::class);

        // Collect all shortcodes from HTML fields
        $allShortcodes = [];

        foreach ($htmlFields as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                $shortcodes = $this->parser->parse($data[$field]);
                $allShortcodes = array_merge($allShortcodes, $shortcodes);
            }
        }

        // Only add contentLinks if shortcodes were found
        if (! empty($allShortcodes)) {
            $locale = $this->getLocale();
            $resolvedLinks = $this->resolver->resolveLabels($allShortcodes, $locale);

            $data['contentLinks'] = array_map(function (array $link): array {
                return [
                    'type' => $link['type'],
                    'slug' => $link['slug'],
                    'label' => $link['label'],
                    'customLabel' => $link['customLabel'],
                    'altText' => $link['altText'],
                    'exists' => $link['exists'],
                ];
            }, $resolvedLinks);
        }

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (isset($context[self::ALREADY_CALLED])) {
            return false;
        }

        return $data instanceof ContentInterface;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            ContentInterface::class => false,
        ];
    }

    /**
     * @return array<string>
     */
    private function getHtmlFields(string $entityClass): array
    {
        if (isset($this->htmlFieldsCache[$entityClass])) {
            return $this->htmlFieldsCache[$entityClass];
        }

        $fields = [];
        $reflectionClass = new \ReflectionClass($entityClass);

        foreach ($reflectionClass->getProperties() as $property) {
            foreach ($property->getAttributes() as $attribute) {
                $instance = $attribute->newInstance();
                if ($instance instanceof FieldAttributeInterface && $instance->getFieldType() === 'html') {
                    $fields[] = $property->getName();
                    break;
                }
            }
        }

        $this->htmlFieldsCache[$entityClass] = $fields;

        return $fields;
    }

    private function getLocale(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request !== null) {
            $locale = $request->query->get('locale')
                ?? $request->headers->get('Accept-Language');

            if ($locale !== null && $locale !== '') {
                // Extract primary language from Accept-Language (e.g., "fr-FR,fr;q=0.9" -> "fr")
                return explode(',', explode('-', $locale)[0])[0];
            }
        }

        return self::DEFAULT_LOCALE;
    }
}
