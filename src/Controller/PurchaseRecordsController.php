<?php

namespace App\Controller;

use App\Entity\CustomOrder;
use App\Entity\User;
use App\Repository\CustomOrderRepository;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin/purchase-records')]
class PurchaseRecordsController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private CustomOrderRepository $customOrderRepo
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
     * Get all purchase records (completed orders)
     */
    #[Route('', name: 'admin_get_purchase_records', methods: ['GET'])]
    public function getPurchaseRecords(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $statusFilter = $request->query->get('status', 'complete');
        $customerId = $request->query->get('customerId');
        $dateFrom = $request->query->get('dateFrom');
        $dateTo = $request->query->get('dateTo');

        $qb = $this->customOrderRepo->createQueryBuilder('o')
            ->orderBy('o.createdAt', 'DESC');

        if ($statusFilter) {
            $qb->where('o.status = :status')
               ->setParameter('status', $statusFilter);
        }

        if ($customerId) {
            $qb->andWhere('o.customer = :customerId')
               ->setParameter('customerId', $customerId);
        }

        if ($dateFrom) {
            $qb->andWhere('o.createdAt >= :dateFrom')
               ->setParameter('dateFrom', new \DateTime($dateFrom));
        }

        if ($dateTo) {
            $qb->andWhere('o.createdAt <= :dateTo')
               ->setParameter('dateTo', new \DateTime($dateTo . ' 23:59:59'));
        }

        $orders = $qb->getQuery()->getResult();

        $ordersData = array_map(function($order) {
            return $this->serializeOrder($order);
        }, $orders);

        return $this->json($ordersData);
    }

    /**
     * Get purchase records statistics
     */
    #[Route('/statistics', name: 'admin_get_purchase_statistics', methods: ['GET'])]
    public function getStatistics(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $allOrders = $this->customOrderRepo->findAll();
        $completedOrders = array_filter($allOrders, fn($o) => $o->getStatus() === 'complete');

        $stats = [
            'totalOrders' => count($allOrders),
            'completedOrders' => count($completedOrders),
            'totalRevenue' => 0,
            'totalQuantity' => 0,
            'averageOrderValue' => 0,
        ];

        foreach ($completedOrders as $order) {
            $stats['totalRevenue'] += $order->getTotalPrice();
            $stats['totalQuantity'] += $order->getQuantity();
        }

        if (count($completedOrders) > 0) {
            $stats['averageOrderValue'] = $stats['totalRevenue'] / count($completedOrders);
        }

        return $this->json($stats);
    }

    /**
     * Get a specific purchase record
     */
    #[Route('/{id}', name: 'admin_get_purchase_record', methods: ['GET'])]
    public function getPurchaseRecord(int $id, Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $order = $this->customOrderRepo->find($id);

        if (!$order) {
            return $this->json(['error' => 'Purchase record not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeOrder($order, true));
    }

    /**
     * Serialize order for API response
     */
    private function serializeOrder(CustomOrder $order, bool $includeDetails = false): array
    {
        $data = [
            'id' => $order->getId(),
            'quantity' => $order->getQuantity(),
            'totalPrice' => $order->getTotalPrice(),
            'status' => $order->getStatus(),
            'createdAt' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $order->getUpdatedAt()->format('Y-m-d H:i:s'),
            'customer' => [
                'id' => $order->getCustomer()->getId(),
                'username' => $order->getCustomer()->getUsername(),
                'firstName' => $order->getCustomer()->getFirstName(),
                'lastName' => $order->getCustomer()->getLastName(),
                'email' => $order->getCustomer()->getEmail(),
            ],
            'product' => [
                'id' => $order->getProduct()->getId(),
                'name' => $order->getProduct()->getName(),
                'price' => $order->getProduct()->getPrice(),
                'image' => $order->getProduct()->getImage(),
                'category' => $order->getProduct()->getCategory(),
            ],
        ];

        if ($includeDetails) {
            $product = $order->getProduct();
            $data['product']['description'] = $product->getDescription();
            $data['product']['stockQuantity'] = $product->getStockQuantity();
            $data['product']['group'] = $product->getGroup() ? [
                'id' => $product->getGroup()->getId(),
                'name' => $product->getGroup()->getName(),
            ] : null;
        }

        return $data;
    }
}

