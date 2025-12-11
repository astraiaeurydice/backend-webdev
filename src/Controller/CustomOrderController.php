<?php

namespace App\Controller;

use App\Entity\CustomOrder;
use App\Entity\Product;
use App\Entity\User;
use App\Service\JwtService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class CustomOrderController extends AbstractController
{
    private ActivityLogService $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    private function validateAdminToken(Request $request, JwtService $jwtService, EntityManagerInterface $em): ?array
    {
        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return ['error' => 'No token provided', 'code' => 401];
        }

        $token = substr($authHeader, 7);

        try {
            $decoded = $jwtService->validateToken($token);
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

    #[Route('/api/admin/custom-orders', name: 'api_admin_get_custom_orders', methods: ['GET'])]
    public function getCustomOrders(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateAdminToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $orders = $em->getRepository(CustomOrder::class)->findBy([], ['createdAt' => 'DESC']);

        $ordersData = array_map(function($order) {
            return [
                'id' => $order->getId(),
                'customer' => [
                    'id' => $order->getCustomer()->getId(),
                    'username' => $order->getCustomer()->getUsername(),
                    'fullName' => $order->getCustomer()->getFirstName() . ' ' . $order->getCustomer()->getLastName(),
                ],
                'product' => [
                    'id' => $order->getProduct()->getId(),
                    'name' => $order->getProduct()->getName(),
                    'price' => $order->getProduct()->getPrice(),
                ],
                'quantity' => $order->getQuantity(),
                'totalPrice' => $order->getTotalPrice(),
                'status' => $order->getStatus(),
                'createdAt' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            ];
        }, $orders);

        return $this->json($ordersData);
    }

    #[Route('/api/admin/custom-orders', name: 'api_admin_create_custom_order', methods: ['POST'])]
    public function createCustomOrder(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateAdminToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['customerId']) || !isset($data['productId']) || !isset($data['quantity'])) {
            return $this->json(['error' => 'Missing required fields'], 400);
        }

        $customer = $em->getRepository(User::class)->find($data['customerId']);
        if (!$customer) {
            return $this->json(['error' => 'Customer not found'], 404);
        }

        $product = $em->getRepository(Product::class)->find($data['productId']);
        if (!$product) {
            return $this->json(['error' => 'Product not found'], 404);
        }

        if ($data['quantity'] <= 0) {
            return $this->json(['error' => 'Quantity must be greater than 0'], 400);
        }

        // Check stock availability
        $currentStock = $product->getStockQuantity() ?? 0;
        if ($currentStock < $data['quantity']) {
            return $this->json([
                'error' => "Insufficient stock. Available: {$currentStock}, Requested: {$data['quantity']}"
            ], 400);
        }

        $totalPrice = $product->getPrice() * $data['quantity'];

        $customOrder = new CustomOrder();
        $customOrder->setCustomer($customer);
        $customOrder->setProduct($product);
        $customOrder->setQuantity($data['quantity']);
        $customOrder->setTotalPrice($totalPrice);
        $customOrder->setStatus('complete'); // Set to complete since it's purchased immediately

        // Decrease product stock
        $product->decreaseStock($data['quantity']);

        $em->persist($customOrder);
        $em->flush();

        // Log the activity using logCreate
        $this->activityLogService->logCreate(
            $validation['user'],
            'CustomOrder',
            $customOrder->getId(),
            "Order for {$customer->getUsername()}: {$product->getName()} x{$data['quantity']} - ₱{$totalPrice} (Status: complete)",
            $request
        );

        return $this->json([
            'message' => 'Custom order created successfully',
            'order' => [
                'id' => $customOrder->getId(),
                'customer' => [
                    'id' => $customer->getId(),
                    'username' => $customer->getUsername(),
                ],
                'product' => [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                ],
                'quantity' => $customOrder->getQuantity(),
                'totalPrice' => $customOrder->getTotalPrice(),
                'status' => $customOrder->getStatus(),
            ]
        ], 201);
    }

    #[Route('/api/admin/customers', name: 'api_admin_get_customers', methods: ['GET'])]
    public function getCustomers(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateAdminToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $allUsers = $em->getRepository(User::class)->findAll();
        
        // Filter users who have ONLY ROLE_USER (no additional roles)
        $customers = array_filter($allUsers, function($user) {
            $roles = $user->getRoles();
            
            // getRoles() always includes ROLE_USER, so check if it only has that one role
            return count($roles) === 1 && in_array('ROLE_USER', $roles);
        });

        $customersData = array_map(function($user) {
            return [
                'id' => $user->getId(),
                'username' => $user->getUsername(),
                'email' => $user->getEmail(),
                'fullName' => $user->getFirstName() . ' ' . $user->getLastName(),
            ];
        }, $customers);

        // Re-index array to avoid gaps in JSON
        return $this->json(array_values($customersData));
    }

    #[Route('/api/admin/products', name: 'api_admin_get_products', methods: ['GET'])]
    public function getProducts(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em
    ): JsonResponse {
        $validation = $this->validateAdminToken($request, $jwtService, $em);
        
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $products = $em->getRepository(Product::class)->findBy(['status' => 'active']);

        $productsData = array_map(function($product) {
            return [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'stockQuantity' => $product->getStockQuantity(),
                'groupName' => $product->getGroupName(),
            ];
        }, $products);

        return $this->json($productsData);
    }
}