<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Entity;

use c975L\UiBundle\Contract\HasBlocksInterface;
use c975L\UiBundle\Contract\TrashableInterface;
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Entity\Trait\HasBlocksTrait;
use c975L\UiBundle\Entity\Trait\TrashableTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

// The category is the top-level unit of the gallery - a site's galleries are its categories, there is no container above them
// The slug is the category's natural key: the front-office url resolves on it (see GalleryController::resolveCategory) and the import matches on it (see GalleryImportProvider), so it can't be allowed to collide
// A collision is reported on the form and the category isn't saved, rather than being silently worked around with a numeric suffix that would leave the admin with two look-alike categories
#[ORM\Entity(repositoryClass: \c975L\GalleryBundle\Repository\GalleryCategoryRepository::class)]
#[ORM\Table(name: 'gallery_category')]
#[UniqueEntity('slug')]
class GalleryCategory implements HasBlocksInterface, TrashableInterface, \Stringable
{
    use HasBlocksTrait;
    // A category deleted from the back-office only lands here, its medias and their files untouched - what actually removes them is deletePermanently() (see GalleryCategoryCrudController), the only path left that reaches the cascade below
    use TrashableTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    // The category's own lead-in, rich text typed in the back-office (see GalleryCategoryCrudController) - printed above the grid and, stripped of its markup, used as the page's description/og:description meta. One field for both: what introduces a gallery to a reader is what introduces it to a search engine, and an admin having to type the same sentence twice would leave one of them stale
    // Named after SiteBundle's Page::$summarySocialNetwork, and ConfigBundle's UrlMetadata::$summarySocialNetwork, rather than after what it prints: it is the same text in the same role across the three, the one both layouts read through the Twig variable of that very name, and one name for it is what keeps a site from having to learn a second one per bundle
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $summarySocialNetwork = null;

    #[ORM\Column]
    private int $position = 0;

    // The fields this site adds to a gallery and no other site has, held as one JSON payload rather than a column each - same reasoning as UiBundle's Block::$data: what a single site needs is then a form type it declares (see GalleryCustomizationProviderInterface::getCategoryDataFormType()), no schema migration for every app running this bundle. Anything the database itself has to filter, sort or join on stays a real column
    /** @var array<string, mixed>|null */
    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $data = null;

    #[ORM\ManyToOne(targetEntity: GalleryMedia::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?GalleryMedia $coverMedia = null;

    // Auto-created catch-all category a Gallery's medias fall back to when no real category is picked at upload time (see GalleryCategoryRepository::findOrCreateUncategorized) - flagged rather than matched by slug so it survives a title/slug translation or edit
    #[ORM\Column(options: ['default' => false])]
    private bool $uncategorized = false;

    // The gallery of the last additions: a category holding no media of its own, showing those the other categories received on their last days of upload (see GalleryLatestProvider). Flagged rather than matched by slug, exactly as the catch-all above, so it survives a rename - and written by GalleryCategoryRepository::findOrCreateAutomatic(), the import guarding against a second one (see GalleryImportProvider::import): the schema does not enforce it
    // It is a real row rather than a category built on the fly, so it is arranged, described, given a heading, linked from a menu and declared in the sitemap like any other gallery, without a single screen having to learn a second kind of category
    #[ORM\Column(options: ['default' => false])]
    private bool $automatic = false;

    // Not a column: an automatic gallery's medias belong to other categories, so they are handed over at read time rather than held by the relation below - everything reading a category's medias (its index tile, its count, its og:image) then works on it without knowing which kind it has in hand
    /** @var list<GalleryMedia> */
    private array $automaticMedias = [];

    // Not a column either: the visible medias read in bulk for a category that has no cover, so a listing draws every tile from one query instead of initializing the lazy relation below category by category (see GalleryCategoryRepository::findAllOrdered)
    // Null means "nobody handed anything over", which is not the same as an empty list: a category preloaded and found empty has no media at all, and must not go back to the database to be told so again
    /** @var ?list<GalleryMedia> */
    private ?array $loadedMedias = null;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: GalleryMedia::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $medias;

    // Editorial heading composed in the back-office and rendered above the grid (see gallery/category.html.twig), so a category can introduce its medias with any of UiBundle's block kinds instead of only ever being a wall of thumbnails
    #[ORM\ManyToMany(targetEntity: Block::class, cascade: ['persist', 'remove'])]
    #[ORM\JoinTable(name: 'gallery_category_block')]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $blocks;

    public function __construct()
    {
        $this->medias = new ArrayCollection();
        $this->blocks = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getTitle(): ?string
    {
        return $this->title;
    }

    public function setTitle(?string $title): self
    {
        $this->title = $title;

        return $this;
    }

    public function getSummarySocialNetwork(): ?string
    {
        return $this->summarySocialNetwork;
    }

    public function setSummarySocialNetwork(?string $summarySocialNetwork): self
    {
        $this->summarySocialNetwork = $summarySocialNetwork;

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

    /** @return array<string, mixed> */
    public function getData(): array
    {
        return $this->data ?? [];
    }

    /** @param array<string, mixed>|null $data */
    public function setData(?array $data): static
    {
        $this->data = $data;

        return $this;
    }

    // One field of what this site adds, read by name so a template never spells out the payload's shape
    public function getDataValue(string $key, mixed $default = null): mixed
    {
        return $this->getData()[$key] ?? $default;
    }

    public function getCoverMedia(): ?GalleryMedia
    {
        return $this->coverMedia;
    }

    public function setCoverMedia(?GalleryMedia $coverMedia): self
    {
        $this->coverMedia = $coverMedia;

        return $this;
    }

    public function isUncategorized(): bool
    {
        return $this->uncategorized;
    }

    public function setUncategorized(?bool $uncategorized): self
    {
        $this->uncategorized = $uncategorized ?? false;

        return $this;
    }

    public function isAutomatic(): bool
    {
        return $this->automatic;
    }

    public function setAutomatic(?bool $automatic): self
    {
        $this->automatic = $automatic ?? false;

        return $this;
    }

    // Posed by GalleryLatestProvider alone, on the automatic category and on no other - a category given a list it isn't meant to show would report a media count it doesn't display
    /** @param list<GalleryMedia> $medias */
    public function setAutomaticMedias(array $medias): self
    {
        $this->automaticMedias = $medias;

        return $this;
    }

    // Posed by GalleryCategoryRepository alone, on the categories it read the medias of - the list is the whole visible one, so the count read next to a tile stays the count the grid shows
    /** @param list<GalleryMedia> $medias */
    public function setLoadedMedias(array $medias): self
    {
        $this->loadedMedias = $medias;

        return $this;
    }

    /** @return Collection<int, GalleryMedia> */
    public function getMedias(): Collection
    {
        return $this->medias;
    }

    // The face the category shows wherever it stands for itself - its index tile, its back-office row, its page's og:image - being the cover an admin picked, or one of its medias at random until one is (see GalleryCategoryCrudController::saveMediasLayout())
    // A trashed media is never drawn, nor kept as a cover: it is a photo an admin took off the site, and having it stand for the whole category on the index would put it back on the very page it was removed from
    public function getCoverOrRandomMedia(): ?GalleryMedia
    {
        if (null !== $this->coverMedia && !$this->coverMedia->isDeleted()) {
            return $this->coverMedia;
        }

        $medias = $this->visibleMedias();
        if ([] === $medias) {
            return null;
        }

        // The automatic gallery shows its newest photo rather than one of them at random: its list is ordered by date of addition, and what it stands for is precisely what has just arrived
        return $this->automatic ? $medias[0] : $medias[array_rand($medias)];
    }

    // What the back-office category listing shows instead of the medias themselves, the medias being managed from the category's own edit screen (see GalleryCategoryCrudController)
    // Counts what the site shows, not what the table holds: the number is read next to the category everywhere, and one that kept counting the trash would never match the grid it names
    public function getMediasCount(): int
    {
        return count($this->visibleMedias());
    }

    /** @return list<GalleryMedia> */
    private function visibleMedias(): array
    {
        // Handed over rather than held: the relation of an automatic gallery is empty by definition, and reading it would leave its tile blank and its count at zero
        if ($this->automatic) {
            return $this->automaticMedias;
        }

        // Read in bulk with the listing this category came from, the relation then never being touched at all
        if (null !== $this->loadedMedias) {
            return $this->loadedMedias;
        }

        return array_values(array_filter(
            $this->medias->toArray(),
            static fn (GalleryMedia $media): bool => !$media->isDeleted()
        ));
    }

    public function addMedia(GalleryMedia $media): self
    {
        if (!$this->medias->contains($media)) {
            $this->medias->add($media);
            $media->setCategory($this);
        }

        return $this;
    }

    public function removeMedia(GalleryMedia $media): self
    {
        if ($this->medias->removeElement($media)) {
            if ($media->getCategory() === $this) {
                $media->setCategory(null);
            }
        }

        return $this;
    }
}
