<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Service;

use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\GalleryBundle\Entity\GalleryMedia;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\GalleryBundle\Model\PrintOffer;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\GalleryBundle\Repository\GalleryPrintCopyRepository;
use c975L\GalleryBundle\Repository\GalleryPrintFormatRepository;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * What is on sale, and at which sizes.
 *
 * Always registered, and answering false rather than failing when the shop is off, so a template asks it one question -
 * "is this photograph for sale" - without having to know whether prints are configured at all.
 */
class GalleryPrintService
{
    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryPrintFormatRepository $formatRepository,
        private readonly GalleryMediaRepository $mediaRepository,
        private readonly GalleryPrintCopyRepository $copyRepository,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
    ) {
    }

    // The shop's master switch. Deliberately not inferred from the presence of an api key: a key is pasted to test in the lab's sandbox long before anything should be on sale, and inferring would turn developing into publishing
    public function isEnabled(): bool
    {
        return true === $this->configService->get('gallery-print-enabled');
    }

    // Whether this photograph can be bought as a print at all - which is a question about the photograph, the shop and the file behind it, and the only one a template has to ask
    public function isPrintable(GalleryMedia $media): bool
    {
        // The gallery filing it answers as well: a masked gallery is off the site whole, and the basket is reached without ever going through the page that would have said so (see GalleryPrintBasketItemProvider)
        if (!$this->isEnabled() || !$media->isPrintable() || $media->isHidden() || true === $media->getCategory()?->isHidden()) {
            return false;
        }

        if (GalleryMedia::MEDIA_TYPE_IMAGE !== $media->getMediaType()) {
            return false;
        }

        return [] !== $this->getOffers($media);
    }

    /**
     * The sizes this photograph is actually offered at: those of the catalogue whose proportions it matches and whose
     * pixels it has.
     *
     * Filtered here rather than cropped later on purpose. Offering every size and cutting the difference means selling
     * a print of something other than the photograph, and deciding for the photographer where the frame falls - the
     * catalogue proposes, the file disposes.
     *
     * @return list<PrintOffer>
     */
    public function getOffers(GalleryMedia $media): array
    {
        $size = $this->getOriginalSize($media);

        if (null === $size) {
            return [];
        }

        [$width, $height] = $size;
        $ratio = min($width, $height) > 0 ? max($width, $height) / min($width, $height) : 0.0;
        $offers = [];

        foreach ($this->formatRepository->findPublished() as $format) {
            if ($format->acceptsRatio($ratio) && max($width, $height) >= $format->getRequiredPixels()) {
                $offers[] = new PrintOffer($media, $format);
            }
        }

        return $offers;
    }

    /**
     * Pixels of the kept original, or null when there is none - which is the ordinary state of a gallery whose medias
     * were uploaded without asking for originals to be kept.
     *
     * Read off the file and not off a column because nothing stores it: the derivatives' sizes are known, the
     * original's is whatever the camera wrote. Cheap enough to ask on a sale page, getimagesize() reading a header
     * rather than an image.
     *
     * @return array{0: int, 1: int}|null
     */
    public function getOriginalSize(GalleryMedia $media): ?array
    {
        $path = $this->getOriginalPath($media);

        if (null === $path || !is_file($path)) {
            return null;
        }

        $size = @getimagesize($path);

        return false === $size ? null : [(int) $size[0], (int) $size[1]];
    }

    // Where the untouched upload sits, outside public/ - the only file with the pixels a print needs, and the only one without the signature burnt into it at web size
    public function getOriginalPath(GalleryMedia $media): ?string
    {
        $filename = $media->getOriginalFilename();

        return null === $filename ? null : $this->projectDir . '/' . GalleryMedia::ORIGINAL_DIRECTORY . '/' . $filename;
    }

    // How many of a limited edition are left, null when the photograph is sold as an open one - what the sale page states, and the whole of the scarcity it announces
    public function getRemaining(GalleryMedia $media): ?int
    {
        return $media->isLimitedEdition() ? $this->copyRepository->countAvailable($media) : null;
    }

    // Resolves what a basket line names back into the pair it was built from, or null when either half is gone - a photograph unpublished or a format withdrawn while the basket sat there
    public function findOffer(int | string $id): ?PrintOffer
    {
        $parsed = PrintOffer::parseId($id);

        if (null === $parsed) {
            return null;
        }

        [$mediaId, $formatSlug] = $parsed;
        $media = $this->mediaRepository->find($mediaId);
        $format = $this->formatRepository->findBySlug($formatSlug);

        if (null === $media || null === $format) {
            return null;
        }

        return new PrintOffer($media, $format);
    }

    // The catalogue as the back-office lists it, used by the screens that price and by the ones that check a lab's skus are filled in
    /** @return list<GalleryPrintFormat> */
    public function getFormats(): array
    {
        return $this->formatRepository->findPublished();
    }
}
