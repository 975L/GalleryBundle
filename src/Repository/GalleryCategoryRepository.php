<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Repository;

use c975L\GalleryBundle\Entity\Gallery;
use c975L\GalleryBundle\Entity\GalleryCategory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryCategory>
 */
class GalleryCategoryRepository extends ServiceEntityRepository
{
    private const UNCATEGORIZED_SLUG = 'non-classe';

    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryCategory::class);
    }

    public function findOneBySlug(Gallery $gallery, string $slug): ?GalleryCategory
    {
        return $this->findOneBy(['gallery' => $gallery, 'slug' => $slug]);
    }

    // Catch-all category a GalleryPhoto falls back to when uploaded without picking a real one.
    // Created lazily (rather than eagerly whenever a Gallery is persisted) so it only ever exists
    // once it's actually needed, and flushed immediately so it's safe to reference the same row
    // from within the same request right after.
    public function findOrCreateUncategorized(Gallery $gallery): GalleryCategory
    {
        $category = $this->findOneBy(['gallery' => $gallery, 'uncategorized' => true]);
        if (null !== $category) {
            return $category;
        }

        // Plain literal title (not a translation key): like any other category it's a normal DB
        // row, editable/renamable later from the Management CRUD
        $category = (new GalleryCategory())
            ->setGallery($gallery)
            ->setSlug(self::UNCATEGORIZED_SLUG)
            ->setTitle('Non classé')
            ->setUncategorized(true)
        ;

        $em = $this->getEntityManager();
        $em->persist($category);
        $em->flush();

        return $category;
    }
}
