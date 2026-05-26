<?php

namespace App\Service;

use App\Entity\Product;

class ProductCatalogNotificationService
{
    public function __construct(
        private UserNotificationService $userNotificationService,
    ) {
    }

    public function notifyProductCreated(Product $product, ?int $actorUserId = null): void
    {
        $this->notify($product, 'product_created', 'New product in shop', sprintf('"%s" was just added.', $product->getName()), $actorUserId);
    }

    public function notifyProductUpdated(Product $product, ?int $actorUserId = null): void
    {
        $this->notify($product, 'product_updated', 'Product updated', sprintf('"%s" was updated in the shop.', $product->getName()), $actorUserId);
    }

    public function notifyProductDeleted(string $productName, ?int $productId, ?int $actorUserId = null): void
    {
        $this->userNotificationService->deliverToAllUsers(
            'product_deleted',
            'Product removed',
            sprintf('"%s" is no longer available.', $productName),
            [
                'productId' => $productId,
                'productName' => $productName,
            ],
            $actorUserId,
        );
    }

    private function notify(Product $product, string $type, string $title, string $body, ?int $actorUserId): void
    {
        $this->userNotificationService->deliverToAllUsers(
            $type,
            $title,
            $body,
            [
                'productId' => $product->getId(),
                'productName' => $product->getName(),
            ],
            $actorUserId,
        );
    }
}
