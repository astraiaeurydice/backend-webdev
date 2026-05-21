<?php

namespace App\Controller;

use App\Entity\TradePost;
use App\Entity\TradeRequest;
use App\Entity\TradeTransaction;
use App\Entity\User;
use App\Repository\TradePostRepository;
use App\Repository\TradeRequestRepository;
use App\Repository\TradeTransactionRepository;
use App\Service\JwtService;
use App\Service\TradeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;

#[Route('/api/trades')]
class TradeController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private JwtService $jwtService,
        private TradePostRepository $tradePostRepo,
        private TradeRequestRepository $tradeRequestRepo,
        private TradeTransactionRepository $tradeTransactionRepo,
        private TradeService $tradeService,
    ) {}

    /**
     * Validate JWT token and return user or error
     */
    private function validateToken(Request $request, JwtService $jwtService, EntityManagerInterface $em): ?array
    {
        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return ['error' => 'No token provided', 'code' => 401];
        }

        $token = substr($authHeader, 7);

        try {
            $decoded = $jwtService->validateToken($token);
            
            if ($decoded === null || !is_array($decoded)) {
                return ['error' => 'Invalid or expired token', 'code' => 401];
            }
            
            $userId = $decoded['id'] ?? null;

            if (!$userId) {
                return ['error' => 'Invalid token structure', 'code' => 401];
            }

            $user = $em->getRepository(User::class)->find($userId);

            if (!$user) {
                return ['error' => 'User not found', 'code' => 401];
            }

            return ['user' => $user];

        } catch (\Exception $e) {
            return ['error' => 'Invalid or expired token', 'code' => 401];
        }
    }

    // ==================== TRADE POSTS ====================

    /**
     * Get all available trade posts (excluding current user's posts)
     */
    #[Route('', name: 'get_trades', methods: ['GET'])]
    public function getTrades(Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];
        $trades = $this->tradePostRepo->findOpenTradesExcludingUser($user);

        return $this->json(array_map(function($trade) {
            return $this->serializeTradePost($trade);
        }, $trades));
    }

    /**
     * Get current user's trade posts
     */
    #[Route('/my-posts', name: 'get_my_trade_posts', methods: ['GET'])]
    public function getMyTradePosts(Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];
        $trades = $this->tradePostRepo->findByUser($user);

        return $this->json(array_map(function($trade) {
            return $this->serializeTradePost($trade, true);
        }, $trades));
    }

    // ==================== TRADE REQUESTS (MUST BE BEFORE /{id} ROUTES) ====================

    /**
     * Get all requests made by current user
     */
    #[Route('/requests/sent', name: 'get_sent_requests', methods: ['GET'])]
    public function getSentRequests(Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];
        $requests = $this->tradeRequestRepo->findByRequester($user);

        return $this->json(array_map(function($request) {
            return $this->serializeTradeRequest($request);
        }, $requests));
    }

    /**
     * Get all requests received on current user's posts
     */
    #[Route('/requests/received', name: 'get_received_requests', methods: ['GET'])]
    public function getReceivedRequests(Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];
        $requests = $this->tradeRequestRepo->findRequestsForUserPosts($user);

        return $this->json(array_map(function($request) {
            return $this->serializeTradeRequest($request);
        }, $requests));
    }

    /**
     * Get all transactions involving current user
     */
    #[Route('/transactions', name: 'get_user_transactions', methods: ['GET'])]
    public function getUserTransactions(Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];
        $transactions = $this->tradeTransactionRepo->findByUser($user);

        return $this->json(array_map(function($transaction) {
            return $this->serializeTradeTransaction($transaction);
        }, $transactions));
    }

    /**
     * Get a specific trade post by ID
     */
    #[Route('/{id}', name: 'get_trade_post', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function getTradePost(int $id, Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];
        $trade = $this->tradePostRepo->find($id);
        if (!$trade) {
            return $this->json(['error' => 'Trade post not found'], 404);
        }

        $isOwner = $trade->getUser()->getId() === $user->getId();
        return $this->json($this->serializeTradePost($trade, $isOwner));
    }

    /**
     * Create a new trade post
     */
    #[Route('', name: 'create_trade_post', methods: ['POST'])]
    public function createTradePost(Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];

        $data = json_decode($request->getContent(), true);

        if (!isset($data['itemOffered']) || !isset($data['itemWanted'])) {
            return $this->json(['error' => 'Item offered and item wanted are required'], 400);
        }

        $tradePost = new TradePost();
        $tradePost->setUser($user);
        $tradePost->setItemOffered($data['itemOffered']);
        $tradePost->setItemOfferedDescription($data['itemOfferedDescription'] ?? null);
        $tradePost->setItemOfferedImage($data['itemOfferedImage'] ?? null);
        $tradePost->setItemWanted($data['itemWanted']);
        $tradePost->setItemWantedDescription($data['itemWantedDescription'] ?? null);

        $this->em->persist($tradePost);
        $this->em->flush();

        return $this->json($this->serializeTradePost($tradePost, true), 201);
    }

    /**
     * Update a trade post
     */
    #[Route('/{id}', name: 'update_trade_post', methods: ['PUT'], requirements: ['id' => '\d+'])]
    public function updateTradePost(int $id, Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];

        $trade = $this->tradePostRepo->find($id);
        if (!$trade) {
            return $this->json(['error' => 'Trade post not found'], 404);
        }

        if ($trade->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $data = json_decode($request->getContent(), true);

        if (isset($data['itemOffered'])) $trade->setItemOffered($data['itemOffered']);
        if (isset($data['itemOfferedDescription'])) $trade->setItemOfferedDescription($data['itemOfferedDescription']);
        if (isset($data['itemOfferedImage'])) $trade->setItemOfferedImage($data['itemOfferedImage']);
        if (isset($data['itemWanted'])) $trade->setItemWanted($data['itemWanted']);
        if (isset($data['itemWantedDescription'])) $trade->setItemWantedDescription($data['itemWantedDescription']);
        if (isset($data['status'])) $trade->setStatus($data['status']);

        $trade->setUpdatedAt(new \DateTime());

        $this->em->flush();

        return $this->json($this->serializeTradePost($trade, true));
    }

    /**
     * Delete a trade post
     */
    #[Route('/{id}', name: 'delete_trade_post', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function deleteTradePost(int $id, Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];

        $trade = $this->tradePostRepo->find($id);
        if (!$trade) {
            return $this->json(['error' => 'Trade post not found'], 404);
        }

        if ($trade->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        $this->em->remove($trade);
        $this->em->flush();

        return $this->json(['message' => 'Trade post deleted successfully']);
    }

    // ==================== TRADE REQUESTS ====================

    /**
     * Create a trade request (send "Want to Trade" request)
     */
    #[Route('/{tradePostId}/request', name: 'create_trade_request', methods: ['POST'], requirements: ['tradePostId' => '\d+'])]
    public function createTradeRequest(int $tradePostId, Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];

        $tradePost = $this->tradePostRepo->find($tradePostId);
        if (!$tradePost) {
            return $this->json(['error' => 'Trade post not found'], 404);
        }

        if ($tradePost->getUser()->getId() === $user->getId()) {
            return $this->json(['error' => 'Cannot request your own trade'], 400);
        }

        if ($tradePost->getStatus() !== 'open') {
            return $this->json(['error' => 'Trade post is not open'], 400);
        }

        if ($this->tradeRequestRepo->hasUserRequestedTrade($user, $tradePost)) {
            return $this->json(['error' => 'You already sent a request for this trade'], 400);
        }

        $data = json_decode($request->getContent(), true);

        $tradeRequest = new TradeRequest();
        $tradeRequest->setTradePost($tradePost);
        $tradeRequest->setRequester($user);
        $tradeRequest->setMessage($data['message'] ?? null);

        $this->em->persist($tradeRequest);
        $this->em->flush();

        $this->tradeService->sendTradeOffer($tradeRequest);

        return $this->json($this->serializeTradeRequest($tradeRequest), 201);
    }

    /**
     * Accept a trade request (owner accepts)
     */
    #[Route('/requests/{requestId}/accept', name: 'accept_trade_request', methods: ['POST'], requirements: ['requestId' => '\d+'])]
    public function acceptTradeRequest(int $requestId, Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];

        $tradeRequest = $this->tradeRequestRepo->find($requestId);
        if (!$tradeRequest) {
            return $this->json(['error' => 'Trade request not found'], 404);
        }

        $tradePost = $tradeRequest->getTradePost();
        if ($tradePost->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        if ($tradeRequest->getStatus() !== 'pending') {
            return $this->json(['error' => 'Request is not pending'], 400);
        }

        // Update request status
        $tradeRequest->setStatus('accepted');

        // Update trade post status
        $tradePost->setStatus('pending');
        $tradePost->setUpdatedAt(new \DateTime());

        // Create trade transaction for admin verification
        $transaction = new TradeTransaction();
        $transaction->setTradeRequest($tradeRequest);
        $transaction->setOwner($tradePost->getUser());
        $transaction->setRequester($tradeRequest->getRequester());

        $this->em->persist($transaction);

        // Reject all other pending requests for this trade post
        $otherRequests = $this->tradeRequestRepo->findPendingByTradePost($tradePost);
        $supersededRequests = [];
        foreach ($otherRequests as $otherRequest) {
            if ($otherRequest->getId() !== $requestId) {
                $otherRequest->setStatus('rejected');
                $supersededRequests[] = $otherRequest;
            }
        }

        $this->em->flush();

        $this->tradeService->acceptTrade($tradeRequest, $transaction);
        foreach ($supersededRequests as $superseded) {
            $this->tradeService->notifyTradeOfferSuperseded($superseded);
        }

        return $this->json([
            'message' => 'Trade request accepted. Waiting for admin verification.',
            'transaction' => $this->serializeTradeTransaction($transaction)
        ]);
    }

    /**
     * Reject a trade request
     */
    #[Route('/requests/{requestId}/reject', name: 'reject_trade_request', methods: ['POST'], requirements: ['requestId' => '\d+'])]
    public function rejectTradeRequest(int $requestId, Request $request): JsonResponse
    {
        $validation = $this->validateToken($request, $this->jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $user = $validation['user'];

        $tradeRequest = $this->tradeRequestRepo->find($requestId);
        if (!$tradeRequest) {
            return $this->json(['error' => 'Trade request not found'], 404);
        }

        $tradePost = $tradeRequest->getTradePost();
        if ($tradePost->getUser()->getId() !== $user->getId()) {
            return $this->json(['error' => 'Forbidden'], 403);
        }

        if ($tradeRequest->getStatus() !== 'pending') {
            return $this->json(['error' => 'Request is not pending'], 400);
        }

        $tradeRequest->setStatus('rejected');
        $this->em->flush();

        $this->tradeService->rejectTrade($tradeRequest);

        return $this->json(['message' => 'Trade request rejected']);
    }

    // ==================== SERIALIZERS ====================

    private function serializeTradePost(TradePost $trade, bool $includeRequests = false): array
    {
        $data = [
            'id' => $trade->getId(),
            'itemOffered' => $trade->getItemOffered(),
            'itemOfferedDescription' => $trade->getItemOfferedDescription(),
            'itemOfferedImage' => $trade->getItemOfferedImage(),
            'itemWanted' => $trade->getItemWanted(),
            'itemWantedDescription' => $trade->getItemWantedDescription(),
            'status' => $trade->getStatus(),
            'createdAt' => $trade->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $trade->getUpdatedAt()->format('Y-m-d H:i:s'),
            'user' => [
                'id' => $trade->getUser()->getId(),
                'username' => $trade->getUser()->getUsername(),
                'firstName' => $trade->getUser()->getFirstName(),
                'lastName' => $trade->getUser()->getLastName(),
            ]
        ];

        if ($includeRequests) {
            $data['requestsCount'] = $trade->getTradeRequests()->count();
            $pendingRequests = $this->tradeRequestRepo->findPendingByTradePost($trade);
            $data['pendingRequestsCount'] = is_array($pendingRequests) ? count($pendingRequests) : 0;
        }

        return $data;
    }

    private function serializeTradeRequest(TradeRequest $request): array
    {
        return [
            'id' => $request->getId(),
            'status' => $request->getStatus(),
            'message' => $request->getMessage(),
            'createdAt' => $request->getCreatedAt()->format('Y-m-d H:i:s'),
            'tradePost' => $this->serializeTradePost($request->getTradePost()),
            'requester' => [
                'id' => $request->getRequester()->getId(),
                'username' => $request->getRequester()->getUsername(),
                'firstName' => $request->getRequester()->getFirstName(),
                'lastName' => $request->getRequester()->getLastName(),
            ]
        ];
    }

    private function serializeTradeTransaction(TradeTransaction $transaction): array
    {
        return [
            'id' => $transaction->getId(),
            'status' => $transaction->getStatus(),
            'createdAt' => $transaction->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $transaction->getUpdatedAt()->format('Y-m-d H:i:s'),
            'verifiedAt' => $transaction->getVerifiedAt()?->format('Y-m-d H:i:s'),
            'adminNotes' => $transaction->getAdminNotes(),
            'owner' => [
                'id' => $transaction->getOwner()->getId(),
                'username' => $transaction->getOwner()->getUsername(),
            ],
            'requester' => [
                'id' => $transaction->getRequester()->getId(),
                'username' => $transaction->getRequester()->getUsername(),
            ],
            'verifiedBy' => $transaction->getVerifiedBy() ? [
                'id' => $transaction->getVerifiedBy()->getId(),
                'username' => $transaction->getVerifiedBy()->getUsername(),
            ] : null
        ];
    }
}