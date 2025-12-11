<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Psr\Log\LoggerInterface;

class JwtAuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private JwtService $jwtService,
        private EntityManagerInterface $em,
        private TokenStorageInterface $tokenStorage,
        private LoggerInterface $logger
    ) {}

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        $request = $event->getRequest();
        
        // Skip if not main request
        if (!$event->isMainRequest()) {
            return;
        }

        // Get Authorization header
        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return;
        }

        $token = substr($authHeader, 7);

        try {
            $decoded = $this->jwtService->validateToken($token);
            
            if (!$decoded || !is_array($decoded)) {
                return;
            }
            
            $userId = $decoded['id'] ?? null;
            
            if (!$userId) {
                return;
            }

            $user = $this->em->getRepository(User::class)->find($userId);
            
            if (!$user) {
                $this->logger->warning('JwtAuthenticationSubscriber: User not found', [
                    'user_id' => $userId
                ]);
                return;
            }

            // Set the user in the security token storage
            $authenticatedToken = new UsernamePasswordToken(
                $user,
                'main', // firewall name
                $user->getRoles()
            );
            
            $this->tokenStorage->setToken($authenticatedToken);
            
            $this->logger->info('JwtAuthenticationSubscriber: User authenticated', [
                'username' => $user->getUsername(),
                'roles' => $user->getRoles()
            ]);

        } catch (\Exception $e) {
            $this->logger->error('JwtAuthenticationSubscriber: Authentication failed', [
                'error' => $e->getMessage()
            ]);
        }
    }
}
