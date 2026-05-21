<?php

namespace App\Controller;

use App\Service\BrevoEmailService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Routing\Annotation\Route;

class ContactController extends AbstractController
{
    #[Route('/api/contact', name: 'contact', methods: ['POST'])]
    public function contact(
        Request $request,
        BrevoEmailService $brevoEmailService,
        LoggerInterface $logger
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);
        if (!\is_array($data)) {
            return $this->json(['error' => 'Invalid JSON body.'], 400);
        }

        $name    = trim($data['name'] ?? '');
        $email   = trim($data['email'] ?? '');
        $subject = trim($data['subject'] ?? '');
        $message = trim($data['message'] ?? '');

        // Basic validation
        if (!$name || !$email || !$subject || !$message) {
            return $this->json(['error' => 'All fields are required.'], 400);
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid email address.'], 400);
        }

        try {
            $brevoEmailService->sendContactEmail($email, $name, $subject, $message);
        } catch (TransportExceptionInterface $e) {
            $logger->error('Contact form mail failed', [
                'exception' => $e->getMessage(),
                'email' => $email,
            ]);

            return $this->json([
                'error' => 'Could not send email. Check MAILER_DSN and your Brevo sender configuration.',
            ], 503);
        } catch (\Throwable $e) {
            $logger->error('Contact form error', [
                'exception' => $e->getMessage(),
                'email' => $email,
            ]);

            return $this->json(['error' => 'Something went wrong while sending your message.'], 500);
        }

        return $this->json(['message' => 'Message sent! Check your email for confirmation.']);
    }
}