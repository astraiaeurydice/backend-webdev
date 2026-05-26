<?php

namespace App\Controller;

use App\Service\IntegrationConfig;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class HealthController extends AbstractController
{
    public function __construct(private IntegrationConfig $integrationConfig)
    {
    }
    #[Route('/', name: 'api_root', methods: ['GET'])]
    public function root(): JsonResponse
    {
        return $this->json([
            'status' => 'ok',
            'message' => 'K-Dream API is running',
            'health' => '/api/health',
            'ping' => '/ping.php',
        ]);
    }

    #[Route('/api/health', name: 'api_health', methods: ['GET'])]
    public function health(): JsonResponse
    {
        return $this->json(['status' => 'ok']);
    }

    /** Public diagnostics for Google OAuth / Brevo (no secrets). */
    #[Route('/api/health/integrations', name: 'api_health_integrations', methods: ['GET'])]
    public function integrations(): JsonResponse
    {
        $status = $this->integrationConfig->getStatus();
        $ok = ($status['frontend_configured'] ?? false)
            && ($status['google_oauth']['configured'] ?? false)
            && ($status['mailer']['configured'] ?? false)
            && ($status['mailer']['from_address_set'] ?? false);

        return $this->json([
            'status' => $ok ? 'ok' : 'misconfigured',
            ...$status,
        ]);
    }

    /** Mobile notifications: poll API always works; WebSocket/OneSignal are optional extras. */
    #[Route('/api/health/realtime', name: 'api_health_realtime', methods: ['GET'])]
    public function realtime(): JsonResponse
    {
        $wsUrl = (string) ($_ENV['WORKERMAN_INTERNAL_URL'] ?? getenv('WORKERMAN_INTERNAL_URL') ?: '');
        $wsToken = (string) ($_ENV['WORKERMAN_INTERNAL_TOKEN'] ?? getenv('WORKERMAN_INTERNAL_TOKEN') ?: '');
        $oneSignalId = (string) ($_ENV['ONESIGNAL_APP_ID'] ?? getenv('ONESIGNAL_APP_ID') ?: '');

        return $this->json([
            'status' => 'ok',
            'poll_api' => '/api/notifications/poll',
            'websocket_push' => [
                'configured' => $wsUrl !== '' && $wsToken !== '',
                'internal_url_set' => $wsUrl !== '',
            ],
            'onesignal' => [
                'configured' => $oneSignalId !== '',
            ],
        ]);
    }
}
