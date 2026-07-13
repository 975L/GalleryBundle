<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Repository;

use c975L\GalleryBundle\Entity\GalleryCategory;
use c975L\GalleryBundle\Entity\GalleryPhoto;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryPhoto>
 */
class GalleryPhotoRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryPhoto::class);
    }

    /** @return GalleryPhoto[] */
    public function findByCategory(GalleryCategory $category): array
    {
        return $this->findBy(['category' => $category], ['position' => 'ASC']);
    }

    // Previous/next neighbours within the same category, for the photo detail page's navigation
    public function findPreviousAndNext(GalleryPhoto $photo): array
    {
        $photos = $this->findByCategory($photo->getCategory());
        $count = count($photos);

        $index = array_search($photo, $photos, true);
        if (false === $index) {
            return ['previous' => null, 'next' => null];
        }

        return [
            'previous' => $photos[0 === $index ? $count - 1 : $index - 1],
            'next' => $photos[$count - 1 === $index ? 0 : $index + 1],
        ];
    }
}
