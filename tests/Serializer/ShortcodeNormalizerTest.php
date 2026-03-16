<?php

declare(strict_types=1);

namespace PsychedCms\Shortcode\Tests\Serializer;

use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use PsychedCms\Core\Attribute\Field\HtmlField;
use PsychedCms\Core\Attribute\Field\TextField;
use PsychedCms\Core\Content\ContentInterface;
use PsychedCms\Elasticsearch\Client\ElasticsearchClientInterface;
use PsychedCms\Shortcode\Serializer\ShortcodeNormalizer;
use PsychedCms\Shortcode\Service\ContentTypeRegistryInterface;
use PsychedCms\Shortcode\Service\LabelFormatter\CompositeLabelFormatter;
use PsychedCms\Shortcode\Service\LabelFormatter\DefaultLabelFormatter;
use PsychedCms\Shortcode\Service\ShortcodeLabelResolver;
use PsychedCms\Shortcode\Service\ShortcodeParser;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ShortcodeNormalizerTest extends TestCase
{
    private ShortcodeNormalizer $normalizer;
    private ContentTypeRegistryInterface&MockObject $registry;
    private ElasticsearchClientInterface&MockObject $esClient;
    private RequestStack $requestStack;

    protected function setUp(): void
    {
        $this->registry = $this->createMock(ContentTypeRegistryInterface::class);
        $this->registry->method('isSupported')->willReturn(true);
        $this->registry->method('getEsContentType')
            ->willReturnCallback(fn (string $type) => match ($type) {
                'bands' => 'band',
                'releases' => 'release',
                default => $type,
            });
        $this->registry->method('getTypeFromEsContentType')
            ->willReturnCallback(fn (string $esType) => match ($esType) {
                'band' => 'bands',
                'release' => 'releases',
                default => null,
            });
        $this->registry->method('getIndexName')
            ->willReturnCallback(fn (string $type) => 'psychedcms_' . $type);

        $this->esClient = $this->createMock(ElasticsearchClientInterface::class);

        $parser = new ShortcodeParser($this->registry);
        $labelFormatter = new CompositeLabelFormatter([new DefaultLabelFormatter()]);
        $resolver = new ShortcodeLabelResolver($this->esClient, $this->registry, $labelFormatter);

        $this->requestStack = new RequestStack();
        $this->requestStack->push(new Request(['locale' => 'en']));

        $this->normalizer = new ShortcodeNormalizer($parser, $resolver, $this->requestStack);

        $innerNormalizer = $this->createMock(NormalizerInterface::class);
        $innerNormalizer->method('normalize')
            ->willReturnCallback(function (mixed $object) {
                if ($object instanceof ContentEntityStub) {
                    $data = ['name' => $object->getName()];
                    if ($object->getBio() !== null) {
                        $data['bio'] = $object->getBio();
                    }

                    return $data;
                }

                return [];
            });

        $this->normalizer->setNormalizer($innerNormalizer);
    }

    public function testApiResponseIncludesContentLinksForHtmlFieldWithShortcodes(): void
    {
        $entity = new ContentEntityStub();
        $entity->setName('Test Band');
        $entity->setBio('Check out [[bands:graveyard]] for more doom metal!');

        $this->esClient->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'band',
                                '_slug' => 'graveyard',
                                '_locale' => 'en',
                                'name' => 'Graveyard',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->normalizer->normalize($entity);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('contentLinks', $result);
        $this->assertCount(1, $result['contentLinks']);
        $this->assertEquals('bands', $result['contentLinks'][0]['type']);
        $this->assertEquals('graveyard', $result['contentLinks'][0]['slug']);
        $this->assertEquals('Graveyard', $result['contentLinks'][0]['label']);
        $this->assertTrue($result['contentLinks'][0]['exists']);
    }

    public function testHtmlFieldWithoutShortcodesHasNoContentLinksProperty(): void
    {
        $entity = new ContentEntityStub();
        $entity->setName('Test Band');
        $entity->setBio('No shortcodes here, just plain text.');

        $result = $this->normalizer->normalize($entity);

        $this->assertIsArray($result);
        $this->assertArrayNotHasKey('contentLinks', $result);
    }

    public function testContentLinksContainsResolvedMetadata(): void
    {
        $entity = new ContentEntityStub();
        $entity->setName('Test Band');
        $entity->setBio('See [[releases:hisingen-blues|Custom Label|Alt text]] review.');

        $this->esClient->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'release',
                                '_slug' => 'hisingen-blues',
                                '_locale' => 'en',
                                'title' => 'Hisingen Blues',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->normalizer->normalize($entity);

        $link = $result['contentLinks'][0];
        $this->assertEquals('releases', $link['type']);
        $this->assertEquals('hisingen-blues', $link['slug']);
        $this->assertEquals('Hisingen Blues', $link['label']);
        $this->assertEquals('Custom Label', $link['customLabel']);
        $this->assertEquals('Alt text', $link['altText']);
        $this->assertTrue($link['exists']);
    }

    public function testBrokenLinksMarkedAsExistsFalse(): void
    {
        $entity = new ContentEntityStub();
        $entity->setName('Test Band');
        $entity->setBio('Check out [[bands:non-existent]]!');

        $this->esClient->method('search')
            ->willReturn(['hits' => ['hits' => []]]);

        $result = $this->normalizer->normalize($entity);

        $this->assertArrayHasKey('contentLinks', $result);
        $this->assertFalse($result['contentLinks'][0]['exists']);
    }

    public function testSupportsContentInterfaceEntities(): void
    {
        $entity = new ContentEntityStub();

        $this->assertTrue($this->normalizer->supportsNormalization($entity));
        $this->assertFalse($this->normalizer->supportsNormalization(new \stdClass()));
    }

    public function testContentLinksExcludesRawField(): void
    {
        $entity = new ContentEntityStub();
        $entity->setName('Test Band');
        $entity->setBio('Check out [[bands:graveyard]]!');

        $this->esClient->method('search')
            ->willReturn([
                'hits' => [
                    'hits' => [
                        [
                            '_source' => [
                                '_content_type' => 'band',
                                '_slug' => 'graveyard',
                                '_locale' => 'en',
                                'name' => 'Graveyard',
                            ],
                        ],
                    ],
                ],
            ]);

        $result = $this->normalizer->normalize($entity);

        $this->assertArrayNotHasKey('raw', $result['contentLinks'][0]);
    }
}

/**
 * Stub entity implementing ContentInterface with HtmlField attribute.
 */
class ContentEntityStub implements ContentInterface
{
    #[TextField]
    private ?string $name = null;

    #[HtmlField]
    private ?string $bio = null;

    public function getId(): ?int
    {
        return 1;
    }

    public function getSlug(): ?string
    {
        return 'test-stub';
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return null;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return null;
    }

    public function getAuthor(): ?object
    {
        return null;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): void
    {
        $this->name = $name;
    }

    public function getBio(): ?string
    {
        return $this->bio;
    }

    public function setBio(?string $bio): void
    {
        $this->bio = $bio;
    }
}
