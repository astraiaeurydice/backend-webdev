<?php

namespace App\Controller;

use App\Entity\Product;
use App\Repository\ProductRepository;
use App\Repository\SupplierRepository;
use App\Repository\GroupRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/products')]
class ProductController extends AbstractController
{
    #[Route('', name: 'product_create', methods: ['POST'])]
    public function create(
        Request $request, 
        EntityManagerInterface $em,
        SupplierRepository $supplierRepository,
        GroupRepository $groupRepository
    ): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
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
            
            // Optional fields
            if (isset($data['image'])) {
                $product->setImage($data['image']);
            }
            
            // Handle Group relationship (NEW WAY)
            if (isset($data['groupId'])) {
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

            // Manual supplier override (optional, if you want to allow this)
            if (isset($data['supplierId']) && !isset($data['groupId'])) {
                $supplier = $supplierRepository->find($data['supplierId']);
                if ($supplier) {
                    $product->setSupplier($supplier);
                }
            }

            $em->persist($product);
            $em->flush();

            return $this->json([
                'message' => 'Product created successfully',
                'product' => [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'price' => $product->getPrice(),
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

    #[Route('', name: 'product_list', methods: ['GET'])]
    public function list(ProductRepository $repository): JsonResponse
    {
        $products = $repository->findAll();
        
        $data = array_map(function(Product $product) {
            return [
                'id' => $product->getId(),
                'name' => $product->getName(),
                'description' => $product->getDescription(),
                'price' => $product->getPrice(),
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
                ] : null
            ];
        }, $products);

        return $this->json($data);
    }

    #[Route('/{id}', name: 'product_show', methods: ['GET'])]
    public function show(Product $product): JsonResponse
    {
        return $this->json([
            'id' => $product->getId(),
            'name' => $product->getName(),
            'description' => $product->getDescription(),
            'price' => $product->getPrice(),
            'image' => $product->getImage(),
            'group' => $product->getGroup() ? [
                'id' => $product->getGroup()->getId(),
                'name' => $product->getGroup()->getName(),
                'supplier' => $product->getGroup()->getSupplier() ? [
                    'id' => $product->getGroup()->getSupplier()->getId(),
                    'companyName' => $product->getGroup()->getSupplier()->getCompanyName()
                ] : null
            ] : null,
            'groupName' => $product->getGroupName(),
            'category' => $product->getCategory(),
            'subcategory' => $product->getSubcategory(),
            'stockQuantity' => $product->getStockQuantity(),
            'status' => $product->getStatus(),
            'supplier' => $product->getSupplier() ? [
                'id' => $product->getSupplier()->getId(),
                'name' => $product->getSupplier()->getCompanyName(),
                'email' => $product->getSupplier()->getEmail()
            ] : null,
            'createdAt' => $product->getCreatedAt()->format('Y-m-d H:i:s'),
            'updatedAt' => $product->getUpdatedAt()->format('Y-m-d H:i:s')
        ]);
    }

    #[Route('/{id}', name: 'product_update', methods: ['PUT', 'PATCH'])]
    public function update(
        Product $product,
        Request $request,
        EntityManagerInterface $em,
        SupplierRepository $supplierRepository,
        GroupRepository $groupRepository
    ): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            if (isset($data['name'])) $product->setName($data['name']);
            if (isset($data['description'])) $product->setDescription($data['description']);
            if (isset($data['price'])) $product->setPrice((float) $data['price']);
            if (isset($data['image'])) $product->setImage($data['image']);
            
            // Handle Group relationship
            if (isset($data['groupId'])) {
                $group = $groupRepository->find($data['groupId']);
                $product->setGroup($group);
                // Auto-update supplier from group
                if ($group && $group->getSupplier()) {
                    $product->setSupplier($group->getSupplier());
                }
            }
            
            if (isset($data['groupName'])) $product->setGroupName($data['groupName']);
            if (isset($data['category'])) $product->setCategory($data['category']);
            if (isset($data['subcategory'])) $product->setSubcategory($data['subcategory']);
            if (isset($data['stockQuantity'])) $product->setStockQuantity((int) $data['stockQuantity']);
            if (isset($data['status'])) $product->setStatus($data['status']);

            // Manual supplier override
            if (isset($data['supplierId']) && !isset($data['groupId'])) {
                $supplier = $supplierRepository->find($data['supplierId']);
                $product->setSupplier($supplier);
            }

            $em->flush();

            return $this->json([
                'message' => 'Product updated successfully',
                'product' => [
                    'id' => $product->getId(),
                    'name' => $product->getName(),
                    'price' => $product->getPrice()
                ]
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to update product: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'product_delete', methods: ['DELETE'])]
    public function delete(Product $product, EntityManagerInterface $em): JsonResponse
    {
        try {
            $em->remove($product);
            $em->flush();

            return $this->json(['message' => 'Product deleted successfully']);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to delete product: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}