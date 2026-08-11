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
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends ServiceEntityRepository<GalleryCategory>
 */
class GalleryCategoryRepository extends ServiceEntityRepository
{
    private const string UNCATEGORIZED_SLUG = 'non-classe';

    public function __construct(
        ManagerRegistry $registry,
        private readonly TranslatorInterface $translator,
    ) {
        parent::__construct($registry, GalleryCategory::class);
    }

    public function findOneBySlug(string $slug): ?GalleryCategory
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    // What the front-office index and the blocks list, in the order the admin arranged them. coverMedia is joined rather than left lazy: every caller renders the category's thumbnail from it (see components/Gallery/Category.html.twig), so each category would otherwise initialize its proxy with a query of its own - one per category on a page listing them all
    /** @return list<GalleryCategory> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.coverMedia', 'm')
            ->addSelect('m')
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    // Catch-all category a GalleryMedia falls back to when imported without a real one to attach it to. Created lazily so it only ever exists once it's actually needed, and flushed immediately so it's safe to reference the same row from within the same request right after.
    public function findOrCreateUncategorized(): GalleryCategory
    {
        $category = $this->findOneBy(['uncategorized' => true]);
        if (null !== $category) {
            return $category;
        }

        // Translated at creation time only - like any other category it's a normal DB row afterwards, editable/renamable later from the Management CRUD
        $category = new GalleryCategory()
            ->setSlug(self::UNCATEGORIZED_SLUG)
            ->setTitle($this->translator->trans('label.gallery_uncategorized', [], 'gallery'))
            ->setUncategorized(true)
        ;

        $em = $this->getEntityManager();
        $em->persist($category);
        $em->flush();

        return $category;
    }
}
