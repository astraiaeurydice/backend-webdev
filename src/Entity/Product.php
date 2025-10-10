<?php

namespace App\Entity;

use App\Repository\ProductRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ProductRepository::class)]
#[ORM\HasLifecycleCallbacks]
class Product
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    private ?string $name = null;

    #[ORM\Column(type: Types::TEXT)]
    private ?string $description = null;

    #[ORM\Column]
    private ?float $price = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $image = null;

    // CHANGED: Now using relationship instead of just string
    #[ORM\ManyToOne(targetEntity: Group::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Group $group = null;

    // KEPT for backwards compatibility, but will be deprecated
    #[ORM\Column(length: 100, nullable: true)]
    private ?string $groupName = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $category = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $subcategory = null;

    #[ORM\Column(nullable: true, options: ["default" => 0])]
    private ?int $stockQuantity = 0; 

    #[ORM\Column(length: 20, options: ["default" => "active"])]
    private ?string $status = 'active';

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\ManyToOne(targetEntity: Supplier::class, inversedBy: 'products')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?Supplier $supplier = null;

    #[ORM\PrePersist]
    public function onPrePersist(): void
    {
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
        
        // Auto-sync groupName from Group relationship
        if ($this->group) {
            $this->groupName = $this->group->getName();
        }
    }

    #[ORM\PreUpdate]
    public function onPreUpdate(): void
    {
        $this->updatedAt = new \DateTime();
        
        // Auto-sync groupName from Group relationship
        if ($this->group) {
            $this->groupName = $this->group->getName();
        }
    }

    // === GETTERS AND SETTERS ===

    public function getId(): ?int { return $this->id; }

    public function getName(): ?string { return $this->name; }
    public function setName(string $name): static { $this->name = $name; return $this; }

    public function getDescription(): ?string { return $this->description; }
    public function setDescription(string $description): static { $this->description = $description; return $this; }

    public function getPrice(): ?float { return $this->price; }
    public function setPrice(float $price): static { $this->price = $price; return $this; }

    public function getImage(): ?string { return $this->image; }
    public function setImage(?string $image): self { $this->image = $image; return $this; }

    // NEW: Group relationship
    public function getGroup(): ?Group { return $this->group; }
    public function setGroup(?Group $group): self { 
        $this->group = $group;
        // Auto-update groupName when group is set
        if ($group) {
            $this->groupName = $group->getName();
        }
        return $this; 
    }

    // KEPT for backwards compatibility
    public function getGroupName(): ?string { return $this->groupName; }
    public function setGroupName(?string $groupName): self { $this->groupName = $groupName; return $this; }

    public function getCategory(): ?string { return $this->category; }
    public function setCategory(?string $category): self { $this->category = $category; return $this; }

    public function getSubcategory(): ?string { return $this->subcategory; }
    public function setSubcategory(?string $subcategory): self { $this->subcategory = $subcategory; return $this; }

    public function getStockQuantity(): ?int { return $this->stockQuantity; }
    public function setStockQuantity(?int $stockQuantity): self { $this->stockQuantity = $stockQuantity; return $this; }

    public function getStatus(): ?string { return $this->status; }
    public function setStatus(string $status): self { $this->status = $status; return $this; }

    public function getCreatedAt(): ?\DateTimeInterface { return $this->createdAt; }
    public function setCreatedAt(\DateTimeInterface $createdAt): self { $this->createdAt = $createdAt; return $this; }

    public function getUpdatedAt(): ?\DateTimeInterface { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeInterface $updatedAt): self { $this->updatedAt = $updatedAt; return $this; }

    public function getSupplier(): ?Supplier { return $this->supplier; }
    public function setSupplier(?Supplier $supplier): static { $this->supplier = $supplier; return $this; }

    public function increaseStock(int $amount): self
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }
        
        $this->stockQuantity = ($this->stockQuantity ?? 0) + $amount;
        return $this;
    }

    public function decreaseStock(int $amount): self
    {
        if ($amount < 0) {
            throw new \InvalidArgumentException('Amount must be positive');
        }
        
        $newQuantity = ($this->stockQuantity ?? 0) - $amount;
        
        if ($newQuantity < 0) {
            throw new \InvalidArgumentException('Insufficient stock quantity');
        }
        
        $this->stockQuantity = $newQuantity;
        return $this;
    }
}