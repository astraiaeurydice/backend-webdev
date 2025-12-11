<?php

namespace App\Controller;

use App\Entity\TradeTransaction;
use App\Entity\User;
use App\Repository\TradeTransactionRepository;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin/trading-history')]
class TradingHistoryController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private TradeTransactionRepository $tradeTransactionRepo
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
     * Get all trading history
     */
    #[Route('', name: 'admin_get_trading_history', methods: ['GET'])]
    public function getTradingHistory(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $statusFilter = $request->query->get('status');
        $userId = $request->query->get('userId');
        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');

        $qb = $this->tradeTransactionRepo->createQueryBuilder('tt')
            ->orderBy('tt.createdAt', 'DESC');

        if ($statusFilter) {
            $qb->where('tt.status = :status')
               ->setParameter('status', $statusFilter);
        }

        if ($userId) {
            $qb->andWhere('tt.owner = :userId OR tt.requester = :userId')
               ->setParameter('userId', $userId);
        }

        if ($dateFrom) {
            $qb->andWhere('tt.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo) {
            $qb->andWhere('tt.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        $transactions = $qb->getQuery()->getResult();

        $transactionsData = array_map(function($transaction) {
            return $this->serializeTransaction($transaction);
        }, $transactions);

        return $this->json($transactionsData);
    }

    /**
     * Get trading history statistics
     */
    #[Route('/statistics', name: 'admin_get_trading_statistics', methods: ['GET'])]
    public function getStatistics(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $stats = $this->tradeTransactionRepo->getStatistics();
        
        // Add additional stats
        $allTransactions = $this->tradeTransactionRepo->findAll();
        $verifiedTransactions = array_filter($allTransactions, fn($t) => $t->getStatus() === 'verified');
        $completedTransactions = array_filter($allTransactions, fn($t) => $t->getStatus() === 'completed');

        $stats['verifiedCount'] = count($verifiedTransactions);
        $stats['completedCount'] = count($completedTransactions);
        $stats['totalActive'] = $stats['pending'] + $stats['verified'];

        return $this->json($stats);
    }

    /**
     * Get a specific transaction details
     */
    #[Route('/{id}', name: 'admin_get_trading_record', methods: ['GET'])]
    public function getTradingRecord(int $id, Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $transaction = $this->tradeTransactionRepo->find($id);

        if (!$transaction) {
            return $this->json(['error' => 'Trading record not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeTransaction($transaction, true));
    }

    /**
     * Serialize transaction for API response
     */
    private function serializeTransaction(TradeTransaction $transaction, bool $includeDetails = false): array
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

