<?php

namespace App\Service;

use Psr\Log\LoggerInterface;

/**
 * Sends push notifications via the OneSignal REST API.
 */
class OneSignalService
{
    private const API_URL = 'https://onesignal.com/api/v1/notifications';

    public function __construct(
        private string $appId,
        private string $apiKey,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Send a push notification to a user identified by external_id (user id).
     */
    public function notify(int $userId, string $title, string $body, array $data = []): void
    {
        if ($this->appId === '' || $this->apiKey === '') {
            return;
        }

        try {
            $payload = json_encode([
                'app_id' => $this->appId,
                'target_channel' => 'push',
                'include_aliases' => [
                    'external_id' => [(string) $userId],
                ],
                'headings' => ['en' => $title],
                'contents' => ['en' => $body],
                'data' => $data,
            ], JSON_THROW_ON_ERROR);

            $ch = curl_init(self::API_URL);
            if ($ch === false) {
                $this->logger->error('OneSignal: failed to initialize cURL');

                return;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => [
                    'Content-Type: application/json',
                    'Authorization: Basic ' . $this->apiKey,
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
                $this->logger->error('OneSignal: cURL request failed', [
                    'userId' => $userId,
                    'error' => $curlError,
                ]);

                return;
            }

            if ($httpCode < 200 || $httpCode >= 300) {
                $this->logger->error('OneSignal: API returned non-success status', [
                    'userId' => $userId,
                    'httpCode' => $httpCode,
                    'response' => $response,
                ]);

                return;
            }

            $this->logger->info('OneSignal: notification sent', [
                'userId' => $userId,
                'title' => $title,
            ]);
        } catch (\Throwable $e) {
            $this->logger->error('OneSignal: unexpected error', [
                'userId' => $userId,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
