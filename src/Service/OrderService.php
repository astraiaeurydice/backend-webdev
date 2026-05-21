<?php

namespace App\Service;

use App\Entity\CustomOrder;
use App\Entity\User;
use App\Service\Concerns\DeliversUserNotifications;
use Psr\Log\LoggerInterface;

class OrderService
{
    use DeliversUserNotifications;

    public function __construct(
        private WebSocketPublisher $webSocketPublisher,
        private OneSignalService $oneSignalService,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Notify customer when order status changes (admin updates, fulfillment, etc.).
     */
    public function updateOrderStatus(CustomOrder $order, string $previousStatus): void
    {
        $newStatus = $order->getStatus();
        if ($newStatus === $previousStatus) {
            return;
        }

        $customer = $order->getCustomer();
        if (!$customer) {
            return;
        }

        $productName = $order->getProduct()?->getName() ?? 'your order';

        $this->deliverUserNotification(
            $this->webSocketPublisher,
            $this->oneSignalService,
            $this->logger,
            (int) $customer->getId(),
            'Order Update',
            sprintf('%s is now %s', $productName, $newStatus),
            [
                'type' => 'order_update',
                'orderId' => $order->getId(),
                'status' => $newStatus,
                'previousStatus' => $previousStatus,
            ],
        );
    }

    /** Notify customer after mobile checkout completes. */
    public function notifyCheckoutComplete(User $customer, array $orderIds, float $total, string $receiptNumber): void
    {
        $count = count($orderIds);
        $body = $count === 1
            ? sprintf('Order confirmed. Total: ₱%.2f', $total)
            : sprintf('%d orders confirmed. Total: ₱%.2f', $count, $total);

        $this->deliverUserNotification(
            $this->webSocketPublisher,
            $this->oneSignalService,
            $this->logger,
            (int) $customer->getId(),
            'Order Confirmed',
            $body,
            [
                'type' => 'order_receipt',
                'orderIds' => $orderIds,
                'total' => $total,
                'receiptNumber' => $receiptNumber,
            ],
        );
    }

    /** Notify customer when staff creates an order on their behalf. */
    public function notifyOrderCreated(CustomOrder $order): void
    {
        $customer = $order->getCustomer();
        if (!$customer) {
            return;
        }

        $productName = $order->getProduct()?->getName() ?? 'Item';

        $this->deliverUserNotification(
            $this->webSocketPublisher,
            $this->oneSignalService,
            $this->logger,
            (int) $customer->getId(),
            'New Order',
            sprintf('An order for %s was placed for you.', $productName),
            [
                'type' => 'order_created',
                'orderId' => $order->getId(),
                'status' => $order->getStatus(),
            ],
        );
    }
}
