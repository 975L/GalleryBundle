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
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\Filesystem\Filesystem;

// Rewrites a media's thumbnail from a file it still holds, for a gallery filled before thumbnails kept the photo's own proportions (see UiBundle's VichImageResizeListener) - an upload generates its own, this is only for the ones already on disk
// Same target and same options as that listener, deliberately: two ways of writing the same file would drift apart at the first change to either
class GalleryThumbnailRebuilder
{
    private readonly Filesystem $filesystem;

    public function __construct(
        private readonly ParameterBagInterface $parameterBag,
    ) {
        $this->filesystem = new Filesystem();
    }

    // True once the thumbnail has been written, false for a media with no file left to rebuild it from
    public function rebuild(GalleryMedia $media): bool
    {
        // The highres derivative first: it is the largest file a media keeps, the stored one being a downscale, and a thumbnail taken from the smaller of the two would be the softer of the two
        $source = $this->firstExisting([$media->getHighresFilename(), $media->getFilename()]);
        $thumbnail = $this->absolutePath($media->getThumbnailFilename());

        if (null === $source || null === $thumbnail) {
            return false;
        }

        // Nothing is stamped here, and nothing needs to be: this cuts a thumbnail out of the highres derivative, which is exactly what an upload does (see UiBundle's VichImageResizeListener::processMultiSizeDerivatives). The signature was laid on that highres at a share of its width, so it comes down with the pixels and a rebuilt thumbnail carries it at the same size as a freshly uploaded one - only marginally softer, for having been resampled once more
        $size = $media->getThumbnailSize();
        new Imagine()
            ->open($source)
            ->thumbnail(new Box($size, $size), ImageInterface::THUMBNAIL_INSET)
            ->save($thumbnail, ['format' => 'webp', 'webp_quality' => 90])
        ;

        return true;
    }

    /**
     * @param array<int, string|null> $filenames
     */
    private function firstExisting(array $filenames): ?string
    {
        foreach ($filenames as $filename) {
            $path = $this->absolutePath($filename);
            if (null !== $path && $this->filesystem->exists($path)) {
                return $path;
            }
        }

        return null;
    }

    private function absolutePath(?string $filename): ?string
    {
        if (null === $filename) {
            return null;
        }

        return $this->parameterBag->get('kernel.project_dir') . '/public/' . $filename;
    }
}
