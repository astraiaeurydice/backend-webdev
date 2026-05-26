<?php

namespace App\Service;

/**
 * Pushes real-time messages to connected clients via the Workerman internal HTTP API.
 */
class WebSocketPublisher
{
    public function __construct(
        private string $workermanUrl = 'http://localhost:8091',
        private string $internalToken = '',
    ) {
    }

    /**
     * Send a JSON payload to a specific user if they are connected to the WebSocket server.
     */
    public function send(int $userId, array $payload): void
    {
        try {
            $body = json_encode([
                'userId' => $userId,
                'payload' => $payload,
            ], JSON_THROW_ON_ERROR);

            $ch = curl_init($this->workermanUrl);
            if ($ch === false) {
                return;
            }

            $headers = ['Content-Type: application/json'];
            if ($this->internalToken !== '') {
                $headers[] = 'X-Internal-Token: ' . $this->internalToken;
            }

            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_HTTPHEADER => $headers,
                CURLOPT_POSTFIELDS => $body,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 2,
                CURLOPT_CONNECTTIMEOUT => 2,
            ]);

            curl_exec($ch);
            curl_close($ch);
        } catch (\Throwable) {
            // Workerman may be offline — do not interrupt the Symfony request
        }
    }
}
