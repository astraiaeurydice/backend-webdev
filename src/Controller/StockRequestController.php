<?php

namespace App\Controller;

use App\Entity\StockRequest;
use App\Entity\User;
use App\Repository\StockRequestRepository;
use App\Repository\ProductRepository;
use App\Repository\SupplierRepository;
use App\Service\ActivityLogService;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/stock-requests')]
class StockRequestController extends AbstractController
{
    public function __construct(
        private ActivityLogService $activityLogService,
        private JwtService $jwtService
    ) {}

    /**
     * Get current user from JWT token (admin/staff only for logging)
     */
    private function getCurrentUser(Request $request, EntityManagerInterface $em): ?User
    {
        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        try {
            $decoded = $this->jwtService->validateToken($token);
            
            if ($decoded === null || !is_array($decoded)) {
                return null;
            }
            
            $userId = $decoded['id'] ?? null;

            if (!$userId) {
                return null;
            }

            $user = $em->getRepository(User::class)->find($userId);

            if (!$user) {
                return null;
            }

            // Only log for admin/staff
            $roles = $user->getRoles();
            if (!in_array('ROLE_ADMIN', $roles) && !in_array('ROLE_STAFF', $roles)) {
                return null;
            }

            return $user;
        } catch (\Exception $e) {
            return null;
        }
    }
    #[Route('', name: 'stock_request_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $entityManager,
        ProductRepository $productRepository,
        SupplierRepository $supplierRepository
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);

            if ($data === null) {
                return $this->json(['error' => 'Invalid JSON'], 400);
            }

            // Validate required fields
            if (!isset($data['product_id']) || !isset($data['quantity'])) {
                return $this->json([
                    'error' => 'Missing required fields: product_id and quantity are required'
                ], 400);
            }

            // Find product
            $product = $productRepository->find($data['product_id']);
            if (!$product) {
                return $this->json(['error' => 'Product not found'], 404);
            }

            // Determine supplier
            $supplier = null;
            
            // Priority 1: Manual override from request
            if (isset($data['supplier_id']) && !empty($data['supplier_id'])) {
                $supplier = $supplierRepository->find($data['supplier_id']);
                if (!$supplier) {
                    return $this->json(['error' => 'Specified supplier not found'], 404);
                }
            }
            // Priority 2: Product's direct supplier
            else if (method_exists($product, 'getSupplier') && $product->getSupplier()) {
                $supplier = $product->getSupplier();
            }
            // Priority 3: Product group's supplier
            else if (method_exists($product, 'getGroup') && $product->getGroup() && 
                     method_exists($product->getGroup(), 'getSupplier') && $product->getGroup()->getSupplier()) {
                $supplier = $product->getGroup()->getSupplier();
            }
            
            // If no supplier found, return error
            if (!$supplier) {
                return $this->json([
                    'error' => 'No supplier available for this product. Please assign a supplier to the product or its group, or select one manually.'
                ], 400);
            }

            // Create stock request
            $stockRequest = new StockRequest();
            $stockRequest->setProduct($product);
            $stockRequest->setSupplier($supplier);
            $stockRequest->setQuantity((int)$data['quantity']);
            
            // Set optional fields
            if (isset($data['unit_price']) && !empty($data['unit_price'])) {
                $stockRequest->setUnitPrice((float)$data['unit_price']);
            }
            
            if (isset($data['notes']) && !empty($data['notes'])) {
                $stockRequest->setNotes($data['notes']);
            }

            $entityManager->persist($stockRequest);
            $entityManager->flush();

            // Log activity for admin/staff
            $currentUser = $this->getCurrentUser($request, $entityManager);
            if ($currentUser) {
                $supplierName = $supplier->getCompanyName() ?? 'Unknown';
                $this->activityLogService->logCreate(
                    $currentUser,
                    'StockRequest',
                    $stockRequest->getId(),
                    "Product: {$product->getName()}, Quantity: {$stockRequest->getQuantity()}, Supplier: {$supplierName}",
                    $request
                );
            }

            // Get supplier name
            $supplierName = 'Unknown';
            if (method_exists($supplier, 'getCompanyName') && $supplier->getCompanyName()) {
                $supplierName = $supplier->getCompanyName();
            } elseif (method_exists($supplier, 'getName') && $supplier->getName()) {
                $supplierName = $supplier->getName();
            }

            return $this->json([
                'message' => 'Stock request created successfully',
                'id' => $stockRequest->getId(),
                'request' => [
                    'id' => $stockRequest->getId(),
                    'product' => [
                        'id' => $product->getId(),
                        'name' => $product->getName()
                    ],
                    'supplier' => [
                        'id' => $supplier->getId(),
                        'name' => $supplierName
                    ],
                    'quantity' => $stockRequest->getQuantity(),
                    'unitPrice' => $stockRequest->getUnitPrice(),
                    'totalPrice' => $stockRequest->getTotalPrice(),
                    'status' => $stockRequest->getStatus(),
                    'createdAt' => $stockRequest->getRequestedAt()?->format('Y-m-d H:i:s'),
                    'notes' => $stockRequest->getNotes()
                ]
            ], 201);

        } catch (\Exception $e) {
            // Log the full error for debugging
            error_log('Stock Request Error: ' . $e->getMessage());
            error_log('Stack trace: ' . $e->getTraceAsString());
            
            return $this->json([
                'error' => 'Failed to create stock request: ' . $e->getMessage()
            ], 500);
        }
    }

    #[Route('/{id}', name: 'stock_request_delete', methods: ['DELETE'])]
    public function delete(
        int $id,
        StockRequestRepository $repository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        try {
            $stockRequest = $repository->find($id);

            if (!$stockRequest) {
                return $this->json(['error' => 'Stock request not found'], 404);
            }

            // If the request was accepted, reverse the stock changes
            if ($stockRequest->isAccepted()) {
                $product = $stockRequest->getProduct();
                if ($product && $stockRequest->getQuantity()) {
                    $currentStock = $product->getStockQuantity();
                    $newStock = $currentStock - $stockRequest->getQuantity();
                    
                    // Prevent negative stock
                    if ($newStock < 0) {
                        return $this->json([
                            'error' => 'Cannot delete this request: it would result in negative stock. Current stock: ' . $currentStock
                        ], 400);
                    }
                    
                    $product->setStockQuantity($newStock);
                }
            }

            // Store info before deletion for logging
            $requestId = $stockRequest->getId();
            $productName = $stockRequest->getProduct()?->getName() ?? 'Unknown';
            $quantity = $stockRequest->getQuantity();

            $entityManager->remove($stockRequest);
            $entityManager->flush();

            // Log activity for admin/staff
            $currentUser = $this->getCurrentUser($request, $entityManager);
            if ($currentUser) {
                $this->activityLogService->logDelete(
                    $currentUser,
                    'StockRequest',
                    $requestId,
                    "Product: {$productName}, Quantity: {$quantity}",
                    $request
                );
            }

            return $this->json([
                'message' => 'Stock request deleted successfully',
                'id' => $id
            ]);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to delete: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/accept', name: 'stock_request_accept', methods: ['POST'])]
    public function accept(
        int $id,
        Request $request,
        StockRequestRepository $repository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        try {
            $stockRequest = $repository->find($id);

            if (!$stockRequest) {
                return $this->json(['error' => 'Stock request not found'], 404);
            }

            if (!$stockRequest->isPending()) {
                return $this->json([
                    'error' => 'Only pending requests can be accepted'
                ], 400);
            }

            // The accept() method in entity already handles stock increase
            $stockRequest->accept();
            
            $entityManager->flush();

            // Log activity for admin/staff
            $currentUser = $this->getCurrentUser($request, $entityManager);
            if ($currentUser) {
                $productName = $stockRequest->getProduct()?->getName() ?? 'Unknown';
                $quantity = $stockRequest->getQuantity();
                $this->activityLogService->log(
                    $currentUser,
                    'UPDATE',
                    "StockRequest: Accepted - Product: {$productName}, Quantity: {$quantity} (ID: {$stockRequest->getId()})",
                    $request
                );
            }

            return $this->json([
                'message' => 'Stock request accepted successfully',
                'request' => [
                    'id' => $stockRequest->getId(),
                    'status' => $stockRequest->getStatus(),
                    'respondedAt' => $stockRequest->getRespondedAt()?->format('Y-m-d H:i:s')
                ]
            ], 200);

        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to accept request: ' . $e->getMessage()], 500);
        }
    }

    #[Route('/{id}/decline', name: 'stock_request_decline', methods: ['POST'])]
    public function decline(
        int $id,
        Request $request,
        StockRequestRepository $repository,
        EntityManagerInterface $entityManager
    ): JsonResponse {
        try {
            $stockRequest = $repository->find($id);

            if (!$stockRequest) {
                return $this->json(['error' => 'Stock request not found'], 404);
            }

            if (!$stockRequest->isPending()) {
                return $this->json([
                    'error' => 'Only pending requests can be declined'
                ], 400);
            }

            $stockRequest->decline();
            $entityManager->flush();

            // Log activity for admin/staff
            $currentUser = $this->getCurrentUser($request, $entityManager);
            if ($currentUser) {
                $productName = $stockRequest->getProduct()?->getName() ?? 'Unknown';
                $quantity = $stockRequest->getQuantity();
                $this->activityLogService->log(
                    $currentUser,
                    'UPDATE',
                    "StockRequest: Declined - Product: {$productName}, Quantity: {$quantity} (ID: {$stockRequest->getId()})",
                    $request
                );
            }

            return $this->json([
                'message' => 'Stock request declined successfully',
                'request' => [
                    'id' => $stockRequest->getId(),
                    'status' => $stockRequest->getStatus(),
                    'respondedAt' => $stockRequest->getRespondedAt()?->format('Y-m-d H:i:s')
                ]
            ], 200);

        } catch (\LogicException $e) {
            return $this->json(['error' => $e->getMessage()], 400);
        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to decline request: ' . $e->getMessage()], 500);
        }
    }

    #[Route('', name: 'stock_request_list', methods: ['GET'])]
    public function list(StockRequestRepository $repository): JsonResponse
    {
        try {
            $requests = $repository->findAll();
            
            $data = array_map(function ($request) {
                $supplier = $request->getSupplier();
                $supplierName = 'Unknown';
                
                if ($supplier) {
                    if (method_exists($supplier, 'getCompanyName') && $supplier->getCompanyName()) {
                        $supplierName = $supplier->getCompanyName();
                    } elseif (method_exists($supplier, 'getName') && $supplier->getName()) {
                        $supplierName = $supplier->getName();
                    }
                }
                
                return [
                    'id' => $request->getId(),
                    'product' => [
                        'id' => $request->getProduct()?->getId(),
                        'name' => $request->getProduct()?->getName()
                    ],
                    'supplier' => [
                        'id' => $supplier?->getId(),
                        'name' => $supplierName
                    ],
                    'productName' => $request->getProduct()?->getName(),
                    'quantity' => $request->getQuantity(),
                    'unitPrice' => $request->getUnitPrice(),
                    'totalPrice' => $request->getTotalPrice(),
                    'status' => $request->getStatus(),
                    'createdAt' => $request->getRequestedAt()?->format('Y-m-d H:i:s'),
                    'respondedAt' => $request->getRespondedAt()?->format('Y-m-d H:i:s'),
                    'notes' => $request->getNotes()
                ];
            }, $requests);

            return $this->json($data, 200);

        } catch (\Exception $e) {
            return $this->json(['error' => 'Failed to fetch requests: ' . $e->getMessage()], 500);
        }
    }
}