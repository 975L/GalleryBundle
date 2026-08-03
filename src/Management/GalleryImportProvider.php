<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Management;

use c975L\ConfigBundle\Management\ImportProviderInterface;
use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use c975L\GalleryBundle\Repository\GalleryCategoryRepository;
use c975L\GalleryBundle\Repository\GalleryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Vich\UploaderBundle\FileAbstraction\ReplacingFile;

// Imports a "gallery_category" content export (see GalleryCategoryCrudController::exportSelection/ContentExporter) - the export unit is a GalleryCategory (with its Photos), not the whole Gallery: that's the granularity the admin actually checks boxes for. The parent Gallery is found-or-created by slug (a site with the single default gallery just gets it recreated under the same slug, see GalleryRepository::findOrCreateDefault()). Matches the category by (gallery, slug); Photos have no natural key of their own, so the category's whole photo collection is replaced, same principle as PageImportProvider replacing a Page's Blocks
class GalleryImportProvider implements ImportProviderInterface
{
    public const KIND = 'gallery_category';

    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly GalleryRepository $galleryRepository,
        private readonly GalleryCategoryRepository $categoryRepository,
    ) {
    }

    public function supportsImport(string $kind): bool
    {
        return self::KIND === $kind;
    }

    public function import(array $items, ?string $filesDir = null): array
    {
        $created = 0;
        $updated = 0;
        $galleriesBySlug = [];
        $defaultTaken = false;

        foreach ($items as $item) {
            // Cached in-memory per gallerySlug so two items sharing a not-yet-existing gallery reuse the same new Gallery instead of each creating one (findOneBySlug can't see an unflushed persist)
            $gallery = $galleriesBySlug[$item['gallerySlug']] ??= $this->galleryRepository->findOneBySlug($item['gallerySlug']);
            if (null === $gallery) {
                // Only one Gallery can be the default one (findDefault() would return an arbitrary one otherwise), so an import never steals that flag from an existing local gallery, nor sets it twice within the same batch (findDefault can't see an unflushed persist)
                $isDefault = ($item['galleryDefault'] ?? false) && !$defaultTaken && null === $this->galleryRepository->findDefault();
                $defaultTaken = $defaultTaken || $isDefault;

                $gallery = (new Gallery())
                    ->setSlug($item['gallerySlug'])
                    ->setTitle($item['galleryTitle'] ?? $item['gallerySlug'])
                    ->setPosition($item['galleryPosition'] ?? 0)
                    ->setDefault($isDefault);
                $this->em->persist($gallery);
                $galleriesBySlug[$item['gallerySlug']] = $gallery;
            }

            $category = $this->categoryRepository->findOneBySlug($gallery, $item['slug']);
            $isNew = null === $category;
            $category ??= new GalleryCategory();
            $gallery->addCategory($category);

            $category
                ->setSlug($item['slug'])
                ->setTitle($item['title'])
                ->setPosition($item['position'] ?? 0)
                ->setUncategorized($item['uncategorized'] ?? false)
                ->setCoverPhoto(null);

            // Existing Photos have no natural key to match the imported ones against, so the whole collection is replaced - orphanRemoval on GalleryCategory::$photos deletes the orphaned rows on flush
            foreach ($category->getPhotos()->toArray() as $existingPhoto) {
                $category->removePhoto($existingPhoto);
            }

            $newPhotos = [];
            foreach ($item['photos'] ?? [] as $photoData) {
                $photo = $this->buildPhoto($photoData, $filesDir);
                $this->em->persist($photo);
                $category->addPhoto($photo);
                $newPhotos[] = $photo;
            }

            $coverIndex = $item['coverPhotoIndex'] ?? null;
            if (null !== $coverIndex && isset($newPhotos[$coverIndex])) {
                $category->setCoverPhoto($newPhotos[$coverIndex]);
            }

            $this->em->persist($category);
            $isNew ? $created++ : $updated++;
        }

        $this->em->flush();

        return ['created' => $created, 'updated' => $updated];
    }

    // Same ReplacingFile technique as PageImportProvider/FontImportProvider - see PageCrudController::cloneMedia() for why a plain File won't do
    private function buildPhoto(array $photoData, ?string $filesDir): GalleryPhoto
    {
        $photo = (new GalleryPhoto())
            ->setAlt($photoData['alt'] ?? null)
            ->setCredits($photoData['credits'] ?? null)
            ->setRightsReserved($photoData['rightsReserved'] ?? false)
            ->setMediaType($photoData['mediaType'] ?? null)
            ->setExternalId($photoData['externalId'] ?? null)
            ->setPosition($photoData['position'] ?? 0);

        if (null !== $filesDir && isset($photoData['file'])) {
            $photo->setFile(new ReplacingFile($filesDir . '/' . $photoData['file'], true, true, true));
        }

        return $photo;
    }
}
