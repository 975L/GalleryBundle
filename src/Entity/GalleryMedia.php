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
use c975L\UiBundle\Video\VideoPlatform;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\HttpFoundation\File\File;
use Symfony\Component\Validator\Constraints as Assert;
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

    // A video hosted somewhere nobody declared - an instance of one's own, a platform this ecosystem doesn't know. It is framed exactly as pasted, landscape by default, which is the whole difference with the declared platforms: nothing is known about it beyond the url an admin vouched for
    public const MEDIA_TYPE_EMBED = 'embed';

    // A video file of the site's own, played by the browser itself: no third party, nothing to consent to, and a video that outlives whatever a platform decides. What it costs is the storage and the bandwidth, which is why it stands next to the embeds rather than replacing them
    public const MEDIA_TYPE_VIDEO = 'video';

    // The three formats every browser's <video> can play, which is also what the upload field accepts - the same list UiBundle's own "video" block declares
    public const VIDEO_MIME_TYPES = ['video/mp4', 'video/webm', 'video/ogg'];

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

    // The site's own copy of the video, played by the browser instead of framed from a third party. Same mapping as the image above, which is what puts it in the same folder under the same name root - UiMediaNamer only forces the webp extension on the image formats, so a video keeps its own (see determineExtension)
    // It sits on this entity rather than on a Media of the library: the still, the title, the slug and the credits are already here, and a video of one's own is that entry's file, not a second entry pointing at it
    #[Vich\UploadableField(
        mapping: 'block_media',
        fileNameProperty: 'videoFilename',
        size: 'videoSize',
        mimeType: 'videoMimeType'
    )]
    private ?File $videoFile = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $videoFilename = null;

    #[ORM\Column(nullable: true)]
    private ?int $videoSize = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $videoMimeType = null;

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

    // Not an admin's choice but a reading of the url below, posed by setExternalUrl() alone - one of UiBundle's declared platforms, "embed" for a player nobody declared, "image" for an entry carrying no url at all
    #[ORM\Column(length: 20, options: ['default' => self::MEDIA_TYPE_IMAGE])]
    private string $mediaType = self::MEDIA_TYPE_IMAGE;

    // The url the player is framed from, stored whole rather than as an id: it is what lets a gallery hold a video from a platform this ecosystem never declared - an instance of one's own among them - where an id alone is only meaningful next to a scheme somebody wrote down
    // What is stored is always the canonical embed url of whatever was pasted, normalized once on the way in (see setExternalUrl): the privacy-first host is picked there, so nothing downstream has to remember to ask for it
    // The length is doubled by a constraint so an url longer than the column - tracking parameters make that ordinary - comes back as a form error instead of a "Data too long" the visitor reads as a 500
    #[ORM\Column(length: 500, nullable: true)]
    #[Assert\Length(max: 500)]
    private ?string $externalUrl = null;

    #[ORM\Column(options: ['default' => false])]
    private bool $rightsReserved = false;

    // Whether the site's signature is stamped into this media's derivatives, and in which corner. Not columns, same as keepOriginal above: the signature is laid by the pipeline that stores a file (see UiBundle's VichImageResizeListener), so the question is only ever asked when a file is being stored - by the batch that uploads it, and by the edit form when it is replaced (see GalleryMediaCrudController). Once stamped it lives in the derivatives' own pixels, and a rebuilt thumbnail carries it down with them (see GalleryThumbnailRebuilder), nothing being left for a stored flag to answer
    // A null corner takes the one set site-wide, so a gallery follows a change of mind about where signatures go
    private bool $watermark = false;
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

    // Never set by hand: the type is what the media turned out to carry, so this list is only ever read - by the badge naming a video in the grid, and by whoever styles a player after its platform
    public static function mediaTypes(): array
    {
        return [self::MEDIA_TYPE_IMAGE, self::MEDIA_TYPE_VIDEO, ...VideoPlatform::values(), self::MEDIA_TYPE_EMBED];
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function getExternalUrl(): ?string
    {
        return $this->externalUrl;
    }

    // A url one of UiBundle's platforms recognizes is stored as that platform's canonical embed url, which is the privacy-first one: youtube-nocookie rather than youtube.com, Vimeo's "dnt=1". Anything else is kept exactly as pasted - the admin vouched for it, and refusing it is what would put the gallery back behind a list of platforms somebody has to maintain
    // Http(s) only though: the url is handed to an iframe's src on the front end, so a javascript: one would run in the site's own origin. Dropped here rather than by the form alone, an import writing straight to this setter (see GalleryImportProvider)
    public function setExternalUrl(?string $externalUrl): self
    {
        $externalUrl = null !== $externalUrl ? trim($externalUrl) : null;
        $this->externalUrl = null !== $externalUrl && 1 === preg_match('#^https?://#i', $externalUrl)
            ? (VideoPlatform::resolve($externalUrl)?->embedUrl() ?? $externalUrl)
            : null;

        return $this->refreshMediaType();
    }

    public function getVideoFile(): ?File
    {
        return $this->videoFile;
    }

    public function setVideoFile(?File $videoFile): void
    {
        $this->videoFile = $videoFile;
        if (null !== $videoFile) {
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    public function getVideoFilename(): ?string
    {
        return $this->videoFilename;
    }

    // Written by Vich once the upload is stored, and by an admin removing it - either way it is what decides the media carries a video of its own, hence the type being refreshed from here
    public function setVideoFilename(?string $videoFilename): self
    {
        $this->videoFilename = '' !== $videoFilename ? $videoFilename : null;

        return $this->refreshMediaType();
    }

    public function getVideoSize(): ?int
    {
        return $this->videoSize;
    }

    public function setVideoSize(?int $videoSize): self
    {
        $this->videoSize = $videoSize;

        return $this;
    }

    public function getVideoMimeType(): ?string
    {
        return $this->videoMimeType;
    }

    public function setVideoMimeType(?string $videoMimeType): self
    {
        $this->videoMimeType = $videoMimeType;

        return $this;
    }

    // Carries a player of some kind - the still stays what the grid shows either way (see Gallery:Media)
    public function isVideo(): bool
    {
        return self::MEDIA_TYPE_IMAGE !== $this->mediaType;
    }

    // Played by the browser itself, from the site's own file: no third party, so no consent to ask and nothing to frame
    public function isSelfHostedVideo(): bool
    {
        return null !== $this->videoFilename;
    }

    // Only when there is no file of the site's own: a media carrying both plays its own copy, an url left over from before it was uploaded not being a reason to send the visitor to a third party
    public function getEmbedUrl(): ?string
    {
        return $this->isSelfHostedVideo() ? null : $this->externalUrl;
    }

    // The shape to reserve for the player before it loads, portrait for the platforms built for phones - a video framed from somewhere nobody declared gets the landscape default, there being nothing to know it by
    public function getAspectRatio(): string
    {
        return VideoPlatform::tryFrom($this->mediaType)?->aspectRatio() ?? '16 / 9';
    }

    // The type is derived rather than asked for, so it can never contradict what the media actually carries - a type with nothing to play, an url left behind by a file that replaced it
    // The site's own file wins over a pasted url: it is the copy that outlives the platform, and a media carrying both has no reason to send its visitor away
    private function refreshMediaType(): self
    {
        if (null !== $this->videoFilename) {
            $this->mediaType = self::MEDIA_TYPE_VIDEO;

            return $this;
        }

        if (null === $this->externalUrl) {
            $this->mediaType = self::MEDIA_TYPE_IMAGE;

            return $this;
        }

        $this->mediaType = VideoPlatform::resolve($this->externalUrl)?->platform->value ?? self::MEDIA_TYPE_EMBED;

        return $this;
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

    // Asked for by whoever is putting a file on the media, and never remembered afterwards: a file already stored carries the signature it was given, and a replacement is an upload of its own, answering the question again
    public function wantsWatermark(): bool
    {
        return $this->watermark;
    }

    public function setWatermark(bool $watermark): self
    {
        $this->watermark = $watermark;

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
