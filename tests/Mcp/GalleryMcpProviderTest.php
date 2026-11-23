<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Mcp;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Mcp\GalleryMcpProvider;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Service\GalleryCategoryUrls;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use Doctrine\ORM\EntityManagerInterface;
use Mcp\Server\Exception\ToolCallException;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

class GalleryMcpProviderTest extends TestCase
{
    // The tools named under the "gallery" key, the writing ones flagged
    public function testTools(): void
    {
        $tools = iterator_to_array($this->provider()->getMcpTools(), false);

        $this->assertSame('gallery', $this->provider()->getMcpKey());
        $this->assertStringContainsString('at once', $this->provider()->getMcpInstructions());
        $this->assertSame(['gallery_list', 'gallery_medias', 'gallery_create', 'gallery_edit', 'gallery_media_edit'], array_map(static fn ($tool): string => $tool->name, $tools));
        $this->assertSame([false, false, true, true, true], array_map(static fn ($tool): bool => $tool->writes, $tools));
    }

    // A gallery added hidden, its slug from the title and its freed url and the media urls below it released
    public function testCreateHidden(): void
    {
        $persisted = new \ArrayObject();
        $redirector = $this->createMock(GalleryUrlRedirector::class);
        $redirector->expects($this->exactly(2))->method('release');

        $this->assertSame('item-1', $this->provider(entityManager: $this->persisting($persisted), redirector: $redirector)->create('Item 1', ' summary-1 ')['slug']);
        $this->assertInstanceOf(GalleryCategory::class, $persisted[0]);
        $this->assertTrue($persisted[0]->isHidden());
        $this->assertSame('summary-1', $persisted[0]->getSummarySocialNetwork());
    }

    // A slug another gallery holds is refused rather than suffixed
    public function testCreateRefusesATakenSlug(): void
    {
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('findOneBySlug')->willReturn(new GalleryCategory());

        $this->expectException(ToolCallException::class);
        $this->provider($repository)->create('Item 1');
    }

    // An empty title is refused
    public function testCreateRefusesAnEmptyTitle(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider()->create(' ');
    }

    // A renamed gallery gets its slug rebuilt from the new title and its old url and the media urls below it redirected, its summary untouched
    public function testEditRenameRedirects(): void
    {
        $category = new GalleryCategory()->setTitle('catalog')->setSlug('catalog')->setSummarySocialNetwork('summary-1');
        $redirector = $this->createMock(GalleryUrlRedirector::class);
        $redirector->expects($this->exactly(2))->method('record');

        $this->provider($this->holding($category), redirector: $redirector)->edit(1, title: 'Catalog 2');
        $this->assertSame(['Catalog 2', 'catalog-2', 'summary-1'], [$category->getTitle(), $category->getSlug(), $category->getSummarySocialNetwork()]);
    }

    // The summary alone changes nothing of the url
    public function testEditSummaryKeepsTheSlug(): void
    {
        $category = new GalleryCategory()->setTitle('catalog')->setSlug('catalog');

        $this->provider($this->holding($category), redirector: $this->recordingNothing())->edit(1, title: 'catalog', summary: ' summary-2 ');
        $this->assertSame(['catalog', 'summary-2'], [$category->getSlug(), $category->getSummarySocialNetwork()]);
    }

    // A new title giving the same slug changes the title and redirects nothing
    public function testEditTitleKeepingTheSlugRedirectsNothing(): void
    {
        $category = new GalleryCategory()->setTitle('catalog')->setSlug('catalog');

        $this->provider($this->holding($category), redirector: $this->recordingNothing())->edit(1, title: 'Catalog');
        $this->assertSame(['Catalog', 'catalog'], [$category->getTitle(), $category->getSlug()]);
    }

    // A rename onto a slug another gallery holds is refused, as the category form refuses it
    public function testEditRefusesATakenSlug(): void
    {
        $category = new GalleryCategory()->setTitle('catalog')->setSlug('catalog');
        $repository = $this->holding($category);
        $repository->method('findOneBySlug')->willReturn(new GalleryCategory());

        try {
            $this->provider($repository)->edit(1, title: 'Catalog 2');
            $this->fail('A taken slug was accepted.');
        } catch (ToolCallException) {
            $this->assertSame(['catalog', 'catalog'], [$category->getTitle(), $category->getSlug()]);
        }
    }

    // A gallery in the trash answers as unknown
    public function testEditRefusesATrashedGallery(): void
    {
        $category = new GalleryCategory();
        $category->setIsDeleted(true);

        $this->expectException(ToolCallException::class);
        $this->provider($this->holding($category))->edit(1, title: 'catalog-2');
    }

    // Only the fields given of a media change, empty credits clearing them, its slug kept
    public function testEditMedia(): void
    {
        $media = new GalleryMedia()->setTitle('item-1')->setSlug('item-1')->setCredits('credits-1');
        $media->setCategory(new GalleryCategory());
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('find')->willReturn($media);

        $this->provider(mediaRepository: $mediaRepository)->editMedia(1, description: ' description-1 ', credits: '');
        $this->assertSame(['item-1', 'item-1', 'description-1', null], [$media->getTitle(), $media->getSlug(), $media->getDescription(), $media->getCredits()]);
    }

    // Credits longer than their column are refused rather than cut, as the back office refuses them, the title given with them left unwritten
    public function testEditMediaRefusesTooLongCredits(): void
    {
        $media = new GalleryMedia()->setTitle('item-1')->setCategory(new GalleryCategory());
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('find')->willReturn($media);

        try {
            $this->provider(mediaRepository: $mediaRepository)->editMedia(1, title: 'item-2', credits: str_repeat('a', 256));
            $this->fail('Too long credits were accepted.');
        } catch (ToolCallException) {
            $this->assertSame('item-1', $media->getTitle());
        }
    }

    // A media whose gallery is in the trash answers as unknown, even though the media itself is not
    public function testEditMediaRefusesAMediaOfATrashedGallery(): void
    {
        $category = new GalleryCategory();
        $category->setIsDeleted(true);
        $media = new GalleryMedia()->setCategory($category);
        $mediaRepository = $this->createStub(GalleryMediaRepository::class);
        $mediaRepository->method('find')->willReturn($media);

        $this->expectException(ToolCallException::class);
        $this->provider(mediaRepository: $mediaRepository)->editMedia(1, title: 'item-2');
    }

    // An account that does not edit the galleries is refused every tool
    public function testRefusedWithoutTheRole(): void
    {
        $this->expectException(ToolCallException::class);
        $this->provider(granted: false)->list();
    }

    // A category repository finding the given gallery
    private function holding(GalleryCategory $category): GalleryCategoryRepository & Stub
    {
        $repository = $this->createStub(GalleryCategoryRepository::class);
        $repository->method('find')->willReturn($category);

        return $repository;
    }

    // A redirector the test expects to record no row
    private function recordingNothing(): GalleryUrlRedirector
    {
        $redirector = $this->createMock(GalleryUrlRedirector::class);
        $redirector->expects($this->never())->method('record');

        return $redirector;
    }

    // An entity manager keeping what is persisted
    /** @param \ArrayObject<int, object> $persisted */
    private function persisting(\ArrayObject $persisted): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(static function (object $entity) use ($persisted): void {
            $persisted->append($entity);
        });

        return $entityManager;
    }

    // The provider for an account editing the galleries or not
    private function provider(?GalleryCategoryRepository $repository = null, ?GalleryMediaRepository $mediaRepository = null, ?EntityManagerInterface $entityManager = null, ?GalleryUrlRedirector $redirector = null, bool $granted = true): GalleryMcpProvider
    {
        $config = $this->createStub(ConfigServiceInterface::class);
        $config->method('get')->willReturn('ROLE_EDITOR');
        $security = $this->createStub(Security::class);
        $security->method('isGranted')->willReturn($granted);
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(static fn (string $route, array $parameters): string => '/gallery/' . $parameters['category']);

        return new GalleryMcpProvider(
            $repository ?? $this->createStub(GalleryCategoryRepository::class),
            $mediaRepository ?? $this->createStub(GalleryMediaRepository::class),
            new GalleryCategoryUrls($redirector ?? $this->createStub(GalleryUrlRedirector::class), $urlGenerator, new AsciiSlugger()),
            $entityManager ?? $this->createStub(EntityManagerInterface::class),
            $config,
            $security,
        );
    }
}
