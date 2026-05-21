<?php

namespace App\Service\Concerns;

use App\Service\OneSignalService;
use App\Service\WebSocketPublisher;
use Psr\Log\LoggerInterface;

/**
 * Sends both real-time (WebSocket) and background (OneSignal) notifications.
 */
trait DeliversUserNotifications
{
    /**
     * WebSocket first for in-app updates; OneSignal for when the app is closed.
     * Failures are logged and never thrown — the main business action already succeeded.
     */
    protected function deliverUserNotification(
        WebSocketPublisher $webSocketPublisher,
        OneSignalService $oneSignalService,
        LoggerInterface $logger,
        int $userId,
        string $title,
        string $body,
        array $data = [],
    ): void {
        $payload = array_merge([
            'title' => $title,
            'body' => $body,
        ], $data);

        try {
            $webSocketPublisher->send($userId, $payload);
        } catch (\Throwable $e) {
            $logger->warning('WebSocket notification failed', [
                'userId' => $userId,
                'type' => $data['type'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $oneSignalService->notify($userId, $title, $body, $data);
        } catch (\Throwable $e) {
            $logger->warning('OneSignal notification failed', [
                'userId' => $userId,
                'type' => $data['type'] ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
