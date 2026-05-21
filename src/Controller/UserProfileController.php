<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/user')]
class UserProfileController extends AbstractController
{
    private function validateToken(Request $request, JwtService $jwtService, EntityManagerInterface $em): ?array
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

            return ['user' => $user];

        } catch (\Exception $e) {
            return ['error' => 'Invalid or expired token', 'code' => 401];
        }
    }

    /**
     * Get current user profile
     */
    #[Route('/profile', name: 'api_user_profile', methods: ['GET'])]
    public function getProfile(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];

        return $this->json([
            'id' => $user->getId(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'phoneNumber' => $user->getPhoneNumber(),
            'avatarUrl' => $user->getAvatarUrl(),
            'roles' => $user->getRoles(),
            'fullName' => $user->getFirstName() . ' ' . $user->getLastName()
        ]);
    }

    /**
     * Update user profile
     */
    #[Route('/profile/update', name: 'api_user_profile_update', methods: ['PUT'])]
    public function updateProfile(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Validate required fields
            if (empty($data['firstName']) || empty($data['lastName']) || 
                empty($data['email']) || empty($data['username']) || 
                empty($data['phoneNumber'])) {
                return $this->json(['error' => 'All fields are required'], Response::HTTP_BAD_REQUEST);
            }

            // Check if email is already taken by another user
            if ($data['email'] !== $user->getEmail()) {
                $existingEmail = $em->getRepository(User::class)
                    ->findOneBy(['email' => $data['email']]);
                
                if ($existingEmail && $existingEmail->getId() !== $user->getId()) {
                    return $this->json(['error' => 'Email is already taken'], Response::HTTP_CONFLICT);
                }
            }

            // Check if username is already taken by another user
            if ($data['username'] !== $user->getUsername()) {
                $existingUsername = $em->getRepository(User::class)
                    ->findOneBy(['username' => $data['username']]);
                
                if ($existingUsername && $existingUsername->getId() !== $user->getId()) {
                    return $this->json(['error' => 'Username is already taken'], Response::HTTP_CONFLICT);
                }
            }

            // Validate email format
            if (!filter_var($data['email'], FILTER_VALIDATE_EMAIL)) {
                return $this->json(['error' => 'Invalid email format'], Response::HTTP_BAD_REQUEST);
            }

            // Validate phone number (basic validation)
            if (!preg_match('/^[0-9+\-\s()]+$/', $data['phoneNumber'])) {
                return $this->json(['error' => 'Invalid phone number format'], Response::HTTP_BAD_REQUEST);
            }

            // Update user data
            $user->setFirstName($data['firstName']);
            $user->setLastName($data['lastName']);
            $user->setEmail($data['email']);
            $user->setUsername($data['username']);
            $user->setPhoneNumber($data['phoneNumber']);
            if (array_key_exists('avatarUrl', $data)) {
                $user->setAvatarUrl($data['avatarUrl'] ?: null);
            }

            $em->flush();

            return $this->json([
                'id' => $user->getId(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'phoneNumber' => $user->getPhoneNumber(),
                'avatarUrl' => $user->getAvatarUrl(),
                'roles' => $user->getRoles(),
                'fullName' => $user->getFirstName() . ' ' . $user->getLastName(),
                'message' => 'Profile updated successfully'
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to update profile: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Change user password
     */
    #[Route('/password/change', name: 'api_user_password_change', methods: ['PUT'])]
    public function changePassword(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $validation = $this->validateToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON data'], Response::HTTP_BAD_REQUEST);
        }

        try {
            // Validate required fields
            if (empty($data['currentPassword']) || empty($data['newPassword'])) {
                return $this->json([
                    'error' => 'Current password and new password are required'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Verify current password
            if (!$passwordHasher->isPasswordValid($user, $data['currentPassword'])) {
                return $this->json([
                    'error' => 'Current password is incorrect'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Validate new password length
            if (strlen($data['newPassword']) < 6) {
                return $this->json([
                    'error' => 'New password must be at least 6 characters long'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Check if new password is same as current password
            if ($passwordHasher->isPasswordValid($user, $data['newPassword'])) {
                return $this->json([
                    'error' => 'New password must be different from current password'
                ], Response::HTTP_BAD_REQUEST);
            }

            // Hash and update password
            $hashedPassword = $passwordHasher->hashPassword($user, $data['newPassword']);
            $user->setPassword($hashedPassword);

            $em->flush();

            return $this->json([
                'message' => 'Password changed successfully'
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to change password: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Get user by ID (Admin only)
     */
    #[Route('/profile/{id}', name: 'api_user_profile_by_id', methods: ['GET'])]
    public function getProfileById(
        int $id,
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $currentUser = $validation['user'];

        // Check if user is admin
        if (!in_array('ROLE_ADMIN', $currentUser->getRoles())) {
            return $this->json(['error' => 'Access denied. Admin only.'], Response::HTTP_FORBIDDEN);
        }

        $user = $em->getRepository(User::class)->find($id);

        if (!$user) {
            return $this->json(['error' => 'User not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'id' => $user->getId(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'phoneNumber' => $user->getPhoneNumber(),
            'roles' => $user->getRoles(),
            'fullName' => $user->getFirstName() . ' ' . $user->getLastName()
        ]);
    }

    /**
     * Delete user account (Self-delete with password confirmation)
     */
    #[Route('/profile/delete', name: 'api_user_profile_delete', methods: ['DELETE'])]
    public function deleteProfile(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $validation = $this->validateToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];
        $data = json_decode($request->getContent(), true);

        // Require password confirmation for account deletion
        if (empty($data['password'])) {
            return $this->json([
                'error' => 'Password confirmation is required'
            ], Response::HTTP_BAD_REQUEST);
        }

        // Verify password
        if (!$passwordHasher->isPasswordValid($user, $data['password'])) {
            return $this->json([
                'error' => 'Incorrect password'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $em->remove($user);
            $em->flush();

            return $this->json([
                'message' => 'Account deleted successfully'
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to delete account: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}