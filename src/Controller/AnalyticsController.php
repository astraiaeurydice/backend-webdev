<?php

namespace App\Controller;

use App\Entity\User;
use App\Repository\CustomOrderRepository;
use App\Repository\ProductRepository;
use App\Repository\StockRequestRepository;
use App\Repository\TradeTransactionRepository;
use App\Repository\UserRepository;
use App\Entity\ActivityLog;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin/analytics')]
class AnalyticsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProductRepository $productRepo,
        private CustomOrderRepository $customOrderRepo,
        private UserRepository $userRepo,
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
     * Get all analytics data
     */
    #[Route('', name: 'admin_get_analytics', methods: ['GET'])]
    public function getAnalytics(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        try {
            $days = (int) $request->query->get('days', 30);
            
            // Ensure days is within reasonable bounds
            if ($days < 1 || $days > 365) {
                $days = 30;
            }

            return $this->json([
                'products' => $this->getProductAnalytics(),
                'orders' => $this->getOrderAnalytics($days),
                'users' => $this->getUserAnalytics($days),
                'trading' => $this->getTradingAnalytics($days),
                'stockRequests' => $this->getStockRequestAnalytics($days),
                'activityLogs' => $this->getActivityLogAnalytics($days),
            ]);
        } catch (\Exception $e) {
            error_log('AnalyticsController error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            return $this->json([
                'error' => 'Failed to generate analytics: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Product Analytics
     */
    private function getProductAnalytics(): array
    {
        $allProducts = $this->productRepo->findAll();
        
        $byStatus = ['active' => 0, 'inactive' => 0];
        $byCategory = [];
        $stockLevels = ['inStock' => 0, 'lowStock' => 0, 'outOfStock' => 0];
        $totalStock = 0;

        foreach ($allProducts as $product) {
            // By status
            $status = $product->getStatus() ?? 'inactive';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            // By category
            $category = $product->getCategory() ?? 'Uncategorized';
            $byCategory[$category] = ($byCategory[$category] ?? 0) + 1;

            // Stock levels
            $stock = $product->getStockQuantity() ?? 0;
            $totalStock += $stock;
            if ($stock === 0) {
                $stockLevels['outOfStock']++;
            } elseif ($stock < 10) {
                $stockLevels['lowStock']++;
            } else {
                $stockLevels['inStock']++;
            }
        }

        return [
            'total' => count($allProducts),
            'byStatus' => $byStatus,
            'byCategory' => $byCategory,
            'stockLevels' => $stockLevels,
            'totalStock' => $totalStock,
        ];
    }

    /**
     * Order Analytics
     */
    private function getOrderAnalytics(int $days): array
    {
        $allOrders = $this->customOrderRepo->findAll();
        $completedOrders = array_filter($allOrders, fn($o) => $o->getStatus() === 'complete');
        
        $revenue = 0;
        $totalQuantity = 0;
        $byStatus = [];
        $revenueOverTime = [];
        $topProducts = [];

        // Initialize date range
        $endDate = new \DateTime();
        $startDate = (clone $endDate)->modify("-{$days} days");
        
        for ($i = 0; $i < $days; $i++) {
            $date = (clone $startDate)->modify("+{$i} days");
            $dateKey = $date->format('Y-m-d');
            $revenueOverTime[$dateKey] = 0;
        }

        foreach ($completedOrders as $order) {
            $revenue += $order->getTotalPrice() ?? 0;
            $totalQuantity += $order->getQuantity() ?? 0;

            // Revenue over time
            if ($order->getCreatedAt()) {
                $orderDate = $order->getCreatedAt()->format('Y-m-d');
                if (isset($revenueOverTime[$orderDate])) {
                    $revenueOverTime[$orderDate] += $order->getTotalPrice() ?? 0;
                }
            }

            // Top products
            if ($order->getProduct()) {
                $productName = $order->getProduct()->getName();
                if (!isset($topProducts[$productName])) {
                    $topProducts[$productName] = ['quantity' => 0, 'revenue' => 0];
                }
                $topProducts[$productName]['quantity'] += $order->getQuantity() ?? 0;
                $topProducts[$productName]['revenue'] += $order->getTotalPrice() ?? 0;
            }
        }

        // By status
        foreach ($allOrders as $order) {
            $status = $order->getStatus() ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
        }

        // Sort top products by revenue
        arsort($topProducts);
        $topProducts = array_slice($topProducts, 0, 10, true);

        return [
            'total' => count($allOrders),
            'completed' => count($completedOrders),
            'totalRevenue' => $revenue,
            'totalQuantity' => $totalQuantity,
            'averageOrderValue' => count($completedOrders) > 0 ? $revenue / count($completedOrders) : 0,
            'byStatus' => $byStatus,
            'revenueOverTime' => $revenueOverTime,
            'topProducts' => $topProducts,
        ];
    }

    /**
     * User Analytics
     */
    private function getUserAnalytics(int $days): array
    {
        $allUsers = $this->userRepo->findAll();
        
        $byRole = [];
        $registrationsOverTime = [];
        
        $endDate = new \DateTime();
        $startDate = (clone $endDate)->modify("-{$days} days");
        
        for ($i = 0; $i < $days; $i++) {
            $date = (clone $startDate)->modify("+{$i} days");
            $dateKey = $date->format('Y-m-d');
            $registrationsOverTime[$dateKey] = 0;
        }

        foreach ($allUsers as $user) {
            // By role
            $roles = $user->getRoles();
            $primaryRole = 'ROLE_USER';
            foreach ($roles as $role) {
                if ($role !== 'ROLE_USER') {
                    $primaryRole = $role;
                    break;
                }
            }
            $byRole[$primaryRole] = ($byRole[$primaryRole] ?? 0) + 1;

            // User entity doesn't have createdAt field, so we can't track registrations over time
            // This is left empty but structure is maintained for future use
        }

        return [
            'total' => count($allUsers),
            'byRole' => $byRole,
            'registrationsOverTime' => $registrationsOverTime,
        ];
    }

    /**
     * Trading Analytics
     */
    private function getTradingAnalytics(int $days): array
    {
        $allTransactions = $this->tradeTransactionRepo->findAll();
        
        $byStatus = [];
        $transactionsOverTime = [];
        
        $endDate = new \DateTime();
        $startDate = (clone $endDate)->modify("-{$days} days");
        
        for ($i = 0; $i < $days; $i++) {
            $date = (clone $startDate)->modify("+{$i} days");
            $dateKey = $date->format('Y-m-d');
            $transactionsOverTime[$dateKey] = 0;
        }

        foreach ($allTransactions as $transaction) {
            $status = $transaction->getStatus() ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;

            // Transactions over time
            if ($transaction->getCreatedAt()) {
                $txDate = $transaction->getCreatedAt()->format('Y-m-d');
                if (isset($transactionsOverTime[$txDate])) {
                    $transactionsOverTime[$txDate]++;
                }
            }
        }

        return [
            'total' => count($allTransactions),
            'byStatus' => $byStatus,
            'transactionsOverTime' => $transactionsOverTime,
        ];
    }

    /**
     * Stock Request Analytics
     */
    private function getStockRequestAnalytics(int $days): array
    {
        $allRequests = $this->stockRequestRepo->findAll();
        
        $byStatus = [];
        $requestsOverTime = [];
        $totalValue = 0;
        
        $endDate = new \DateTime();
        $startDate = (clone $endDate)->modify("-{$days} days");
        
        for ($i = 0; $i < $days; $i++) {
            $date = (clone $startDate)->modify("+{$i} days");
            $dateKey = $date->format('Y-m-d');
            $requestsOverTime[$dateKey] = 0;
        }

        foreach ($allRequests as $request) {
            $status = $request->getStatus() ?? 'unknown';
            $byStatus[$status] = ($byStatus[$status] ?? 0) + 1;
            $totalValue += (float)($request->getTotalPrice() ?? 0);

            // Requests over time
            if ($request->getRequestedAt()) {
                $reqDate = $request->getRequestedAt()->format('Y-m-d');
                if (isset($requestsOverTime[$reqDate])) {
                    $requestsOverTime[$reqDate]++;
                }
            }
        }

        return [
            'total' => count($allRequests),
            'byStatus' => $byStatus,
            'totalValue' => $totalValue,
            'requestsOverTime' => $requestsOverTime,
        ];
    }

    /**
     * Activity Log Analytics
     */
    private function getActivityLogAnalytics(int $days): array
    {
        $allLogs = $this->em->getRepository(ActivityLog::class)->findAll();
        
        $byAction = [];
        $logsOverTime = [];
        $byRole = [];
        
        $endDate = new \DateTime();
        $startDate = (clone $endDate)->modify("-{$days} days");
        
        for ($i = 0; $i < $days; $i++) {
            $date = (clone $startDate)->modify("+{$i} days");
            $dateKey = $date->format('Y-m-d');
            $logsOverTime[$dateKey] = 0;
        }

        foreach ($allLogs as $log) {
            // By action
            $action = $log->getAction();
            $byAction[$action] = ($byAction[$action] ?? 0) + 1;

            // By role
            $role = $log->getRole();
            $byRole[$role] = ($byRole[$role] ?? 0) + 1;

            // Logs over time
            $logDate = $log->getCreatedAt()->format('Y-m-d');
            if (isset($logsOverTime[$logDate])) {
                $logsOverTime[$logDate]++;
            }
        }

        return [
            'total' => count($allLogs),
            'byAction' => $byAction,
            'byRole' => $byRole,
            'logsOverTime' => $logsOverTime,
        ];
    }
}

