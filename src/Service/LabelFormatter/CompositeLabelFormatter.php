<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Service\LabelFormatter;

use Symfony\Component\DependencyInjection\Attribute\AutowireIterator;

final class CompositeLabelFormatter
{
    /** @var iterable<LabelFormatterInterface> */
    private readonly iterable $formatters;
    private readonly DefaultLabelFormatter $defaultFormatter;

    /**
     * @param iterable<LabelFormatterInterface> $formatters
     */
    public function __construct(
        #[AutowireIterator('psychedcms.shortcode.label_formatter')]
        iterable $formatters,
    ) {
        $this->formatters = $formatters;
        $this->defaultFormatter = new DefaultLabelFormatter();
    }

    /**
     * @param array<string, mixed> $esDocument
     */
    public function format(string $contentType, array $esDocument): string
    {
        foreach ($this->formatters as $formatter) {
            if ($formatter instanceof DefaultLabelFormatter) {
                continue;
            }

            if ($formatter->supports($contentType)) {
                return $formatter->format($contentType, $esDocument);
            }
        }

        return $this->defaultFormatter->format($contentType, $esDocument);
    }

    /**
     * @return array<string>
     */
    public function getRequiredFields(): array
    {
        $fields = $this->defaultFormatter->getRequiredFields('');

        foreach ($this->formatters as $formatter) {
            $fields = array_merge($fields, $formatter->getRequiredFields(''));
        }

        return array_values(array_unique($fields));
    }
}
