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
}
