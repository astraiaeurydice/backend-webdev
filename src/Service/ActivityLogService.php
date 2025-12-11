<?php

namespace App\Service;

use App\Entity\ActivityLog;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\Request;

class ActivityLogService
{
    private EntityManagerInterface $em;

    public function __construct(EntityManagerInterface $em)
    {
        $this->em = $em;
    }

    /**
     * Generic log method
     */
    public function log(
        User $user,
        string $action,
        ?string $targetData = null,
        ?Request $request = null
    ): void {
        $activityLog = new ActivityLog();
        $activityLog->setUserId($user->getId());
        $activityLog->setUsername($user->getUsername());
        
        // Get primary role (first non-USER role, or ROLE_USER if that's all they have)
        $roles = $user->getRoles();
        $primaryRole = 'ROLE_USER';
        foreach ($roles as $role) {
            if ($role !== 'ROLE_USER') {
                $primaryRole = $role;
                break;
            }
        }
        $activityLog->setRole($primaryRole);
        
        $activityLog->setAction($action);
        $activityLog->setTargetData($targetData);
        
        // IP address is not stored for privacy reasons

        $this->em->persist($activityLog);
        $this->em->flush();
    }

    /**
     * Log user login
     */
    public function logLogin(User $user, Request $request): void
    {
        $this->log($user, 'LOGIN', null, $request);
    }

    /**
     * Log user logout
     */
    public function logLogout(User $user, Request $request): void
    {
        $this->log($user, 'LOGOUT', null, $request);
    }

    /**
     * Log create action
     */
    public function logCreate(User $user, string $entityType, $entityId, ?string $entityName = null, ?Request $request = null): void
    {
        $targetData = $entityName 
            ? "{$entityType}: {$entityName} (ID: {$entityId})"
            : "{$entityType} (ID: {$entityId})";
        
        $this->log($user, 'CREATE', $targetData, $request);
    }

    /**
     * Log update action
     */
    public function logUpdate(User $user, string $entityType, $entityId, ?string $entityName = null, ?Request $request = null): void
    {
        $targetData = $entityName 
            ? "{$entityType}: {$entityName} (ID: {$entityId})"
            : "{$entityType} (ID: {$entityId})";
        
        $this->log($user, 'UPDATE', $targetData, $request);
    }

    /**
     * Log delete action
     */
    public function logDelete(User $user, string $entityType, $entityId, ?string $entityName = null, ?Request $request = null): void
    {
        $targetData = $entityName 
            ? "{$entityType}: {$entityName} (ID: {$entityId})"
            : "{$entityType} (ID: {$entityId})";
        
        $this->log($user, 'DELETE', $targetData, $request);
    }
}