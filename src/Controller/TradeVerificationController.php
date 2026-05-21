<?php

namespace App\Controller;

use App\Entity\TradeTransaction;
use App\Entity\User;
use App\Repository\TradeTransactionRepository;
use App\Service\JwtService;
use App\Service\ActivityLogService;
use App\Service\TradeService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin/trades')]
class TradeVerificationController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TradeTransactionRepository $tradeTransactionRepo,
        private ActivityLogService $activityLogService,
        private TradeService $tradeService,
    ) {}

    /**
     * Validate admin token
     */
    private function validateAdminToken(Request $request, JwtService $jwtService, EntityManagerInterface $em): ?array
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

            if (!in_array('ROLE_ADMIN', $user->getRoles())) {
                return ['error' => 'Access denied. Admin only.', 'code' => 403];
            }

            return ['user' => $user];

        } catch (\Exception $e) {
            return ['error' => 'Invalid or expired token', 'code' => 401];
        }
    }

    /**
     * Get all transactions with optional status filter
     */
    #[Route('/transactions', name: 'admin_get_all_transactions', methods: ['GET'])]
    public function getAllTransactions(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $status = $request->query->get('status');
        
        if ($status) {
            $transactions = $this->tradeTransactionRepo->findBy(['status' => $status], ['createdAt' => 'DESC']);
        } else {
            $transactions = $this->tradeTransactionRepo->findAll();
            usort($transactions, function($a, $b) {
                // Sort by: pending_verification first, then by date
                if ($a->getStatus() === 'pending_verification' && $b->getStatus() !== 'pending_verification') {
                    return -1;
                }
                if ($a->getStatus() !== 'pending_verification' && $b->getStatus() === 'pending_verification') {
                    return 1;
                }
                return $b->getCreatedAt() <=> $a->getCreatedAt();
            });
        }

        return $this->json(array_map(function($transaction) {
            return $this->serializeTradeTransaction($transaction);
        }, $transactions));
    }

    /**
     * Get pending verification transactions
     */
    #[Route('/transactions/pending', name: 'admin_get_pending_transactions', methods: ['GET'])]
    public function getPendingTransactions(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $transactions = $this->tradeTransactionRepo->findPendingVerification();

        return $this->json(array_map(function($transaction) {
            return $this->serializeTradeTransaction($transaction);
        }, $transactions));
    }

    /**
     * Get transaction statistics
     */
    #[Route('/statistics', name: 'admin_get_trade_statistics', methods: ['GET'])]
    public function getStatistics(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $stats = $this->tradeTransactionRepo->getStatistics();

        return $this->json($stats);
    }

    /**
     * Verify a trade transaction
     */
    #[Route('/transactions/{id}/verify', name: 'admin_verify_transaction', methods: ['POST'])]
    public function verifyTransaction(int $id, Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $admin = $validation['user'];
        $transaction = $this->tradeTransactionRepo->find($id);

        if (!$transaction) {
            return $this->json(['error' => 'Transaction not found'], Response::HTTP_NOT_FOUND);
        }

        if ($transaction->getStatus() !== 'pending_verification') {
            return $this->json(['error' => 'Transaction is not pending verification'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $adminNotes = $data['adminNotes'] ?? null;

        $transaction->setStatus('verified');
        $transaction->setVerifiedBy($admin);
        $transaction->setVerifiedAt(new \DateTime());
        if ($adminNotes) {
            $transaction->setAdminNotes($adminNotes);
        }

        // Update the associated trade post status
        $tradePost = $transaction->getTradeRequest()->getTradePost();
        $tradePost->setStatus('completed');
        $tradePost->setUpdatedAt(new \DateTime());

        $this->em->flush();

        // Log the activity
        $this->activityLogService->logUpdate(
            $admin,
            'TradeTransaction',
            $transaction->getId(),
            'Trade Transaction Verified',
            $request
        );

        $this->tradeService->notifyTransactionVerified($transaction);

        return $this->json([
            'message' => 'Transaction verified successfully',
            'transaction' => $this->serializeTradeTransaction($transaction)
        ]);
    }

    /**
     * Reject a trade transaction
     */
    #[Route('/transactions/{id}/reject', name: 'admin_reject_transaction', methods: ['POST'])]
    public function rejectTransaction(int $id, Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $admin = $validation['user'];
        $transaction = $this->tradeTransactionRepo->find($id);

        if (!$transaction) {
            return $this->json(['error' => 'Transaction not found'], Response::HTTP_NOT_FOUND);
        }

        if ($transaction->getStatus() !== 'pending_verification') {
            return $this->json(['error' => 'Transaction is not pending verification'], Response::HTTP_BAD_REQUEST);
        }

        $data = json_decode($request->getContent(), true);
        $adminNotes = $data['adminNotes'] ?? null;

        if (!$adminNotes) {
            return $this->json(['error' => 'Admin notes are required when rejecting a transaction'], Response::HTTP_BAD_REQUEST);
        }

        $transaction->setStatus('rejected');
        $transaction->setVerifiedBy($admin);
        $transaction->setVerifiedAt(new \DateTime());
        $transaction->setAdminNotes($adminNotes);

        // Update the associated trade post status back to open
        $tradePost = $transaction->getTradeRequest()->getTradePost();
        $tradePost->setStatus('open');
        $tradePost->setUpdatedAt(new \DateTime());

        // Reject the trade request
        $tradeRequest = $transaction->getTradeRequest();
        $tradeRequest->setStatus('rejected');

        $this->em->flush();

        // Log the activity
        $this->activityLogService->logUpdate(
            $admin,
            'TradeTransaction',
            $transaction->getId(),
            'Trade Transaction Rejected',
            $request
        );

        $this->tradeService->notifyTransactionRejected($transaction);

        return $this->json([
            'message' => 'Transaction rejected',
            'transaction' => $this->serializeTradeTransaction($transaction)
        ]);
    }

    /**
     * Get a specific transaction details
     */
    #[Route('/transactions/{id}', name: 'admin_get_transaction', methods: ['GET'])]
    public function getTransaction(int $id, Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $transaction = $this->tradeTransactionRepo->find($id);

        if (!$transaction) {
            return $this->json(['error' => 'Transaction not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeTradeTransaction($transaction, true));
    }

    /**
     * Serialize trade transaction for API response
     */
    private function serializeTradeTransaction(TradeTransaction $transaction, bool $includeDetails = false): array
    {
        $tradeRequest = $transaction->getTradeRequest();
        $tradePost = $tradeRequest->getTradePost();

        $data = [
            'id' => $transaction->getId(),
            'status' => $transaction->getStatus(),
            'createdAt' => $transaction->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $transaction->getUpdatedAt()->format('Y-m-d H:i:s'),
            'verifiedAt' => $transaction->getVerifiedAt()?->format('Y-m-d H:i:s'),
            'adminNotes' => $transaction->getAdminNotes(),
            'owner' => [
                'id' => $transaction->getOwner()->getId(),
                'username' => $transaction->getOwner()->getUsername(),
                'firstName' => $transaction->getOwner()->getFirstName(),
                'lastName' => $transaction->getOwner()->getLastName(),
                'email' => $transaction->getOwner()->getEmail(),
            ],
            'requester' => [
                'id' => $transaction->getRequester()->getId(),
                'username' => $transaction->getRequester()->getUsername(),
                'firstName' => $transaction->getRequester()->getFirstName(),
                'lastName' => $transaction->getRequester()->getLastName(),
                'email' => $transaction->getRequester()->getEmail(),
            ],
            'verifiedBy' => $transaction->getVerifiedBy() ? [
                'id' => $transaction->getVerifiedBy()->getId(),
                'username' => $transaction->getVerifiedBy()->getUsername(),
            ] : null,
        ];

        if ($includeDetails) {
            $data['tradePost'] = [
                'id' => $tradePost->getId(),
                'itemOffered' => $tradePost->getItemOffered(),
                'itemOfferedDescription' => $tradePost->getItemOfferedDescription(),
                'itemOfferedImage' => $tradePost->getItemOfferedImage(),
                'itemWanted' => $tradePost->getItemWanted(),
                'itemWantedDescription' => $tradePost->getItemWantedDescription(),
                'status' => $tradePost->getStatus(),
            ];
            $data['tradeRequest'] = [
                'id' => $tradeRequest->getId(),
                'message' => $tradeRequest->getMessage(),
                'status' => $tradeRequest->getStatus(),
                'createdAt' => $tradeRequest->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }

        return $data;
    }
}

