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

    // Previous/next neighbours within the same category, for the photo detail page's navigation - two indexed queries (rather than hydrating and array_search()-ing the whole category) so a category with hundreds of photos stays cheap; wraps around to the category's other edge when the photo is first/last, and falls back to itself in a single-photo category, same as the previous array-based logic
    public function findPreviousAndNext(GalleryPhoto $photo): array
    {
        $category = $photo->getCategory();

        return [
            'previous' => $this->findAdjacent($category, $photo, 'previous') ?? $this->findEdge($category, 'last'),
            'next' => $this->findAdjacent($category, $photo, 'next') ?? $this->findEdge($category, 'first'),
        ];
    }

    protected function findAdjacent(GalleryCategory $category, GalleryPhoto $photo, string $direction): ?GalleryPhoto
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.category = :category')
            ->andWhere('p != :photo')
            ->setParameter('category', $category)
            ->setParameter('photo', $photo)
            ->setParameter('position', $photo->getPosition())
            ->setParameter('id', $photo->getId())
            ->setMaxResults(1)
        ;

        if ('next' === $direction) {
            $qb->andWhere('p.position > :position OR (p.position = :position AND p.id > :id)')
                ->orderBy('p.position', 'ASC')->addOrderBy('p.id', 'ASC');
        } else {
            $qb->andWhere('p.position < :position OR (p.position = :position AND p.id < :id)')
                ->orderBy('p.position', 'DESC')->addOrderBy('p.id', 'DESC');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    protected function findEdge(GalleryCategory $category, string $edge): ?GalleryPhoto
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.category = :category')
            ->setParameter('category', $category)
            ->setMaxResults(1)
        ;

        if ('first' === $edge) {
            $qb->orderBy('p.position', 'ASC')->addOrderBy('p.id', 'ASC');
        } else {
            $qb->orderBy('p.position', 'DESC')->addOrderBy('p.id', 'DESC');
        }

        return $qb->getQuery()->getOneOrNullResult();
    }
}
