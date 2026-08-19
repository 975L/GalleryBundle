<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Management;

use c975L\ConfigBundle\Entity\HealthCheckResult;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Management\GalleryFilesHealthCheckProvider;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGeneratorInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;

class GalleryFilesHealthCheckProviderTest extends TestCase
{
    private string $projectDir;

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/gallery-files-health-check-test-' . uniqid();
        new Filesystem()->mkdir($this->projectDir . '/public');
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    /**
     * @param array<int, array{0: GalleryMedia, 1: string[]}> $rows the media and the files of its own that sit on disk
     */
    private function createProvider(array $rows): GalleryFilesHealthCheckProvider
    {
        $medias = [];
        foreach ($rows as [$media, $onDisk]) {
            foreach ($onDisk as $filename) {
                $path = $this->projectDir . '/public/' . $filename;
                new Filesystem()->mkdir(\dirname($path));
                file_put_contents($path, 'file');
            }

            $medias[] = $media;
        }

        $galleryMediaRepository = $this->createStub(GalleryMediaRepository::class);
        $galleryMediaRepository->method('findWithFilename')->willReturn($medias);

        $configService = $this->createStub(ConfigServiceInterface::class);
        $configService->method('get')->willReturn('https://example.com');

        $adminUrlGenerator = $this->createStub(AdminUrlGeneratorInterface::class);
        $adminUrlGenerator->method('unsetAll')->willReturnSelf();
        $adminUrlGenerator->method('setController')->willReturnSelf();
        $adminUrlGenerator->method('setAction')->willReturnSelf();
        $adminUrlGenerator->method('setEntityId')->willReturnSelf();
        $adminUrlGenerator->method('generateUrl')->willReturn('/management/gallery-media/edit');

        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnCallback(
            static fn (string $id, array $params = []) => $id . '|' . implode('', $params)
        );

        return new GalleryFilesHealthCheckProvider(
            $galleryMediaRepository,
            $adminUrlGenerator,
            $configService,
            $translator,
            $this->projectDir,
        );
    }

    private function createMedia(string $filename, ?string $videoFilename = null, int $id = 1): GalleryMedia
    {
        $media = new GalleryMedia()
            ->setTitle('Coucher de soleil')
            ->setSlug('coucher-de-soleil')
            ->setFilename($filename)
            ->setVideoFilename($videoFilename)
        ;
        $media->setCategory(new GalleryCategory()->setSlug('soleil'));
        new \ReflectionProperty(GalleryMedia::class, 'id')->setValue($media, $id);

        return $media;
    }

    public function testGetKind(): void
    {
        $this->assertSame('files-gallery', $this->createProvider([])->getKind());
    }

    public function testAGalleryDeclaringNoFileReportsNothing(): void
    {
        $this->assertSame([], $this->createProvider([])->runChecks());
    }

    public function testADeclaredFileMissingFromTheServerIsAnError(): void
    {
        $rows = $this->createProvider([[$this->createMedia('medias/gallery/soleil/coucher-de-soleil-abc.webp'), []]])->runChecks();

        $this->assertCount(1, $rows);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $rows[0]['status']);
        $this->assertSame('https://example.com/medias/gallery/soleil/coucher-de-soleil-abc.webp', $rows[0]['url']);
        $this->assertStringContainsString('label.health_check_declared_file_missing', $rows[0]['summary']);
    }

    // The OK row is what lets a re-uploaded file go back to green: results are kept per url and kind
    public function testAFileInPlaceStillGetsItsRow(): void
    {
        $rows = $this->createProvider([[
            $this->createMedia('medias/gallery/soleil/coucher-de-soleil-abc.webp'),
            ['medias/gallery/soleil/coucher-de-soleil-abc.webp'],
        ]])->runChecks();

        $this->assertCount(1, $rows);
        $this->assertSame(HealthCheckResult::STATUS_OK, $rows[0]['status']);
    }

    // A thousand medias share a handful of titles, so the category is part of what tells two rows apart on the dashboard
    public function testTheRowIsLabelledByItsCategoryAndTitle(): void
    {
        $rows = $this->createProvider([[$this->createMedia('medias/gallery/soleil/coucher-de-soleil-abc.webp'), []]])->runChecks();

        $this->assertSame('soleil / Coucher de soleil', $rows[0]['label']);
    }

    // Two rows for one media, so the dashboard says which of its two files went missing rather than which media is incomplete
    public function testAMediaHostingItsOwnVideoGetsARowForEachFile(): void
    {
        $rows = $this->createProvider([[
            $this->createMedia('medias/gallery/soleil/coucher-de-soleil-abc.webp', 'medias/gallery/soleil/coucher-de-soleil-abc.mp4'),
            ['medias/gallery/soleil/coucher-de-soleil-abc.webp'],
        ]])->runChecks();

        $this->assertCount(2, $rows);
        $this->assertSame(HealthCheckResult::STATUS_OK, $rows[0]['status']);
        $this->assertSame(HealthCheckResult::STATUS_ERROR, $rows[1]['status']);
        $this->assertSame('https://example.com/medias/gallery/soleil/coucher-de-soleil-abc.mp4', $rows[1]['url']);
    }
}
