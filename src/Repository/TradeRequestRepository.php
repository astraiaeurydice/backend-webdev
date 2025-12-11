<?php

namespace App\Repository;

use App\Entity\TradeRequest;
use App\Entity\TradePost;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeRequest>
 */
class TradeRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeRequest::class);
    }

    /**
     * Find all requests made by a user
     */
    public function findByRequester(User $requester): array
    {
        return $this->createQueryBuilder('tr')
            ->where('tr.requester = :requester')
            ->setParameter('requester', $requester)
            ->orderBy('tr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all requests received for user's trade posts
     */
    public function findRequestsForUserPosts(User $user): array
    {
        return $this->createQueryBuilder('tr')
            ->innerJoin('tr.tradePost', 'tp')
            ->where('tp.user = :user')
            ->setParameter('user', $user)
            ->orderBy('tr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending requests for a specific trade post
     */
    public function findPendingByTradePost(TradePost $tradePost): array
    {
        return $this->createQueryBuilder('tr')
            ->where('tr.tradePost = :tradePost')
            ->andWhere('tr.status = :status')
            ->setParameter('tradePost', $tradePost)
            ->setParameter('status', 'pending')
            ->orderBy('tr.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Check if user already requested a specific trade post
     */
    public function hasUserRequestedTrade(User $requester, TradePost $tradePost): bool
    {
        $result = $this->createQueryBuilder('tr')
            ->select('COUNT(tr.id)')
            ->where('tr.requester = :requester')
            ->andWhere('tr.tradePost = :tradePost')
            ->setParameter('requester', $requester)
            ->setParameter('tradePost', $tradePost)
            ->getQuery()
            ->getSingleScalarResult();

        return $result > 0;
    }

    /**
     * Find requests by status
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('tr')
            ->where('tr.status = :status')
            ->setParameter('status', $status)
            ->orderBy('tr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count requests by status for a user's posts
     */
    public function countRequestsByStatusForUser(User $user, string $status): int
    {
        return $this->createQueryBuilder('tr')
            ->select('COUNT(tr.id)')
            ->innerJoin('tr.tradePost', 'tp')
            ->where('tp.user = :user')
            ->andWhere('tr.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find all pending requests for a user's posts
     */
    public function findPendingRequestsForUser(User $user): array
    {
        return $this->createQueryBuilder('tr')
            ->innerJoin('tr.tradePost', 'tp')
            ->where('tp.user = :user')
            ->andWhere('tr.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'pending')
            ->orderBy('tr.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}