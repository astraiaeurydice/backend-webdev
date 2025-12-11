<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;

class RegisterController extends AbstractController
{
    #[Route('/api/register', name: 'api_register', methods: ['POST'])]
    public function register(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $passwordHasher
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $firstName = $data['firstName'] ?? null;
        $lastName = $data['lastName'] ?? null;
        $username = $data['username'] ?? null;
        $phoneNumber = $data['phoneNumber'] ?? null;
        $email = $data['email'] ?? null;
        $password = $data['password'] ?? null;

        // Check required fields
        if (!$email || !$password || !$username) {
            return new JsonResponse(['error' => 'Missing required fields'], 400);
        }

        // Check if email already exists
        $existingEmail = $em->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existingEmail) {
            return new JsonResponse(['error' => 'Email already registered'], 400);
        }

        // Check if username already exists
        $existingUsername = $em->getRepository(User::class)->findOneBy(['username' => $username]);
        if ($existingUsername) {
            return new JsonResponse(['error' => 'Username already taken'], 400);
        }

        // Create user
        $user = new User();
        $user->setFirstName($firstName);
        $user->setLastName($lastName);
        $user->setUsername($username);
        $user->setPhoneNumber($phoneNumber);
        $user->setEmail($email);

        // Hash password
        $hashedPassword = $passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        // Save
        $em->persist($user);
        $em->flush();

        return new JsonResponse(['message' => 'User registered successfully'], 201);
    }
}
