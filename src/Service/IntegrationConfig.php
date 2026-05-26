<?php

namespace App\Service;

/**
 * Read-only checks for production integrations (no secrets exposed).
 */
class IntegrationConfig
{
    public function getStatus(): array
    {
        $frontendUrl = rtrim((string) $this->env('FRONTEND_URL'), '/');
        $defaultUri = rtrim((string) $this->env('DEFAULT_URI'), '/');
        $mailerDsn = (string) $this->env('MAILER_DSN');
        $googleId = (string) $this->env('GOOGLE_CLIENT_ID');
        $googleSecret = (string) $this->env('GOOGLE_CLIENT_SECRET');
        $googleRedirect = (string) $this->env('GOOGLE_REDIRECT_URI');
        $fromAddress = (string) $this->env('MAILER_FROM_ADDRESS');

        if ($googleRedirect === '' && $defaultUri !== '') {
            $googleRedirect = $defaultUri . '/connect/google/check';
        }

        $mailerReady = $mailerDsn !== '' && !str_starts_with($mailerDsn, 'null://');
        $googleReady = $googleId !== '' && $googleSecret !== '';
        $frontendReady = $frontendUrl !== ''
            && !str_contains($frontendUrl, 'localhost')
            && !str_contains($frontendUrl, '127.0.0.1');

        return [
            'frontend_url' => $frontendUrl !== '' ? $frontendUrl : null,
            'frontend_configured' => $frontendReady,
            'backend_url' => $defaultUri !== '' ? $defaultUri : null,
            'backend_configured' => $defaultUri !== ''
                && !str_contains($defaultUri, 'localhost')
                && !str_contains($defaultUri, '127.0.0.1'),
            'google_oauth' => [
                'configured' => $googleReady,
                'redirect_uri' => $googleRedirect !== '' ? $googleRedirect : null,
                'start_url' => ($defaultUri !== '' ? $defaultUri : null) ? $defaultUri . '/connect/google' : null,
            ],
            'mailer' => [
                'configured' => $mailerReady,
                'from_address_set' => $fromAddress !== '',
            ],
            'hints' => $this->buildHints($frontendReady, $googleReady, $mailerReady, $fromAddress !== '', $googleRedirect),
        ];
    }

    private function buildHints(
        bool $frontendReady,
        bool $googleReady,
        bool $mailerReady,
        bool $fromSet,
        string $googleRedirect
    ): array {
        $hints = [];
        if (!$frontendReady) {
            $hints[] = 'Set FRONTEND_URL on Railway to your Vercel URL (e.g. https://your-app.vercel.app).';
        }
        if ($defaultUri === '' || str_contains($defaultUri, 'localhost') || str_contains($defaultUri, '127.0.0.1')) {
            $hints[] = 'Set DEFAULT_URI on Railway to your public API URL (e.g. https://backend-webdev-production.up.railway.app) so verification emails link correctly.';
        }
        if (!$googleReady) {
            $hints[] = 'Set GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET on Railway (from Google Cloud Console).';
        }
        if ($googleRedirect !== '') {
            $hints[] = 'Add this Authorized redirect URI in Google Console: ' . $googleRedirect;
        }
        if (!$mailerReady) {
            $hints[] = 'Set MAILER_DSN on Railway to your Brevo SMTP URL (not null://null).';
        }
        if (!$fromSet) {
            $hints[] = 'Set MAILER_FROM_ADDRESS to a sender verified in Brevo.';
        }
        return $hints;
    }

    private function env(string $key): ?string
    {
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
        if ($value === false || $value === null) {
            return null;
        }
        return (string) $value;
    }
}
