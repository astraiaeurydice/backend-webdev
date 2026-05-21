<?php

namespace App\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;

/**
 * Sends contact-form mail via Symfony Mailer (configure MAILER_DSN for Brevo SMTP).
 * No Brevo REST SDK required.
 */
class BrevoEmailService
{
    public function __construct(
        private MailerInterface $mailer,
        private string $fromAddress,
        private string $fromName,
        private string $notifyEmail,
        private LoggerInterface $logger
    ) {
    }

    public function sendContactEmail(
        string $toEmail,
        string $toName,
        string $subject,
        string $message
    ): void {
        $from = new Address($this->fromAddress, $this->fromName);
        $safeSubject = htmlspecialchars($subject, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $safeName = htmlspecialchars($toName, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // 1. Confirmation to the visitor (From must be a verified sender in Brevo)
        $userEmail = (new Email())
            ->from($from)
            ->to(new Address($toEmail, $toName))
            ->subject('We got your message: ' . $subject)
            ->html(
                "<div style='font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto;'>
                    <p>Hi <strong>{$safeName}</strong>,</p>
                    <p>Thank you for contacting K-Dream Merchandise. We received your message and will reply soon.</p>
                    <p><strong>Subject:</strong> {$safeSubject}</p>
                    <div style='background:#f3f4f6;padding:16px;border-radius:8px;'>{$safeMessage}</div>
                </div>"
            );

        $this->mailer->send($userEmail);

        // 2. Notification to your team
        $teamEmail = (new Email())
            ->from($from)
            ->to(new Address($this->notifyEmail, 'K-Dream Team'))
            ->replyTo(new Address($toEmail, $toName))
            ->subject('[Contact] ' . $subject . ' — from ' . $toName)
            ->html(
                '<h2>New contact form submission</h2>' .
                '<p><strong>Name:</strong> ' . $safeName . '</p>' .
                '<p><strong>Email:</strong> ' . htmlspecialchars($toEmail, ENT_QUOTES | ENT_HTML5, 'UTF-8') . '</p>' .
                '<p><strong>Subject:</strong> ' . $safeSubject . '</p>' .
                '<p><strong>Message:</strong></p>' .
                '<div style="background:#f3f4f6;padding:16px;border-radius:8px;">' . $safeMessage . '</div>'
            );

        $this->mailer->send($teamEmail);

        $this->logger->info('Contact form emails sent', [
            'to' => $toEmail,
            'notify' => $this->notifyEmail,
        ]);
    }
}
