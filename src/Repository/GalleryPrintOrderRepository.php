<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Repository;

use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<GalleryPrintOrder>
 */
class GalleryPrintOrderRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, GalleryPrintOrder::class);
    }

    // What the back-office badge counts: the orders nobody has printed yet because they are waiting for a signature or because the lab said no
    public function countNeedingAttention(): int
    {
        return (int) $this->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->andWhere('o.state IN (:states)')
            ->setParameter('states', [GalleryPrintOrder::STATE_PENDING, GalleryPrintOrder::STATE_FAILED])
            ->getQuery()
            ->getSingleScalarResult()
        ;
    }

    // The order a lab's callback is about - labs quote their own reference and know nothing of ours
    public function findByReference(string $provider, string $reference): ?GalleryPrintOrder
    {
        return $this->findOneBy(['provider' => $provider, 'reference' => $reference]);
    }

    // The orders a lab is holding, which the nightly synchronisation asks it about (see GalleryPrintSyncCommand). A reference is what makes an order askable at all, an order without one having never been accepted
    /** @return list<GalleryPrintOrder> */
    public function findTracked(): array
    {
        return $this->createQueryBuilder('o')
            ->andWhere('o.state IN (:states)')
            ->andWhere('o.reference IS NOT NULL')
            ->setParameter('states', GalleryPrintOrder::STATES_HELD_BY_LAB)
            ->orderBy('o.sentAt', 'ASC')
            ->getQuery()
            ->getResult()
        ;
    }

    // Moves an order the lab is still holding, and answers whether this call is the one that moved it. The database is the lock: a callback the lab replayed and the nightly command can both read the same state, but only one of them updates a row still held by a lab, and only that one goes on to write the letter (see GalleryPrintOrderTracker)
    public function claim(GalleryPrintOrder $order, string $state, ?\DateTimeImmutable $shippedAt): bool
    {
        return 0 < $this->createQueryBuilder('o')
            ->update()
            ->set('o.state', ':state')
            ->set('o.shippedAt', ':shippedAt')
            ->andWhere('o.id = :id')
            ->andWhere('o.state IN (:states)')
            ->setParameter('state', $state)
            ->setParameter('shippedAt', $shippedAt)
            ->setParameter('id', $order->getId())
            ->setParameter('states', GalleryPrintOrder::STATES_HELD_BY_LAB)
            ->getQuery()
            ->execute()
        ;
    }
}
