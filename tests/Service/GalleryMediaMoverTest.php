<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Service;

use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Repository\RedirectRepository;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Service\GalleryMediaMover;
use c975L\GalleryBundle\Service\GalleryMediaSlugger;
use c975L\GalleryBundle\Service\GalleryUrlRedirector;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\AsciiSlugger;

class GalleryMediaMoverTest extends TestCase
{
    private string $projectDir;

    /** @var list<object> */
    private array $persisted = [];

    protected function setUp(): void
    {
        $this->projectDir = sys_get_temp_dir() . '/gallery-mover-test-' . uniqid();
        new Filesystem()->mkdir([$this->projectDir . '/public', $this->projectDir . '/private']);
        $this->persisted = [];
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->projectDir);
    }

    private function createMover(): GalleryMediaMover
    {
        $urlGenerator = $this->createStub(UrlGeneratorInterface::class);
        $urlGenerator->method('generate')->willReturnCallback(
            static fn (string $route, array $parameters = []): string => '/galerie/' . $parameters['category'] . '/' . $parameters['slug']
        );

        return new GalleryMediaMover(
            new GalleryMediaSlugger(new AsciiSlugger()),
            new GalleryUrlRedirector($this->createStub(RedirectRepository::class)),
            $urlGenerator,
            $this->projectDir,
        );
    }

    // Records what the redirector persists, the two urls of a moved media being read back off it
    private function createEntityManager(): EntityManagerInterface
    {
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('persist')->willReturnCallback(function (object $entity): void {
            $this->persisted[] = $entity;
        });

        return $entityManager;
    }

    // The event the mover moves its files on, once the rows have actually been written
    private function flushed(): PostFlushEventArgs
    {
        return $this->createStub(PostFlushEventArgs::class);
    }

    private function createCategory(string $slug, string $title): GalleryCategory
    {
        return new GalleryCategory()->setSlug($slug)->setTitle($title);
    }

    // The shape a real upload leaves behind: a stored webp under the gallery's own directory, its two derivatives named after it, and the kept original outside public/
    private function createMedia(GalleryCategory $category, string $slug, int $position = 0): GalleryMedia
    {
        $media = new GalleryMedia()
            ->setTitle(ucfirst($slug))
            ->setSlug($slug)
            ->setPosition($position)
            ->setFilename('medias/gallery/' . $category->getSlug() . '/' . $slug . '-abc123.webp')
        ;

        $media->setOriginalFilename('medias/gallery/' . $category->getSlug() . '/' . $slug . '-abc123-original.jpg');
        $category->addMedia($media);

        return $media;
    }

    private function writeFilesOf(GalleryMedia $media): void
    {
        foreach ([$media->getFilename(), $media->getThumbnailFilename(), $media->getHighresFilename()] as $filename) {
            $this->writeFile('public/' . $filename);
        }

        $this->writeFile('private/' . $media->getOriginalFilename());
    }

    private function writeFile(string $relativePath, string $content = 'file'): void
    {
        $path = $this->projectDir . '/' . $relativePath;
        new Filesystem()->mkdir(\dirname($path));
        file_put_contents($path, $content);
    }

    private function exists(string $relativePath): bool
    {
        return is_file($this->projectDir . '/' . $relativePath);
    }

    // The whole set follows the media into the gallery it now belongs to, the kept original included
    public function testTheFilesFollowTheMediaIntoItsNewGallery(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');
        $this->writeFilesOf($media);

        $mover = $this->createMover();
        $mover->move($this->createEntityManager(), [$media], $target);
        $mover->postFlush($this->flushed());

        $this->assertTrue($this->exists('public/medias/gallery/volvo/volvo-240-abc123.webp'));
        $this->assertTrue($this->exists('public/medias/gallery/volvo/volvo-240-abc123-thumb.webp'));
        $this->assertTrue($this->exists('public/medias/gallery/volvo/volvo-240-abc123-highres.webp'));
        $this->assertTrue($this->exists('private/medias/gallery/volvo/volvo-240-abc123-original.jpg'));
        $this->assertFalse($this->exists('public/medias/gallery/voitures/volvo-240-abc123.webp'));
    }

    // The row names the files where they now are - the name itself never changes, only the directory above it
    public function testTheRowNamesTheFilesWhereTheyNowAre(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');

        $this->createMover()->move($this->createEntityManager(), [$media], $target);

        $this->assertSame('medias/gallery/volvo/volvo-240-abc123.webp', $media->getFilename());
        $this->assertSame('medias/gallery/volvo/volvo-240-abc123-thumb.webp', $media->getThumbnailFilename());
        $this->assertSame('medias/gallery/volvo/volvo-240-abc123-original.jpg', $media->getOriginalFilename());
        $this->assertSame($target, $media->getCategory());
    }

    // Nothing is touched on disk until the flush has gone through, a failed one leaving every file where the rows still point at it
    public function testNothingIsMovedOnDiskBeforeTheFlush(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');
        $this->writeFilesOf($media);

        $this->createMover()->move($this->createEntityManager(), [$media], $target);

        $this->assertTrue($this->exists('public/medias/gallery/voitures/volvo-240-abc123.webp'));
        $this->assertFalse($this->exists('public/medias/gallery/volvo/volvo-240-abc123.webp'));
    }

    // The video a media carries of its own follows it too
    public function testTheSelfHostedVideoFollowsTheMedia(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');
        $media->setVideoFilename('medias/gallery/voitures/volvo-240-abc123.mp4');
        $this->writeFile('public/medias/gallery/voitures/volvo-240-abc123.mp4');

        $mover = $this->createMover();
        $mover->move($this->createEntityManager(), [$media], $target);
        $mover->postFlush($this->flushed());

        $this->assertSame('medias/gallery/volvo/volvo-240-abc123.mp4', $media->getVideoFilename());
        $this->assertTrue($this->exists('public/medias/gallery/volvo/volvo-240-abc123.mp4'));
    }

    // A slug is only unique within its gallery, so one already taken where the media arrives is suffixed rather than refused
    public function testASlugAlreadyTakenInTheArrivalGalleryIsSuffixed(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $this->createMedia($target, 'volvo-240');
        $media = $this->createMedia($source, 'volvo-240');

        $this->createMover()->move($this->createEntityManager(), [$media], $target);

        $this->assertSame('volvo-240-2', $media->getSlug());
    }

    // The media's page moves with it, the gallery's slug being the segment above its own
    public function testTheOldMediaPageIsLeftRedirectingToTheNewOne(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');

        $this->createMover()->move($this->createEntityManager(), [$media], $target);

        $redirects = array_values(array_filter($this->persisted, static fn (object $entity): bool => $entity instanceof Redirect));
        $this->assertCount(1, $redirects);
        $this->assertSame('/galerie/voitures/volvo-240', $redirects[0]->getFromPath());
        $this->assertSame('/galerie/volvo/volvo-240', $redirects[0]->getToUrl());
    }

    // The medias arrive after what the gallery already holds, and the gap they leave behind is closed
    public function testTheRanksOfBothGalleriesAreRenumbered(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $this->createMedia($target, 'volvo-p1800', 0);
        $stayed = $this->createMedia($source, 'renault-4', 0);
        $moved = $this->createMedia($source, 'volvo-240', 1);
        $alsoStayed = $this->createMedia($source, 'peugeot-205', 2);

        $this->createMover()->move($this->createEntityManager(), [$moved], $target);

        $this->assertSame(1, $moved->getPosition());
        $this->assertSame(0, $stayed->getPosition());
        $this->assertSame(1, $alsoStayed->getPosition());
    }

    // Filled, the title root renumbers the titles from where the arrival gallery leaves off - exactly as a batch upload numbers its own
    public function testTheTitleRootRenumbersTheTitlesFromWhereTheGalleryLeavesOff(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $this->createMedia($target, 'volvo-p1800', 0);
        $first = $this->createMedia($source, 'voiture-12', 0);
        $second = $this->createMedia($source, 'voiture-13', 1);

        $this->createMover()->move($this->createEntityManager(), [$first, $second], $target, 'Volvo');

        $this->assertSame('Volvo 2', $first->getTitle());
        $this->assertSame('Volvo 3', $second->getTitle());
    }

    // The relation carries no OrderBy, so a gallery dragged into a new order loads its medias in the order they were uploaded - the numbered titles must follow the grid, not that
    public function testTheSelectionIsMovedInTheOrderTheGridShowsIt(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $first = $this->createMedia($source, 'voiture-12', 2);
        $second = $this->createMedia($source, 'voiture-13', 0);
        $third = $this->createMedia($source, 'voiture-14', 1);

        $this->createMover()->move($this->createEntityManager(), [$first, $second, $third], $target, 'Volvo');

        $this->assertSame('Volvo 1', $second->getTitle());
        $this->assertSame('Volvo 2', $third->getTitle());
        $this->assertSame('Volvo 3', $first->getTitle());
        $this->assertSame([0, 1, 2], [$second->getPosition(), $third->getPosition(), $first->getPosition()]);
    }

    // Left empty it changes nothing, the medias keeping the titles they had
    public function testAnEmptyTitleRootKeepsTheTitlesTheMediasHad(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');

        $this->createMover()->move($this->createEntityManager(), [$media], $target, '  ');

        $this->assertSame('Volvo-240', $media->getTitle());
    }

    // The gallery would go on showing a cover it no longer holds
    public function testTheCoverOfTheGalleryLeftBehindIsReleased(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');
        $source->setCoverMedia($media);

        $this->createMover()->move($this->createEntityManager(), [$media], $target);

        $this->assertNull($source->getCoverMedia());
    }

    // Its own collection goes on holding it: that relation is orphanRemoval, and taking the media out of it would have the flush delete the very row being moved
    public function testTheGalleryLeftBehindKeepsTheMediaInItsCollection(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');

        $this->createMover()->move($this->createEntityManager(), [$media], $target);

        $this->assertTrue($source->getMedias()->contains($media));
        $this->assertTrue($target->getMedias()->contains($media));
    }

    // A media already where it is asked to go is counted for nothing rather than renumbered and redirected for no reason
    public function testAMediaAlreadyInTheArrivalGalleryIsSkipped(): void
    {
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($target, 'volvo-240', 3);

        $moved = $this->createMover()->move($this->createEntityManager(), [$media], $target);

        $this->assertSame(0, $moved);
        $this->assertSame(3, $media->getPosition());
        $this->assertSame([], $this->persisted);
    }

    // A media whose file is gone contributes nothing rather than failing the whole move
    public function testAFileThatIsGoneIsSkippedRatherThanFailing(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');
        $this->writeFile('public/' . $media->getFilename());

        $mover = $this->createMover();
        $mover->move($this->createEntityManager(), [$media], $target);
        $mover->postFlush($this->flushed());

        $this->assertTrue($this->exists('public/medias/gallery/volvo/volvo-240-abc123.webp'));
        $this->assertFalse($this->exists('public/medias/gallery/volvo/volvo-240-abc123-thumb.webp'));
    }

    // A name written by something that is not this bundle is left exactly where it is, exactly as an import only honours those under its own directory
    public function testANameOutsideTheBundlesDirectoryIsLeftWhereItIs(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');
        $media->setFilename('uploads/legacy.webp');

        $mover = $this->createMover();
        $mover->move($this->createEntityManager(), [$media], $target);

        $this->assertSame('uploads/legacy.webp', $media->getFilename());
    }

    // A file replaced in the very save that moves the media is Vich's to store, under the gallery the media now belongs to - moving what is about to be deleted would race the cleanup for the same names
    public function testAFileBeingReplacedIsLeftToVich(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');
        $this->writeFile('public/replacement.webp');
        $media->setFile(new File($this->projectDir . '/public/replacement.webp'));

        $media->setCategory($target);

        $mover = $this->createMover();
        $mover->follow($media, $source);
        $mover->postFlush($this->flushed());

        $this->assertSame('medias/gallery/voitures/volvo-240-abc123.webp', $media->getFilename());
    }

    // The gallery emptied of its last media leaves no directory behind, named after a slug no file hangs under any more
    public function testTheEmptiedDirectoryIsRemoved(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($source, 'volvo-240');
        $this->writeFilesOf($media);

        $mover = $this->createMover();
        $mover->move($this->createEntityManager(), [$media], $target);
        $mover->postFlush($this->flushed());

        $this->assertFalse(is_dir($this->projectDir . '/public/medias/gallery/voitures'));
        $this->assertFalse(is_dir($this->projectDir . '/private/medias/gallery/voitures'));
    }

    // A media its own edit form has already moved: the rank an admin typed there is honoured rather than overwritten
    public function testFollowKeepsTheRankTheAdminTyped(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $this->createMedia($target, 'volvo-p1800', 0);
        $media = $this->createMedia($source, 'volvo-240');
        $media->setCategory($target)->setPosition(7);

        $this->createMover()->follow($media, $source, keepPosition: true);

        $this->assertSame(7, $media->getPosition());
    }

    // An untouched one simply lands after what the arrival gallery already holds
    public function testFollowAppendsAnUntouchedRank(): void
    {
        $source = $this->createCategory('voitures', 'Voitures');
        $target = $this->createCategory('volvo', 'Volvo');
        $this->createMedia($target, 'volvo-p1800', 4);
        $media = $this->createMedia($source, 'volvo-240');
        $media->setCategory($target);

        $this->createMover()->follow($media, $source);

        $this->assertSame(5, $media->getPosition());
    }

    // A media saved without its gallery having changed goes through none of this
    public function testFollowDoesNothingWhenTheGalleryHasNotChanged(): void
    {
        $target = $this->createCategory('volvo', 'Volvo');
        $media = $this->createMedia($target, 'volvo-240', 3);

        $this->createMover()->follow($media, $target);

        $this->assertSame(3, $media->getPosition());
        $this->assertSame('medias/gallery/volvo/volvo-240-abc123.webp', $media->getFilename());
    }
}
