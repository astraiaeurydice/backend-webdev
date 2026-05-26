<?php

namespace App\Service;

use Symfony\Component\Mailer\Transport;

/**
 * Quick SMTP connectivity check (does not send mail).
 */
class MailerHealthService
{
    public function __construct(private MailerConfig $mailerConfig)
    {
    }

    /**
     * @return array{ok: bool, message: string, latencyMs?: int}
     */
    public function testConnection(): array
    {
        if (!$this->mailerConfig->isConfigured()) {
            return ['ok' => false, 'message' => 'MAILER_DSN or MAILER_FROM_ADDRESS not set'];
        }

        $dsn = (string) ($_ENV['MAILER_DSN'] ?? getenv('MAILER_DSN') ?: '');
        if (!str_contains($dsn, 'timeout=')) {
            $dsn .= (str_contains($dsn, '?') ? '&' : '?') . 'timeout=8';
        }

        $start = microtime(true);
        try {
            $transport = Transport::fromDsn($dsn);
            $transport->start();
            $transport->stop();
            $ms = (int) round((microtime(true) - $start) * 1000);

            return ['ok' => true, 'message' => 'SMTP connection OK', 'latencyMs' => $ms];
        } catch (\Throwable $e) {
            return [
                'ok' => false,
                'message' => $e->getMessage(),
                'latencyMs' => (int) round((microtime(true) - $start) * 1000),
            ];
        }
    }
}
