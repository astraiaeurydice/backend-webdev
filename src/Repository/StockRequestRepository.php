<?php

namespace App\Repository;

use App\Entity\StockRequest;
use App\Entity\Product;
use App\Entity\Supplier;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<StockRequest>
 */
class StockRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, StockRequest::class);
    }

    /**
     * Find all stock requests with eager-loaded relations
     */
    public function findAllWithRelations(): array
    {
        return $this->createQueryBuilder('sr')
            ->leftJoin('sr.product', 'p')
            ->leftJoin('sr.supplier', 's')
            ->addSelect('p', 's')
            ->orderBy('sr.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find requests by status
     */
    public function findByStatus(string $status): array
    {
        return $this->createQueryBuilder('sr')
            ->leftJoin('sr.product', 'p')
            ->leftJoin('sr.supplier', 's')
            ->addSelect('p', 's')
            ->where('sr.status = :status')
            ->setParameter('status', $status)
            ->orderBy('sr.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find pending requests
     */
    public function findPending(): array
    {
        return $this->findByStatus('pending');
    }

    /**
     * Find accepted requests
     */
    public function findAccepted(): array
    {
        return $this->findByStatus('accepted');
    }

    /**
     * Find declined requests
     */
    public function findDeclined(): array
    {
        return $this->findByStatus('declined');
    }

    /**
     * Find requests by supplier
     */
    public function findBySupplier(Supplier $supplier, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('sr')
            ->leftJoin('sr.product', 'p')
            ->addSelect('p')
            ->where('sr.supplier = :supplier')
            ->setParameter('supplier', $supplier);

        if ($status) {
            $qb->andWhere('sr.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->orderBy('sr.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find requests by product
     */
    public function findByProduct(Product $product, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('sr')
            ->leftJoin('sr.supplier', 's')
            ->addSelect('s')
            ->where('sr.product = :product')
            ->setParameter('product', $product);

        if ($status) {
            $qb->andWhere('sr.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->orderBy('sr.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find requests within a date range
     */
    public function findByDateRange(\DateTimeInterface $from, \DateTimeInterface $to, ?string $status = null): array
    {
        $qb = $this->createQueryBuilder('sr')
            ->leftJoin('sr.product', 'p')
            ->leftJoin('sr.supplier', 's')
            ->addSelect('p', 's')
            ->where('sr.requestedAt BETWEEN :from AND :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        if ($status) {
            $qb->andWhere('sr.status = :status')
               ->setParameter('status', $status);
        }

        return $qb->orderBy('sr.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get total value of requests by status
     */
    public function getTotalValueByStatus(string $status): float
    {
        $result = $this->createQueryBuilder('sr')
            ->select('SUM(sr.totalPrice) as total')
            ->where('sr.status = :status')
            ->andWhere('sr.totalPrice IS NOT NULL')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();

        return (float)($result ?? 0);
    }

    /**
     * Count requests by status
     */
    public function countByStatus(string $status): int
    {
        return (int) $this->createQueryBuilder('sr')
            ->select('COUNT(sr.id)')
            ->where('sr.status = :status')
            ->setParameter('status', $status)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Get total quantity requested for a product (pending + accepted)
     */
    public function getTotalQuantityForProduct(Product $product, array $statuses = ['pending', 'accepted']): int
    {
        $result = $this->createQueryBuilder('sr')
            ->select('SUM(sr.quantity) as total')
            ->where('sr.product = :product')
            ->andWhere('sr.status IN (:statuses)')
            ->setParameter('product', $product)
            ->setParameter('statuses', $statuses)
            ->getQuery()
            ->getSingleScalarResult();

        return (int)($result ?? 0);
    }

    /**
     * Get requests that are pending for more than X days
     */
    public function findPendingOlderThan(int $days): array
    {
        $date = new \DateTime();
        $date->modify("-{$days} days");

        return $this->createQueryBuilder('sr')
            ->leftJoin('sr.product', 'p')
            ->leftJoin('sr.supplier', 's')
            ->addSelect('p', 's')
            ->where('sr.status = :status')
            ->andWhere('sr.requestedAt < :date')
            ->setParameter('status', 'pending')
            ->setParameter('date', $date)
            ->orderBy('sr.requestedAt', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get statistics for dashboard
     */
    public function getStatistics(): array
    {
        return [
            'pending' => $this->countByStatus('pending'),
            'accepted' => $this->countByStatus('accepted'),
            'declined' => $this->countByStatus('declined'),
            'total_pending_value' => $this->getTotalValueByStatus('pending'),
            'total_accepted_value' => $this->getTotalValueByStatus('accepted'),
        ];
    }

    /**
     * Get recent requests (last N days)
     */
    public function findRecentRequests(int $days = 30): array
    {
        $date = new \DateTime();
        $date->modify("-{$days} days");

        return $this->createQueryBuilder('sr')
            ->leftJoin('sr.product', 'p')
            ->leftJoin('sr.supplier', 's')
            ->addSelect('p', 's')
            ->where('sr.requestedAt >= :date')
            ->setParameter('date', $date)
            ->orderBy('sr.requestedAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Get supplier response rate statistics
     */
    public function getSupplierResponseStats(Supplier $supplier): array
    {
        $total = $this->count(['supplier' => $supplier]);
        $accepted = $this->count(['supplier' => $supplier, 'status' => 'accepted']);
        $declined = $this->count(['supplier' => $supplier, 'status' => 'declined']);
        $pending = $this->count(['supplier' => $supplier, 'status' => 'pending']);

        $responded = $accepted + $declined;
        $responseRate = $total > 0 ? ($responded / $total) * 100 : 0;
        $acceptanceRate = $responded > 0 ? ($accepted / $responded) * 100 : 0;

        return [
            'total_requests' => $total,
            'accepted' => $accepted,
            'declined' => $declined,
            'pending' => $pending,
            'response_rate' => round($responseRate, 2),
            'acceptance_rate' => round($acceptanceRate, 2),
        ];
    }
}