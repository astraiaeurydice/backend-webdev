<?php

namespace App\EventSubscriber;

use App\Entity\ActivityLog;
use App\Entity\User;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Entity\StockRequest;
use App\Entity\PurchaseRecord;
use App\Entity\Trade;
use App\Entity\Group;
use Doctrine\Common\EventSubscriber;
use Doctrine\ORM\Events;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Event\PreRemoveEventArgs;
use Symfony\Bundle\SecurityBundle\Security;
use Psr\Log\LoggerInterface;

class ActivityLogSubscriber implements EventSubscriber
{
    private array $entitiesToDelete = [];

    public function __construct(
        private Security $security,
        private LoggerInterface $logger
    ) {}

    public function getSubscribedEvents(): array
    {
        return [
            Events::postPersist,
            Events::postUpdate,
            Events::preRemove,
            Events::postFlush,
        ];
    }

    public function postPersist(PostPersistEventArgs $args): void
    {
        $entity = $args->getObject();
        $entityClass = get_class($entity);

        // DEBUG: Log every postPersist event
        $this->logger->info('ActivityLogSubscriber: postPersist triggered', [
            'entity_class' => $entityClass
        ]);

        if ($entity instanceof ActivityLog) {
            return;
        }

        if ($entity instanceof Product ||
            $entity instanceof Supplier ||
            $entity instanceof StockRequest ||
            $entity instanceof PurchaseRecord ||
            $entity instanceof Trade ||
            $entity instanceof Group) {
            
            $this->logger->info('ActivityLogSubscriber: Entity matches, calling logActivity', [
                'entity_class' => $entityClass,
                'action' => 'CREATE'
            ]);
            
            $this->logActivity('CREATE', $entity, $args->getObjectManager());
        }
    }

    public function postUpdate(PostUpdateEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof ActivityLog) {
            return;
        }

        if ($entity instanceof Product ||
            $entity instanceof User ||
            $entity instanceof Supplier ||
            $entity instanceof StockRequest ||
            $entity instanceof PurchaseRecord ||
            $entity instanceof Trade ||
            $entity instanceof Group) {
            $this->logActivity('UPDATE', $entity, $args->getObjectManager());
        }
    }

    public function preRemove(PreRemoveEventArgs $args): void
    {
        $entity = $args->getObject();

        if ($entity instanceof ActivityLog) {
            return;
        }

        if ($entity instanceof Product ||
            $entity instanceof User ||
            $entity instanceof Supplier ||
            $entity instanceof StockRequest ||
            $entity instanceof PurchaseRecord ||
            $entity instanceof Trade ||
            $entity instanceof Group) {
            
            // Store entity data before it's deleted
            $this->entitiesToDelete[] = [
                'entity' => $entity,
                'action' => 'DELETE',
                'em' => $args->getObjectManager()
            ];
            
            $this->logger->info('ActivityLogSubscriber: preRemove - entity scheduled for deletion', [
                'entity_class' => get_class($entity)
            ]);
        }
    }

    public function postFlush(): void
    {
        // Log all deleted entities after flush
        foreach ($this->entitiesToDelete as $item) {
            $this->logActivity($item['action'], $item['entity'], $item['em']);
        }
        
        // Clear the array
        $this->entitiesToDelete = [];
    }

    private function logActivity(string $action, object $entity, $entityManager): void
    {
        try {
            $entityClass = (new \ReflectionClass($entity))->getShortName();
            
            // DEBUG: Log that we entered the method
            $this->logger->info('ActivityLogSubscriber: logActivity called', [
                'action' => $action,
                'entity' => $entityClass
            ]);
            
            $user = $this->security->getUser();

            // DEBUG: Check if user exists
            if (!$user) {
                $this->logger->warning('ActivityLogSubscriber: No user in security context', [
                    'action' => $action,
                    'entity' => $entityClass
                ]);
                return;
            }

            if (!$user instanceof User) {
                $this->logger->warning('ActivityLogSubscriber: User is not instance of User entity', [
                    'action' => $action,
                    'entity' => $entityClass,
                    'user_class' => get_class($user)
                ]);
                return;
            }

            $roles = $user->getRoles();
            
            // DEBUG: Log user and roles
            $this->logger->info('ActivityLogSubscriber: User found', [
                'username' => $user->getUsername(),
                'roles' => $roles,
                'is_admin' => in_array('ROLE_ADMIN', $roles),
                'is_staff' => in_array('ROLE_STAFF', $roles)
            ]);
            
            if (!in_array('ROLE_ADMIN', $roles) && !in_array('ROLE_STAFF', $roles)) {
                $this->logger->info('ActivityLogSubscriber: User does not have ROLE_ADMIN or ROLE_STAFF', [
                    'username' => $user->getUsername(),
                    'roles' => $roles
                ]);
                return;
            }

            $targetData = [
                'entity' => $entityClass,
            ];

            if ($entity instanceof Product) {
                $targetData['entity_id'] = $entity->getId();
                $targetData['entity_name'] = $entity->getName();
                $targetData['category'] = $entity->getCategory();
                $targetData['sku'] = $entity->getSku();
            } elseif ($entity instanceof User) {
                $targetData['entity_id'] = $entity->getId();
                $targetData['entity_name'] = $entity->getUsername();
                $targetData['email'] = $entity->getEmail();
            } elseif ($entity instanceof Supplier) {
                $targetData['entity_id'] = $entity->getId();
                $targetData['entity_name'] = $entity->getCompanyName();
                $targetData['email'] = $entity->getEmail();
                $targetData['status'] = $entity->getStatus();
                
                // DEBUG: Supplier specific logging
                $this->logger->info('ActivityLogSubscriber: Processing Supplier entity', [
                    'action' => $action,
                    'supplier_id' => $entity->getId(),
                    'supplier_name' => $entity->getCompanyName()
                ]);
            } elseif ($entity instanceof StockRequest) {
                $targetData['entity_id'] = $entity->getId();
                $targetData['entity_name'] = $entity->getProduct()?->getName() ?? 'Unknown Product';
                $targetData['quantity'] = $entity->getQuantity();
                $targetData['status'] = $entity->getStatus();
            } elseif ($entity instanceof PurchaseRecord) {
                $targetData['entity_id'] = $entity->getId();
                $targetData['entity_name'] = 'Purchase #' . $entity->getId();
            } elseif ($entity instanceof Trade) {
                $targetData['entity_id'] = $entity->getId();
                $targetData['entity_name'] = 'Trade #' . $entity->getId();
            } elseif ($entity instanceof Group) {
                $targetData['entity_id'] = $entity->getId();
                $targetData['entity_name'] = $entity->getName();
            }

            $activityLog = new ActivityLog();
            $activityLog->setUserId($user->getId());
            $activityLog->setUsername($user->getUsername());
            $activityLog->setRole(implode(',', $user->getRoles()));
            $activityLog->setAction($action);
            $activityLog->setTargetData(json_encode($targetData));

            $entityManager->persist($activityLog);
            $entityManager->flush();
            
            // DEBUG: Success
            $this->logger->info('ActivityLogSubscriber: Activity log created successfully', [
                'action' => $action,
                'entity' => $entityClass,
                'username' => $user->getUsername()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('ActivityLogSubscriber: Failed to log activity', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}
