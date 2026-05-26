<?php

namespace App\Service;

/**
 * Builds email verification links using DEFAULT_URI (Railway public API URL).
 */
class VerificationUrlBuilder
{
    public function __construct(
        private string $defaultUri = '',
    ) {
    }

    public function build(string $token): string
    {
        $base = rtrim($this->resolveBaseUrl(), '/');

        return $base . '/verify-email?' . http_build_query(['token' => $token]);
    }

    private function resolveBaseUrl(): string
    {
        $candidates = [
            $this->defaultUri,
            (string) ($_ENV['DEFAULT_URI'] ?? ''),
            (string) (getenv('DEFAULT_URI') ?: ''),
        ];

        foreach ($candidates as $url) {
            $url = rtrim(trim($url), '/');
            if ($url !== '' && !str_contains($url, 'localhost') && !str_contains($url, '127.0.0.1')) {
                return $url;
            }
        }

        foreach ($candidates as $url) {
            $url = rtrim(trim($url), '/');
            if ($url !== '') {
                return $url;
            }
        }

        return 'http://127.0.0.1:8000';
    }
}
