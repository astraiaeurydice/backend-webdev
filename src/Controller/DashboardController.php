<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\CustomOrderRepository;
use App\Repository\ProductRepository;
use App\Repository\TradeTransactionRepository;
use App\Repository\StockRequestRepository;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin/dashboard')]
class DashboardController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProductRepository $productRepo,
        private CustomOrderRepository $customOrderRepo,
        private TradeTransactionRepository $tradeTransactionRepo,
        private StockRequestRepository $stockRequestRepo
    ) {}

    /**
     * Validate admin/staff token
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

            $roles = $user->getRoles();
            if (!in_array('ROLE_ADMIN', $roles) && !in_array('ROLE_STAFF', $roles)) {
                return ['error' => 'Access denied. Admin/Staff only.', 'code' => 403];
            }

            return ['user' => $user];

        } catch (\Exception $e) {
            return ['error' => 'Invalid or expired token', 'code' => 401];
        }
    }

    /**
     * Get dashboard statistics
     */
    #[Route('/statistics', name: 'admin_dashboard_statistics', methods: ['GET'])]
    public function getStatistics(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        try {
            // Get total products
            $totalProducts = count($this->productRepo->findAll());
            
            // Get low stock products (stock < 10)
            $lowStockProducts = $this->productRepo->createQueryBuilder('p')
                ->where('p.stockQuantity < 10 OR p.stockQuantity IS NULL')
                ->getQuery()
                ->getResult();
            $lowStockCount = count($lowStockProducts);

            // Get total orders
            $allOrders = $this->customOrderRepo->findAll();
            $totalOrders = count($allOrders);

            // Get total trades (all trade transactions)
            $allTrades = $this->tradeTransactionRepo->findAll();
            $totalTrades = count($allTrades);

            // Get pending trades (status = 'pending_verification')
            $pendingTrades = $this->tradeTransactionRepo->findBy(['status' => 'pending_verification']);
            $pendingTradesCount = count($pendingTrades);

            // Get stock requests count
            $stockRequests = $this->stockRequestRepo->findAll();
            $stockRequestsCount = count($stockRequests);

            return $this->json([
                'totalProducts' => $totalProducts,
                'totalOrders' => $totalOrders,
                'totalTrades' => $totalTrades,
                'pendingTrades' => $pendingTradesCount,
                'lowStockCount' => $lowStockCount,
                'stockRequestsCount' => $stockRequestsCount,
            ]);
        } catch (\Exception $e) {
            error_log('DashboardController error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return $this->json([
                'error' => 'Failed to fetch dashboard statistics: ' . $e->getMessage()
            ], 500);
        }
    }
}

