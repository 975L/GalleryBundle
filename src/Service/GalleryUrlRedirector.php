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

// Keeps a renamed page's old url reachable, and a deleted one answering 410 Gone, through ConfigBundle's own Redirect (served by its RedirectSubscriber) - shared by the screens that move or remove a public url: a renamed or deleted category, and a retitled, moved or deleted media
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

        // The 410 a previous deletion may have left on that very row is lifted: a row carrying both a destination and the gone flag is a state Redirect's own validation refuses, and one RedirectSubscriber would settle in favour of the 410
        $entityManager->persist($redirect->setToUrl($toUrl)->setPermanent(true)->setGone(false));
    }

    // A url that no longer exists and has nowhere to send anyone - every media page and every category being declared in the sitemap (see GallerySitemapProvider), a 410 is what drops it from an index, where the plain 404 the route would otherwise return is retried for months
    // Same row per url as record(), so a media deleted after a rename reuses the one that rename left behind
    public function recordGone(EntityManagerInterface $entityManager, string $path): void
    {
        // The rows that redirected to this url now point at a 410 - one hop a crawler has no reason to make, and a chain the health check flags (see ConfigBundle's RedirectChainHealthCheckProvider). They answer the same 410 directly instead
        foreach ($this->redirectRepository->findByToUrl($path) as $redirect) {
            $entityManager->persist($redirect->setToUrl(null)->setGone(true));
        }

        $redirect = $this->redirectRepository->findOneByFromPath($path) ?? (new Redirect())->setFromPath($path);

        $entityManager->persist($redirect->setToUrl(null)->setGone(true));
    }

    // A whole removed url tree: the path itself, plus everything below it in a single wildcard row (ConfigBundle's own convention, see RedirectSubscriber::resolve) rather than one row per media, which is what would turn a deleted category into a hundred of them
    public function recordGoneTree(EntityManagerInterface $entityManager, string $path): void
    {
        $wildcard = $path . '/*';

        // The rows earlier renames left below that path, each pointing at a url the wildcard now covers - an exact fromPath wins over it, so they would keep answering 301 towards a 410 instead of letting it apply
        // A row whose destination lies outside the tree is left alone: a media moved to another category still has a live url to send anyone to, and the wildcard would answer 410 for it
        foreach ($this->redirectRepository->findByFromPathPrefix($path . '/') as $redirect) {
            $toUrl = $redirect->getToUrl();

            if ($wildcard !== $redirect->getFromPath() && (null === $toUrl || $toUrl === $path || str_starts_with($toUrl, $path . '/'))) {
                $entityManager->remove($redirect);
            }
        }

        $this->recordGone($entityManager, $path);
        $this->recordGone($entityManager, $wildcard);
    }

    // Frees a url that is being created again under a slug an earlier deletion left answering 410 - nothing else ever lifts one, and the page would exist while RedirectSubscriber, which runs before the router, kept returning Gone
    // Only a gone row is dropped: a row redirecting elsewhere is deliberate, and record() is what rewrites those
    public function release(EntityManagerInterface $entityManager, string $path): void
    {
        $redirect = $this->redirectRepository->findOneByFromPath($path);

        if (null !== $redirect && $redirect->isGone()) {
            $entityManager->remove($redirect);
        }
    }
}
