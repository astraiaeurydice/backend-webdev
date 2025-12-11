<?php

namespace App\Repository;

use App\Entity\TradePost;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradePost>
 */
class TradePostRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradePost::class);
    }

    /**
     * Find all open trade posts (excluding user's own posts)
     */
    public function findOpenTradesExcludingUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.user != :user')
            ->setParameter('status', 'open')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all trade posts by a specific user
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->setParameter('user', $user)
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find open trade posts by user
     */
    public function findOpenByUser(User $user): array
    {
        return $this->createQueryBuilder('t')
            ->where('t.user = :user')
            ->andWhere('t.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'open')
            ->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count trade posts by status
     */
    public function countByStatus(string $status): int
    {
        return $this->createQueryBuilder('t')
            ->select('COUNT(t.id)')
            ->where('t.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Search trade posts by item name
     */
    public function searchByItem(string $searchTerm, ?User $excludeUser = null): array
    {
        $qb = $this->createQueryBuilder('t')
            ->where('t.status = :status')
            ->andWhere('t.itemOffered LIKE :search OR t.itemWanted LIKE :search')
            ->setParameter('status', 'open')
            ->setParameter('search', '%' . $searchTerm . '%');

        if ($excludeUser) {
            $qb->andWhere('t.user != :user')
               ->setParameter('user', $excludeUser);
        }

        return $qb->orderBy('t.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}