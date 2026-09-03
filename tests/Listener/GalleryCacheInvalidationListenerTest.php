<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Tests\Listener;

use c975L\ConfigBundle\Entity\Config;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Listener\GalleryCacheInvalidationListener;
use c975L\GalleryBundle\Routing\GalleryRoutePrefix;
use c975L\GalleryBundle\Service\GalleryBlockCacheInvalidator;
use c975L\UiBundle\Entity\Block;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostFlushEventArgs;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use PHPUnit\Framework\TestCase;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

// Which change drops the cached blocks - UiBundle only ever invalidates the Block that was edited, and knows nothing of what these two kinds query at render time
class GalleryCacheInvalidationListenerTest extends TestCase
{
    private array $invalidated;

    protected function setUp(): void
    {
        $this->invalidated = [];
    }

    public function testAGalleryOrAPhotographChangeDropsTheBlocks(): void
    {
        foreach ([new GalleryCategory(), new GalleryMedia()] as $entity) {
            $this->invalidated = [];
            $this->listen($entity);

            $this->assertSame([[GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES]], $this->invalidated, $entity::class);
        }
    }

    // The prefix opens every url the blocks print, and the thumbnail setting picks the class each tile is drawn with: neither touches a row of this bundle
    public function testASettingTheBlocksAreDrawnWithDropsThem(): void
    {
        foreach ([GalleryRoutePrefix::SLUG, 'gallery-thumbnail-whole'] as $slug) {
            $this->invalidated = [];
            $this->listen($this->config($slug));

            $this->assertSame([[GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES]], $this->invalidated, $slug);
        }
    }

    // Every entity of the site travels through these events, and the galleries are not concerned by most of them
    public function testAnEntityOfAnotherBundleDropsNothing(): void
    {
        $this->listen(new Block());

        $this->assertSame([], $this->invalidated);
    }

    // Emptying the entry closes the gallery of the last additions, which takes it out of every listing without a single row of this bundle being touched
    public function testASettingOfTheAutomaticGalleriesDropsTheBlocks(): void
    {
        $listener = $this->createListener();
        $manager = $this->createStub(EntityManagerInterface::class);

        $listener->postUpdate(new PostUpdateEventArgs($this->config('gallery-latest-days'), $manager));
        $this->assertSame([], $this->invalidated, 'nothing until the flush is over');

        $listener->postFlush(new PostFlushEventArgs($manager));
        $this->assertSame([[GalleryBlockCacheInvalidator::CACHE_TAG_GALLERIES]], $this->invalidated);
    }

    // The whole settings group is saved at once: the tag goes once, not once per row
    public function testAGroupOfSettingsDropsTheTagOnlyOnce(): void
    {
        $listener = $this->createListener();
        $manager = $this->createStub(EntityManagerInterface::class);

        foreach (['gallery-latest-days', 'gallery-latest-max', 'gallery-print-enabled', GalleryRoutePrefix::SLUG, 'gallery-thumbnail-whole'] as $slug) {
            $listener->postUpdate(new PostUpdateEventArgs($this->config($slug), $manager));
        }

        $listener->postFlush(new PostFlushEventArgs($manager));

        $this->assertCount(1, $this->invalidated);
    }

    public function testASettingOfAnotherBundleDropsNothing(): void
    {
        $listener = $this->createListener();
        $manager = $this->createStub(EntityManagerInterface::class);

        $listener->postUpdate(new PostUpdateEventArgs($this->config('site-name'), $manager));
        $listener->postFlush(new PostFlushEventArgs($manager));

        $this->assertSame([], $this->invalidated);
    }

    // A photograph added to an already-cached gallery is an INSERT, for which postUpdate never fires - and deleting a gallery of five hundred raises the three events as many times, for one tag dropped after the commit
    public function testTheThreeEventsAllInvalidateOnceTheFlushIsOver(): void
    {
        $listener = $this->createListener();
        $manager = $this->createStub(EntityManagerInterface::class);
        $media = new GalleryMedia();

        $listener->postPersist(new PostPersistEventArgs($media, $manager));
        $listener->postUpdate(new PostUpdateEventArgs($media, $manager));
        $listener->preRemove(new PreRemoveEventArgs($media, $manager));
        $this->assertSame([], $this->invalidated, 'nothing until the flush is over');

        $listener->postFlush(new PostFlushEventArgs($manager));

        $this->assertCount(1, $this->invalidated);
    }

    private function config(string $slug): Config
    {
        $config = new Config();
        $config->setSlug($slug);

        return $config;
    }

    // The whole cycle a save goes through: the event raises the flag, the flush that follows drops the tag
    private function listen(object $entity): void
    {
        $listener = $this->createListener();
        $manager = $this->createStub(EntityManagerInterface::class);

        $listener->postUpdate(new PostUpdateEventArgs($entity, $manager));
        $listener->postFlush(new PostFlushEventArgs($manager));
    }

    private function createListener(): GalleryCacheInvalidationListener
    {
        $cache = $this->createStub(TagAwareCacheInterface::class);
        $cache->method('invalidateTags')->willReturnCallback(function (array $tags): bool {
            $this->invalidated[] = $tags;

            return true;
        });

        return new GalleryCacheInvalidationListener(new GalleryBlockCacheInvalidator($cache));
    }
}
