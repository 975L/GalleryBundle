<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;

/**
 * One line of the print catalogue: a size, a paper, a price, and the reference the lab knows it by.
 *
 * An entity and not a block of JSON in the configuration because this is what an admin changes most often - a price
 * rises, a paper is discontinued, a lab renames a product - and editing a JSON array in a text field is how a shop ends
 * up with a catalogue nobody dares touch. It is also the one place a change of lab shows: the same sizes, other skus.
 */
#[ORM\Entity(repositoryClass: \c975L\GalleryBundle\Repository\GalleryPrintFormatRepository::class)]
#[ORM\Table(name: 'gallery_print_format')]
#[UniqueEntity('slug')]
class GalleryPrintFormat implements \Stringable
{
    // Below this, an image printed at the size asked for shows its pixels. Overridable per format because a print meant to be read at arm's length and one meant for a wall are not looked at from the same distance
    public const DEFAULT_DPI = 300;

    // How far a photograph's proportions may be from the format's before it is not offered at that size. A 3:2 sensor and a 30x45 sheet agree exactly; 24x30 and 4:3 are half a percent apart, and refusing that would be refusing arithmetic
    public const RATIO_TOLERANCE = 0.03;

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // What a basket line and an old order name this format by, so a renamed label never orphans what was already sold
    #[ORM\Column(length: 50, unique: true)]
    private ?string $slug = null;

    // What the customer reads - "30 x 45 cm, Hahnemühle Photo Rag" - written by the admin rather than translated: a catalogue is a shop's own words, and a paper has one name
    #[ORM\Column(length: 255)]
    private ?string $label = null;

    #[ORM\Column]
    private ?int $widthCm = null;

    #[ORM\Column]
    private ?int $heightCm = null;

    #[ORM\Column(options: ['default' => self::DEFAULT_DPI])]
    private int $dpi = self::DEFAULT_DPI;

    // VAT included, in cents, as everywhere in this ecosystem (see PaymentBundle's VatCalculator)
    #[ORM\Column]
    private ?int $price = null;

    #[ORM\Column]
    private float $vat = 20.0;

    // What the lab calls this product. The only field here that belongs to the lab and not to the shop - changing lab rewrites this column and nothing else
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(options: ['default' => 0])]
    private int $position = 0;

    #[ORM\Column(options: ['default' => true])]
    private bool $published = true;

    public function __toString(): string
    {
        return $this->label ?? (string) $this->slug;
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

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function getWidthCm(): ?int
    {
        return $this->widthCm;
    }

    public function setWidthCm(?int $widthCm): self
    {
        $this->widthCm = $widthCm;

        return $this;
    }

    public function getHeightCm(): ?int
    {
        return $this->heightCm;
    }

    public function setHeightCm(?int $heightCm): self
    {
        $this->heightCm = $heightCm;

        return $this;
    }

    public function getDpi(): int
    {
        return $this->dpi;
    }

    public function setDpi(int $dpi): self
    {
        $this->dpi = $dpi;

        return $this;
    }

    public function getPrice(): ?int
    {
        return $this->price;
    }

    public function setPrice(?int $price): self
    {
        $this->price = $price;

        return $this;
    }

    public function getVat(): float
    {
        return $this->vat;
    }

    public function setVat(float $vat): self
    {
        $this->vat = $vat;

        return $this;
    }

    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function setSku(?string $sku): self
    {
        $this->sku = $sku;

        return $this;
    }

    public function getPosition(): int
    {
        return $this->position;
    }

    public function setPosition(int $position): self
    {
        $this->position = $position;

        return $this;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function setPublished(?bool $published): self
    {
        $this->published = $published ?? true;

        return $this;
    }

    // Always at or above 1, so a portrait format and its landscape twin compare to a photograph the same way round - what is being matched is proportions, not orientation, and a lab prints either
    public function getRatio(): float
    {
        $long = max((int) $this->widthCm, (int) $this->heightCm);
        $short = min((int) $this->widthCm, (int) $this->heightCm);

        return $short > 0 ? $long / $short : 0.0;
    }

    // The long edge in pixels a file must have to be printed here without showing them
    public function getRequiredPixels(): int
    {
        return (int) ceil(max((int) $this->widthCm, (int) $this->heightCm) / 2.54 * $this->dpi);
    }

    // Whether a photograph of these proportions belongs at this size at all. Asked before anything is priced, so a 3:2 photograph is never offered a square print it would have to be cropped into
    public function acceptsRatio(float $ratio): bool
    {
        $own = $this->getRatio();

        return $own > 0.0 && abs($ratio - $own) / $own <= self::RATIO_TOLERANCE;
    }
}
