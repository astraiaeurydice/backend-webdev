<?php

namespace App\Repository;

use App\Entity\Group;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Group>
 */
class GroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Group::class);
    }

    /**
     * Find all active groups with their suppliers
     */
    public function findAllActiveWithSuppliers(): array
    {
        return $this->createQueryBuilder('g')
            ->leftJoin('g.supplier', 's')
            ->addSelect('s')
            ->where('g.status = :status')
            ->setParameter('status', 'active')
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find groups by supplier
     */
    public function findBySupplier(int $supplierId): array
    {
        return $this->createQueryBuilder('g')
            ->where('g.supplier = :supplierId')
            ->setParameter('supplierId', $supplierId)
            ->orderBy('g.name', 'ASC')
            ->getQuery()
            ->getResult();
    }
}