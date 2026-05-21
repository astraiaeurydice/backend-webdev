<?php

namespace App\Controller;

use App\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;

class EmailVerificationController extends AbstractController
{
    public function __construct(private string $frontendUrl)
    {
    }

    // This is the link users click from the email (GET).
    #[Route('/verify-email', name: 'verify_email', methods: ['GET'])]
    public function verifyEmail(
        Request $request,
        EmailVerificationService $emailVerificationService
    ): RedirectResponse {
        $token = $request->query->get('token');

        if (!$token) {
            return new RedirectResponse($this->frontendUrl . '/login?verified=invalid');
        }

        $user = $emailVerificationService->verifyToken($token);

        if (!$user) {
            return new RedirectResponse($this->frontendUrl . '/login?verified=invalid');
        }

        // Redirect to React login page with success message
        return new RedirectResponse($this->frontendUrl . '/login?verified=true');
    }
}