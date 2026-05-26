<?php

namespace App\Service;

class MailerConfig
{
    public function isConfigured(): bool
    {
        $dsn = (string) ($_ENV['MAILER_DSN'] ?? $_SERVER['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: '');
        $from = (string) ($_ENV['MAILER_FROM_ADDRESS'] ?? $_SERVER['MAILER_FROM_ADDRESS'] ?? getenv('MAILER_FROM_ADDRESS') ?: '');

        return $dsn !== '' && !str_starts_with($dsn, 'null://') && $from !== '';
    }

    public function requireConfigured(): void
    {
        if (!$this->isConfigured()) {
            throw new \RuntimeException(
                'Email is not configured. Set MAILER_DSN (Brevo SMTP) and MAILER_FROM_ADDRESS on the server.'
            );
        }
    }
}
