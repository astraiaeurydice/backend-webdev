<?php

namespace App\Entity;

use App\Repository\SupplierRepository;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Symfony\Component\Validator\Constraints as Assert;


#[ORM\Entity(repositoryClass: SupplierRepository::class)]
#[ORM\Table(name: 'supplier')]
class Supplier
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    private ?int $id = null;

    #[ORM\Column(type: 'string', length: 255)]
    private ?string $companyName = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $contactPerson = null;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private ?string $email = null;

    #[ORM\Column(type: 'string', length: 50, nullable: true)]
    #[Assert\NotBlank(message: 'Phone number is required.')]
    #[Assert\Regex(
        pattern: '/^\+82\d{8,10}$/',
        message: 'Phone number must start with +82 and contain 8 to 10 digits after it.'
    )]
    private ?string $phone = null;


    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $address = null;

    #[ORM\Column(type: 'string', length: 20)]
    private ?string $status = 'active';

    #[ORM\OneToMany(mappedBy: 'supplier', targetEntity: Product::class)]
    private Collection $products;


    // Remove any 'name' property if it exists!

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCompanyName(): ?string
    {
        return $this->companyName;
    }

    public function setCompanyName(string $companyName): self
    {
        $this->companyName = $companyName;
        return $this;
    }

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): self
    {
        $this->contactPerson = $contactPerson;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): self
    {
        $this->phone = $phone;
        return $this;
    }

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): self
    {
        $this->address = $address;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function __construct()
{
    $this->products = new ArrayCollection();
}

// Add these methods at the end
/**
 * @return Collection<int, Product>
 */
public function getProducts(): Collection
{
    return $this->products;
}

public function addProduct(Product $product): self
{
    if (!$this->products->contains($product)) {
        $this->products[] = $product;
        $product->setSupplier($this);
    }
    return $this;
}

public function removeProduct(Product $product): self
{
    if ($this->products->removeElement($product)) {
        if ($product->getSupplier() === $this) {
            $product->setSupplier(null);
        }
    }
    return $this;
}
}