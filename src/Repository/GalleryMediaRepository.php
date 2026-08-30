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

    // What the back-office grid lists, the trash left out of it - a hidden media stays in it, an admin having to see what he has masked (findVisibleByCategory() below is what the public reads)
    /** @return GalleryMedia[] */
    public function findByCategory(GalleryCategory $category): array
    {
        return $this->findBy(['category' => $category, 'isDeleted' => false], ['position' => 'ASC']);
    }

    // The same list, hidden medias left out too - what every public read asks for, the back-office grid keeping findByCategory() above so an admin still sees what he has masked
    /** @return list<GalleryMedia> */
    public function findVisibleByCategory(GalleryCategory $category): array
    {
        return $this->visibleMediasOf([$category]);
    }

    // The same list for several categories at once, grouped by category id - what GalleryCategoryRepository hands to the categories left without a cover, so a page listing them costs one query rather than one per category
    /**
     * @param list<GalleryCategory> $categories
     *
     * @return array<int, list<GalleryMedia>>
     */
    public function findVisibleByCategories(array $categories): array
    {
        if ([] === $categories) {
            return [];
        }

        $grouped = [];
        foreach ($this->visibleMediasOf($categories) as $media) {
            $grouped[(int) $media->getCategory()?->getId()][] = $media;
        }

        return $grouped;
    }

    // The read itself, apart from the grouping above so a test exercises one without going through the other (same shape as latestMedias() below)
    /**
     * @param list<GalleryCategory> $categories
     *
     * @return list<GalleryMedia>
     */
    protected function visibleMediasOf(array $categories): array
    {
        return $this->createQueryBuilder('m')
            ->andWhere('m.category IN (:categories)')
            ->andWhere('m.isDeleted = false')
            // A hidden media is off every public page while staying whole in the back-office - the trash is where what is being removed goes, this is where what is kept and not shown stays
            ->andWhere('m.hidden = false')
            ->setParameter('categories', $categories)
            ->orderBy('m.position', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    // Rows naming a file, for the check that then looks for each one on disk (see GalleryFilesHealthCheckProvider) - a media can name two, its image and its self-hosted video
    // The trash is left out: a media an admin took off the site is served nowhere, and its files are its category's to keep or to drop, never a defect to report
    /** @return GalleryMedia[] */
    public function findWithFilename(): array
    {
        return $this->createQueryBuilder('m')
            ->where('m.isDeleted = false')
            // Parenthesised by hand: andWhere() joins with AND without bracketing what it is given, and an unbracketed OR would bind looser than it and let the trash back in
            ->andWhere('(m.filename IS NOT NULL AND m.filename != :empty) OR (m.videoFilename IS NOT NULL AND m.videoFilename != :empty)')
            ->setParameter('empty', '')
            ->orderBy('m.filename', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    // The medias added over the last days, whatever category they landed in - what the automatic gallery shows, and what its back-office screen lets an admin credit, download or trash in one go (see GalleryLatestProvider)
    // A rolling window of calendar days, today included: "what has arrived lately" is what a visitor reads under that title, and a date is what he counts it in
    // It falls back on the last day that carries an addition when that window holds nothing: a site publishing once a month would otherwise show an empty gallery for three weeks out of four, and its tile would simply vanish from the index (see components/Gallery/Category.html.twig, which draws nothing without a media)
    // The ceiling is what keeps the day a whole library came in at once - a migration, an import - from being served whole, and it is also what bounds each query below
    /** @return list<GalleryMedia> */
    public function findLatest(int $days, int $max): array
    {
        if ($days < 1 || $max < 1) {
            return [];
        }

        // Today counts as one of them, so a seven-day window opens six days back
        $since = new \DateTimeImmutable('today')->modify(sprintf('-%d days', $days - 1));

        $medias = $this->latestMedias($since, $max);
        if ([] !== $medias) {
            return $medias;
        }

        // Nothing this week: the most recent media names the day the gallery falls back on, and that day alone - being the most recent one, "since its morning" holds exactly it
        $last = $this->latestMedias(null, 1)[0] ?? null;
        $day = $last?->getCreatedAt()?->setTime(0, 0);

        return null === $day ? [] : $this->latestMedias($day, $max);
    }

    // The most recently added medias, from a date on or from the whole table, most recent first
    // A trashed category's medias are left out as its own trashed medias are: they are off the site, and an addition is only an addition to something that shows. A masked category's are left out for the same reason (see GalleryCategory::$hidden), a gallery taken off the site taking its photographs with it. So are the medias of the automatic category itself, which a category flagged after it was filled would otherwise feed itself with
    /** @return list<GalleryMedia> */
    protected function latestMedias(?\DateTimeImmutable $since, int $limit): array
    {
        $qb = $this->createQueryBuilder('m')
            ->innerJoin('m.category', 'c')
            ->addSelect('c')
            ->where('m.isDeleted = false')
            ->andWhere('m.hidden = false')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.hidden = false')
            ->andWhere('c.automaticKind IS NULL')
            ->orderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($limit)
        ;

        if (null !== $since) {
            $qb->andWhere('m.createdAt >= :since')->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }

    // The photographs on sale as prints, whatever category they landed in - what the automatic gallery of the prints shows (see GalleryPrintableProvider)
    // Read off the flag alone and never off GalleryPrintService::isPrintable(): that one opens the file to count its pixels, which on a page listing the galleries would be one getimagesize() per photograph. A flagged photograph whose file is too small for any format shows here and is simply not offered for sale on its own page, which is the answer a visitor can act on
    /** @return list<GalleryMedia> */
    public function findPrintable(int $max): array
    {
        if ($max < 1) {
            return [];
        }

        return $this->createQueryBuilder('m')
            ->innerJoin('m.category', 'c')
            ->addSelect('c')
            ->where('m.isDeleted = false')
            ->andWhere('m.hidden = false')
            ->andWhere('m.printable = true')
            ->andWhere('m.mediaType = :image')
            ->andWhere('c.isDeleted = false')
            ->andWhere('c.hidden = false')
            ->andWhere('c.automaticKind IS NULL')
            ->setParameter('image', GalleryMedia::MEDIA_TYPE_IMAGE)
            ->orderBy('m.position', 'ASC')
            ->addOrderBy('m.createdAt', 'DESC')
            ->addOrderBy('m.id', 'DESC')
            ->setMaxResults($max)
            ->getQuery()
            ->getResult()
        ;
    }

    // What the public media page resolves on - the category is part of the match, a slug only being unique within it (see GalleryMediaSlugger)
    // Unfiltered on purpose, like GalleryCategoryRepository::findOneBySlug(): the page answers 410 for a trashed media, which it can only do holding the row. The same goes for a hidden one, which the controller answers 404 for
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
            ->andWhere('p.hidden = false')
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
            ->andWhere('p.hidden = false')
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
