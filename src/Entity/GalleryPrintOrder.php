<?php

/*
 * (c) 2026: 975L <contact@975l.com>
 * (c) 2026: Laurent Marquet <laurent.marquet@laposte.net>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 */

namespace c975L\GalleryBundle\Entity;

use c975L\PaymentBundle\Entity\Basket;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

// One shipment a lab is asked to print, holding the copies it carries. Written once the basket is paid and never before: a basket that is never paid for leaves nothing behind
// Deliberately not merged with the basket it comes from - a basket is what a customer bought, this is what a printer was told, and the two part ways as soon as an order is refused, retried or sent to a different lab than the one configured today
#[ORM\Entity(repositoryClass: \c975L\GalleryBundle\Repository\GalleryPrintOrderRepository::class)]
#[ORM\Table(name: 'gallery_print_order')]
class GalleryPrintOrder implements \Stringable
{
    // Paid and not yet handed to a lab. Where an art edition waits for the admin to validate it, and where a failed sending falls back to - the state a human acts on
    public const STATE_PENDING = 'pending';

    // Accepted by the lab, which has given a reference
    public const STATE_SENT = 'sent';

    // Being printed - the lab has taken it past the point where it can be cancelled
    public const STATE_PRODUCING = 'producing';

    public const STATE_SHIPPED = 'shipped';

    public const STATE_CANCELLED = 'cancelled';

    // The lab refused it, or could not be reached often enough to keep trying. Reads like pending to the admin, who retries or orders it by hand, but is kept apart so a genuine refusal is not lost among the orders simply waiting
    public const STATE_FAILED = 'failed';

    // The states an order can still be moved out of: those where a lab is holding it. A pending or failed order is a human's business, and a shipped or cancelled one is over
    public const array STATES_HELD_BY_LAB = [self::STATE_SENT, self::STATE_PRODUCING];

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    // What was bought, and the only place the customer's name and delivery address live - copying them here would freeze an address the customer can still have corrected before the order leaves
    #[ORM\ManyToOne]
    #[ORM\JoinColumn(nullable: false)]
    private ?Basket $basket = null;

    // The driver this went through, stored rather than read from configuration: a site that changes lab still has to ask the previous one about the orders it is already printing
    #[ORM\Column(length: 50)]
    private ?string $provider = null;

    // What the lab calls this order, null until it has accepted it. Every later exchange - status, callback, cancellation - is keyed on it
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $reference = null;

    #[ORM\Column(length: 20, options: ['default' => self::STATE_PENDING])]
    private string $state = self::STATE_PENDING;

    // What the lab said when it refused, kept verbatim for the admin who has to decide whether to retry or to reprice - a refusal is nearly always about the file or the format, and the message is what says which
    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $lastError = null;

    /** @var Collection<int, GalleryPrintCopy> */
    #[ORM\OneToMany(targetEntity: GalleryPrintCopy::class, mappedBy: 'order')]
    private Collection $copies;

    #[ORM\Column]
    private ?\DateTimeImmutable $createdAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $sentAt = null;

    #[ORM\Column(nullable: true)]
    private ?\DateTimeImmutable $shippedAt = null;

    public function __construct()
    {
        $this->copies = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable();
    }

    public function __toString(): string
    {
        return $this->reference ?? sprintf('#%d', (int) $this->id);
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getBasket(): ?Basket
    {
        return $this->basket;
    }

    public function setBasket(?Basket $basket): self
    {
        $this->basket = $basket;

        return $this;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function setProvider(?string $provider): self
    {
        $this->provider = $provider;

        return $this;
    }

    public function getReference(): ?string
    {
        return $this->reference;
    }

    public function setReference(?string $reference): self
    {
        $this->reference = $reference;

        return $this;
    }

    public function getState(): string
    {
        return $this->state;
    }

    public function setState(string $state): self
    {
        $this->state = $state;

        return $this;
    }

    // The two states a human is expected to act on, which is what the back-office lists first
    public function needsAttention(): bool
    {
        return \in_array($this->state, [self::STATE_PENDING, self::STATE_FAILED], true);
    }

    public function getLastError(): ?string
    {
        return $this->lastError;
    }

    public function setLastError(?string $lastError): self
    {
        $this->lastError = $lastError;

        return $this;
    }

    /** @return Collection<int, GalleryPrintCopy> */
    public function getCopies(): Collection
    {
        return $this->copies;
    }

    public function addCopy(GalleryPrintCopy $copy): self
    {
        if (!$this->copies->contains($copy)) {
            $this->copies->add($copy);
            $copy->setOrder($this);
        }

        return $this;
    }

    // An order carrying a numbered copy is one whose certificate has to be signed by hand before it ships, which is what holds it in pending rather than sending it straight away
    public function hasLimitedEdition(): bool
    {
        foreach ($this->copies as $copy) {
            if (null !== $copy->getNumber()) {
                return true;
            }
        }

        return false;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getSentAt(): ?\DateTimeImmutable
    {
        return $this->sentAt;
    }

    public function setSentAt(?\DateTimeImmutable $sentAt): self
    {
        $this->sentAt = $sentAt;

        return $this;
    }

    public function getShippedAt(): ?\DateTimeImmutable
    {
        return $this->shippedAt;
    }

    public function setShippedAt(?\DateTimeImmutable $shippedAt): self
    {
        $this->shippedAt = $shippedAt;

        return $this;
    }
}
