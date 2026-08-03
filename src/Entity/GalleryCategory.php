<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Entity;

use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

// (gallery, slug) is the category's natural key - the front-office url resolves on it (see GalleryController::resolveCategory) and the import matches on it (see GalleryImportProvider), so it can't be allowed to collide
#[ORM\Entity(repositoryClass: \c975L\GalleryBundle\Repository\GalleryCategoryRepository::class)]
#[ORM\Table(name: 'gallery_category')]
#[ORM\UniqueConstraint(name: 'gallery_category_slug_unique', columns: ['gallery_id', 'slug'])]
class GalleryCategory
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Gallery::class, inversedBy: 'categories')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    private ?Gallery $gallery = null;

    #[ORM\Column(length: 100)]
    private ?string $slug = null;

    #[ORM\Column(length: 255)]
    private ?string $title = null;

    #[ORM\Column]
    private int $position = 0;

    #[ORM\ManyToOne(targetEntity: GalleryPhoto::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?GalleryPhoto $coverPhoto = null;

    // Auto-created catch-all category a Gallery's photos fall back to when no real category is picked at upload time (see GalleryCategoryRepository::findOrCreateUncategorized) - flagged rather than matched by slug so it survives a title/slug translation or edit
    #[ORM\Column(options: ['default' => false])]
    private bool $uncategorized = false;

    #[ORM\OneToMany(mappedBy: 'category', targetEntity: GalleryPhoto::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $photos;

    public function __construct()
    {
        $this->photos = new ArrayCollection();
    }

    public function __toString(): string
    {
        return $this->title ?? '';
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getGallery(): ?Gallery
    {
        return $this->gallery;
    }

    public function setGallery(?Gallery $gallery): self
    {
        $this->gallery = $gallery;

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

    public function getCoverPhoto(): ?GalleryPhoto
    {
        return $this->coverPhoto;
    }

    public function setCoverPhoto(?GalleryPhoto $coverPhoto): self
    {
        $this->coverPhoto = $coverPhoto;

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

    /** @return Collection<int, GalleryPhoto> */
    public function getPhotos(): Collection
    {
        return $this->photos;
    }

    public function addPhoto(GalleryPhoto $photo): self
    {
        if (!$this->photos->contains($photo)) {
            $this->photos->add($photo);
            $photo->setCategory($this);
        }

        return $this;
    }

    public function removePhoto(GalleryPhoto $photo): self
    {
        if ($this->photos->removeElement($photo)) {
            if ($photo->getCategory() === $this) {
                $photo->setCategory(null);
            }
        }

        return $this;
    }
}
