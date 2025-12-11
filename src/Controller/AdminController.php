<?php

namespace App\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminController extends AbstractController
{
    #[Route('/api/admin/dashboard', name: 'admin_dashboard', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function dashboard(): JsonResponse
    {
        $user = $this->getUser(); // Logged-in User entity
        return new JsonResponse([
            'message' => 'Welcome Admin!',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'roles' => $user->getRoles(),
            ]
        ]);
    }
}
