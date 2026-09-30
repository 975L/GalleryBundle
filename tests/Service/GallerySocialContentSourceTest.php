<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\ConfigBundle\Service\SiteUrlResolver;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use c975L\GalleryBundle\Service\GallerySocialContentSource;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class GallerySocialContentSourceTest extends TestCase
{
    /** @var list<string>|null */
    private ?array $excludedIds = null;

    /** @var list<string>|null */
    private ?array $categoryIds = null;

    private function createMedia(): GalleryMedia
    {
        $category = new GalleryCategory()->setSlug('montagne')->setTitle('Montagne');
        $media = new GalleryMedia()
            ->setCategory($category)
            ->setSlug('lac')
            ->setTitle('Lac d\'Annecy')
            ->setDescription('<p>Au lever du jour &amp; sans vent</p>')
            ->setFilename('medias/gallery/montagne/lac-abc.webp');
        new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, 42);

        return $media;
    }

    /** @param list<GalleryCategory> $categories */
    private function createSource(?GalleryMedia $media, ?string $siteUrl = 'https://example.org', string $order = 'oldest', array $categories = []): GallerySocialContentSource
    {
        $repository = $this->createStub(GalleryMediaRepository::class);
        $repository->method('findNextToPost')->willReturnCallback(function (array $excludedIds, array $categoryIds = []) use ($media): ?GalleryMedia {
            $this->excludedIds = $excludedIds;
            $this->categoryIds = $categoryIds;

            return $media;
        });
        $repository->method('findPostable')->willReturn($media);
        $repository->method('findPostableIds')->willReturnCallback(function (array $excludedIds, array $categoryIds = []) use ($media): array {
            $this->excludedIds = $excludedIds;
            $this->categoryIds = $categoryIds;

            return null === $media ? [] : [(int) $media->getId()];
        });

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturnMap([['gallery-social-order', $order], [GalleryRoutePrefix::SLUG, 'galerie']]);

        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        // The prefix read from the parameters, not written in: the route has no default for it, and a console has no request to take it from
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/' . $parameters[GalleryRoutePrefix::PARAMETER] . '/' . $parameters['category'] . '/' . $parameters['slug']);

        $siteUrlResolver = $this->createStub(SiteUrlResolver::class);
        $siteUrlResolver->method('siteUrl')->willReturn($siteUrl);

        $categoryRepository = $this->createStub(GalleryCategoryRepository::class);
        $categoryRepository->method('findAllOrdered')->willReturn($categories);

        return new GallerySocialContentSource($repository, $categoryRepository, $urlGenerator, $siteUrlResolver, $configService, new GalleryRoutePrefix($configService), '/var/www/site');
    }

    public function testTheNextPhotographIsHandedOverWithAbsoluteUrls(): void
    {
        $content = $this->createSource($this->createMedia())->getNextContent(['7']);

        $this->assertSame(['7'], $this->excludedIds);
        $this->assertSame('42', $content?->sourceId);
        $this->assertSame('Lac d\'Annecy', $content->title);
        $this->assertSame('https://example.org/galerie/montagne/lac', $content->url);
        $this->assertSame('/var/www/site/public/medias/gallery/montagne/lac-abc.webp', $content->imagePath);
        $this->assertSame('https://example.org/medias/gallery/montagne/lac-abc.webp', $content->imageUrl);
    }

    // The description is rich text typed in the back-office, which a post shows as it is
    public function testTheVariablesCarryTheCategoryAndThePlainDescription(): void
    {
        $content = $this->createSource($this->createMedia())->getNextContent([]);

        $this->assertSame(['category' => 'Montagne', 'description' => 'Au lever du jour & sans vent'], $content?->variables);
    }

    // Run from a console, the only host there is to link to is the configured one
    public function testNothingIsHandedOverWhileTheSiteUrlIsUnset(): void
    {
        $this->assertNull($this->createSource($this->createMedia(), null)->getNextContent([]));
        $this->assertNull($this->excludedIds);
    }

    public function testNothingLeftToPostIsNull(): void
    {
        $this->assertNull($this->createSource(null)->getNextContent([]));
    }

    public function testAPhotographIsNeverOfferedTwice(): void
    {
        $this->assertNull($this->createSource(null)->getRepeatAfterDays());
    }

    public function testAPhotographIsReadAgainByItsId(): void
    {
        $this->assertSame('42', $this->createSource($this->createMedia())->getContent('42')?->sourceId);
    }

    // Taken off the site after the post was prepared (the repository no longer finds it postable): publishing the draft a day later must not put it back
    public function testAPhotographTakenOffSinceIsNotReadAgain(): void
    {
        $this->assertNull($this->createSource(null)->getContent('42'));
    }

    // The default: drawn among every photograph not posted yet, the posted ones left out
    public function testAtRandomThePhotographIsDrawnAmongTheOnesNotPostedYet(): void
    {
        $content = $this->createSource($this->createMedia(), order: 'random')->getNextContent(['7']);

        $this->assertSame(['7'], $this->excludedIds);
        $this->assertSame('42', $content?->sourceId);
    }

    public function testAtRandomNothingLeftToPostIsNull(): void
    {
        $this->assertNull($this->createSource(null, order: 'random')->getNextContent([]));
    }

    // A slot narrowed to some galleries hands their ids down to the query, whichever the order
    public function testAScopedDrawOnlyLooksInTheGivenGalleries(): void
    {
        $this->createSource($this->createMedia())->getNextScopedContent(['7'], ['3', '5']);
        $this->assertSame(['3', '5'], $this->categoryIds);

        $this->createSource($this->createMedia(), order: 'random')->getNextScopedContent([], ['3']);
        $this->assertSame(['3'], $this->categoryIds);
    }

    public function testTheUnscopedDrawLooksInEveryGallery(): void
    {
        $this->createSource($this->createMedia())->getNextContent([]);

        $this->assertSame([], $this->categoryIds);
    }

    // An automatic gallery only gathers the photographs of the others, a slot picking it would pick nothing of its own
    public function testTheScopesAreTheGalleriesHoldingTheirOwnPhotographs(): void
    {
        $mountain = new GalleryCategory()->setSlug('montagne')->setTitle('Montagne');
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($mountain, 3);
        $latest = new GalleryCategory()->setSlug('dernieres')->setTitle('Dernières');
        new \ReflectionProperty(GalleryCategory::class, 'id')->setValue($latest, 4);
        new \ReflectionProperty(GalleryCategory::class, 'automaticKind')->setValue($latest, 'latest');

        $this->assertSame(['3' => 'Montagne'], $this->createSource(null, categories: [$mountain, $latest])->getScopes());
    }
}
