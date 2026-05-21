<?php

namespace App\Controller;

use App\Entity\Product;
use App\Entity\User;
use App\Repository\ProductRepository;
use App\Repository\SupplierRepository;
use App\Repository\GroupRepository;
use App\Service\ActivityLogService;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\File\Exception\FileException;

#[Route('/api/products')]
class ProductController extends AbstractController
{
    private ActivityLogService $activityLogService;

    public function __construct(ActivityLogService $activityLogService)
    {
        $this->activityLogService = $activityLogService;
    }

    /**
     * Helper method to get current user from JWT token
     */
    private function getCurrentUser(Request $request, JwtService $jwtService, EntityManagerInterface $em): ?User
    {
        $authHeader = $request->headers->get('Authorization');
        
        if (!$authHeader || !str_starts_with($authHeader, 'Bearer ')) {
            return null;
        }

        $token = substr($authHeader, 7);

        try {
            $decoded = $jwtService->validateToken($token);
            $userId = $decoded['id'] ?? null;

            if (!$userId) {
                return null;
            }

            return $em->getRepository(User::class)->find($userId);
        } catch (\Exception $e) {
            return null;
        }
    }

    #[Route('', name: 'product_list', methods: ['GET'])]
    public function list(ProductRepository $productRepository): JsonResponse
    {
        try {
            $products = $productRepository->findAll();
            
            $productsData = array_map(function($product) {
                return [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'description' => $product->getDescription(),
                    'price' => $product->getPrice(),
                    'category' => $product->getCategory(),
                    'subcategory' => $product->getSubcategory(),
                    'image' => $product->getImage(),
                    'stockQuantity' => $product->getStockQuantity(),
                    'status' => $product->getStatus(),
                    'group' => $product->getGroup() ? [
                        'id' => $product->getGroup()->getId(),
                        'name' => $product->getGroup()->getName(),
                        'debutYear' => $product->getGroup()->getDebutYear(),
                        'supplier' => $product->getGroup()->getSupplier() ? [
                            'id' => $product->getGroup()->getSupplier()->getId(),
                            'companyName' => $product->getGroup()->getSupplier()->getCompanyName(),
                            'name' => $product->getGroup()->getSupplier()->getCompanyName(),
                            'email' => $product->getGroup()->getSupplier()->getEmail(),
                        ] : null,
                    ] : null,
                    'groupId' => $product->getGroup() ? $product->getGroup()->getId() : null,
                    'groupName' => $product->getGroupName(),
                    'supplier' => $product->getSupplier() ? [
                        'id' => $product->getSupplier()->getId(),
                        'companyName' => $product->getSupplier()->getCompanyName(),
                        'name' => $product->getSupplier()->getCompanyName(),
                        'email' => $product->getSupplier()->getEmail(),
                    ] : null,
                    'createdAt' => $product->getCreatedAt() ? $product->getCreatedAt()->format('Y-m-d H:i:s') : null,
                    'updatedAt' => $product->getUpdatedAt() ? $product->getUpdatedAt()->format('Y-m-d H:i:s') : null,
                ];
            }, $products);
            
            return $this->json($productsData);
            
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to fetch products: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'product_show', methods: ['GET'])]
    public function show(Product $product): JsonResponse
    {
        try {
            return $this->json([
                'id' => $product->getId(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'price' => $product->getPrice(),
                'category' => $product->getCategory(),
                'subcategory' => $product->getSubcategory(),
                'image' => $product->getImage(),
                'stockQuantity' => $product->getStockQuantity(),
                'status' => $product->getStatus(),
                'group' => $product->getGroup() ? [
                    'id' => $product->getGroup()->getId(),
                    'name' => $product->getGroup()->getName(),
                    'debutYear' => $product->getGroup()->getDebutYear(),
                    'supplier' => $product->getGroup()->getSupplier() ? [
                        'id' => $product->getGroup()->getSupplier()->getId(),
                        'companyName' => $product->getGroup()->getSupplier()->getCompanyName(),
                        'name' => $product->getGroup()->getSupplier()->getCompanyName(),
                        'email' => $product->getGroup()->getSupplier()->getEmail(),
                        'phone' => $product->getGroup()->getSupplier()->getPhone(),
                        'address' => $product->getGroup()->getSupplier()->getAddress(),
                    ] : null,
                ] : null,
                'groupId' => $product->getGroup() ? $product->getGroup()->getId() : null,
                'groupName' => $product->getGroupName(),
                'supplier' => $product->getSupplier() ? [
                    'id' => $product->getSupplier()->getId(),
                    'companyName' => $product->getSupplier()->getCompanyName(),
                    'name' => $product->getSupplier()->getCompanyName(),
                    'email' => $product->getSupplier()->getEmail(),
                    'phone' => $product->getSupplier()->getPhone(),
                    'address' => $product->getSupplier()->getAddress(),
                ] : null,
                'createdAt' => $product->getCreatedAt() ? $product->getCreatedAt()->format('Y-m-d H:i:s') : null,
                'updatedAt' => $product->getUpdatedAt() ? $product->getUpdatedAt()->format('Y-m-d H:i:s') : null,
            ]);
            
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to fetch product: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

#[Route('', name: 'product_create', methods: ['POST'])]
public function create(
    Request $request, 
    EntityManagerInterface $em,
    SupplierRepository $supplierRepository,
    GroupRepository $groupRepository,
    JwtService $jwtService
): JsonResponse
{
    // Check if request has file upload (multipart/form-data)
    $isFileUpload = $request->files->count() > 0;
    
    if ($isFileUpload) {
        // Handle form data
        $data = [
            'name' => $request->request->get('name'),
            'description' => $request->request->get('description'),
            'price' => $request->request->get('price'),
            'category' => $request->request->get('category'),
            'subcategory' => $request->request->get('subcategory'),
            'stockQuantity' => $request->request->get('stockQuantity'),
            'status' => $request->request->get('status'),
            'groupId' => $request->request->get('groupId'),
            'groupName' => $request->request->get('groupName'),
            'supplierId' => $request->request->get('supplierId'),
        ];
    } else {
        // Handle JSON data (backwards compatibility)
        $data = json_decode($request->getContent(), true);
        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }
    }

    // Validate required fields
    if (empty($data['name']) || empty($data['description']) || !isset($data['price'])) {
        return $this->json([
            'error' => 'Missing required fields: name, description, and price are required'
        ], Response::HTTP_BAD_REQUEST);
    }

    try {
        $product = new Product();
        $product->setName($data['name']);
        $product->setDescription($data['description']);
        $product->setPrice((float) $data['price']);
        
        // Handle file upload
        if ($isFileUpload && $request->files->get('image')) {
            $imageFile = $request->files->get('image');
            
            // Validate file using client-provided MIME type (avoids need for fileinfo extension)
            $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
            $maxFileSize = 5 * 1024 * 1024; // 5MB

            // FIX: Use getClientMimeType() instead of getMimeType() to avoid fileinfo dependency
            $mimeType = $imageFile->getClientMimeType();
            
            if (!in_array($mimeType, $allowedMimeTypes)) {
                return $this->json([
                    'error' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.'
                ], Response::HTTP_BAD_REQUEST);
            }
            
            if ($imageFile->getSize() > $maxFileSize) {
                return $this->json([
                    'error' => 'File size exceeds 5MB limit.'
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
            
            // Generate unique filename
            $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $this->sanitizeFilename($originalFilename);

            // FIX: Use getClientOriginalExtension() instead of guessExtension() to avoid fileinfo dependency
            $newFilename = $safeFilename . '-' . uniqid() . '.' . $imageFile->getClientOriginalExtension();
            
            // Move file to uploads directory
            try {
                $uploadsDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/products';
                
                // Create directory if it doesn't exist
                if (!is_dir($uploadsDirectory)) {
                    mkdir($uploadsDirectory, 0777, true);
                }
                
                $imageFile->move($uploadsDirectory, $newFilename);
                $product->setImage('/uploads/products/' . $newFilename);
            } catch (FileException $e) {
                return $this->json([
                    'error' => 'Failed to upload file: ' . $e->getMessage()
                ], Response::HTTP_INTERNAL_SERVER_ERROR);
            }
        } elseif (isset($data['image'])) {
            // Backwards compatibility: accept image URL
            $product->setImage($data['image']);
        }
        
        // Handle Group relationship
        if (isset($data['groupId']) && !empty($data['groupId'])) {
            $group = $groupRepository->find($data['groupId']);
            if ($group) {
                $product->setGroup($group);
                // Auto-set supplier from group
                if ($group->getSupplier()) {
                    $product->setSupplier($group->getSupplier());
                }
            }
        }
        
        // Backwards compatibility: accept groupName as string
        if (isset($data['groupName']) && !isset($data['groupId'])) {
            $product->setGroupName($data['groupName']);
        }
        
        if (isset($data['category'])) {
            $product->setCategory($data['category']);
        }
        if (isset($data['subcategory'])) {
            $product->setSubcategory($data['subcategory']);
        }
        if (isset($data['stockQuantity'])) {
            $product->setStockQuantity((int) $data['stockQuantity']);
        }
        if (isset($data['status'])) {
            $product->setStatus($data['status']);
        }

        // Manual supplier override
        if (isset($data['supplierId']) && !empty($data['supplierId']) && !isset($data['groupId'])) {
            $supplier = $supplierRepository->find($data['supplierId']);
            if ($supplier) {
                $product->setSupplier($supplier);
            }
        }

        $em->persist($product);
        $em->flush();

        // Log the create activity
        $currentUser = $this->getCurrentUser($request, $jwtService, $em);
        if ($currentUser) {
            $this->activityLogService->logCreate(
                $currentUser,
                'Product',
                $product->getId(),
                $product->getName(),
                $request
            );
        }

        return $this->json([
            'message' => 'Product created successfully',
            'product' => [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'price' => $product->getPrice(),
                'image' => $product->getImage(),
                'group' => $product->getGroup() ? [
                    'id' => $product->getGroup()->getId(),
                    'name' => $product->getGroup()->getName()
                ] : null,
                'groupName' => $product->getGroupName(),
                'category' => $product->getCategory(),
                'stockQuantity' => $product->getStockQuantity(),
                'status' => $product->getStatus(),
                'supplier' => $product->getSupplier() ? [
                    'id' => $product->getSupplier()->getId(),
                    'name' => $product->getSupplier()->getCompanyName()
                ] : null,
                'createdAt' => $product->getCreatedAt()->format('Y-m-d H:i:s')
            ]
        ], Response::HTTP_CREATED);

    } catch (\Exception $e) {
        return $this->json([
            'error' => 'Failed to create product: ' . $e->getMessage()
        ], Response::HTTP_INTERNAL_SERVER_ERROR);
    }
}
    #[Route('/{id}', name: 'product_update', methods: ['PUT', 'POST'])]
    public function update(
        Product $product,
        Request $request,
        EntityManagerInterface $em,
        SupplierRepository $supplierRepository,
        GroupRepository $groupRepository,
        JwtService $jwtService
    ): JsonResponse
    {
        // Check if this is a file upload or JSON request
        $isFileUpload = $request->files->count() > 0;
        
        if ($isFileUpload) {
            // Handle multipart/form-data
            $data = [
                'name' => $request->request->get('name'),
                'description' => $request->request->get('description'),
                'price' => $request->request->get('price'),
                'category' => $request->request->get('category'),
                'subcategory' => $request->request->get('subcategory'),
                'stockQuantity' => $request->request->get('stockQuantity'),
                'status' => $request->request->get('status'),
                'groupId' => $request->request->get('groupId'),
                'groupName' => $request->request->get('groupName'),
                'supplierId' => $request->request->get('supplierId'),
            ];
        } else {
            // Handle JSON data
            $data = json_decode($request->getContent(), true);
            if (!$data) {
                return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
            }
        }

        try {
            // Update basic fields
            if (isset($data['name'])) {
                $product->setName($data['name']);
            }
            if (isset($data['description'])) {
                $product->setDescription($data['description']);
            }
            if (isset($data['price'])) {
                $product->setPrice((float) $data['price']);
            }
            if (isset($data['category'])) {
                $product->setCategory($data['category']);
            }
            if (isset($data['subcategory'])) {
                $product->setSubcategory($data['subcategory']);
            }
            if (isset($data['stockQuantity'])) {
                $product->setStockQuantity((int) $data['stockQuantity']);
            }
            if (isset($data['status'])) {
                $product->setStatus($data['status']);
            }
            
            // Handle file upload for update
            if ($isFileUpload && $request->files->get('image')) {
                $imageFile = $request->files->get('image');
                
                // Validate file
                $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
                $maxFileSize = 5 * 1024 * 1024;
                
                if (!in_array($imageFile->getMimeType(), $allowedMimeTypes)) {
                    return $this->json([
                        'error' => 'Invalid file type. Only JPG, PNG, GIF, and WEBP are allowed.'
                    ], Response::HTTP_BAD_REQUEST);
                }
                
                if ($imageFile->getSize() > $maxFileSize) {
                    return $this->json([
                        'error' => 'File size exceeds 5MB limit.'
                    ], Response::HTTP_BAD_REQUEST);
                }
                
                // Delete old image if exists
                if ($product->getImage()) {
                    $oldImagePath = $this->getParameter('kernel.project_dir') . '/public' . $product->getImage();
                    if (file_exists($oldImagePath) && is_file($oldImagePath)) {
                        unlink($oldImagePath);
                    }
                }
                
                // Upload new image
                $originalFilename = pathinfo($imageFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = $this->sanitizeFilename($originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $imageFile->guessExtension();
                
                $uploadsDirectory = $this->getParameter('kernel.project_dir') . '/public/uploads/products';
                if (!is_dir($uploadsDirectory)) {
                    mkdir($uploadsDirectory, 0777, true);
                }
                
                try {
                    $imageFile->move($uploadsDirectory, $newFilename);
                    $product->setImage('/uploads/products/' . $newFilename);
                } catch (FileException $e) {
                    return $this->json([
                        'error' => 'Failed to upload file: ' . $e->getMessage()
                    ], Response::HTTP_INTERNAL_SERVER_ERROR);
                }
            } elseif (isset($data['image']) && !empty($data['image'])) {
                // Only update if a new image URL is provided
                $product->setImage($data['image']);
            }
            
            // Handle Group relationship
            if (isset($data['groupId'])) {
                if (!empty($data['groupId'])) {
                    $group = $groupRepository->find($data['groupId']);
                    if ($group) {
                        $product->setGroup($group);
                        // Auto-set supplier from group
                        if ($group->getSupplier()) {
                            $product->setSupplier($group->getSupplier());
                        }
                    } else {
                        return $this->json([
                            'error' => 'Group not found'
                        ], Response::HTTP_BAD_REQUEST);
                    }
                } else {
                    // If groupId is empty, remove the group relationship
                    $product->setGroup(null);
                }
            }
            
            if (isset($data['groupName'])) {
                $product->setGroupName($data['groupName']);
            }

            // Manual supplier override (only if not using group)
            if (isset($data['supplierId']) && !isset($data['groupId'])) {
                if (!empty($data['supplierId'])) {
                    $supplier = $supplierRepository->find($data['supplierId']);
                    if ($supplier) {
                        $product->setSupplier($supplier);
                    }
                } else {
                    $product->setSupplier(null);
                }
            }

            $em->flush();

            // Log the update activity
            $currentUser = $this->getCurrentUser($request, $jwtService, $em);
            if ($currentUser) {
                $this->activityLogService->logUpdate(
                    $currentUser,
                    'Product',
                    $product->getId(),
                    $product->getName(),
                    $request
                );
            }

            return $this->json([
                'message' => 'Product updated successfully',
                'product' => [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'description' => $product->getDescription(),
                    'price' => $product->getPrice(),
                    'category' => $product->getCategory(),
                    'stockQuantity' => $product->getStockQuantity(),
                    'image' => $product->getImage(),
                    'group' => $product->getGroup() ? [
                        'id' => $product->getGroup()->getId(),
                        'name' => $product->getGroup()->getName()
                    ] : null,
                    'supplier' => $product->getSupplier() ? [
                        'id' => $product->getSupplier()->getId(),
                        'companyName' => $product->getSupplier()->getCompanyName()
                    ] : null,
                ]
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to update product: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'product_delete', methods: ['DELETE'])]
    public function delete(
        Product $product, 
        EntityManagerInterface $em,
        Request $request,
        JwtService $jwtService
    ): JsonResponse
    {
        try {
            // Check for related records before deleting
            $stockRequests = $em->getRepository('App\Entity\StockRequest')
                ->findBy(['product' => $product]);
            
            if (count($stockRequests) > 0) {
                return $this->json([
                    'error' => 'Cannot delete this product because it has ' . count($stockRequests) . ' related stock request(s). Please remove or reassign those records first.',
                    'relatedRecords' => count($stockRequests)
                ], Response::HTTP_CONFLICT);
            }
            
            // Store product info before deletion for logging
            $productName = $product->getName();
            $productId = $product->getId();
            
            // Delete image file if exists
            if ($product->getImage()) {
                $imagePath = $this->getParameter('kernel.project_dir') . '/public' . $product->getImage();
                if (file_exists($imagePath) && is_file($imagePath)) {
                    @unlink($imagePath); // @ suppresses warnings if file can't be deleted
                }
            }
            
            $em->remove($product);
            $em->flush();

            // Log the delete activity
            $currentUser = $this->getCurrentUser($request, $jwtService, $em);
            if ($currentUser) {
                $this->activityLogService->logDelete(
                    $currentUser,
                    'Product',
                    $productId,
                    $productName,
                    $request
                );
            }

            return $this->json([
                'message' => 'Product deleted successfully'
            ]);
            
        } catch (\Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException $e) {
            return $this->json([
                'error' => 'Cannot delete this product because it is referenced by other records (orders, stock requests, etc.). Please remove those references first.'
            ], Response::HTTP_CONFLICT);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to delete product: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    /**
     * Sanitize filename by removing special characters
     * Alternative to transliterator_transliterate when intl extension is not available
     */
    private function sanitizeFilename(string $filename): string
    {
        // Convert to lowercase
        $filename = strtolower($filename);
        
        // Replace spaces with hyphens
        $filename = str_replace(' ', '-', $filename);
        
        // Remove all non-alphanumeric characters except hyphens and underscores
        $filename = preg_replace('/[^a-z0-9\-_]/', '', $filename);
        
        // Replace multiple consecutive hyphens with single hyphen
        $filename = preg_replace('/-+/', '-', $filename);
        
        // Trim hyphens from start and end
        $filename = trim($filename, '-');
        
        // If filename is empty after sanitization, use a default
        if (empty($filename)) {
            $filename = 'product';
        }
        
        return $filename;
    }
}