<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Entity;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\GalleryBundle\Repository\GalleryMediaRepository;
use c975L\UiBundle\Contract\VichMediaNamableInterface;
use c975L\UiBundle\Contract\VichMultiSizeImageInterface;
use c975L\UiBundle\Contract\VichOriginalKeepableInterface;
use c975L\UiBundle\Contract\VichWatermarkableInterface;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Vich\UploaderBundle\Mapping\Attribute as Vich;

// getImageWidth()/getThumbnailSize()/getHighresWidth() (VichMultiSizeImageInterface, from UiBundle) drive UiBundle's own VichImageResizeListener - naming/resizing itself stays centralized there, this bundle only declares the target sizes and reads back the derivative filenames it produces
// The slug is the media's url segment under its category, so it can only be unique within it - two categories are free to both hold a "col-du-galibier", GalleryMediaSlugger suffixing only what would collide inside one category
#[ORM\Entity(repositoryClass: GalleryMediaRepository::class)]
#[ORM\Table(name: 'gallery_media')]
#[ORM\UniqueConstraint(name: 'gallery_media_category_slug', columns: ['category_id', 'slug'])]
#[Vich\Uploadable]
class GalleryMedia implements VichMultiSizeImageInterface, VichMediaNamableInterface, VichOriginalKeepableInterface, VichWatermarkableInterface
{
    // Where a kept original lands, outside public/: it is an untouched multi-megabyte upload nothing on the site ever serves, only kept so a media can be re-processed (a new target size, a new format) without a re-upload
    public const ORIGINAL_DIRECTORY = 'private';

    // "Medium" is the uploaded file's own stored size/format, used for the standard media detail view. The thumbnail (grid) and highres (zoom) are generated as sibling files alongside it - see UiBundle's VichImageResizeListener::processMultiSizeDerivatives()
    // The thumbnail holds the whole media, its longest side capped here, the grid being what squares it (see the "gallery-thumbnail-whole" config): 600 rather than the tile's own measure so the cropped display, which only keeps the shortest side of a 3:2 photo, still has 400 pixels to fill a 150px tile on a 2x screen
    public const MEDIUM_WIDTH = 1024;
    public const HIGHRES_WIDTH = 2048;
    public const THUMBNAIL_SIZE = 600;

    // What the detail page shows. Every entry carries an uploaded image whatever its type - it is what the grid displays, and what a video entry has instead of a poster fetched from a third party at render time; the type only decides whether that image or an embed is shown once the entry is opened
    public const MEDIA_TYPE_IMAGE = 'image';
    public const MEDIA_TYPE_YOUTUBE = 'youtube';
    public const MEDIA_TYPE_TIKTOK = 'tiktok';
    public const MEDIA_TYPES = [self::MEDIA_TYPE_IMAGE, self::MEDIA_TYPE_YOUTUBE, self::MEDIA_TYPE_TIKTOK];

    // Cookie-free hosts on both sides: nothing is set until the visitor actually plays, which is what lets the embeds be served without a consent gate
    private const EMBED_URLS = [
        self::MEDIA_TYPE_YOUTUBE => 'https://www.youtube-nocookie.com/embed/%s',
        self::MEDIA_TYPE_TIKTOK => 'https://www.tiktok.com/embed/v2/%s',
    ];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: GalleryCategory::class, inversedBy: 'medias')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?GalleryCategory $category = null;

    // Reuses UiBundle's own "block_media" mapping (same UiMediaNamer, same storage) rather than declaring a new one - see GalleryMedia::getVichMediaPath() for where this actually lands on disk
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

    // One field for three jobs: what the media is called on screen, its alt text, and what its slug is built from - an admin retouching it retouches all three at once, where a separate alt would be the one nobody thinks of filling in
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $title = null;

    // Posed once, when the media is created, and never recomputed from the title afterwards: it is a public url, and a title is retouched precisely because the first one was a placeholder - having the url follow it made every such correction cost a redirect, and made naming the batch right the first time a problem it never had to be. Only an admin editing the slug field itself moves it now, and that one is recorded as a redirect (see GalleryMediaCrudController::updateEntity)
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $slug = null;

    // Path of the kept original under ORIGINAL_DIRECTORY, null when none was kept - written back by UiBundle's VichImageResizeListener, which is the only place the untouched upload still exists. Doubles as the answer to "does this media have an original", no separate flag being needed
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $originalFilename = null;

    // Not a column: what the upload screen's checkbox asks for, carried to the listener that acts on it. What deserves to be stored is whether an original was actually kept, which originalFilename already says - a media whose file is later replaced keeps whatever it had (see getOriginalDirectory)
    private bool $keepOriginal = false;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $credits = null;

    #[ORM\Column(length: 20, options: ['default' => self::MEDIA_TYPE_IMAGE])]
    private string $mediaType = self::MEDIA_TYPE_IMAGE;

    // The video's id on its platform, nothing more - the watch/embed urls are built from it here, so a platform changing its url scheme is one constant to edit rather than a column to migrate
    #[ORM\Column(length: 64, nullable: true)]
    private ?string $externalId = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $rightsReserved = false;

    // Whether the site's signature is stamped into this media's derivatives, and in which corner - columns, unlike keepOriginal above, whose outcome originalFilename already records: nothing in a stamped webp says it was stamped, and a thumbnail rebuilt years later has to come back signed the same way (see GalleryThumbnailRebuilder)
    // A null corner takes the one set site-wide, so a gallery follows a change of mind about where signatures go without every row being rewritten
    #[ORM\Column(options: ['default' => false])]
    private bool $watermarked = false;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $watermarkPosition = null;

    #[ORM\ManyToOne]
    private ?UserInterface $user = null;

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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(?string $slug): self
    {
        $this->slug = $slug;

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

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    // Falls back to "image" rather than rejecting an unknown value: this is fed by an import as much as by the admin form, and an entry showing its still instead of an embed beats an import dying halfway
    public function setMediaType(?string $mediaType): self
    {
        $this->mediaType = \in_array($mediaType, self::MEDIA_TYPES, true) ? $mediaType : self::MEDIA_TYPE_IMAGE;

        return $this;
    }

    public function getExternalId(): ?string
    {
        return $this->externalId;
    }

    public function setExternalId(?string $externalId): self
    {
        $this->externalId = '' !== $externalId ? $externalId : null;

        return $this;
    }

    // A video only once it has both: a type alone has nothing to embed, and an id left behind by a type switched back to "image" must not resurrect the player
    public function isVideo(): bool
    {
        return null !== $this->externalId && isset(self::EMBED_URLS[$this->mediaType]);
    }

    public function getEmbedUrl(): ?string
    {
        return $this->isVideo() ? sprintf(self::EMBED_URLS[$this->mediaType], $this->externalId) : null;
    }

    public function getUser(): ?UserInterface
    {
        return $this->user;
    }

    public function setUser(?UserInterface $user): self
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

    // Asked for by the batch that uploaded the media, and kept afterwards: a media whose file is replaced comes back signed, having been signed before
    public function wantsWatermark(): bool
    {
        return $this->watermarked;
    }

    public function setWatermarked(bool $watermarked): self
    {
        $this->watermarked = $watermarked;

        return $this;
    }

    public function getWatermarkPosition(): ?string
    {
        return $this->watermarkPosition;
    }

    // Anything but one of the four corners is stored as none at all, which takes the site-wide setting - the value comes from a form choice, and a corner nobody named is not a corner
    public function setWatermarkPosition(?string $position): self
    {
        $this->watermarkPosition = in_array($position, VichWatermarkableInterface::POSITIONS, true) ? $position : null;

        return $this;
    }

    // The stored file is named after the slug the media is reached by, so the file on disk and the page pointing at it read the same - UiMediaNamer appends its own "-{uniqid}.{ext}", which is what keeps two same-titled medias apart and busts the cache when a file is replaced
    // Reads what is already in memory and nothing else: Vich's prePersist listener runs before the auto-increment id is assigned, hence a slug posed by whoever created the media (see GalleryMediaSlugger) rather than anything derived from the row
    // Renaming a media afterwards does not rename its file: the slug carried here is the one it had when the file was uploaded, and moving three files (medium, thumbnail, high resolution) would cost the old urls their place in an image index for a signal the alt text already carries
    public function getVichMediaPath(): string
    {
        $categorySlug = $this->getCategory()?->getSlug() ?? 'uncategorized';

        return 'medias/gallery/' . $categorySlug . '/' . ($this->slug ?? 'media');
    }

    // Sibling files generated alongside the stored (medium) one - see UiBundle's VichImageResizeListener::processMultiSizeDerivatives()
    public function getThumbnailFilename(): ?string
    {
        return $this->deriveFilename('-thumb');
    }

    public function getHighresFilename(): ?string
    {
        return $this->deriveFilename('-highres');
    }

    public function getOriginalFilename(): ?string
    {
        return $this->originalFilename;
    }

    public function setOriginalFilename(?string $filename): void
    {
        $this->originalFilename = $filename;
    }

    // Asked for by the batch that created the media, or already true of a media whose file is being replaced: an original kept once goes on being kept, the checkbox only ever being answered at upload time
    public function getOriginalDirectory(): ?string
    {
        return $this->keepOriginal || null !== $this->originalFilename ? self::ORIGINAL_DIRECTORY : null;
    }

    public function setKeepOriginal(bool $keepOriginal): self
    {
        $this->keepOriginal = $keepOriginal;

        return $this;
    }

    // Not derived like the thumbnail and highres ones: those keep the stored file's extension, which UiMediaNamer forces to webp, where the original carries the upload's own (see VichImageResizeListener::keepOriginal)
    private function deriveFilename(string $suffix): ?string
    {
        if (null === $this->filename) {
            return null;
        }

        return preg_replace('/(\.[^.\/]+)$/', $suffix . '$1', $this->filename);
    }
}
