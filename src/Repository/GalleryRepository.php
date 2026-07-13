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
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Gallery>
 */
class GalleryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Gallery::class);
    }

    // Resolves the gallery whose public routes omit the {gallery} slug segment (see GalleryController)
    public function findDefault(): ?Gallery
    {
        return $this->findOneBy(['default' => true]);
    }

    public function findOneBySlug(string $slug): ?Gallery
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    // The Management CRUD doesn't expose a Gallery picker yet (see GalleryCategoryCrudController) -
    // every category/photo it creates is attached to this one, lazily created on first use, so a site
    // never needs to set one up manually to start using the gallery
    public function findOrCreateDefault(): Gallery
    {
        $gallery = $this->findDefault();
        if (null !== $gallery) {
            return $gallery;
        }

        $gallery = (new Gallery())
            ->setSlug('main')
            ->setTitle('Galerie')
            ->setDefault(true)
        ;

        $em = $this->getEntityManager();
        $em->persist($gallery);
        $em->flush();

        return $gallery;
    }
}
