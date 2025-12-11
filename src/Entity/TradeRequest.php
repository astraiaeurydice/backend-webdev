<?php

namespace App\Entity;

use App\Repository\TradeRequestRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: TradeRequestRepository::class)]
class TradeRequest
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: TradePost::class, inversedBy: 'tradeRequests')]
    #[ORM\JoinColumn(nullable: false)]
    private ?TradePost $tradePost = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $requester = null;

    #[ORM\Column(length: 50)]
    private ?string $status = 'pending'; // pending, accepted, rejected, admin_review

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $message = null;

    #[ORM\Column(type: Types::DATETIME_MUTABLE)]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\OneToOne(targetEntity: TradeTransaction::class, mappedBy: 'tradeRequest', cascade: ['persist'])]
    private ?TradeTransaction $tradeTransaction = null;

    public function __construct()
    {
        $this->createdAt = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getTradePost(): ?TradePost
    {
        return $this->tradePost;
    }

    public function setTradePost(?TradePost $tradePost): static
    {
        $this->tradePost = $tradePost;
        return $this;
    }

    public function getRequester(): ?User
    {
        return $this->requester;
    }

    public function setRequester(?User $requester): static
    {
        $this->requester = $requester;
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

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function setMessage(?string $message): static
    {
        $this->message = $message;
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

    public function getTradeTransaction(): ?TradeTransaction
    {
        return $this->tradeTransaction;
    }

    public function setTradeTransaction(?TradeTransaction $tradeTransaction): static
    {
        // unset the owning side of the relation if necessary
        if ($tradeTransaction === null && $this->tradeTransaction !== null) {
            $this->tradeTransaction->setTradeRequest(null);
        }

        // set the owning side of the relation if necessary
        if ($tradeTransaction !== null && $tradeTransaction->getTradeRequest() !== $this) {
            $tradeTransaction->setTradeRequest($this);
        }

        $this->tradeTransaction = $tradeTransaction;

        return $this;
    }
}