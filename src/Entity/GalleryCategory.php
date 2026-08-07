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
use c975L\UiBundle\Entity\Block;
use c975L\UiBundle\Entity\Trait\HasBlocksTrait;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

// The category is the top-level unit of the gallery - a site's galleries are its categories, there is no container above them
// The slug is the category's natural key: the front-office url resolves on it (see GalleryController::resolveCategory) and the import matches on it (see GalleryImportProvider), so it can't be allowed to collide
// A collision is reported on the form and the category isn't saved, rather than being silently worked around with a numeric suffix that would leave the admin with two look-alike categories
#[ORM\Entity(repositoryClass: \c975L\GalleryBundle\Repository\GalleryCategoryRepository::class)]
#[ORM\Table(name: 'gallery_category')]
#[UniqueEntity('slug')]
class GalleryCategory implements HasBlocksInterface
{
    use HasBlocksTrait;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: GalleryMedia::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?GalleryMedia $coverMedia = null;

    // Auto-created catch-all category a Gallery's medias fall back to when no real category is picked at upload time (see GalleryCategoryRepository::findOrCreateUncategorized) - flagged rather than matched by slug so it survives a title/slug translation or edit
    #[ORM\Column(options: ['default' => false])]
    private bool $uncategorized = false;

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

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(?int $position): self
    {
        $this->position = $position ?? 0;

        return $this;
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

    /** @return Collection<int, GalleryMedia> */
    public function getMedias(): Collection
    {
        return $this->medias;
    }

    // What the back-office category listing shows instead of the medias themselves, the medias being managed from the category's own edit screen (see GalleryCategoryCrudController)
    public function getMediasCount(): int
    {
        return $this->medias->count();
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
