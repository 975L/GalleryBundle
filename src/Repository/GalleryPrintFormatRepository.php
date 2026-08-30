<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Repository;

use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryPrintFormat>
 */
class GalleryPrintFormatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryPrintFormat::class);
    }

    // The catalogue as the sale page reads it, smallest first
    /** @return list<GalleryPrintFormat> */
    public function findPublished(): array
    {
        return $this->createQueryBuilder('f')
            ->andWhere('f.published = true')
            ->orderBy('f.position', 'ASC')
            ->addOrderBy('f.widthCm', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    // A format is looked up by slug and not by id because that is what a basket line kept - an order placed last year still has to price itself
    public function findBySlug(string $slug): ?GalleryPrintFormat
    {
        return $this->findOneBy(['slug' => $slug]);
    }
}
