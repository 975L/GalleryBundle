<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Command;

use c975L\GalleryBundle\Entity\GalleryPrintOrder;
use c975L\GalleryBundle\Exception\PrintFulfilmentException;
use c975L\GalleryBundle\Repository\GalleryPrintOrderRepository;
use c975L\GalleryBundle\Service\GalleryPrintOrderTracker;
use c975L\GalleryBundle\Service\PrintFulfilmentRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Asks each lab where the orders it is holding now stand.
 *
 * The callbacks are what usually move an order along, and this is what makes them optional: a callback posted while the
 * site was down, a lab that posts none, a printer with no api at all. Without it, one lost request leaves a customer's
 * order reading "sent" for ever and their shipping notice never leaves.
 *
 * Writes nothing itself - the states go through GalleryPrintOrderTracker, exactly as a callback's do.
 */
#[AsCommand(
    name: 'c975l:gallery:print:sync',
    description: 'Ask the labs about the print orders they are holding, and move the ones that have shipped'
)]
class GalleryPrintSyncCommand extends Command
{
    public function __construct(
        private readonly GalleryPrintOrderRepository $orderRepository,
        private readonly PrintFulfilmentRegistry $fulfilmentRegistry,
        private readonly GalleryPrintOrderTracker $tracker,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $moved = 0;
        $failed = 0;

        foreach ($this->orderRepository->findTracked() as $order) {
            $state = $this->askLab($order, $io);

            if (null === $state) {
                ++$failed;

                continue;
            }

            if ($this->tracker->apply($order, $state)) {
                ++$moved;
                $io->writeln(sprintf('  #%d %s', (int) $order->getId(), $state));
            }
        }

        $io->success(sprintf('%d order(s) moved, %d lab(s) could not be asked', $moved, $failed));

        // A lab that could not be reached is not a failure of this command: it is asked again tomorrow, and the order is left exactly as it stands
        return Command::SUCCESS;
    }

    // What the lab says, or null when it could not be asked - an unreachable lab must never turn into a state, and least of all into "failed", which means a lab refused the order
    private function askLab(GalleryPrintOrder $order, SymfonyStyle $io): ?string
    {
        try {
            return $this->fulfilmentRegistry
                ->getByName((string) $order->getProvider())
                ->getState((string) $order->getReference())
            ;
        } catch (PrintFulfilmentException | \InvalidArgumentException $exception) {
            $io->warning(sprintf('#%d: %s', (int) $order->getId(), $exception->getMessage()));

            return null;
        }
    }
}
