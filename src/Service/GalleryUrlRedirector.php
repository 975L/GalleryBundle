<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\ConfigBundle\Entity\Redirect;
use c975L\ConfigBundle\Repository\RedirectRepository;
use Doctrine\ORM\EntityManagerInterface;

// Keeps a renamed page's old url reachable, through ConfigBundle's own Redirect (served by its RedirectSubscriber) - shared by the two screens that move a public url: a renamed category and a retitled or moved media
class GalleryUrlRedirector
{
    public function __construct(
        private readonly RedirectRepository $redirectRepository,
    ) {
    }

    // One row per old url, reused on a further rename instead of piling up - the redirect is persisted, not flushed, the caller being in the middle of saving the very entity that moved
    public function record(EntityManagerInterface $entityManager, string $fromPath, string $toUrl): void
    {
        if ($fromPath === $toUrl) {
            return;
        }

        // Something renamed and then renamed back would otherwise leave two rows pointing at each other, the browser looping between them - the wildcard row written alongside a renamed category is dropped too, a surviving one prefix-matching every url below the slug that has just been freed
        foreach ([$toUrl, $toUrl . '/*'] as $reversePath) {
            $reverseRedirect = $this->redirectRepository->findOneByFromPath($reversePath);
            if (null !== $reverseRedirect) {
                $entityManager->remove($reverseRedirect);
            }
        }

        $redirect = $this->redirectRepository->findOneByFromPath($fromPath) ?? (new Redirect())->setFromPath($fromPath);

        $entityManager->persist($redirect->setToUrl($toUrl)->setPermanent(true));
    }
}
