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
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/orders')]
class CustomerOrdersController extends AbstractController
{
    public function __construct(
        private CustomOrderRepository $customOrderRepo,
    ) {
    }

    private function getCustomerFromRequest(Request $request, JwtService $jwtService, EntityManagerInterface $em): array
    {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return ['error' => 'No token provided', 'code' => 401];
        }

        try {
            $decoded = $jwtService->validateToken(substr($authHeader, 7));
            $user = $em->getRepository(User::class)->find($decoded['id'] ?? null);
            if (!$user) {
                return ['error' => 'User not found', 'code' => 401];
            }

            return ['user' => $user];
        } catch (\Exception $e) {
            return ['error' => 'Invalid token', 'code' => 401];
        }
    }

    /**
     * Customer order history grouped by receipt (mobile + web dashboard).
     */
    #[Route('/my', name: 'customer_my_orders', methods: ['GET'])]
    public function myOrders(Request $request, JwtService $jwtService, EntityManagerInterface $em): JsonResponse
    {
        $auth = $this->getCustomerFromRequest($request, $jwtService, $em);
        if (isset($auth['error'])) {
            return $this->json(['error' => $auth['error']], $auth['code']);
        }

        $orders = $this->customOrderRepo->findBy(
            ['customer' => $auth['user']],
            ['createdAt' => 'DESC']
        );

        $receipts = [];
        foreach ($orders as $order) {
            $key = $order->getReceiptNumber() ?? ('ORDER-' . $order->getId());
            if (!isset($receipts[$key])) {
                $receipts[$key] = [
                    'receiptNumber' => $key,
                    'createdAt' => $order->getCreatedAt()->format(\DateTimeInterface::ATOM),
                    'total' => 0.0,
                    'items' => [],
                ];
            }
            $receipts[$key]['items'][] = $this->serializeLineItem($order);
            $receipts[$key]['total'] += $order->getTotalPrice();
        }

        return $this->json([
            'receipts' => array_values($receipts),
            'orders' => array_map(fn (CustomOrder $o) => $this->serializeLineItem($o), $orders),
        ]);
    }

    private function serializeLineItem(CustomOrder $order): array
    {
        $product = $order->getProduct();

        return [
            'orderId' => $order->getId(),
            'receiptNumber' => $order->getReceiptNumber(),
            'productId' => $product->getId(),
            'name' => $product->getName(),
            'quantity' => $order->getQuantity(),
            'unitPrice' => $product->getPrice(),
            'totalPrice' => $order->getTotalPrice(),
            'status' => $order->getStatus(),
            'createdAt' => $order->getCreatedAt()->format('Y-m-d H:i:s'),
            'image' => $product->getImage(),
        ];
    }
}
