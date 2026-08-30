<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Entity;

use c975L\GalleryBundle\Model\PrintCopySnapshot;
use Doctrine\ORM\Mapping as ORM;

/**
 * One print, and for a limited edition one number of it. This is the register: what an edition of thirty means is thirty
 * rows, written when the edition is published and claimed one at a time as they sell.
 *
 * Written up front rather than counted on the way out because two baskets can hold the last copy at once. A counter
 * decremented at checkout has no answer for that; thirty rows and a unique constraint do - claiming is an update that
 * either takes an unclaimed row or takes nothing, and the second customer is told the edition is out before anything is
 * charged.
 *
 * An open edition has rows too, created as they sell and carrying no number - so one table answers "what was printed"
 * for both, and the certificate only has something to say about the numbered ones.
 */
#[ORM\Entity(repositoryClass: \c975L\GalleryBundle\Repository\GalleryPrintCopyRepository::class)]
#[ORM\Table(name: 'gallery_print_copy')]
#[ORM\UniqueConstraint(name: 'gallery_print_copy_number', columns: ['media_id', 'number'])]
class GalleryPrintCopy implements \Stringable
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // Detached rather than kept when the photograph is deleted for good: the sale is whole on this row already (see PrintCopySnapshot), so an admin emptying a trash never meets a foreign key he can only clear from the base, and the register keeps saying what was sold
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?GalleryMedia $media = null;

    // Rank in the edition, null for an open one. Unique per media and never reused: a cancelled order releases the row back to the edition, it does not renumber what has already been sold
    #[ORM\Column(nullable: true)]
    private ?int $number = null;

    // Null while the copy is still to be sold - which is the whole point for a limited edition, where the rows exist before any customer
    #[ORM\ManyToOne(inversedBy: 'copies')]
    private ?GalleryPrintOrder $order = null;

    // The format key from the print catalogue, and what it cost, both frozen at the sale: the catalogue is an admin's to reprice, and a certificate issued last year has to keep saying what was actually bought
    #[ORM\Column(length: 50, nullable: true)]
    private ?string $format = null;

    #[ORM\Column(nullable: true)]
    private ?int $price = null;

    // What the certificate prints, all five read once at the sale and never looked up again (see PrintCopySnapshot). The paper is signed by hand and posted, so from that day the buyer holds the document - retitling the photograph, renaming the format or renaming the site must not make the site's own copy of it say something else
    // Each as wide as what it copies, never narrower: these are written by the statement that claims a number, which runs once the customer has already paid, and a value too long for its column would fail there of all places
    #[ORM\Column(length: 255, nullable: true)]
    private ?string $formatLabel = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $sku = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $workTitle = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $credits = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $issuer = null;

    // What the certificate's public page resolves on and what its QR code carries. Posed at the sale and not at publication, so the certificate of a copy nobody has bought cannot be reached by guessing a number
    #[ORM\Column(length: 36, unique: true, nullable: true)]
    private ?string $certificate = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $soldAt = null;

    // The title and the rank, which is how a copy is named everywhere it is read - the back-office list, the certificate, the letter that announces it
    // The frozen title once the copy is sold, the live one while the row is still waiting for a buyer and has nothing frozen yet
    public function __toString(): string
    {
        $title = $this->workTitle ?? $this->media?->getTitle() ?? '';

        if (null === $this->number) {
            return $title;
        }

        return sprintf('%s %d/%d', $title, $this->number, (int) $this->media?->getEditionSize());
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getMedia(): ?GalleryMedia
    {
        return $this->media;
    }

    public function setMedia(?GalleryMedia $media): self
    {
        $this->media = $media;

        return $this;
    }

    public function getNumber(): ?int
    {
        return $this->number;
    }

    public function setNumber(?int $number): self
    {
        $this->number = $number;

        return $this;
    }

    public function getOrder(): ?GalleryPrintOrder
    {
        return $this->order;
    }

    public function setOrder(?GalleryPrintOrder $order): self
    {
        $this->order = $order;

        return $this;
    }

    // Still available to a buyer - the question the sale page asks of every row of an edition to say how many are left
    public function isAvailable(): bool
    {
        return null === $this->order;
    }

    public function getFormat(): ?string
    {
        return $this->format;
    }

    public function setFormat(?string $format): self
    {
        $this->format = $format;

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

    public function getCertificate(): ?string
    {
        return $this->certificate;
    }

    public function setCertificate(?string $certificate): self
    {
        $this->certificate = $certificate;

        return $this;
    }

    public function getSoldAt(): ?\DateTimeImmutable
    {
        return $this->soldAt;
    }

    public function setSoldAt(?\DateTimeImmutable $soldAt): self
    {
        $this->soldAt = $soldAt;

        return $this;
    }

    // Freezes what the certificate will state. Called on the open-edition path, the numbered one writing the very same columns inside its claiming update (see GalleryPrintCopyRepository::claimNumber)
    public function applySnapshot(PrintCopySnapshot $snapshot): self
    {
        $this->format = $snapshot->format;
        $this->formatLabel = $snapshot->formatLabel;
        $this->sku = $snapshot->sku;
        $this->price = $snapshot->price;
        $this->workTitle = $snapshot->workTitle;
        $this->credits = $snapshot->credits;
        $this->issuer = $snapshot->issuer;

        return $this;
    }

    public function getFormatLabel(): ?string
    {
        return $this->formatLabel;
    }

    // What the lab is asked to print, and never the catalogue key next to it - the two are different strings and only this one means anything to a printer (see ProdigiFulfilment)
    public function getSku(): ?string
    {
        return $this->sku;
    }

    public function getWorkTitle(): ?string
    {
        return $this->workTitle;
    }

    public function getCredits(): ?string
    {
        return $this->credits;
    }

    public function getIssuer(): ?string
    {
        return $this->issuer;
    }
}
