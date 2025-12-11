<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Service\JwtService;
use App\Service\ActivityLogService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/admin/inventory')]
class InventoryController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $em,
        private ProductRepository $productRepo,
        private ActivityLogService $activityLogService
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
     * Get all products with inventory information
     */
    #[Route('/products', name: 'admin_get_inventory_products', methods: ['GET'])]
    public function getInventoryProducts(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $search = $request->query->get('search', '');
        $lowStock = $request->query->get('lowStock', 'false') === 'true';
        $status = $request->query->get('status');

        $qb = $this->productRepo->createQueryBuilder('p');

        if ($search) {
            $qb->where('p.name LIKE :search OR p.description LIKE :search OR p.category LIKE :search')
               ->setParameter('search', '%' . $search . '%');
        }

        if ($lowStock) {
            $qb->andWhere('p.stockQuantity < 10 OR p.stockQuantity IS NULL');
        }

        if ($status) {
            $qb->andWhere('p.status = :status')
               ->setParameter('status', $status);
        }

        $qb->orderBy('p.stockQuantity', 'ASC')
           ->addOrderBy('p.name', 'ASC');

        $products = $qb->getQuery()->getResult();

        $productsData = array_map(function($product) {
            return $this->serializeProduct($product);
        }, $products);

        return $this->json($productsData);
    }

    /**
     * Get inventory statistics
     */
    #[Route('/statistics', name: 'admin_get_inventory_statistics', methods: ['GET'])]
    public function getStatistics(Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $allProducts = $this->productRepo->findAll();
        
        $stats = [
            'totalProducts' => count($allProducts),
            'totalStock' => 0,
            'lowStockCount' => 0,
            'outOfStockCount' => 0,
            'activeProducts' => 0,
        ];

        foreach ($allProducts as $product) {
            $stock = $product->getStockQuantity() ?? 0;
            $stats['totalStock'] += $stock;
            
            if ($stock < 10 && $stock > 0) {
                $stats['lowStockCount']++;
            }
            
            if ($stock === 0 || $stock === null) {
                $stats['outOfStockCount']++;
            }
            
            if ($product->getStatus() === 'active') {
                $stats['activeProducts']++;
            }
        }

        return $this->json($stats);
    }

    /**
     * Add stock to a product
     */
    #[Route('/products/{id}/add-stock', name: 'admin_add_stock', methods: ['POST'])]
    public function addStock(int $id, Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $admin = $validation['user'];
        $product = $this->productRepo->find($id);

        if (!$product) {
            return $this->json(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['quantity']) || !is_numeric($data['quantity'])) {
            return $this->json(['error' => 'Quantity is required and must be a number'], Response::HTTP_BAD_REQUEST);
        }

        $quantity = (int)$data['quantity'];

        if ($quantity <= 0) {
            return $this->json(['error' => 'Quantity must be greater than 0'], Response::HTTP_BAD_REQUEST);
        }

        $oldStock = $product->getStockQuantity() ?? 0;
        $product->increaseStock($quantity);
        $newStock = $product->getStockQuantity();

        $this->em->flush();

        // Log the activity
        $this->activityLogService->logUpdate(
            $admin,
            'Product',
            $product->getId(),
            "Stock increased from {$oldStock} to {$newStock} (+{$quantity})",
            $request
        );

        return $this->json([
            'message' => "Successfully added {$quantity} units to stock",
            'product' => $this->serializeProduct($product),
            'oldStock' => $oldStock,
            'newStock' => $newStock,
            'added' => $quantity
        ]);
    }

    /**
     * Update stock quantity directly (set to specific value)
     */
    #[Route('/products/{id}/update-stock', name: 'admin_update_stock', methods: ['PUT'])]
    public function updateStock(int $id, Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $admin = $validation['user'];
        $product = $this->productRepo->find($id);

        if (!$product) {
            return $this->json(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        $data = json_decode($request->getContent(), true);

        if (!isset($data['quantity']) || !is_numeric($data['quantity'])) {
            return $this->json(['error' => 'Quantity is required and must be a number'], Response::HTTP_BAD_REQUEST);
        }

        $quantity = (int)$data['quantity'];

        if ($quantity < 0) {
            return $this->json(['error' => 'Quantity cannot be negative'], Response::HTTP_BAD_REQUEST);
        }

        $oldStock = $product->getStockQuantity() ?? 0;
        $product->setStockQuantity($quantity);
        $newStock = $product->getStockQuantity();

        $this->em->flush();

        // Log the activity
        $difference = $newStock - $oldStock;
        $this->activityLogService->logUpdate(
            $admin,
            'Product',
            $product->getId(),
            "Stock updated from {$oldStock} to {$newStock} (" . ($difference >= 0 ? '+' : '') . "{$difference})",
            $request
        );

        return $this->json([
            'message' => "Stock quantity updated successfully",
            'product' => $this->serializeProduct($product),
            'oldStock' => $oldStock,
            'newStock' => $newStock,
            'difference' => $difference
        ]);
    }

    /**
     * Get a specific product's inventory details
     */
    #[Route('/products/{id}', name: 'admin_get_product_inventory', methods: ['GET'])]
    public function getProductInventory(int $id, Request $request, JwtService $jwtService): JsonResponse
    {
        $validation = $this->validateAdminToken($request, $jwtService, $this->em);
        if (isset($validation['error'])) {
            return $this->json(['error' => $validation['error']], $validation['code']);
        }

        $product = $this->productRepo->find($id);

        if (!$product) {
            return $this->json(['error' => 'Product not found'], Response::HTTP_NOT_FOUND);
        }

        return $this->json($this->serializeProduct($product));
    }

    /**
     * Serialize product for inventory API response
     */
    private function serializeProduct(Product $product): array
    {
        return [
            'id' => $product->getId(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'price' => $product->getPrice(),
            'stockQuantity' => $product->getStockQuantity() ?? 0,
            'status' => $product->getStatus(),
            'category' => $product->getCategory(),
            'subcategory' => $product->getSubcategory(),
            'image' => $product->getImage(),
            'group' => $product->getGroup() ? [
                'id' => $product->getGroup()->getId(),
                'name' => $product->getGroup()->getName(),
            ] : null,
            'supplier' => $product->getSupplier() ? [
                'id' => $product->getSupplier()->getId(),
                'companyName' => $product->getSupplier()->getCompanyName(),
            ] : null,
            'createdAt' => $product->getCreatedAt()?->format('Y-m-d H:i:s'),
            'updatedAt' => $product->getUpdatedAt()?->format('Y-m-d H:i:s'),
        ];
    }
}

