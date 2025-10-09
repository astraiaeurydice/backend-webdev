<?php

namespace App\Controller;

use App\Entity\StockRequest;
use App\Entity\Product;
use App\Entity\Supplier;
use App\Repository\StockRequestRepository;
use App\Repository\ProductRepository;
use App\Repository\SupplierRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/stock-requests')]
final class StockRequestController extends AbstractController
{
    /**
     * Get all stock requests with relations
     */
    #[Route('', name: 'api_stock_request_index', methods: ['GET'])]
    public function index(Request $request, StockRequestRepository $repo): JsonResponse
    {
        try {
            // Check for status filter
            $status = $request->query->get('status');
            
            if ($status) {
                $requests = $repo->findByStatus($status);
            } else {
                $requests = $repo->findAllWithRelations();
            }

            $data = array_map(function(StockRequest $r) {
                return [
                    'id' => $r->getId(),
                    'product' => [
                        'id' => $r->getProduct()->getId(),
                        'name' => $r->getProduct()->getName(),
                    ],
                    'supplier' => [
                        'id' => $r->getSupplier()->getId(),
                        'companyName' => $r->getSupplier()->getCompanyName(),
                    ],
                    'productName' => $r->getProduct()->getName(),
                    'quantity' => $r->getQuantity(),
                    'unitPrice' => $r->getUnitPrice(),
                    'totalPrice' => $r->getTotalPrice(),
                    'status' => $r->getStatus(),
                    'notes' => $r->getNotes(),
                    'createdAt' => $r->getRequestedAt()->format('Y-m-d H:i:s'),
                    'requestedAt' => $r->getRequestedAt()->format('Y-m-d H:i:s'),
                    'respondedAt' => $r->getRespondedAt()?->format('Y-m-d H:i:s'),
                ];
            }, $requests);

            return $this->json($data);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to fetch stock requests',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get a single stock request by ID
     */
    #[Route('/{id}', name: 'api_stock_request_show', methods: ['GET'])]
    public function show(int $id, StockRequestRepository $repo): JsonResponse
    {
        try {
            $req = $repo->find($id);
            
            if (!$req) {
                return $this->json(['error' => 'Request not found'], 404);
            }

            return $this->json([
                'id' => $req->getId(),
                'product' => [
                    'id' => $req->getProduct()->getId(),
                    'name' => $req->getProduct()->getName(),
                ],
                'supplier' => [
                    'id' => $req->getSupplier()->getId(),
                    'companyName' => $req->getSupplier()->getCompanyName(),
                ],
                'productName' => $req->getProduct()->getName(),
                'quantity' => $req->getQuantity(),
                'unitPrice' => $req->getUnitPrice(),
                'totalPrice' => $req->getTotalPrice(),
                'status' => $req->getStatus(),
                'notes' => $req->getNotes(),
                'createdAt' => $req->getRequestedAt()->format('Y-m-d H:i:s'),
                'requestedAt' => $req->getRequestedAt()->format('Y-m-d H:i:s'),
                'respondedAt' => $req->getRespondedAt()?->format('Y-m-d H:i:s'),
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to fetch stock request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get statistics for dashboard
     */
    #[Route('/statistics/overview', name: 'api_stock_request_statistics', methods: ['GET'])]
    public function statistics(StockRequestRepository $repo): JsonResponse
    {
        try {
            return $this->json($repo->getStatistics());
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to fetch statistics',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Admin creates a new stock request
     */
    #[Route('', name: 'api_stock_request_new', methods: ['POST'])]
    public function new(
        Request $request, 
        EntityManagerInterface $em, 
        ProductRepository $productRepo, 
        SupplierRepository $supplierRepo
    ): JsonResponse {
        try {
            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json(['error' => 'Invalid JSON data'], 400);
            }

            $product = $productRepo->find($data['product_id'] ?? 0);
            $supplier = $supplierRepo->find($data['supplier_id'] ?? 0);
            $quantity = $data['quantity'] ?? 0;
            $unitPrice = $data['unit_price'] ?? null;
            $notes = $data['notes'] ?? null;

            if (!$product) {
                return $this->json(['error' => 'Product not found'], 404);
            }

            if (!$supplier) {
                return $this->json(['error' => 'Supplier not found'], 404);
            }

            if ($quantity <= 0) {
                return $this->json(['error' => 'Quantity must be greater than 0'], 400);
            }

            $requestEntity = (new StockRequest())
                ->setProduct($product)
                ->setSupplier($supplier)
                ->setQuantity($quantity);

            if ($unitPrice !== null && $unitPrice > 0) {
                $requestEntity->setUnitPrice($unitPrice);
            }

            if ($notes) {
                $requestEntity->setNotes($notes);
            }

            $em->persist($requestEntity);
            $em->flush();

            return $this->json([
                'message' => 'Stock request created successfully',
                'id' => $requestEntity->getId(),
                'data' => [
                    'id' => $requestEntity->getId(),
                    'product' => [
                        'id' => $product->getId(),
                        'name' => $product->getName(),
                    ],
                    'supplier' => [
                        'id' => $supplier->getId(),
                        'companyName' => $supplier->getCompanyName(),
                    ],
                    'quantity' => $requestEntity->getQuantity(),
                    'unitPrice' => $requestEntity->getUnitPrice(),
                    'status' => $requestEntity->getStatus(),
                ]
            ], 201);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to create stock request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Update a stock request (only if pending)
     */
    #[Route('/{id}', name: 'api_stock_request_update', methods: ['PUT', 'PATCH'])]
    public function update(
        int $id, 
        Request $request, 
        StockRequestRepository $repo, 
        EntityManagerInterface $em,
        ProductRepository $productRepo, 
        SupplierRepository $supplierRepo
    ): JsonResponse {
        try {
            $req = $repo->find($id);
            
            if (!$req) {
                return $this->json(['error' => 'Request not found'], 404);
            }

            if (!$req->isPending()) {
                return $this->json(['error' => 'Only pending requests can be updated'], 400);
            }

            $data = json_decode($request->getContent(), true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return $this->json(['error' => 'Invalid JSON data'], 400);
            }

            if (isset($data['product_id'])) {
                $product = $productRepo->find($data['product_id']);
                if (!$product) {
                    return $this->json(['error' => 'Product not found'], 404);
                }
                $req->setProduct($product);
            }

            if (isset($data['supplier_id'])) {
                $supplier = $supplierRepo->find($data['supplier_id']);
                if (!$supplier) {
                    return $this->json(['error' => 'Supplier not found'], 404);
                }
                $req->setSupplier($supplier);
            }

            if (isset($data['quantity'])) {
                if ($data['quantity'] <= 0) {
                    return $this->json(['error' => 'Quantity must be greater than 0'], 400);
                }
                $req->setQuantity($data['quantity']);
            }

            if (isset($data['unit_price'])) {
                $req->setUnitPrice($data['unit_price']);
            }

            if (isset($data['notes'])) {
                $req->setNotes($data['notes']);
            }

            $em->flush();

            return $this->json([
                'message' => 'Stock request updated successfully',
                'data' => [
                    'id' => $req->getId(),
                    'quantity' => $req->getQuantity(),
                    'unitPrice' => $req->getUnitPrice(),
                    'status' => $req->getStatus(),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to update stock request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Delete a stock request (only if pending)
     */
    #[Route('/{id}', name: 'api_stock_request_delete', methods: ['DELETE'])]
    public function delete(int $id, StockRequestRepository $repo, EntityManagerInterface $em): JsonResponse
    {
        try {
            $req = $repo->find($id);
            
            if (!$req) {
                return $this->json(['error' => 'Request not found'], 404);
            }

            if (!$req->isPending()) {
                return $this->json(['error' => 'Only pending requests can be deleted'], 400);
            }

            $em->remove($req);
            $em->flush();

            return $this->json(['message' => 'Stock request deleted successfully']);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to delete stock request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Supplier accepts the request
     */
#[Route('/{id}/accept', name: 'api_stock_request_accept', methods: ['POST'])]
public function accept(
    int $id, 
    Request $request,
    StockRequestRepository $repo, 
    EntityManagerInterface $em
): JsonResponse {
    try {
        $req = $repo->find($id);
        
        if (!$req) {
            return $this->json(['error' => 'Request not found'], 404);
        }

        if (!$req->isPending()) {
            return $this->json([
                'error' => 'Only pending requests can be accepted',
                'current_status' => $req->getStatus()
            ], 400);
        }

        // Get optional data from request body
        $data = json_decode($request->getContent(), true) ?? [];
        
        if (isset($data['unit_price']) && $data['unit_price'] > 0) {
            $req->setUnitPrice($data['unit_price']);
        }

        if (isset($data['notes'])) {
            $req->setNotes($data['notes']);
        }

        // Accept the request and update stock
        $req->accept();
        
        // Get product and update stock quantity
        $product = $req->getProduct();
        $oldStock = $product->getStockQuantity() ?? 0;
        $newStock = $oldStock + $req->getQuantity();
        $product->setStockQuantity($newStock);

        // Persist and flush
        $em->persist($req);
        $em->persist($product);
        $em->flush();

        return $this->json([
            'message' => 'Stock request accepted and inventory updated',
            'data' => [
                'id' => $req->getId(),
                'status' => $req->getStatus(),
                'product' => [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'oldStock' => $oldStock,
                    'newStock' => $newStock,
                    'addedQuantity' => $req->getQuantity(),
                ],
                'respondedAt' => $req->getRespondedAt()?->format('Y-m-d H:i:s'),
            ]
        ], 200);
    } catch (\Exception $e) {
        // Rollback any changes if error occurs
        $em->clear();
        
        return $this->json([
            'error' => 'Failed to accept stock request',
            'message' => $e->getMessage(),
            'trace' => $_ENV['APP_ENV'] === 'dev' ? $e->getTraceAsString() : null
        ], 500);
    }
}

    /**
     * Supplier declines the request
     */
    #[Route('/{id}/decline', name: 'api_stock_request_decline', methods: ['POST'])]
    public function decline(
        int $id, 
        Request $request,
        StockRequestRepository $repo, 
        EntityManagerInterface $em
    ): JsonResponse {
        try {
            $req = $repo->find($id);
            
            if (!$req) {
                return $this->json(['error' => 'Request not found'], 404);
            }

            if (!$req->isPending()) {
                return $this->json([
                    'error' => 'Only pending requests can be declined',
                    'current_status' => $req->getStatus()
                ], 400);
            }

            // Get optional notes from request body
            $data = json_decode($request->getContent(), true) ?? [];
            
            if (isset($data['notes'])) {
                $req->setNotes($data['notes']);
            }

            // Use the entity's helper method
            $req->decline();
            
            $em->flush();

            return $this->json([
                'message' => 'Stock request declined',
                'data' => [
                    'id' => $req->getId(),
                    'status' => $req->getStatus(),
                    'respondedAt' => $req->getRespondedAt()?->format('Y-m-d H:i:s'),
                ]
            ]);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to decline stock request',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Bulk accept multiple requests
     */
 /**
 * Bulk accept multiple requests
 */
#[Route('/bulk/accept', name: 'api_stock_request_bulk_accept', methods: ['POST'])]
public function bulkAccept(
    Request $request,
    StockRequestRepository $repo,
    EntityManagerInterface $em
): JsonResponse {
    try {
        $data = json_decode($request->getContent(), true);
        $ids = $data['ids'] ?? [];

        if (empty($ids) || !is_array($ids)) {
            return $this->json(['error' => 'Invalid request IDs'], 400);
        }

        $results = [
            'success' => [],
            'failed' => [],
        ];

        foreach ($ids as $id) {
            $req = $repo->find($id);
            
            if (!$req) {
                $results['failed'][] = ['id' => $id, 'reason' => 'Not found'];
                continue;
            }

            if (!$req->isPending()) {
                $results['failed'][] = ['id' => $id, 'reason' => 'Not pending'];
                continue;
            }

            $req->accept();
            
            // Update product stock - FIXED: Use correct method names
            $product = $req->getProduct();
            $currentStock = $product->getStockQuantity() ?? 0;
            $product->setStockQuantity($currentStock + $req->getQuantity());

            $results['success'][] = $id;
        }

        $em->flush();

        return $this->json([
            'message' => 'Bulk accept completed',
            'results' => $results,
            'summary' => [
                'total' => count($ids),
                'accepted' => count($results['success']),
                'failed' => count($results['failed']),
            ]
        ]);
    } catch (\Exception $e) {
        return $this->json([
            'error' => 'Failed to process bulk accept',
            'message' => $e->getMessage()
        ], 500);
    }
}

    /**
     * Get requests by supplier
     */
    #[Route('/supplier/{supplierId}', name: 'api_stock_request_by_supplier', methods: ['GET'])]
    public function getBySupplier(int $supplierId, StockRequestRepository $repo): JsonResponse
    {
        try {
            $requests = $repo->findBy(['supplier' => $supplierId], ['requestedAt' => 'DESC']);

            $data = array_map(function(StockRequest $r) {
                return [
                    'id' => $r->getId(),
                    'product' => [
                        'id' => $r->getProduct()->getId(),
                        'name' => $r->getProduct()->getName(),
                    ],
                    'quantity' => $r->getQuantity(),
                    'unitPrice' => $r->getUnitPrice(),
                    'totalPrice' => $r->getTotalPrice(),
                    'status' => $r->getStatus(),
                    'requestedAt' => $r->getRequestedAt()->format('Y-m-d H:i:s'),
                    'respondedAt' => $r->getRespondedAt()?->format('Y-m-d H:i:s'),
                ];
            }, $requests);

            return $this->json($data);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to fetch supplier requests',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}