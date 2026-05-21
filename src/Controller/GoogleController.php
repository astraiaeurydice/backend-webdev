<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use GuzzleHttp\Client;
use KnpU\OAuth2ClientBundle\Client\ClientRegistry;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Annotation\Route;

class GoogleController extends AbstractController
{
    // Step 1: Redirect user TO Google
    #[Route('/connect/google', name: 'connect_google')]
    public function connectGoogle(ClientRegistry $clientRegistry): RedirectResponse
    {
        return $clientRegistry->getClient('google')->redirect(['email', 'profile']);
    }

    // Step 2: Google sends user BACK here
    #[Route('/connect/google/check', name: 'connect_google_check')]
    public function connectGoogleCheck(): void
    {
        // Handled automatically by GoogleAuthenticator — leave this empty
    }

    #[Route('/api/google/mobile-login', name: 'api_google_mobile_login', methods: ['POST'])]
    public function mobileLogin(
        Request $request,
        EntityManagerInterface $em,
        JwtService $jwtService
    ): JsonResponse {
        $payload = json_decode($request->getContent(), true);
        $accessToken = trim((string) ($payload['token'] ?? ''));
        if ($accessToken === '') {
            return $this->json(['error' => 'Google access token is required'], 400);
        }

        try {
            $client = new Client(['verify' => false]);
            $googleResponse = $client->get('https://www.googleapis.com/userinfo/v2/me', [
                'headers' => [
                    'Authorization' => 'Bearer ' . $accessToken,
                ],
            ]);
            $userData = json_decode((string) $googleResponse->getBody(), true);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to fetch user data from Google'], 400);
        }

        $email = trim((string) ($userData['email'] ?? ''));
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->json(['error' => 'Invalid Google account email'], 400);
        }

        $user = $em->getRepository(User::class)->findOneBy(['email' => $email]);

        if (!$user) {
            $firstName = trim((string) ($userData['given_name'] ?? 'Google'));
            $lastName = trim((string) ($userData['family_name'] ?? 'User'));
            $picture = trim((string) ($userData['picture'] ?? ''));

            $baseUsername = strtolower(explode('@', $email)[0]);
            $username = $baseUsername;
            $counter = 1;
            while ($em->getRepository(User::class)->findOneBy(['username' => $username])) {
                $username = $baseUsername . $counter;
                $counter++;
            }

            $user = new User();
            $user->setEmail($email);
            $user->setFirstName($firstName !== '' ? $firstName : 'Google');
            $user->setLastName($lastName !== '' ? $lastName : 'User');
            $user->setUsername($username);
            $user->setPhoneNumber('N/A');
            $user->setRoles(['ROLE_USER']);
            $user->setStatus('active');
            $user->setIsVerified(true);
            $user->setPassword('');
            if ($picture !== '') {
                $user->setAvatarUrl($picture);
            }
            $em->persist($user);
            $em->flush();
        }

        $jwt = $jwtService->generateToken([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'roles' => $user->getRoles(),
        ]);

        return $this->json([
            'token' => $jwt,
            'roles' => $user->getRoles(),
            'user' => [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'firstName' => $user->getFirstName(),
                'lastName' => $user->getLastName(),
                'phoneNumber' => $user->getPhoneNumber(),
                'avatarUrl' => $user->getAvatarUrl(),
                'role' => $user->getRoles()[0] ?? 'ROLE_USER',
            ],
        ]);
    }
}
// 

// ---

// ## Step 7 — Add Redirect URL in Google Console

// Go back to [console.cloud.google.com](https://console.cloud.google.com) → **Credentials** → click your OAuth Client → under **Authorized redirect URIs**, add:
// ```
// http://localhost:8000/connect/google/check
// ```

// This must match exactly what Symfony generates for the `connect_google_check` route.

// ---

// ## How It All Connects
// ```
// React button → /connect/google (GoogleController)
//   → Google login page
//     → /connect/google/check (GoogleController)
//       → GoogleAuthenticator runs
//         → finds/creates user in DB
//           → redirects to http://localhost:3000