<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\EmailVerificationService;
use App\Service\VerificationUrlBuilder;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegisterController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher,
        private EmailVerificationService $emailVerificationService,
        private VerificationUrlBuilder $verificationUrlBuilder,
        private LoggerInterface $logger,
    ) {}

    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(Request $request): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $firstName   = $data['firstName'] ?? '';
        $lastName    = $data['lastName'] ?? '';
        $username    = $data['username'] ?? null;
        $phoneNumber = $data['phoneNumber'] ?? '';
        $email       = $data['email'] ?? null;
        $password    = $data['password'] ?? null;

        if (!$email || !$password || !$username) {
            return new JsonResponse(['success' => false, 'message' => 'Missing required fields'], 400);
        }

        if (strlen($username) < 3) {
            return new JsonResponse(['success' => false, 'message' => 'Username must be at least 3 characters'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return new JsonResponse(['success' => false, 'message' => 'Invalid email address'], 400);
        }

        if (strlen($password) < 6) {
            return new JsonResponse(['success' => false, 'message' => 'Password must be at least 6 characters'], 400);
        }

        $existingEmail = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingEmail) {
            return new JsonResponse(['success' => false, 'message' => 'Email already registered'], 409);
        }

        $existingUsername = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if ($existingUsername) {
            return new JsonResponse(['success' => false, 'message' => 'Username already taken'], 409);
        }

        $user = new User();
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setUsername($username);
        $user->setPhoneNumber($phoneNumber);
        $user->setEmail($email);
        $user->setRoles(['ROLE_USER']);
        $user->setIsVerified(false);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Generate verification token
        $token = $this->emailVerificationService->generateVerificationToken();
        $user->setVerificationToken($token);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $verificationUrl = $this->verificationUrlBuilder->build($token);

        $emailSent = false;
        try {
            $this->emailVerificationService->sendVerificationEmail($user, $verificationUrl);
            $emailSent = true;
        } catch (\Throwable $e) {
            $this->logger->error('Registration verification email failed', [
                'email' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
        }

        $message = $emailSent
            ? 'Registration successful! Please check your email to verify your account.'
            : 'Account created, but we could not send the verification email. Ask an admin to resend or verify your Brevo settings on the server.';

        return new JsonResponse([
            'success' => true,
            'emailSent' => $emailSent,
            'message' => $message,
            'user' => [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'isVerified' => $user->isVerified(),
                'roles' => $user->getRoles()
            ]
        ], 201);
    }
}