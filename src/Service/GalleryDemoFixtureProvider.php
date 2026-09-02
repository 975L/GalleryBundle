<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\UiBundle\Contract\DemoFixtureProviderInterface;
use c975L\UiBundle\Registry\PlaceholderMediaRegistry;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Contracts\Translation\TranslatorInterface;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

// The gallery a demo site is seeded with, from the very data the block showcase renders (see GallerySampleCatalog) - persisted here, where the showcase only ever builds arrays
class GalleryDemoFixtureProvider implements DemoFixtureProviderInterface
{
    public function __construct(
        private readonly GallerySampleCatalog $catalog,
        private readonly TranslatorInterface $translator,
        private readonly PlaceholderMediaRegistry $placeholderMediaRegistry,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    /**
     * The medias ride the ORM cascade off GalleryCategory (see its "cascade: remove"/"orphanRemoval"), so a
     * category taken back by a reload has VichUploader's removal listener fire on each, taking the stored file - and
     * the thumbnail and high-res siblings drawn beside it - off the disk with the row. Only the categories are
     * yielded, and so recorded.
     */
    public function getDemoFixtures(): iterable
    {
        $images = $this->placeholderMediaRegistry->getImages();

        // A gallery is its photographs: with nothing to put in it, an empty category is a page saying a gallery exists and showing none of it, so nothing is seeded at all - the very reading the showcase makes of the same absence
        if ([] === $images) {
            return;
        }

        $index = 0;

        foreach ($this->catalog->getCategories() as $spec) {
            $category = new GalleryCategory();
            $category->setSlug($spec['slug']);
            $category->setTitle($this->trans($spec['title']));
            $category->setUncategorized(false);
            $category->setAutomaticKind(null);

            $mediaPosition = 0;
            foreach ($spec['medias'] as $mediaSpec) {
                $media = $this->media($mediaSpec, $this->catalog->photograph($mediaSpec['slug'], $images, $index++), ++$mediaPosition);
                if (null === $media) {
                    continue;
                }

                $category->addMedia($media);
            }

            // The cover a real category falls back to when none was chosen is a random one of its medias, so naming the first is what a category filled in one go actually shows
            $category->setCoverMedia($category->getMedias()->first() ?: null);

            yield $category;
        }
    }

    /**
     * @param array{slug: string, title: string} $spec
     */
    private function media(array $spec, string $image, int $position): ?GalleryMedia
    {
        $file = $this->temporaryCopy($image);
        if (null === $file) {
            return null;
        }

        $media = new GalleryMedia();
        // Before the flush that names the uploaded file after it, the slug being half of where that file lands (see GalleryMedia::getVichMediaPath) - taken from the catalog rather than from GalleryMediaSlugger, a load emptying the tables first having nothing to be unique against but itself
        $media->setSlug($spec['slug']);
        $media->setTitle($this->trans($spec['title']));
        $media->setCredits($this->trans(GallerySampleCatalog::CREDITS_KEY));
        $media->setPosition($position);
        $media->setFile($file);

        return $media;
    }

    /**
     * VichUploader moves the file it is handed, so what it gets is a copy: the placeholder itself is read by every
     * other showcase of the site, and would be gone after the first load.
     *
     * A ReplacingFile rather than a plain File, which UploadHandler::hasUploadedFile() leaves silently ignored -
     * the row would be written with no file name and nothing would reach the disk.
     */
    private function temporaryCopy(string $publicPath): ?ReplacingFile
    {
        $source = $this->projectDir . '/public/' . $publicPath;
        if (!is_file($source)) {
            return null;
        }

        $target = sys_get_temp_dir() . '/c975l-demo-' . uniqid() . '-' . basename($publicPath);

        return copy($source, $target) ? new ReplacingFile($target, true, true, true) : null;
    }

    private function trans(string $key): string
    {
        return $this->translator->trans($key, [], 'gallery');
    }
}
