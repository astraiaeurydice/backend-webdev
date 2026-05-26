<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\JwtService;
use App\Service\ActivityLogService;
use App\Service\EmailVerificationService;
use App\Service\VerificationUrlBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserManagementController extends AbstractController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private EmailVerificationService $emailVerificationService,
        private VerificationUrlBuilder $verificationUrlBuilder,
    ) {
    }

    private function userToArray(User $user): array
    {
        return [
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'phoneNumber' => $user->getPhoneNumber(),
            'roles' => $user->getRoles(),
            'status' => $user->getStatus(),
            'isVerified' => $user->isVerified(),
        ];
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

        $usersData = array_map(fn (User $user) => $this->userToArray($user), $users);

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
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $requiredFields = ['email', 'password', 'firstName', 'lastName', 'username', 'phoneNumber'];
        foreach ($requiredFields as $field) {
            if (!isset($data[$field]) || (is_string($data[$field]) && trim($data[$field]) === '')) {
                return $this->json(['error' => "Missing required field: {$field}"], 400);
            }
        }

        $roles = isset($data['roles']) && \is_array($data['roles']) ? $data['roles'] : [];
        $markVerified = filter_var($data['markVerified'] ?? false, FILTER_VALIDATE_BOOLEAN);

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

        $filteredRoles = array_filter($roles, static fn ($role) => $role !== 'ROLE_USER');
        $user->setRoles(array_values($filteredRoles));

        if ($markVerified) {
            $user->setIsVerified(true);
            $user->setVerificationToken(null);
        } else {
            $user->setIsVerified(false);
            $token = $this->emailVerificationService->generateVerificationToken();
            $user->setVerificationToken($token);
        }

        $em->persist($user);
        $em->flush();

        $verificationEmailSent = false;
        if (!$markVerified) {
            $token = $user->getVerificationToken();
            if ($token) {
                $verificationUrl = $this->verificationUrlBuilder->build($token);
                try {
                    $this->emailVerificationService->sendVerificationEmail($user, $verificationUrl);
                    $verificationEmailSent = true;
                } catch (\Throwable) {
                    $verificationEmailSent = false;
                }
            }
        }

        $this->activityLogService->logCreate(
            $validation['user'],
            'User',
            $user->getId(),
            $user->getUsername() . ($markVerified ? ' (verified by admin)' : ' (pending email verification)'),
            $request
        );

        return $this->json([
            'message' => $markVerified
                ? 'User created successfully.'
                : ($verificationEmailSent
                    ? 'User created. A verification email was sent to their address.'
                    : 'User created, but the verification email could not be sent. Use “Resend verification” or mark verified in the editor.'),
            'verificationEmailSent' => $verificationEmailSent,
            'user' => $this->userToArray($user),
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
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $verificationEmailSent = false;
        $emailChangedThisRequest = false;

        // Update email if provided and different — require re-verification
        if (isset($data['email']) && $data['email'] !== $user->getEmail()) {
            $emailChangedThisRequest = true;
            $existingEmail = $em->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existingEmail) {
                return $this->json(['error' => 'Email already exists'], 400);
            }
            $user->setEmail($data['email']);
            $user->setIsVerified(false);
            $token = $this->emailVerificationService->generateVerificationToken();
            $user->setVerificationToken($token);
            $verificationUrl = $this->verificationUrlBuilder->build($token);
            try {
                $this->emailVerificationService->sendVerificationEmail($user, $verificationUrl);
                $verificationEmailSent = true;
            } catch (\Throwable) {
                $verificationEmailSent = false;
            }
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
        if (isset($data['roles']) && \is_array($data['roles'])) {
            $filteredRoles = array_filter($data['roles'], static fn ($role) => $role !== 'ROLE_USER');
            $user->setRoles(array_values($filteredRoles));
        }

        if (!$emailChangedThisRequest && \array_key_exists('isVerified', $data)) {
            $verified = filter_var($data['isVerified'], FILTER_VALIDATE_BOOLEAN);
            if ($verified !== $user->isVerified()) {
                $user->setIsVerified($verified);
                if ($verified) {
                    $user->setVerificationToken(null);
                } else {
                    $user->setVerificationToken($this->emailVerificationService->generateVerificationToken());
                }
            }
        }

        $em->flush();

        $this->activityLogService->logUpdate(
            $validation['user'],
            'User',
            $user->getId(),
            $user->getUsername() . ($verificationEmailSent ? ' (email changed, verification sent)' : ''),
            $request
        );

        return $this->json([
            'message' => 'User updated successfully' . ($verificationEmailSent ? '. Verification email sent to the new address.' : ''),
            'verificationEmailSent' => $verificationEmailSent,
            'user' => $this->userToArray($user),
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
                'isVerified' => $user->isVerified(),
            ]
        ]);
    }

    #[Route('/api/admin/users/{id}/resend-verification', name: 'api_admin_resend_user_verification', methods: ['POST'])]
    public function resendUserVerification(
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

        if ($user->isVerified()) {
            return $this->json(['error' => 'This account is already verified'], 400);
        }

        $token = $this->emailVerificationService->generateVerificationToken();
        $user->setVerificationToken($token);
        $em->flush();

        $verificationUrl = $this->verificationUrlBuilder->build($token);

        try {
            $this->emailVerificationService->sendVerificationEmail($user, $verificationUrl);
        } catch (\Throwable $e) {
            return $this->json([
                'error' => 'Could not send verification email. Check mailer configuration.',
            ], 503);
        }

        $this->activityLogService->logUpdate(
            $validation['user'],
            'User',
            $user->getId(),
            $user->getUsername() . ' (verification email resent)',
            $request
        );

        return $this->json([
            'message' => 'Verification email sent.',
            'user' => $this->userToArray($user),
        ]);
    }
}