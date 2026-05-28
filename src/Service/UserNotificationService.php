<?php

namespace App\Service;

use App\Entity\AppNotification;
use App\Entity\User;
use App\Repository\AppNotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Stores notifications in DB (poll fallback) and delivers via WebSocket + optional FCM.
 */
class UserNotificationService
{
    public function __construct(
        private EntityManagerInterface $em,
        private AppNotificationRepository $notificationRepository,
        private UserRepository $userRepository,
        private WebSocketPublisher $webSocketPublisher,
        private FirebasePushService $firebasePushService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<string, mixed> $data
     */
    public function deliverToUser(int $userId, string $type, string $title, string $body, array $data = []): void
    {
        try {
            $user = $this->userRepository->find($userId);
            if (!$user instanceof User) {
                return;
            }

            $notification = $this->createNotification($user, $type, $title, $body, $data);
            $this->em->flush();
            $payload = $this->buildPayload($notification, $type, $title, $body, $data);

            try {
                $this->webSocketPublisher->send($userId, $payload);
            } catch (\Throwable $e) {
                $this->logger->warning('WebSocket notification failed', [
                    'userId' => $userId,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }

            try {
                $this->firebasePushService->notify($user, $title, $body, $data);
            } catch (\Throwable $e) {
                $this->logger->warning('FCM notification failed', [
                    'userId' => $userId,
                    'type' => $type,
                    'error' => $e->getMessage(),
                ]);
            }
        } catch (\Throwable $e) {
            $this->logger->error('Notification delivery failed', [
                'userId' => $userId,
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Notify every active user (catalog / shop-wide updates). Also broadcasts on WebSocket once.
     *
     * @param array<string, mixed> $data
     */
    public function deliverToAllUsers(string $type, string $title, string $body, array $data = [], ?int $excludeUserId = null): void
    {
        try {
            $payload = array_merge([
                'type' => $type,
                'title' => $title,
                'body' => $body,
            ], $data);

            try {
                $this->webSocketPublisher->broadcast($payload);
            } catch (\Throwable $e) {
                $this->logger->warning('WebSocket broadcast failed', ['type' => $type, 'error' => $e->getMessage()]);
            }

            $users = $this->userRepository->findBy(['status' => 'active']);
            foreach ($users as $user) {
                $id = (int) $user->getId();
                if ($excludeUserId !== null && $id === $excludeUserId) {
                    continue;
                }

                $this->createNotification($user, $type, $title, $body, $data);

                try {
                    $this->firebasePushService->notify($user, $title, $body, $data);
                } catch (\Throwable $e) {
                    $this->logger->warning('FCM notification failed', [
                        'userId' => $id,
                        'type' => $type,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger->error('Broadcast notification failed', [
                'type' => $type,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array{notifications: list<array<string, mixed>>, serverTime: string}
     */
    public function pollForUser(User $user, ?\DateTimeImmutable $since): array
    {
        $items = $this->notificationRepository->findNewForUser($user, $since);

        $notifications = array_map(
            fn (AppNotification $n) => $this->serialize($n),
            $items,
        );

        return [
            'notifications' => $notifications,
            'serverTime' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createNotification(User $user, string $type, string $title, string $body, array $data): AppNotification
    {
        $notification = new AppNotification();
        $notification->setUser($user);
        $notification->setType($type);
        $notification->setTitle($title);
        $notification->setBody($body);
        $notification->setData($data);

        $this->em->persist($notification);

        return $notification;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed>
     */
    private function buildPayload(AppNotification $notification, string $type, string $title, string $body, array $data): array
    {
        return array_merge([
            'notificationId' => $notification->getId(),
            'type' => $type,
            'title' => $title,
            'body' => $body,
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ], $data);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(AppNotification $notification): array
    {
        return [
            'id' => $notification->getId(),
            'type' => $notification->getType(),
            'title' => $notification->getTitle(),
            'body' => $notification->getBody(),
            'data' => $notification->getData(),
            'createdAt' => $notification->getCreatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }
}
