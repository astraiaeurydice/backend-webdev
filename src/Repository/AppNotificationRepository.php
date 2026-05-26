<?php

namespace App\Repository;

use App\Entity\AppNotification;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AppNotification>
 */
class AppNotificationRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AppNotification::class);
    }

    /**
     * @return AppNotification[]
     */
    public function findNewForUser(User $user, ?\DateTimeImmutable $since, int $limit = 50): array
    {
        $qb = $this->createQueryBuilder('n')
            ->andWhere('n.user = :user')
            ->setParameter('user', $user)
            ->orderBy('n.createdAt', 'DESC')
            ->setMaxResults($limit);

        if ($since !== null) {
            $qb->andWhere('n.createdAt > :since')
                ->setParameter('since', $since);
        }

        return $qb->getQuery()->getResult();
    }
}
