<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryCategory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\String\Slugger\SluggerInterface;

// A gallery's slug and the redirect rows its url needs when it is created, restored or renamed, shared by the category screen and the MCP tools so both settle a url the same way
class GalleryCategoryUrls
{
    public function __construct(
        private readonly GalleryUrlRedirector $urlRedirector,
        private readonly UrlGeneratorInterface $urlGenerator,
        private readonly SluggerInterface $slugger,
    ) {
    }

    // The slug a title or a typed slug gives, lowercased, empty when nothing in it survives
    public function slug(string $text): string
    {
        return strtolower($this->slugger->slug($text)->toString());
    }

    // The url a created or restored gallery answers on, and the wildcard covering every media url below it, both freed of the "gone" row an earlier permanent deletion left there, ConfigBundle's RedirectSubscriber running before the router
    public function release(EntityManagerInterface $entityManager, GalleryCategory $category): void
    {
        $url = $this->urlGenerator->generate('gallery_category', ['category' => $category->getSlug()]);

        $this->urlRedirector->release($entityManager, $url);
        $this->urlRedirector->release($entityManager, $url . '/*');
    }

    // A renamed gallery's old url redirected to the new one, plus a wildcard row sending every media url under the old slug to the gallery rather than leaving each to 404 - both generated rather than concatenated, the first segment being the configured route prefix (see GalleryRoutePrefix)
    public function redirectRename(EntityManagerInterface $entityManager, string $oldSlug, string $newSlug): void
    {
        // A new title giving the same slug moves nothing, and its wildcard row would send every live media url to the gallery
        if ($oldSlug === $newSlug) {
            return;
        }

        $oldUrl = $this->urlGenerator->generate('gallery_category', ['category' => $oldSlug]);
        $newUrl = $this->urlGenerator->generate('gallery_category', ['category' => $newSlug]);

        $this->urlRedirector->record($entityManager, $oldUrl, $newUrl);
        $this->urlRedirector->record($entityManager, $oldUrl . '/*', $newUrl);
    }
}
