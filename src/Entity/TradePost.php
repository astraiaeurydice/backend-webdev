<?php

namespace App\Entity;

use App\Repository\TradePostRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradePostRepository::class)]
class TradePost
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $user = null;

    #[ORM\Column(length: 255)]
    private ?string $itemOffered = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $itemOfferedDescription = null;

    #[ORM\Column(length: 255, nullable: true)]
    private ?string $itemOfferedImage = null;

    #[ORM\Column(length: 255)]
    private ?string $itemWanted = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $itemWantedDescription = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'open'; // open, pending, completed, cancelled

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(targetEntity: TradeRequest::class, mappedBy: 'tradePost', cascade: ['remove'])]
    private Collection $tradeRequests;

    public function __construct()
    {
        $this->tradeRequests = new ArrayCollection();
        $this->createdAt = new \DateTime();
        $this->updatedAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
        return $this;
    }

    public function getItemOffered(): ?string
    {
        return $this->itemOffered;
    }

    public function setItemOffered(string $itemOffered): static
    {
        $this->itemOffered = $itemOffered;
        return $this;
    }

    public function getItemOfferedDescription(): ?string
    {
        return $this->itemOfferedDescription;
    }

    public function setItemOfferedDescription(?string $itemOfferedDescription): static
    {
        $this->itemOfferedDescription = $itemOfferedDescription;
        return $this;
    }

    public function getItemOfferedImage(): ?string
    {
        return $this->itemOfferedImage;
    }

    public function setItemOfferedImage(?string $itemOfferedImage): static
    {
        $this->itemOfferedImage = $itemOfferedImage;
        return $this;
    }

    public function getItemWanted(): ?string
    {
        return $this->itemWanted;
    }

    public function setItemWanted(string $itemWanted): static
    {
        $this->itemWanted = $itemWanted;
        return $this;
    }

    public function getItemWantedDescription(): ?string
    {
        return $this->itemWantedDescription;
    }

    public function setItemWantedDescription(?string $itemWantedDescription): static
    {
        $this->itemWantedDescription = $itemWantedDescription;
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

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, TradeRequest>
     */
    public function getTradeRequests(): Collection
    {
        return $this->tradeRequests;
    }

    public function addTradeRequest(TradeRequest $tradeRequest): static
    {
        if (!$this->tradeRequests->contains($tradeRequest)) {
            $this->tradeRequests->add($tradeRequest);
            $tradeRequest->setTradePost($this);
        }

        return $this;
    }

    public function removeTradeRequest(TradeRequest $tradeRequest): static
    {
        if ($this->tradeRequests->removeElement($tradeRequest)) {
            if ($tradeRequest->getTradePost() === $this) {
                $tradeRequest->setTradePost(null);
            }
        }

        return $this;
    }
}