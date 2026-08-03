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

// Top-level container so a site can host more than one independent photo gallery (e.g. a main portfolio plus a separate one-off event gallery), each with its own categories/photos. The "default" gallery's public routes omit the {gallery} slug segment (see GalleryController) so the common single-gallery case keeps the same short, already-indexed URLs.
#[ORM\Entity(repositoryClass: \c975L\GalleryBundle\Repository\GalleryRepository::class)]
#[ORM\Table(name: 'gallery')]
class Gallery
{
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

    // Enforced at the application level (like Media::isSingletonRole in UiBundle), not a DB constraint - only one Gallery is expected to carry this at a time
    // Column named "is_default", not "default": that one is a reserved word every engine quotes in DDL and none quotes in the INSERT Doctrine writes, so a plain "default" only ever fails at the first persist
    #[ORM\Column(name: 'is_default', options: ['default' => false])]
    private bool $default = false;

    #[ORM\OneToMany(mappedBy: 'gallery', targetEntity: GalleryCategory::class, cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[ORM\OrderBy(['position' => 'ASC'])]
    private Collection $categories;

    public function __construct()
    {
        $this->categories = new ArrayCollection();
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

    public function isDefault(): bool
    {
        return $this->default;
    }

    public function setDefault(?bool $default): self
    {
        $this->default = $default ?? false;

        return $this;
    }

    /** @return Collection<int, GalleryCategory> */
    public function getCategories(): Collection
    {
        return $this->categories;
    }

    public function addCategory(GalleryCategory $category): self
    {
        if (!$this->categories->contains($category)) {
            $this->categories->add($category);
            $category->setGallery($this);
        }

        return $this;
    }

    public function removeCategory(GalleryCategory $category): self
    {
        if ($this->categories->removeElement($category)) {
            if ($category->getGallery() === $this) {
                $category->setGallery(null);
            }
        }

        return $this;
    }
}
