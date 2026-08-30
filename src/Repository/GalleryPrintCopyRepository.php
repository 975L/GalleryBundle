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

    /**
     * Claims the lowest unsold number of an edition for an order, or returns null when there is none left.
     *
     * Written as one UPDATE and not as a read followed by a write: between reading "number 7 is free" and writing it,
     * another customer's checkout can have taken it, and both would then be told they own 7. Here the database decides
     * - the statement either updates one row or updates none, and none means the edition is out.
     *
     * The whole snapshot is written by that one statement rather than set afterwards through the orm. The update is not
     * inside the caller's flush - it commits on its own, the moment it runs - so a row claimed here and completed by a
     * later write is a row that can end up sold and blank if anything fails in between.
     */
    public function claimNumber(GalleryMedia $media, GalleryPrintOrder $order, PrintCopySnapshot $snapshot): ?GalleryPrintCopy
    {
        $connection = $this->getEntityManager()->getConnection();

        $id = $connection->fetchOne(
            'SELECT id FROM gallery_print_copy WHERE media_id = :media AND order_id IS NULL ORDER BY number ASC LIMIT 1',
            ['media' => $media->getId()],
        );

        if (false === $id || null === $id) {
            return null;
        }

        $claimed = $connection->executeStatement(
            'UPDATE gallery_print_copy SET order_id = :order, format = :format, format_label = :formatLabel, sku = :sku, price = :price, work_title = :workTitle, credits = :credits, issuer = :issuer, certificate = :certificate, sold_at = :soldAt WHERE id = :id AND order_id IS NULL',
            [
                'order' => $order->getId(),
                'format' => $snapshot->format,
                'formatLabel' => $snapshot->formatLabel,
                'sku' => $snapshot->sku,
                'price' => $snapshot->price,
                'workTitle' => $snapshot->workTitle,
                'credits' => $snapshot->credits,
                'issuer' => $snapshot->issuer,
                'certificate' => \bin2hex(\random_bytes(16)),
                'soldAt' => new \DateTimeImmutable()->format('Y-m-d H:i:s'),
                'id' => $id,
            ],
        );

        // Somebody else took it between the two statements - the edition may still have others, so the caller asks again rather than being told it is out
        if (1 !== $claimed) {
            return $this->claimNumber($media, $order, $snapshot);
        }

        $copy = $this->find($id);

        // The row was written by SQL, so an instance the unit of work is already holding still carries the values it had before the claim
        if (null !== $copy) {
            $this->getEntityManager()->refresh($copy);
        }

        return $copy;
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

    // The certificate's public page, which resolves on the token alone - a copy nobody bought has none, so an unsold number cannot be reached
    public function findByCertificate(string $certificate): ?GalleryPrintCopy
    {
        return $this->findOneBy(['certificate' => $certificate]);
    }
}
