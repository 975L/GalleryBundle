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

    // Deliberately unfiltered, unlike findAllOrdered() below, and for two reasons at once: the front-office needs the trashed row in hand to answer 410 rather than 404 (see GalleryController::resolveCategory, same shape as SiteBundle's PageController::display), and the import matches on the slug, which the unique constraint holds whether the category is in the trash or not - filtering here would have it create a second row under a slug already taken
    public function findOneBySlug(string $slug): ?GalleryCategory
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    // What the front-office index and the blocks list, in the order the admin arranged them. coverMedia is joined rather than left lazy: every caller renders the category's thumbnail from it (see components/Gallery/Category.html.twig), so each category would otherwise initialize its proxy with a query of its own - one per category on a page listing them all
    // The trash filter sits here rather than at each of the six callers (index, blocks, block form, sitemap, link picker, showcase): they all want the same thing, and one of them forgetting it would put a trashed category back on the site
    /** @return list<GalleryCategory> */
    public function findAllOrdered(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.coverMedia', 'm')
            ->addSelect('m')
            ->andWhere('c.isDeleted = false')
            ->orderBy('c.position', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    // What the breadcrumb counts next to its home label - findAllOrdered()'s own count, without reading the rows, so the trash never shows up in "79 galeries"
    public function countVisible(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.isDeleted = false')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    // Catch-all category a GalleryMedia falls back to when imported without a real one to attach it to. Created lazily so it only ever exists once it's actually needed, and flushed immediately so it's safe to reference the same row from within the same request right after.
    public function findOrCreateUncategorized(): GalleryCategory
    {
        // Read back out of the trash too, and restored on the way: the catch-all is the fallback an upload lands on, so it has to be a category that shows. GalleryCategoryCrudController refuses to trash it in the first place, this only covers the row being flagged some other way (a fixture, an import, a hand-written query)
        $category = $this->findOneBy(['uncategorized' => true]);
        if (null !== $category) {
            if ($category->isDeleted()) {
                $category->setIsDeleted(false);
                $this->getEntityManager()->flush();
            }

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
