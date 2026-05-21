<?php

namespace App\Security;

use App\Entity\User;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use KnpU\OAuth2ClientBundle\Security\Authenticator\OAuth2Authenticator;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Exception\AuthenticationException;
use Symfony\Component\Security\Http\Authenticator\Passport\Badge\UserBadge;
use Symfony\Component\Security\Http\Authenticator\Passport\Passport;
use Symfony\Component\Security\Http\Authenticator\Passport\SelfValidatingPassport;

class GoogleAuthenticator extends OAuth2Authenticator
{
    public function __construct(
        private ClientRegistry $clientRegistry,
        private EntityManagerInterface $em,
        private JwtService $jwtService,
        private RouterInterface $router,
        private string $frontendUrl,
    ) {}

    public function supports(Request $request): ?bool
    {
        return $request->attributes->get('_route') === 'connect_google_check';
    }

 public function authenticate(Request $request): Passport
{
    $client = $this->clientRegistry->getClient('google');

    $provider = $client->getOAuth2Provider();
    $provider->setHttpClient(new Client(['verify' => false]));

    $accessToken = $this->fetchAccessToken($client);

    return new SelfValidatingPassport(
        new UserBadge($accessToken->getToken(), function () use ($accessToken, $client) {
            $googleUser = $client->fetchUserFromToken($accessToken);
            $email = $googleUser->getEmail();

            $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);

            if (!$user) {
                // Get name from Google
                $firstName = $googleUser->getFirstName() ?? 'Google';
                $lastName  = $googleUser->getLastName() ?? 'User';

                // Generate a unique username from email (e.g. "christiandelenia123")
                $baseUsername = strtolower(explode('@', $email)[0]);
                $username = $baseUsername;
                $counter = 1;

                // Keep trying until username is unique
                while ($this->em->getRepository(User::class)->findOneBy(['username' => $username])) {
                    $username = $baseUsername . $counter;
                    $counter++;
                }

                $user = new User();
                $user->setEmail($email);
                $user->setFirstName($firstName);
                $user->setLastName($lastName);
                $user->setUsername($username);
                $user->setPhoneNumber('N/A');   // Google doesn't give phone numbers
                $user->setRoles(['ROLE_USER']);
                $user->setPassword('');         // No password for Google users
                $user->setStatus('active');

                $this->em->persist($user);
                $this->em->flush();
            }

            return $user;
        })
    );
}
    public function onAuthenticationSuccess(Request $request, TokenInterface $token, string $firewallName): ?Response
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return new RedirectResponse($this->frontendUrl . '/login?error=google_auth_failed');
        }

        // Mirror /api/login response shape expected by the React app (token + roles + user).
        $jwt = $this->jwtService->generateToken([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ]);

        $roles = $user->getRoles();
        $userPayload = [
            'id' => $user->getId(),
            'username' => $user->getUsername(),
            'email' => $user->getEmail(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'role' => $roles[0] ?? 'ROLE_USER',
        ];

        $query = http_build_query([
            'token' => $jwt,
            'roles' => json_encode($roles),
            'user' => json_encode($userPayload),
        ]);

        // Frontend will store token/roles/user then redirect to the correct dashboard.
        return new RedirectResponse($this->frontendUrl . '/oauth/callback?' . $query);
    }

    public function onAuthenticationFailure(Request $request, AuthenticationException $exception): ?Response
    {
        return new Response('Authentication failed: ' . $exception->getMessage(), 403);
    }
}