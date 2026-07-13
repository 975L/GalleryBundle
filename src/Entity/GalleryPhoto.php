<?php
/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */
namespace c975L\GalleryBundle\Entity;

use App\Entity\User;
use c975L\GalleryBundle\Repository\GalleryPhotoRepository;
use c975L\UiBundle\Contract\VichMediaNamableInterface;
use c975L\UiBundle\Contract\VichMultiSizeImageInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Annotation as Vich;

// getImageWidth()/getThumbnailSize()/getHighresWidth() (VichMultiSizeImageInterface, from UiBundle)
// drive UiBundle's own VichImageResizeListener - naming/resizing itself stays centralized there, this
// bundle only declares the target sizes and reads back the derivative filenames it produces
#[ORM\Entity(repositoryClass: GalleryPhotoRepository::class)]
#[ORM\Table(name: 'gallery_photo')]
#[Vich\Uploadable]
class GalleryPhoto implements VichMultiSizeImageInterface, VichMediaNamableInterface
{
    // "Medium" is the uploaded file's own stored size/format, used for the standard photo detail
    // view. The thumbnail (square, grid) and highres (zoom) are generated as sibling files alongside
    // it - see UiBundle's VichImageResizeListener::processMultiSizeDerivatives()
    public const MEDIUM_WIDTH = 1024;
    public const HIGHRES_WIDTH = 2048;
    public const THUMBNAIL_SIZE = 400;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GalleryCategory::class, inversedBy: 'photos')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GalleryCategory $category = null;

    // Reuses UiBundle's own "block_media" mapping (same UiMediaNamer, same storage) rather than
    // declaring a new one - see GalleryPhoto::getVichMediaPath() for where this actually lands on disk
    #[Vich\UploadableField(
        mapping: 'block_media',
        fileNameProperty: 'filename',
        size: 'size',
        mimeType: 'mimeType'
    )]
    private ?File $file = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $filename = null;

    #[ORM\Column(nullable: true)]
    private ?int $size = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $mimeType = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $updatedAt = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $alt = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $credits = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $rightsReserved = false;

    #[ORM\ManyToOne]
    private ?User $user = null;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    public function __construct()
    {
        $this->createdAt = new \DateTimeImmutable();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCategory(): ?GalleryCategory
    {
        return $this->category;
    }

    public function setCategory(?GalleryCategory $category): self
    {
        $this->category = $category;

        return $this;
    }

    public function getFile(): ?File
    {
        return $this->file;
    }

    public function setFile(?File $file): void
    {
        $this->file = $file;
        if (null !== $file) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): self
    {
        $this->filename = $filename;

        return $this;
    }

    public function getSize(): ?int
    {
        return $this->size;
    }

    public function setSize(?int $size): self
    {
        $this->size = $size;

        return $this;
    }

    public function getMimeType(): ?string
    {
        return $this->mimeType;
    }

    public function setMimeType(?string $mimeType): self
    {
        $this->mimeType = $mimeType;

        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(?\DateTimeImmutable $updatedAt): self
    {
        $this->updatedAt = $updatedAt;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): self
    {
        $this->position = $position ?? 0;

        return $this;
    }

    public function getAlt(): ?string
    {
        return $this->alt;
    }

    public function setAlt(?string $alt): self
    {
        $this->alt = $alt;

        return $this;
    }

    public function getCredits(): ?string
    {
        return $this->credits;
    }

    public function setCredits(?string $credits): self
    {
        $this->credits = $credits;

        return $this;
    }

    public function isRightsReserved(): bool
    {
        return $this->rightsReserved;
    }

    public function setRightsReserved(?bool $rightsReserved): self
    {
        $this->rightsReserved = $rightsReserved ?? false;

        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): self
    {
        $this->user = $user;

        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getImageWidth(): int
    {
        return self::MEDIUM_WIDTH;
    }

    public function getThumbnailSize(): int
    {
        return self::THUMBNAIL_SIZE;
    }

    public function getHighresWidth(): int
    {
        return self::HIGHRES_WIDTH;
    }

    public function getVichMediaPath(): string
    {
        $category = $this->getCategory();
        $gallerySlug = $category?->getGallery()?->getSlug() ?? 'gallery';
        $categorySlug = $category?->getSlug() ?? 'uncategorized';

        return 'medias/gallery/' . $gallerySlug . '/' . $categorySlug . '/photo';
    }

    // Sibling files generated alongside the stored (medium) one - see UiBundle's
    // VichImageResizeListener::processMultiSizeDerivatives()
    public function getThumbnailFilename(): ?string
    {
        return $this->deriveFilename('-thumb');
    }

    public function getHighresFilename(): ?string
    {
        return $this->deriveFilename('-highres');
    }

    private function deriveFilename(string $suffix): ?string
    {
        if (null === $this->filename) {
            return null;
        }

        return preg_replace('/(\.[^.\/]+)$/', $suffix . '$1', $this->filename);
    }
}
