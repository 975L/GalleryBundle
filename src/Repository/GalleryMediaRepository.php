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
use c975L\GalleryBundle\Entity\GalleryMedia;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryMedia>
 */
class GalleryMediaRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryMedia::class);
    }

    // What the public grid lists, the trash left out of it
    /** @return GalleryMedia[] */
    public function findByCategory(GalleryCategory $category): array
    {
        return $this->findBy(['category' => $category, 'isDeleted' => false], ['position' => 'ASC']);
    }

    // What the public media page resolves on - the category is part of the match, a slug only being unique within it (see GalleryMediaSlugger)
    // Unfiltered on purpose, like GalleryCategoryRepository::findOneBySlug(): the page answers 410 for a trashed media, which it can only do holding the row
    public function findOneBySlugInCategory(GalleryCategory $category, string $slug): ?GalleryMedia
    {
        return $this->findOneBy(['category' => $category, 'slug' => $slug]);
    }

    // Previous/next neighbours within the same category, for the media detail page's navigation - two indexed queries (rather than hydrating and array_search()-ing the whole category) so a category with hundreds of medias stays cheap; wraps around to the category's other edge when the media is first/last, and falls back to itself in a single-media category, same as the previous array-based logic
    public function findPreviousAndNext(GalleryMedia $media): array
    {
        $category = $media->getCategory();

        return [
            'previous' => $this->findAdjacent($category, $media, 'previous') ?? $this->findEdge($category, 'last'),
            'next' => $this->findAdjacent($category, $media, 'next') ?? $this->findEdge($category, 'first'),
        ];
    }

    protected function findAdjacent(GalleryCategory $category, GalleryMedia $media, string $direction): ?GalleryMedia
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.category = :category')
            ->andWhere('p != :media')
            ->andWhere('p.isDeleted = false')
            ->setParameter('category', $category)
            ->setParameter('media', $media)
            ->setParameter('position', $media->getPosition())
            ->setParameter('id', $media->getId())
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

    protected function findEdge(GalleryCategory $category, string $edge): ?GalleryMedia
    {
        $qb = $this->createQueryBuilder('p')
            ->where('p.category = :category')
            ->andWhere('p.isDeleted = false')
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
