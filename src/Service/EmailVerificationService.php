<?php

namespace App\Service;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Psr\Log\LoggerInterface;

class EmailVerificationService
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private MailerInterface $mailer,
        private MailerConfig $mailerConfig,
        private string $fromAddress,
        private string $fromName,
        private LoggerInterface $logger
    ) {}

    public function generateVerificationToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    public function sendVerificationEmail(User $user, string $verificationUrl): void
    {
        $this->mailerConfig->requireConfigured();

        $this->logger->info('EmailVerificationService: preparing verification email', [
            'to' => $user->getEmail(),
            'from' => $this->fromAddress,
            'verificationUrl' => $verificationUrl,
        ]);

        $email = (new Email())
            ->from(new Address($this->fromAddress, $this->fromName))
            ->to($user->getEmail())
            ->subject('Verify your K-Dream account')
            ->html("
                <div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <h2 style='color: #7c3aed;'>Welcome to K-Dream, {$user->getFirstName()}!</h2>
                    <p>Thanks for registering. Please verify your email by clicking the button below:</p>
                    <a href='{$verificationUrl}' 
                       style='display: inline-block; padding: 12px 24px; background: linear-gradient(to right, #2563eb, #7c3aed); color: white; text-decoration: none; border-radius: 8px; font-weight: bold;'>
                        Verify Email Address
                    </a>
                    <p style='margin-top: 16px; color: #888;'>Or copy this link: {$verificationUrl}</p>
                    <p style='color: #888;'>If you did not create an account, ignore this email.</p>
                </div>
            ");

        try {
            $previousTimeout = ini_get('default_socket_timeout');
            ini_set('default_socket_timeout', '10');
            $this->mailer->send($email);
            ini_set('default_socket_timeout', (string) $previousTimeout);
            $this->logger->info('EmailVerificationService: verification email sent successfully', [
                'to' => $user->getEmail(),
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('EmailVerificationService: failed to send verification email', [
                'to' => $user->getEmail(),
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    public function verifyToken(string $token): ?User
    {
        $user = $this->entityManager
            ->getRepository(User::class)
            ->findOneBy(['verificationToken' => $token]);

        if (!$user) {
            return null;
        }

        $user->setIsVerified(true);
        $user->setVerificationToken(null);
        $this->entityManager->flush();

        return $user;
    }
}