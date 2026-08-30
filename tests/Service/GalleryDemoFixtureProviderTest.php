<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Service\GalleryDemoFixtureProvider;
use c975L\GalleryBundle\Service\GallerySampleCatalog;
use c975L\UiBundle\Registry\PlaceholderMediaRegistry;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

class GalleryDemoFixtureProviderTest extends TestCase
{
    private const string IMAGE = 'showcase/photo.webp';

    private string $projectDir;

    /** @var list<string> */
    private array $temporaryCopies = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/gallery-demo-test-' . uniqid();
        new Filesystem()->mkdir($this->projectDir . '/public/showcase');
        file_put_contents($this->projectDir . '/public/' . self::IMAGE, 'image');
    }

    // The copies handed to VichUploader live in the system's temp directory, where a real load has them moved away - nothing moves them here, so the test takes them back itself
    protected function tearDown(): void
    {
        new Filesystem()->remove([$this->projectDir, ...$this->temporaryCopies]);
    }

    private function createProvider(array $images = [self::IMAGE]): GalleryDemoFixtureProvider
    {
        $translator = $this->createStub(TranslatorInterface::class);
        $translator->method('trans')->willReturnArgument(0);

        $registry = $this->createStub(PlaceholderMediaRegistry::class);
        $registry->method('getImages')->willReturn($images);

        return new GalleryDemoFixtureProvider(new GallerySampleCatalog($registry), $translator, $registry, $this->projectDir);
    }

    /** @return list<GalleryCategory> */
    private function categories(GalleryDemoFixtureProvider $provider): array
    {
        $categories = iterator_to_array($provider->getDemoFixtures(), false);

        foreach ($categories as $category) {
            foreach ($category->getMedias() as $media) {
                $this->temporaryCopies[] = (string) $media->getFile()?->getPathname();
            }
        }

        return $categories;
    }

    public function testEveryCategoryOfTheCatalogIsSeededWithItsMedias(): void
    {
        $catalog = new GallerySampleCatalog($this->createStub(PlaceholderMediaRegistry::class));
        $categories = $this->categories($this->createProvider());

        $this->assertSame(
            array_column($catalog->getCategories(), 'slug'),
            array_map(fn (GalleryCategory $category): ?string => $category->getSlug(), $categories)
        );

        foreach ($categories as $index => $category) {
            $this->assertCount(count($catalog->getCategories()[$index]['medias']), $category->getMedias(), (string) $category->getSlug());
        }
    }

    // A real category with no cover chosen falls back to a random one of its medias, so naming the first is what a category filled in one go shows
    public function testEveryCategoryNamesItsFirstMediaAsItsCover(): void
    {
        foreach ($this->categories($this->createProvider()) as $category) {
            $this->assertSame($category->getMedias()->first(), $category->getCoverMedia());
        }
    }

    // Set before the flush that names the uploaded file after it, the slug being half of where that file lands
    public function testEveryMediaCarriesItsSlugAndItsFile(): void
    {
        foreach ($this->categories($this->createProvider()) as $category) {
            foreach ($category->getMedias() as $media) {
                $this->assertNotNull($media->getSlug());
                // A plain File is what UploadHandler::hasUploadedFile() silently ignores: the row would be written with no file name and nothing would reach the disk
                $this->assertInstanceOf(ReplacingFile::class, $media->getFile());
                $this->assertFileExists($media->getFile()->getPathname());
            }
        }
    }

    public function testThePlaceholderItselfIsNeverHandedOver(): void
    {
        $media = $this->categories($this->createProvider())[0]->getMedias()->first();

        $this->assertNotSame($this->projectDir . '/public/' . self::IMAGE, $media->getFile()->getPathname());
        $this->assertFileExists($this->projectDir . '/public/' . self::IMAGE);
    }
}
