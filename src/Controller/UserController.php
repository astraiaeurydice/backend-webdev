<?php

namespace App\Controller;

use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class UserController extends AbstractController
{
    #[Route('/api/user/profile', name: 'api_user_profile', methods: ['GET'])]
    public function getProfile(
        Request $request, 
        JwtService $jwtService, 
        EntityManagerInterface $em
    ): JsonResponse {
        // Get the Authorization header
        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(['error' => 'No token provided'], 401);
        }

        // Extract the token (remove "Bearer " prefix)
        $token = substr($authHeader, 7);

        try {
            // Validate and decode the token using your JwtService
            $decoded = $jwtService->validateToken($token);

            // Get user ID from decoded token
            $userId = $decoded['id'] ?? null;

            if (!$userId) {
                return $this->json(['error' => 'Invalid token structure'], 401);
            }

            // Fetch user from database
            $user = $em->getRepository(User::class)->find($userId);

            if (!$user) {
                return $this->json(['error' => 'User not found'], 401);
            }

            // Return user profile
            return $this->json([
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'username' => $user->getUsername(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'fullName' => $user->getFirstName() . ' ' . $user->getLastName(),
                'phoneNumber' => $user->getPhoneNumber(),
                'roles' => $user->getRoles(),
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Invalid or expired token', 
                'message' => $e->getMessage()
            ], 401);
        }
    }
}