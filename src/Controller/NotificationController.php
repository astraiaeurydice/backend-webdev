<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\JwtService;
use App\Service\UserNotificationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/notifications')]
class NotificationController extends AbstractController
{
    public function __construct(
        private JwtService $jwtService,
        private EntityManagerInterface $em,
        private UserNotificationService $userNotificationService,
    ) {
    }

    /**
     * Poll for new notifications (works without OneSignal — mobile calls every ~20s when online).
     * Query: ?since=2026-05-26T12:00:00+00:00 (ISO-8601, optional)
     */
    #[Route('/poll', name: 'api_notifications_poll', methods: ['GET'])]
    public function poll(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $sinceRaw = $request->query->get('since');
        $since = null;
        if (is_string($sinceRaw) && $sinceRaw !== '') {
            try {
                $since = new \DateTimeImmutable($sinceRaw);
            } catch (\Exception) {
                return $this->json(['error' => 'Invalid since parameter (use ISO-8601)'], 400);
            }
        }

        return $this->json($this->userNotificationService->pollForUser($user, $since));
    }

    #[Route('/device-token', name: 'api_notifications_device_token', methods: ['PUT'])]
    public function registerDeviceToken(Request $request): JsonResponse
    {
        $user = $this->resolveUser($request);
        if (!$user instanceof User) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $payload = json_decode($request->getContent(), true);
        if (!is_array($payload)) {
            return $this->json(['error' => 'Invalid JSON body'], 400);
        }

        $token = trim((string) ($payload['token'] ?? ''));
        if ($token === '') {
            return $this->json(['error' => 'token is required'], 400);
        }

        $user->setFcmToken($token);
        $this->em->flush();

        return $this->json(['status' => 'ok']);
    }

    private function resolveUser(Request $request): ?User
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        try {
            $decoded = $this->jwtService->validateToken($token);
            $userId = $decoded['id'] ?? null;
            if (!$userId) {
                return null;
            }

            return $this->em->getRepository(User::class)->find($userId);
        } catch (\Exception) {
            return null;
        }
    }
}
