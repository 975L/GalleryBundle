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
use Symfony\Contracts\Service\ResetInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends ServiceEntityRepository<GalleryCategory>
 */
class GalleryCategoryRepository extends ServiceEntityRepository implements ResetInterface
{
    private const string UNCATEGORIZED_SLUG = 'non-classe';

    // The url and the title key of each automatic gallery, posed once at creation and an admin's to rename afterwards - a rename records its redirect like any other category's (see GalleryCategoryCrudController::updateEntity)
    // Slugs in English, as everything this bundle names itself: they are values shipped by a package, where the titles next to them are translated because they are read by whoever visits the site
    private const array AUTOMATIC_SLUGS = [
        GalleryCategory::AUTOMATIC_LATEST => 'latest',
        GalleryCategory::AUTOMATIC_PRINTABLE => 'prints',
    ];
    private const array AUTOMATIC_TITLES = [
        GalleryCategory::AUTOMATIC_LATEST => 'label.gallery_latest_title',
        GalleryCategory::AUTOMATIC_PRINTABLE => 'label.gallery_printable_title',
    ];

    // The ordered list, read once per request - see findAllOrdered()
    /** @var ?list<GalleryCategory> */
    private ?array $ordered = null;

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

    // What the front-office index and the blocks list, in alphabetical order of title - the one order a visitor can predict on a gallery of eighty categories, and the one a picker is scanned in. coverMedia is joined rather than left lazy: every caller renders the category's thumbnail from it (see components/Gallery/Category.html.twig), so each category would otherwise initialize its proxy with a query of its own - one per category on a page listing them all
    // The trash filter sits here rather than at each of the six callers (index, blocks, block form, sitemap, link picker, showcase): they all want the same thing, and one of them forgetting it would put a trashed category back on the site
    // A hidden gallery is dropped for the very same reason (see GalleryCategory::$hidden), the back-office callers included: a masked gallery offered as a move target, in a block's picker or in a menu's would be a public page composed on something that answers 404. An admin reaches it from the category listing, which lists it like any other (see GalleryCategoryCrudController::createIndexQueryBuilder)
    // Memoized for the request: the six callers are spread over services that know nothing of each other (the blocks extension, the menu link provider, the sitemap...), and a page carrying a gallery block under a menu pointing at a category went through the very same query once for each of them
    /** @return list<GalleryCategory> */
    public function findAllOrdered(): array
    {
        if (null !== $this->ordered) {
            return $this->ordered;
        }

        return $this->ordered = $this->orderedCategories();
    }

    // The read itself, apart from the memoization above so a test exercises one without going through the other (same shape as GalleryMediaRepository::latestMedias)
    /** @return list<GalleryCategory> */
    protected function orderedCategories(): array
    {
        return $this->createQueryBuilder('c')
            ->leftJoin('c.coverMedia', 'm')
            ->addSelect('m')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.hidden = false')
            ->orderBy('c.title', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    // Dropped between two messages of a worker, where the same repository serves requests minutes apart - a category added meanwhile would otherwise stay out of the list for as long as the process lives
    public function reset(): void
    {
        $this->ordered = null;
    }

    // What the breadcrumb counts next to its home label - findAllOrdered()'s own count, without reading the rows, so neither the trash nor a masked gallery shows up in "79 galeries"
    public function countVisible(): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.hidden = false')
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    // An automatic gallery of the asked kind, which nobody has to create: it is a gallery of its own, not an option carried by one of the site's - so it is written the first time the galleries are listed and is a normal category from then on, renamed, described, arranged and given a heading like any other (see GalleryAutomaticProvider for what each kind shows)
    // Unlike the catch-all below, one left in the trash is left there: it is the only way to say "this site does not want that gallery", and lifting it back out would give an admin no way at all to be rid of it
    public function findOrCreateAutomatic(string $kind): GalleryCategory
    {
        $category = $this->findOneBy(['automaticKind' => $kind]);
        if (null !== $category) {
            return $category;
        }

        // Translated at creation time only - like any other category it's a normal DB row afterwards, editable/renamable later from the Management CRUD
        // A kind the bundle doesn't ship names its own url and heading (see AutomaticGalleryInterface): the constants above only hold the two it does, and a site's own kind is a perfectly good slug and a title an admin renames on the spot
        $category = new GalleryCategory()
            ->setSlug($this->freeSlug(self::AUTOMATIC_SLUGS[$kind] ?? $kind))
            ->setTitle($this->translator->trans(self::AUTOMATIC_TITLES[$kind] ?? $kind, [], 'gallery'))
            ->setAutomaticKind($kind)
        ;

        $em = $this->getEntityManager();
        $em->persist($category);
        $em->flush();

        return $category;
    }

    // Catch-all category a GalleryMedia falls back to when imported without a real one to attach it to. Created lazily so it only ever exists once it's actually needed, and flushed immediately so it's safe to reference the same row from within the same request right after.
    public function findOrCreateUncategorized(): GalleryCategory
    {
        // Read back out of the trash too, and restored on the way: the catch-all is the fallback an upload lands on, so it has to be a category that shows. GalleryCategoryCrudController refuses to trash it in the first place, this only covers the row being flagged some other way (a fixture, an import, a hand-written query)
        // Masked is left as it is found, unlike the trash: hiding the catch-all is an admin saying "the unfiled photographs are not for the public yet", which is an answer, where a trashed fallback is a category an upload would land in and never come out of
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
            ->setSlug($this->freeSlug(self::UNCATEGORIZED_SLUG))
            ->setTitle($this->translator->trans('label.gallery_uncategorized', [], 'gallery'))
            ->setUncategorized(true)
        ;

        $em = $this->getEntityManager();
        $em->persist($category);
        $em->flush();

        return $category;
    }

    // The slug both creations above write is a constant, on a column the schema holds unique: a site that already named a gallery "latest" or "non-classe" would meet a UniqueConstraintViolationException on every page listing its galleries, with nothing ever freeing the slug
    // So the taken one is suffixed until it is free, and the admin renames the category afterwards if the url matters
    private function freeSlug(string $slug): string
    {
        $free = $slug;
        $suffix = 1;
        while (null !== $this->findOneBySlug($free)) {
            $free = $slug . '-' . ++$suffix;
        }

        return $free;
    }
}
