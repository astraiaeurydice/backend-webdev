<?php

namespace App\Controller;

use App\Entity\CustomOrder;
use App\Entity\Product;
use App\Entity\User;
use App\Service\ActivityLogService;
use App\Service\JwtService;
use App\Service\OrderService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/cart')]
class CartController extends AbstractController
{
    #[Route('/checkout', name: 'mobile_cart_checkout', methods: ['POST'])]
    public function checkout(
        Request $request,
        JwtService $jwtService,
        EntityManagerInterface $em,
        OrderService $orderService,
        ActivityLogService $activityLogService,
    ): JsonResponse {
        $authHeader = $request->headers->get('Authorization');
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return $this->json(['error' => 'No token provided'], 401);
        }

        try {
            $decoded = $jwtService->validateToken(substr($authHeader, 7));
            $user = $em->getRepository(User::class)->find($decoded['id'] ?? null);
            if (!$user) {
                return $this->json(['error' => 'User not found'], 401);
            }
        } catch (\Exception $e) {
            return $this->json(['error' => 'Invalid token'], 401);
        }

        $data = json_decode($request->getContent(), true);
        $items = $data['items'] ?? [];
        if (!is_array($items) || count($items) === 0) {
            return $this->json(['error' => 'Cart is empty'], 400);
        }

        $receiptNumber = 'RCP-' . date('YmdHis') . '-' . $user->getId();
        $pendingOrders = [];
        $total = 0.0;

        foreach ($items as $item) {
            $productId = $item['productId'] ?? null;
            $quantity = (int) ($item['quantity'] ?? 0);
            if (!$productId || $quantity < 1) {
                return $this->json(['error' => 'Invalid cart item'], 400);
            }

            $product = $em->getRepository(Product::class)->find($productId);
            if (!$product) {
                return $this->json(['error' => "Product {$productId} not found"], 404);
            }
            if (($product->getStockQuantity() ?? 0) < $quantity) {
                return $this->json(['error' => "Insufficient stock for {$product->getName()}"], 400);
            }

            $lineTotal = $product->getPrice() * $quantity;

            $order = new CustomOrder();
            $order->setCustomer($user);
            $order->setProduct($product);
            $order->setQuantity($quantity);
            $order->setTotalPrice($lineTotal);
            $order->setStatus('complete');
            $order->setReceiptNumber($receiptNumber);

            $product->decreaseStock($quantity);
            $em->persist($order);

            $pendingOrders[] = [
                'order' => $order,
                'product' => $product,
                'quantity' => $quantity,
                'lineTotal' => $lineTotal,
            ];
            $total += $lineTotal;
        }

        $em->flush();

        $receiptItems = [];
        $orderIds = [];
        foreach ($pendingOrders as $row) {
            $orderIds[] = $row['order']->getId();
            $receiptItems[] = [
                'orderId' => $row['order']->getId(),
                'productId' => $row['product']->getId(),
                'name' => $row['product']->getName(),
                'quantity' => $row['quantity'],
                'unitPrice' => $row['product']->getPrice(),
                'totalPrice' => $row['lineTotal'],
            ];
        }

        $customerName = trim(($user->getFirstName() ?? '') . ' ' . ($user->getLastName() ?? ''));
        if ($customerName === '') {
            $customerName = $user->getUsername() ?? $user->getEmail() ?? 'Customer';
        }

        $orderService->notifyCheckoutComplete($user, $orderIds, $total, $receiptNumber);

        $activityLogService->logCreate(
            $user,
            'Purchase',
            $receiptNumber,
            sprintf('Checkout %s — %d item(s), total ₱%.2f', $receiptNumber, count($orderIds), $total),
            $request
        );

        return $this->json([
            'message' => 'Checkout successful',
            'receipt' => [
                'receiptNumber' => $receiptNumber,
                'orderIds' => $orderIds,
                'customerName' => $customerName,
                'customerEmail' => $user->getEmail(),
                'items' => $receiptItems,
                'total' => $total,
                'paymentMethod' => 'In-store / Manual (no payment gateway)',
                'createdAt' => (new \DateTime())->format(\DateTimeInterface::ATOM),
            ],
            'orders' => $receiptItems,
            'total' => $total,
        ], 201);
    }
}
