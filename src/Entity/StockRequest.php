<?php

namespace App\Entity;

use App\Repository\StockRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: StockRequestRepository::class)]
#[ORM\HasLifecycleCallbacks]
class StockRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Product $product = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?Supplier $supplier = null;

    #[ORM\Column(type: Types::INTEGER)]
    private ?int $quantity = null;

    #[ORM\Column(length: 50, options: ["default" => "pending"])]
    private ?string $status = 'pending'; // pending | accepted | declined

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $requestedAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE, nullable: true)]
    private ?\DateTimeInterface $respondedAt = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $notes = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $unitPrice = null;

    #[ORM\Column(type: Types::DECIMAL, precision: 10, scale: 2, nullable: true)]
    private ?string $totalPrice = null;

    public function __construct()
    {
        $this->requestedAt = new \DateTime();
        $this->status = 'pending';
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function calculateTotalPrice(): void
    {
        if ($this->unitPrice !== null && $this->quantity !== null) {
            $this->totalPrice = (string)((float)$this->unitPrice * $this->quantity);
        }
    }

    // === GETTERS AND SETTERS ===

    public function getId(): ?int 
    { 
        return $this->id; 
    }

    public function getProduct(): ?Product 
    { 
        return $this->product; 
    }

    public function setProduct(?Product $product): static 
    { 
        $this->product = $product; 
        return $this; 
    }

    public function getSupplier(): ?Supplier 
    { 
        return $this->supplier; 
    }

    public function setSupplier(?Supplier $supplier): static 
    { 
        $this->supplier = $supplier; 
        return $this; 
    }

    public function getQuantity(): ?int 
    { 
        return $this->quantity; 
    }

    public function setQuantity(int $quantity): static 
    { 
        $this->quantity = $quantity; 
        $this->calculateTotalPrice();
        return $this; 
    }

    public function getStatus(): ?string 
    { 
        return $this->status; 
    }

    public function setStatus(string $status): static 
    { 
        $this->status = $status; 
        return $this; 
    }

    public function getRequestedAt(): ?\DateTimeInterface 
    { 
        return $this->requestedAt; 
    }

    public function setRequestedAt(\DateTimeInterface $requestedAt): static 
    { 
        $this->requestedAt = $requestedAt; 
        return $this; 
    }

    public function getRespondedAt(): ?\DateTimeInterface 
    { 
        return $this->respondedAt; 
    }

    public function setRespondedAt(?\DateTimeInterface $respondedAt): static 
    { 
        $this->respondedAt = $respondedAt; 
        return $this; 
    }

    public function getNotes(): ?string 
    { 
        return $this->notes; 
    }

    public function setNotes(?string $notes): static 
    { 
        $this->notes = $notes; 
        return $this; 
    }

    public function getUnitPrice(): ?string 
    { 
        return $this->unitPrice; 
    }

    public function setUnitPrice(?string $unitPrice): static 
    { 
        $this->unitPrice = $unitPrice; 
        $this->calculateTotalPrice();
        return $this; 
    }

    public function getTotalPrice(): ?string 
    { 
        return $this->totalPrice; 
    }

    public function setTotalPrice(?string $totalPrice): static 
    { 
        $this->totalPrice = $totalPrice; 
        return $this; 
    }

    // Helper methods
    public function isPending(): bool 
    { 
        return $this->status === 'pending'; 
    }

    public function isAccepted(): bool 
    { 
        return $this->status === 'accepted'; 
    }

    public function isDeclined(): bool 
    { 
        return $this->status === 'declined'; 
    }

    public function accept(): static
    {
        if ($this->status === 'accepted') {
            throw new \LogicException('Stock request is already accepted');
        }
        
        if ($this->status === 'declined') {
            throw new \LogicException('Cannot accept a declined stock request');
        }
        
        $this->status = 'accepted';
        $this->respondedAt = new \DateTime();
        
        // Increase the product stock quantity
        if ($this->product && $this->quantity) {
            $this->product->increaseStock($this->quantity);
        }
        
        return $this;
    }

    public function decline(): static
    {
        $this->status = 'declined';
        $this->respondedAt = new \DateTime();
        return $this;
    }
}