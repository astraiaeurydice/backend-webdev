<?php

namespace App\Controller;

use App\Repository\ProductRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class MobileMarketController extends AbstractController
{
    #[Route('/shop/products', name: 'mobile_shop_products', methods: ['GET'])]
    public function shopProducts(ProductRepository $productRepository): JsonResponse
    {
        $products = $productRepository->findBy(['status' => 'active'], ['createdAt' => 'DESC']);
        return $this->json([
            'products' => array_map([$this, 'mapProduct'], $products),
        ]);
    }

    #[Route('/items/sale', name: 'mobile_items_sale', methods: ['GET'])]
    public function saleItems(ProductRepository $productRepository): JsonResponse
    {
        $products = $productRepository->findBy(['status' => 'active'], ['createdAt' => 'DESC']);
        return $this->json([
            'items' => array_slice(array_map([$this, 'mapProduct'], $products), 0, 12),
        ]);
    }

    private function mapProduct($product): array
    {
        return [
            'id' => $product->getId(),
            'title' => $product->getName(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'price' => $product->getPrice(),
            'image' => $product->getImage(),
            'category' => $product->getCategory(),
            'stockQuantity' => $product->getStockQuantity(),
        ];
    }
}
