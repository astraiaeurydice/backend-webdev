<?php

namespace App\Service\Concerns;

use App\Service\UserNotificationService;

/**
 * Persists + delivers notifications (WebSocket, poll inbox, optional Firebase push).
 */
trait DeliversUserNotifications
{
    /**
     * @param array<string, mixed> $data
     */
    protected function deliverUserNotification(
        UserNotificationService $userNotificationService,
        int $userId,
        string $title,
        string $body,
        array $data = [],
    ): void {
        $type = (string) ($data['type'] ?? 'general');
        $userNotificationService->deliverToUser($userId, $type, $title, $body, $data);
    }
}
