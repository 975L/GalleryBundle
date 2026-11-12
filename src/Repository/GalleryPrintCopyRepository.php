<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Repository;

use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Model\PrintCopySnapshot;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryPrintCopy>
 */
class GalleryPrintCopyRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryPrintCopy::class);
    }

    // How many of an edition are still to be sold, which the sale page states and which is the whole of the scarcity it announces
    public function countAvailable(GalleryMedia $media): int
    {
        return (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.media = :media')
            ->andWhere('c.order IS NULL')
            ->setParameter('media', $media)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    // Claims the lowest unsold number of an edition for an order in one UPDATE, or returns null when none is left: the database decides between two payments settling at once, and the snapshot travels in the same statement, which runs inside the caller's transaction (see PaymentBundle's BasketService::deliver)
    public function claimNumber(GalleryMedia $media, GalleryPrintOrder $order, PrintCopySnapshot $snapshot): ?GalleryPrintCopy
    {
        // Drawn before the statement, so the row it wins can be read back by it
        $certificate = \bin2hex(\random_bytes(16));

        $claimed = $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE gallery_print_copy SET order_id = :order, format = :format, format_label = :formatLabel, sku = :sku, price = :price, work_title = :workTitle, credits = :credits, issuer = :issuer, certificate = :certificate, sold_at = :soldAt WHERE media_id = :media AND order_id IS NULL ORDER BY number ASC LIMIT 1',
            [
                'order' => $order->getId(),
                'format' => $snapshot->format,
                'formatLabel' => $snapshot->formatLabel,
                'sku' => $snapshot->sku,
                'price' => $snapshot->price,
                'workTitle' => $snapshot->workTitle,
                'credits' => $snapshot->credits,
                'issuer' => $snapshot->issuer,
                'certificate' => $certificate,
                'soldAt' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'media' => $media->getId(),
            ],
        );

        if (1 !== $claimed) {
            return null;
        }

        $copy = $this->findOneBy(['certificate' => $certificate]);

        // The row was written by SQL, so an instance the unit of work is already holding still carries the values it had before the claim
        if (null !== $copy) {
            $this->getEntityManager()->refresh($copy);
        }

        return $copy;
    }

    // Hands the numbers of an order back to its edition, blank again as they were before the sale, and answers how many were freed - an order refunded before it ever reached the lab (see GalleryPrintBasketItemProvider::onBasketRefunded)
    public function releaseNumbers(GalleryPrintOrder $order): int
    {
        return $this->getEntityManager()->getConnection()->executeStatement(
            'UPDATE gallery_print_copy SET order_id = NULL, format = NULL, format_label = NULL, sku = NULL, price = NULL, work_title = NULL, credits = NULL, issuer = NULL, certificate = NULL, sold_at = NULL WHERE order_id = :order AND number IS NOT NULL',
            ['order' => $order->getId()],
        );
    }

    /**
     * Writes the rows of an edition, one per number, so there is something to claim from.
     *
     * Called when an admin turns a photograph into a limited edition. Never called twice for the same media: raising an
     * announced edition is a forgery, and the unique constraint on (media, number) is what says so out loud.
     *
     * @return list<GalleryPrintCopy> the rows created, unflushed
     */
    public function openEdition(GalleryMedia $media, int $size): array
    {
        $manager = $this->getEntityManager();
        $copies = [];

        for ($number = 1; $number <= $size; ++$number) {
            $copy = new GalleryPrintCopy()
                ->setMedia($media)
                ->setNumber($number)
            ;

            $manager->persist($copy);
            $copies[] = $copy;
        }

        return $copies;
    }

    // Whether any of these medias has a print sold, open edition or numbered - what keeps an import from replacing them (see GalleryImportProvider)
    /** @param list<int> $mediaIds */
    public function hasSoldCopyAmong(array $mediaIds): bool
    {
        if ([] === $mediaIds) {
            return false;
        }

        return 0 < (int) $this->createQueryBuilder('c')
            ->select('COUNT(c.id)')
            ->andWhere('c.media IN (:medias)')
            ->andWhere('c.order IS NOT NULL')
            ->setParameter('medias', $mediaIds)
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    // The certificate's public page, which resolves on the token alone - a copy nobody bought has none, so an unsold number cannot be reached
    public function findByCertificate(string $certificate): ?GalleryPrintCopy
    {
        return $this->findOneBy(['certificate' => $certificate]);
    }
}
