<?php

namespace App\Repository;

use App\Entity\TradeTransaction;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TradeTransaction>
 */
class TradeTransactionRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TradeTransaction::class);
    }

    /**
     * Find all transactions pending verification
     */
    public function findPendingVerification(): array
    {
        return $this->createQueryBuilder('tt')
            ->where('tt.status = :status')
            ->setParameter('status', 'pending_verification')
            ->orderBy('tt.createdAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all transactions involving a specific user (as owner or requester)
     */
    public function findByUser(User $user): array
    {
        return $this->createQueryBuilder('tt')
            ->where('tt.owner = :user OR tt.requester = :user')
            ->setParameter('user', $user)
            ->orderBy('tt.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find completed transactions by user
     */
    public function findCompletedByUser(User $user): array
    {
        return $this->createQueryBuilder('tt')
            ->where('(tt.owner = :user OR tt.requester = :user)')
            ->andWhere('tt.status = :status')
            ->setParameter('user', $user)
            ->setParameter('status', 'completed')
            ->orderBy('tt.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Count transactions by status
     */
    public function countByStatus(string $status): int
    {
        return $this->createQueryBuilder('tt')
            ->select('COUNT(tt.id)')
            ->where('tt.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Find transactions verified by a specific admin
     */
    public function findVerifiedByAdmin(User $admin): array
    {
        return $this->createQueryBuilder('tt')
            ->where('tt.verifiedBy = :admin')
            ->setParameter('admin', $admin)
            ->orderBy('tt.verifiedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get transaction statistics
     */
    public function getStatistics(): array
    {
        $qb = $this->createQueryBuilder('tt');
        
        return [
            'total' => $qb->select('COUNT(tt.id)')
                ->getQuery()
                ->getSingleScalarResult(),
            'pending' => $this->countByStatus('pending_verification'),
            'verified' => $this->countByStatus('verified'),
            'completed' => $this->countByStatus('completed'),
            'rejected' => $this->countByStatus('rejected'),
        ];
    }

    /**
     * Find recent transactions (last N days)
     */
    public function findRecentTransactions(int $days = 7): array
    {
        $date = new \DateTime();
        $date->modify("-{$days} days");

        return $this->createQueryBuilder('tt')
            ->where('tt.createdAt >= :date')
            ->setParameter('date', $date)
            ->orderBy('tt.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}