<?php

namespace App\Controller;

use App\Entity\Conversation;
use App\Entity\ConversationMessage;
use App\Entity\User;
use App\Repository\ConversationMessageRepository;
use App\Repository\ConversationRepository;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/messages')]
class MessagesController extends AbstractController
{
    private function getCurrentUser(Request $request, JwtService $jwtService, EntityManagerInterface $em): ?User
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }
        try {
            $decoded = $jwtService->validateToken(substr($authHeader, 7));
            return $em->getRepository(User::class)->find($decoded['id'] ?? null);
        } catch (\Exception $e) {
            return null;
        }
    }

    #[Route('/conversations', name: 'mobile_conversations', methods: ['GET'])]
    public function conversations(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em,
        ConversationRepository $conversationRepository,
        ConversationMessageRepository $messageRepository
    ): JsonResponse {
        $user = $this->getCurrentUser($request, $jwtService, $em);
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $conversations = $conversationRepository->findForUser($user);
        $data = [];
        foreach ($conversations as $conversation) {
            $messages = $messageRepository->findRecentByConversation($conversation);
            $last = end($messages) ?: null;
            $data[] = [
                'id' => $conversation->getId(),
                'name' => $conversation->getName(),
                'lastMessage' => $last ? $last->getMessage() : '',
                'timestamp' => $last ? $last->getCreatedAt()?->format('Y-m-d H:i') : '',
                'avatar' => $conversation->getParticipant()?->getAvatarUrl() ?? '',
            ];
        }

        return $this->json(['conversations' => $data]);
    }

    #[Route('/{conversationId}', name: 'mobile_conversation_messages', methods: ['GET'])]
    public function listMessages(
        int $conversationId,
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em,
        ConversationRepository $conversationRepository,
        ConversationMessageRepository $messageRepository
    ): JsonResponse {
        $user = $this->getCurrentUser($request, $jwtService, $em);
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $conversation = $conversationRepository->find($conversationId);
        if (!$conversation) {
            return $this->json(['error' => 'Conversation not found'], 404);
        }
        if (
            $conversation->getOwner()?->getId() !== $user->getId() &&
            $conversation->getParticipant()?->getId() !== $user->getId()
        ) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $messages = $messageRepository->findRecentByConversation($conversation);
        return $this->json([
            'messages' => array_map(function (ConversationMessage $message) use ($user) {
                return [
                    'id' => $message->getId(),
                    'text' => $message->getMessage(),
                    'sender' => $message->getSender()?->getId() === $user->getId() ? 'me' : 'them',
                    'timestamp' => $message->getCreatedAt()?->format('Y-m-d H:i'),
                ];
            }, $messages),
        ]);
    }

    #[Route('/{conversationId}', name: 'mobile_send_message', methods: ['POST'])]
    public function sendMessage(
        int $conversationId,
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em,
        ConversationRepository $conversationRepository
    ): JsonResponse {
        $user = $this->getCurrentUser($request, $jwtService, $em);
        if (!$user) {
            return $this->json(['error' => 'Unauthorized'], 401);
        }

        $conversation = $conversationRepository->find($conversationId);
        if (!$conversation) {
            return $this->json(['error' => 'Conversation not found'], 404);
        }
        if (
            $conversation->getOwner()?->getId() !== $user->getId() &&
            $conversation->getParticipant()?->getId() !== $user->getId()
        ) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $payload = json_decode($request->getContent(), true);
        $text = trim((string) ($payload['message'] ?? ''));
        if ($text === '') {
            return $this->json(['error' => 'Message is required'], 400);
        }

        $message = new ConversationMessage();
        $message->setConversation($conversation);
        $message->setSender($user);
        $message->setMessage($text);

        $conversation->setUpdatedAt(new \DateTime());
        $em->persist($message);
        $em->flush();

        return $this->json([
            'message' => [
                'id' => $message->getId(),
                'text' => $message->getMessage(),
                'sender' => 'me',
                'timestamp' => $message->getCreatedAt()?->format('Y-m-d H:i'),
            ],
        ], 201);
    }
}
