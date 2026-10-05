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
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\UiBundle\Contract\PickableMediaProviderInterface;
use c975L\UiBundle\Model\PickableMedia;
use Symfony\Contracts\Translation\TranslatableInterface;

use function Symfony\Component\Translation\t;

// Offers the gallery's photographs and uploaded videos to another bundle - a social post taking one of them - the medium file for a photograph, the site's own copy for a video
class GalleryPickableMediaProvider implements PickableMediaProviderInterface
{
    public function __construct(
        private readonly GalleryMediaRepository $mediaRepository,
    ) {
    }

    public function getPickableMediaLabel(): TranslatableInterface
    {
        return t('label.gallery', [], 'gallery');
    }

    public function findPickableMedia(string $search, int $limit): array
    {
        return array_map($this->toPickable(...), $this->mediaRepository->findPickable($search, $limit));
    }

    // A video comes with its still as thumbnail, a photograph with its own
    private function toPickable(GalleryMedia $media): PickableMedia
    {
        $video = GalleryMedia::MEDIA_TYPE_VIDEO === $media->getMediaType();

        return new PickableMedia(
            path: (string) ($video ? $media->getVideoFilename() : $media->getFilename()),
            title: (string) ($media->getTitle() ?? $media->getSlug()),
            mimeType: (string) ($video ? $media->getVideoMimeType() : $media->getMimeType()),
            thumbnailPath: $media->getThumbnailFilename() ?? $media->getFilename(),
        );
    }
}
