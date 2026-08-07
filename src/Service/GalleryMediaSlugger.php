<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\GalleryBundle\Entity\GalleryMedia;
use Symfony\Component\String\Slugger\SluggerInterface;

// Builds the slug a media is reached by under its category (see GalleryController::media) and stored under on disk (see GalleryMedia::getVichMediaPath) - one service rather than one rule per screen, the three ways a media comes into being all needing the same one: a batch upload, the admin's own edit form and an import
class GalleryMediaSlugger
{
    // What an untitled media falls back to, so it still gets a url and a filename of its own - the suffixing below then tells such medias apart within their category
    private const FALLBACK = 'media';

    public function __construct(
        private readonly SluggerInterface $slugger,
    ) {
    }

    // Set on the media rather than returned: every caller does the same thing with it, and it has to be there before the flush that names the uploaded file
    public function assign(GalleryMedia $media, ?string $preferred = null): void
    {
        $media->setSlug($this->generate($media, $preferred));
    }

    // $preferred is for an import, which carries the slug the media already had: honoured when it is still free, so a round-trip through an export leaves the public urls where they were
    // Suffixed rather than refused on a collision, unlike a category's slug (see GalleryCategoryCrudController): a batch straight off a camera legitimately carries the same name twice, and an upload of fifty files can't stop on a form error halfway
    public function generate(GalleryMedia $media, ?string $preferred = null): string
    {
        // An empty preference is no preference at all rather than a request for the FALLBACK below: the slug field emptied on the edit form is how an admin asks for one rebuilt from the title (see GalleryMediaCrudController::updateEntity)
        $preferred = '' !== trim((string) $preferred) ? $preferred : null;

        $base = strtolower($this->slugger->slug((string) ($preferred ?? $media->getTitle()))->toString());
        $base = '' !== $base ? $base : self::FALLBACK;

        $taken = $this->takenSlugs($media);
        $slug = $base;
        $suffix = 1;
        while (\in_array($slug, $taken, true)) {
            $slug = $base . '-' . ++$suffix;
        }

        return $slug;
    }

    // Read off the category's collection, not off a query: it holds the medias already stored and the ones a batch is adding in the same flush alike, where a query would only ever see the first half
    /** @return string[] */
    private function takenSlugs(GalleryMedia $media): array
    {
        $taken = [];
        foreach ($media->getCategory()?->getMedias() ?? [] as $sibling) {
            if ($sibling !== $media && null !== $sibling->getSlug()) {
                $taken[] = $sibling->getSlug();
            }
        }

        return $taken;
    }
}
