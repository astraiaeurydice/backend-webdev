<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\JwtService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserManagementController extends AbstractController
{
    private ActivityLogService $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    private function validateAdminToken(Request $request, JwtService $jwtService, EntityManagerInterface $em): ?array
    {
        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return ['error' => 'No token provided', 'code' => 401];
        }

        $token = substr($authHeader, 7);

        try {
            $decoded = $jwtService->validateToken($token);
            $userId = $decoded['id'] ?? null;

            if (!$userId) {
                return ['error' => 'Invalid token structure', 'code' => 401];
            }

            $user = $em->getRepository(User::class)->find($userId);

            if (!$user) {
                return ['error' => 'User not found', 'code' => 401];
            }

            if (!in_array('ROLE_ADMIN', $user->getRoles())) {
                return ['error' => 'Access denied. Admin only.', 'code' => 403];
            }

            return ['user' => $user];

        } catch (\Exception $e) {
            return ['error' => 'Invalid or expired token', 'code' => 401];
        }
    }

    #[Route('/api/admin/users', name: 'api_admin_get_all_users', methods: ['GET'])]
    public function getAllUsers(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateAdminToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $users = $em->getRepository(User::class)->findAll();

        $usersData = array_map(function($user) {
            return [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'phoneNumber' => $user->getPhoneNumber(),
                'roles' => $user->getRoles(),
                'status' => $user->getStatus(),
            ];
        }, $users);

        return $this->json($usersData);
    }

    #[Route('/api/admin/users', name: 'api_admin_create_user', methods: ['POST'])]
    public function createUser(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $validation = $this->validateAdminToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $data = json_decode($request->getContent(), true);

        // Validate required fields
        $requiredFields = ['email', 'password', 'firstName', 'lastName', 'username', 'phoneNumber', 'roles'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || empty($data[$field])) {
                return $this->json(['error' => "Missing required field: {$field}"], 400);
            }
        }

        // Check if email already exists
        $existingEmail = $em->getRepository(User::class)->findOneBy(['email' => $data['email']]);
        if ($existingEmail) {
            return $this->json(['error' => 'Email already exists'], 400);
        }

        // Check if username already exists
        $existingUsername = $em->getRepository(User::class)->findOneBy(['username' => $data['username']]);
        if ($existingUsername) {
            return $this->json(['error' => 'Username already exists'], 400);
        }

        $user = new User();
        $user->setEmail($data['email']);
        $user->setFirstName($data['firstName']);
        $user->setLastName($data['lastName']);
        $user->setUsername($data['username']);
        $user->setPhoneNumber($data['phoneNumber']);
        
        // Hash the password
        $hashedPassword = $passwordHasher->hashPassword($user, $data['password']);
        $user->setPassword($hashedPassword);

        // Set roles (exclude ROLE_USER from input as it's added automatically)
        $roles = is_array($data['roles']) ? $data['roles'] : [$data['roles']];
        $filteredRoles = array_filter($roles, function($role) {
            return $role !== 'ROLE_USER';
        });
        $user->setRoles($filteredRoles);

        $em->persist($user);
        $em->flush();

        // Log the create activity
        $this->activityLogService->logCreate(
            $validation['user'],
            'User',
            $user->getId(),
            $user->getUsername(),
            $request
        );

        return $this->json([
            'message' => 'User created successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'phoneNumber' => $user->getPhoneNumber(),
                'roles' => $user->getRoles(),
                'status' => $user->getStatus(),
            ]
        ], 201);
    }

    #[Route('/api/admin/users/{id}', name: 'api_admin_update_user', methods: ['PUT'])]
    public function updateUser(
        int $id,
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $validation = $this->validateAdminToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $em->getRepository(User::class)->find($id);

        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        $data = json_decode($request->getContent(), true);

        // Update email if provided and different
        if (isset($data['email']) && $data['email'] !== $user->getEmail()) {
            $existingEmail = $em->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existingEmail) {
                return $this->json(['error' => 'Email already exists'], 400);
            }
            $user->setEmail($data['email']);
        }

        // Update username if provided and different
        if (isset($data['username']) && $data['username'] !== $user->getUsername()) {
            $existingUsername = $em->getRepository(User::class)->findOneBy(['username' => $data['username']]);
            if ($existingUsername) {
                return $this->json(['error' => 'Username already exists'], 400);
            }
            $user->setUsername($data['username']);
        }

        // Update other fields
        if (isset($data['firstName'])) {
            $user->setFirstName($data['firstName']);
        }
        if (isset($data['lastName'])) {
            $user->setLastName($data['lastName']);
        }
        if (isset($data['phoneNumber'])) {
            $user->setPhoneNumber($data['phoneNumber']);
        }

        // Update password if provided
        if (isset($data['password']) && !empty($data['password'])) {
            $hashedPassword = $passwordHasher->hashPassword($user, $data['password']);
            $user->setPassword($hashedPassword);
        }

        // Update roles if provided
        if (isset($data['roles'])) {
            $roles = is_array($data['roles']) ? $data['roles'] : [$data['roles']];
            $filteredRoles = array_filter($roles, function($role) {
                return $role !== 'ROLE_USER';
            });
            $user->setRoles($filteredRoles);
        }

        $em->flush();

        // Log the update activity
        $this->activityLogService->logUpdate(
            $validation['user'],
            'User',
            $user->getId(),
            $user->getUsername(),
            $request
        );

        return $this->json([
            'message' => 'User updated successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'phoneNumber' => $user->getPhoneNumber(),
                'roles' => $user->getRoles(),
                'status' => $user->getStatus(),
            ]
        ]);
    }

    #[Route('/api/admin/users/{id}', name: 'api_admin_delete_user', methods: ['DELETE'])]
    public function deleteUser(
        int $id,
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateAdminToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $em->getRepository(User::class)->find($id);

        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        // Prevent admin from deleting themselves
        if ($user->getId() === $validation['user']->getId()) {
            return $this->json(['error' => 'You cannot delete your own account'], 400);
        }

        // Store user info before deletion for logging
        $deletedUsername = $user->getUsername();
        $deletedId = $user->getId();

        $em->remove($user);
        $em->flush();

        // Log the delete activity
        $this->activityLogService->logDelete(
            $validation['user'],
            'User',
            $deletedId,
            $deletedUsername,
            $request
        );

        return $this->json(['message' => 'User deleted successfully']);
    }

    #[Route('/api/admin/users/{id}/status', name: 'api_admin_update_user_status', methods: ['PATCH'])]
    public function updateUserStatus(
        int $id,
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateAdminToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $em->getRepository(User::class)->find($id);

        if (!$user) {
            return $this->json(['error' => 'User not found'], 404);
        }

        // Prevent admin from changing their own status
        if ($user->getId() === $validation['user']->getId()) {
            return $this->json(['error' => 'You cannot change your own account status'], 400);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['status'])) {
            return $this->json(['error' => 'Status is required'], 400);
        }

        $allowedStatuses = ['active', 'disabled', 'archived'];
        if (!in_array($data['status'], $allowedStatuses)) {
            return $this->json(['error' => 'Invalid status. Must be: active, disabled, or archived'], 400);
        }

        $oldStatus = $user->getStatus();
        $user->setStatus($data['status']);
        $em->flush();

        // Log the status change activity
        $this->activityLogService->logUpdate(
            $validation['user'],
            'User',
            $user->getId(),
            $user->getUsername() . " (status: {$oldStatus} → {$data['status']})",
            $request
        );

        return $this->json([
            'message' => 'User status updated successfully',
            'user' => [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'status' => $user->getStatus(),
            ]
        ]);
    }
}