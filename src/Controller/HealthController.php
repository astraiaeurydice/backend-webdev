<?php

namespace App\Controller;

use App\Service\IntegrationConfig;
use App\Service\MailerHealthService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

class HealthController extends AbstractController
{
    public function __construct(
        private IntegrationConfig $integrationConfig,
        private MailerHealthService $mailerHealthService,
    ) {
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

    /**
     * Full deployment checklist — open in browser after changing Railway variables.
     */
    #[Route('/api/health/deployment-check', name: 'api_health_deployment_check', methods: ['GET'])]
    public function deploymentCheck(): JsonResponse
    {
        $status = $this->integrationConfig->getStatus();
        $smtp = $this->mailerHealthService->testConnection();

        $frontendOk = (bool) ($status['frontend_configured'] ?? false);
        $backendOk = (bool) ($status['backend_configured'] ?? false);
        $mailerVarsOk = (bool) ($status['mailer']['configured'] ?? false)
            && (bool) ($status['mailer']['from_address_set'] ?? false);

        $checks = [
            'frontend_url' => [
                'ok' => $frontendOk,
                'value' => $status['frontend_url'] ?? null,
                'fix' => 'Railway → FRONTEND_URL = your Vercel URL (not localhost)',
            ],
            'default_uri' => [
                'ok' => $backendOk,
                'value' => $status['backend_url'] ?? null,
                'fix' => 'Railway → DEFAULT_URI = https://backend-webdev-production.up.railway.app',
            ],
            'mailer_env' => [
                'ok' => $mailerVarsOk,
                'fix' => 'Railway → MAILER_DSN (Brevo) + MAILER_FROM_ADDRESS (verified in Brevo)',
            ],
            'smtp_connection' => [
                'ok' => $smtp['ok'],
                'message' => $smtp['message'],
                'latencyMs' => $smtp['latencyMs'] ?? null,
                'fix' => 'Check Brevo SMTP login, add ?encryption=tls&timeout=10 to MAILER_DSN, redeploy',
            ],
            'google_oauth_web' => [
                'ok' => (bool) ($status['google_oauth']['configured'] ?? false),
                'redirect_uri' => $status['google_oauth']['redirect_uri'] ?? null,
            ],
        ];

        $allOk = $frontendOk && $backendOk && $mailerVarsOk && $smtp['ok'];

        return $this->json([
            'status' => $allOk ? 'ok' : 'action_required',
            'checks' => $checks,
            'hints' => $status['hints'] ?? [],
            'mobile_google_signin' => [
                'note' => 'DEVELOPER_ERROR on release APK = wrong google-services.json',
                'release_sha1_required' => '65:50:A0:96:3A:3D:88:2F:4E:77:9F:58:B7:26:50:87:55:13:B1:18',
                'quick_fix' => 'Rebuild with: .\\scripts\\build-release-apk.ps1 -UseDebugSigning',
            ],
        ]);
    }
}
