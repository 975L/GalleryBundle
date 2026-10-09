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
use c975L\GalleryBundle\Entity\GalleryPrintCopy;
use c975L\GalleryBundle\Entity\GalleryPrintFormat;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use Imagine\Gd\Imagine;
use Imagine\Image\Box;
use Imagine\Image\ImageInterface;
use Imagine\Image\Point;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Builds the file a lab actually prints.
 *
 * Never the derivative a browser is served. That one is 2048 pixels wide - seventeen centimetres at 300 dpi - and it
 * carries a signature laid out for a screen: enlarged to a sheet of paper it would come out as a blurred stamp. The
 * print is composed from the untouched original instead, and the signature is laid on it again at that size.
 *
 * Written to disk rather than streamed because a lab fetches it by url, on its own schedule and sometimes twice.
 */
class GalleryPrintFileBuilder
{
    // Share of the print's width the signature is laid at, matching what UiBundle stamps derivatives with - a signature is recognisable by its proportion to the image, not by a number of pixels
    public const SIGNATURE_RATIO = 0.12;

    // How far the signature sits from the edge, as a share of the width
    private const float MARGIN_RATIO = 0.025;

    public function __construct(
        private readonly ConfigServiceInterface $configService,
        private readonly GalleryPrintService $printService,
        private readonly Filesystem $filesystem,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        // Where built files wait for the lab to come and get them. Outside public/, reachable only through the signed route that serves them (see PrintFileController)
        #[Autowire(param: 'c975l_gallery.print_dir')]
        private readonly string $printDir,
    ) {
    }

    /**
     * Composes the print of one copy and returns where it was written, or null when the original it needs is gone.
     *
     * Idempotent: a file already built for that copy is handed back as it is. A lab asking twice - and they do, a
     * retried order being an ordinary event - must be given the same pixels, not a second composition of them.
     */
    public function build(GalleryPrintCopy $copy): ?string
    {
        $media = $copy->getMedia();
        $path = $this->getPath($copy);

        if (null === $media || null === $path) {
            return null;
        }

        if ($this->filesystem->exists($path)) {
            return $path;
        }

        $source = $this->printService->getOriginalPath($media);

        if (null === $source || !is_file($source)) {
            return null;
        }

        // The size sold, read back from the catalogue by the slug the copy kept - a format withdrawn since leaves the print unbuildable, which is a refusal and not a fallback to another size
        $format = array_find(
            $this->printService->getFormats(),
            static fn (GalleryPrintFormat $candidate): bool => $candidate->getSlug() === $copy->getFormat(),
        );

        if (null === $format) {
            return null;
        }

        $image = new Imagine()->open($source);

        // Down to what the size asked for needs and never up: the file was refused at the sale if it did not have the pixels, so there is nothing to invent here
        $target = $format->getRequiredPixels();
        $box = $image->getSize();
        if (max($box->getWidth(), $box->getHeight()) > $target) {
            $image = $image->thumbnail(new Box($target, $target), ImageInterface::THUMBNAIL_INSET);
        }

        $this->stamp($image, $media->getWatermarkPosition());

        $this->filesystem->mkdir(\dirname($path));

        // JPEG at the top of its quality rather than a lossless format: it is what every lab accepts without a word, and the artefacts of a single pass at 98 do not survive being printed
        $image->save($path, ['format' => 'jpeg', 'jpeg_quality' => 98]);

        return $path;
    }

    // Where a copy's print sits, keyed on the copy and not on the photograph: two sizes of the same photograph are two different files, and a numbered copy is not the same print as its neighbour. Its certificate joins the number, which two databases sharing the folder may both have given out
    public function getPath(GalleryPrintCopy $copy): ?string
    {
        $id = $copy->getId();

        if (null === $id) {
            return null;
        }

        $certificate = $copy->getCertificate();

        return sprintf('%s/%d%s.jpg', rtrim($this->printDir, '/'), $id, null !== $certificate ? '-' . $certificate : '');
    }

    // Frees what a shipped order no longer needs - these are the largest files this bundle writes, and keeping the print of every order ever placed is how a disk fills up
    public function discard(GalleryPrintCopy $copy): void
    {
        $path = $this->getPath($copy);

        if (null !== $path && $this->filesystem->exists($path)) {
            $this->filesystem->remove($path);
        }
    }

    // Lays the site's signature at print size. Does nothing when no signature file is configured, an unsigned print being a legitimate choice and not an error
    private function stamp(ImageInterface $image, ?string $position): void
    {
        $signature = $this->getSignaturePath();

        if (null === $signature) {
            return;
        }

        $box = $image->getSize();
        $width = (int) round($box->getWidth() * self::SIGNATURE_RATIO);
        $stamp = new Imagine()->open($signature);
        $stamp = $stamp->thumbnail(new Box($width, $width), ImageInterface::THUMBNAIL_INSET);
        $stampBox = $stamp->getSize();
        $margin = (int) round($box->getWidth() * self::MARGIN_RATIO);

        $x = match ($position) {
            VichWatermarkableInterface::POSITION_TOP_LEFT, VichWatermarkableInterface::POSITION_BOTTOM_LEFT => $margin,
            default => $box->getWidth() - $stampBox->getWidth() - $margin,
        };

        $y = match ($position) {
            VichWatermarkableInterface::POSITION_TOP_LEFT, VichWatermarkableInterface::POSITION_TOP_RIGHT => $margin,
            default => $box->getHeight() - $stampBox->getHeight() - $margin,
        };

        $image->paste($stamp, new Point(max(0, $x), max(0, $y)), 100);
    }

    // The signature laid on prints, which a site may want different from the one it stamps its web images with - paper takes a heavier stroke than a screen
    private function getSignaturePath(): ?string
    {
        $configured = $this->configService->get('gallery-print-signature');

        if (!\is_string($configured) || '' === $configured) {
            return null;
        }

        $path = $this->projectDir . '/public/' . ltrim($configured, '/');

        return is_file($path) ? $path : null;
    }
}
