<?php

namespace App\Service;

use App\Entity\User;
use Psr\Log\LoggerInterface;

/**
 * Sends Android system notifications via Firebase Cloud Messaging HTTP v1 API.
 */
class FirebasePushService
{
    private const GOOGLE_TOKEN_URI = 'https://oauth2.googleapis.com/token';

    private ?string $cachedAccessToken = null;
    private int $cachedTokenExpiresAt = 0;

    public function __construct(
        private string $projectId,
        private string $serviceAccountJson,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function notify(User $user, string $title, string $body, array $data = []): void
    {
        $deviceToken = trim((string) ($user->getFcmToken() ?? ''));
        if ($deviceToken === '') {
            return;
        }

        $projectId = trim($this->projectId);
        if ($projectId === '') {
            return;
        }

        $accessToken = $this->getAccessToken();
        if ($accessToken === null) {
            return;
        }

        $url = sprintf('https://fcm.googleapis.com/v1/projects/%s/messages:send', rawurlencode($projectId));

        $stringData = [];
        foreach ($data as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }
            $stringData[$key] = is_scalar($value) || $value === null
                ? (string) $value
                : json_encode($value, JSON_THROW_ON_ERROR);
        }

        $payload = json_encode([
            'message' => [
                'token' => $deviceToken,
                'notification' => [
                    'title' => $title,
                    'body' => $body,
                ],
                'data' => $stringData,
                'android' => [
                    'priority' => 'HIGH',
                    'notification' => [
                        'channel_id' => 'default',
                        'sound' => 'default',
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        $ch = curl_init($url);
        if ($ch === false) {
            return;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $accessToken,
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logger->error('FCM push failed: cURL error', ['error' => $curlError, 'userId' => $user->getId()]);
            return;
        }

        if ($httpCode < 200 || $httpCode >= 300) {
            $this->logger->error('FCM push failed: non-2xx response', [
                'userId' => $user->getId(),
                'httpCode' => $httpCode,
                'response' => $response,
            ]);
        }
    }

    private function getAccessToken(): ?string
    {
        $now = time();
        if ($this->cachedAccessToken !== null && $now < $this->cachedTokenExpiresAt - 60) {
            return $this->cachedAccessToken;
        }

        $sa = $this->parseServiceAccount();
        if ($sa === null) {
            return null;
        }

        $issuedAt = time();
        $expiresAt = $issuedAt + 3600;
        $jwtHeader = $this->base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT'], JSON_THROW_ON_ERROR));
        $jwtPayload = $this->base64UrlEncode(json_encode([
            'iss' => $sa['client_email'],
            'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
            'aud' => $sa['token_uri'] ?: self::GOOGLE_TOKEN_URI,
            'iat' => $issuedAt,
            'exp' => $expiresAt,
        ], JSON_THROW_ON_ERROR));

        $unsignedJwt = $jwtHeader . '.' . $jwtPayload;
        $signature = '';
        $ok = openssl_sign($unsignedJwt, $signature, $sa['private_key'], 'sha256');
        if (!$ok) {
            $this->logger->error('FCM auth failed: could not sign JWT');
            return null;
        }

        $assertion = $unsignedJwt . '.' . $this->base64UrlEncode($signature);

        $ch = curl_init($sa['token_uri'] ?: self::GOOGLE_TOKEN_URI);
        if ($ch === false) {
            return null;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $assertion,
            ]),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            $this->logger->error('FCM auth failed: cURL error', ['error' => $curlError]);
            return null;
        }

        $decoded = json_decode($response, true);
        if (!is_array($decoded) || !isset($decoded['access_token'])) {
            $this->logger->error('FCM auth failed: invalid token response', ['response' => $response, 'httpCode' => $httpCode]);
            return null;
        }

        $this->cachedAccessToken = (string) $decoded['access_token'];
        $this->cachedTokenExpiresAt = time() + (int) ($decoded['expires_in'] ?? 3600);

        return $this->cachedAccessToken;
    }

    /**
     * @return array{client_email: string, private_key: string, token_uri: string}|null
     */
    private function parseServiceAccount(): ?array
    {
        $raw = trim($this->serviceAccountJson);
        if ($raw === '') {
            return null;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\Throwable $e) {
            $this->logger->error('FCM service account JSON parse failed', ['error' => $e->getMessage()]);
            return null;
        }

        if (!is_array($decoded)) {
            return null;
        }

        $clientEmail = (string) ($decoded['client_email'] ?? '');
        $privateKey = (string) ($decoded['private_key'] ?? '');
        $tokenUri = (string) ($decoded['token_uri'] ?? self::GOOGLE_TOKEN_URI);

        if ($clientEmail === '' || $privateKey === '') {
            $this->logger->error('FCM service account JSON missing required keys');
            return null;
        }

        return [
            'client_email' => $clientEmail,
            'private_key' => $privateKey,
            'token_uri' => $tokenUri,
        ];
    }

    private function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }
}

