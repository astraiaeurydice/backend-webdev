<?php

namespace App\Controller;

use App\Service\EmailVerificationService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;

#[Route('/api')]
class ApiEmailVerificationController extends AbstractController
{
    public function __construct(
        private EmailVerificationService $emailVerificationService,
        private EntityManagerInterface $entityManager
    ) {}

    // JSON API verification endpoint (if you ever want to verify via frontend POST).
    #[Route('/verify-email', name: 'api_verify_email_post', methods: ['POST'])]
    public function verifyEmail(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $token = $data['token'] ?? null;

        if (!$token) {
            return $this->json(['success' => false, 'message' => 'Verification token is required'], 400);
        }

        $user = $this->emailVerificationService->verifyToken($token);

        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Invalid or expired verification token'], 400);
        }

        return $this->json([
            'success' => true,
            'message' => 'Email verified successfully',
            'user' => [
                'id' => $user->getId(),
                'email' => $user->getEmail(),
                'isVerified' => $user->isVerified()
            ]
        ], 200);
    }

    #[Route('/resend-verification', name: 'api_resend_verification', methods: ['POST'])]
    public function resendVerification(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Authentication required'], 401);
        }

        if ($user->isVerified()) {
            return $this->json(['success' => false, 'message' => 'Email is already verified'], 400);
        }

        $verificationToken = $this->emailVerificationService->generateVerificationToken();
        $user->setVerificationToken($verificationToken);
        $this->entityManager->flush();

        $verificationUrl = $this->generateUrl(
            'verify_email',
            ['token' => $verificationToken],
            UrlGeneratorInterface::ABSOLUTE_URL
        );

        $this->emailVerificationService->sendVerificationEmail($user, $verificationUrl);

        return $this->json(['success' => true, 'message' => 'Verification email sent successfully'], 200);
    }

    #[Route('/verification-status', name: 'api_verification_status', methods: ['GET'])]
    public function verificationStatus(#[CurrentUser] ?User $user): JsonResponse
    {
        if (!$user) {
            return $this->json(['success' => false, 'message' => 'Authentication required'], 401);
        }

        return $this->json([
            'success' => true,
            'isVerified' => $user->isVerified(),
            'email' => $user->getEmail()
        ], 200);
    }
}