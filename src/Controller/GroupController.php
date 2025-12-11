<?php

namespace App\Controller;

use App\Entity\Group;
use App\Entity\User;
use App\Repository\GroupRepository;
use App\Repository\SupplierRepository;
use App\Service\ActivityLogService;
use App\Service\JwtService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/groups')]
class GroupController extends AbstractController
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
    #[Route('', name: 'group_list', methods: ['GET'])]
    public function list(GroupRepository $repository): JsonResponse
    {
        try {
            $groups = $repository->findAll();
            
            $data = array_map(function(Group $group) {
                return [
                    'id' => $group->getId(),
                    'name' => $group->getName(),
                    'debutYear' => $group->getDebutYear(),
                    'status' => $group->getStatus(),
                    'supplier' => $group->getSupplier() ? [
                        'id' => $group->getSupplier()->getId(),
                        'companyName' => $group->getSupplier()->getCompanyName(),
                        'name' => $group->getSupplier()->getCompanyName(), // For compatibility
                    ] : null
                ];
            }, $groups);

            return $this->json($data);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to fetch groups: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('', name: 'group_create', methods: ['POST'])]
    public function create(
        Request $request,
        EntityManagerInterface $em,
        SupplierRepository $supplierRepository
    ): JsonResponse
    {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        if (empty($data['name']) || empty($data['supplierId'])) {
            return $this->json([
                'error' => 'Missing required fields: name and supplierId are required'
            ], Response::HTTP_BAD_REQUEST);
        }

        try {
            $supplier = $supplierRepository->find($data['supplierId']);
            if (!$supplier) {
                return $this->json([
                    'error' => 'Supplier not found'
                ], Response::HTTP_NOT_FOUND);
            }

            $group = new Group();
            $group->setName($data['name']);
            $group->setSupplier($supplier);
            
            if (isset($data['debutYear'])) {
                $group->setDebutYear($data['debutYear']);
            }
            if (isset($data['status'])) {
                $group->setStatus($data['status']);
            }

            $em->persist($group);
            $em->flush();

            // Log activity for admin/staff
            $currentUser = $this->getCurrentUser($request, $em);
            if ($currentUser) {
                $this->activityLogService->logCreate(
                    $currentUser,
                    'Group',
                    $group->getId(),
                    $group->getName(),
                    $request
                );
            }

            return $this->json([
                'message' => 'Group created successfully',
                'group' => [
                    'id' => $group->getId(),
                    'name' => $group->getName(),
                    'debutYear' => $group->getDebutYear(),
                    'status' => $group->getStatus(),
                    'supplier' => [
                        'id' => $group->getSupplier()->getId(),
                        'companyName' => $group->getSupplier()->getCompanyName()
                    ]
                ]
            ], Response::HTTP_CREATED);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to create group: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/{id}', name: 'group_show', methods: ['GET'])]
    public function show(Group $group): JsonResponse
    {
        return $this->json([
            'id' => $group->getId(),
            'name' => $group->getName(),
            'debutYear' => $group->getDebutYear(),
            'status' => $group->getStatus(),
            'supplier' => $group->getSupplier() ? [
                'id' => $group->getSupplier()->getId(),
                'companyName' => $group->getSupplier()->getCompanyName(),
                'contactPerson' => $group->getSupplier()->getContactPerson(),
                'email' => $group->getSupplier()->getEmail(),
            ] : null
        ]);
    }

    #[Route('/{id}', name: 'group_update', methods: ['PUT', 'PATCH'])]
    public function update(
        Group $group,
        Request $request,
        EntityManagerInterface $em,
        SupplierRepository $supplierRepository
    ): JsonResponse {
        $data = json_decode($request->getContent(), true);

        if (!$data) {
            return $this->json(['error' => 'Invalid JSON'], Response::HTTP_BAD_REQUEST);
        }

        try {
            if (isset($data['name'])) {
                $group->setName($data['name']);
            }

            if (isset($data['debutYear'])) {
                $group->setDebutYear($data['debutYear']);
            }

            if (isset($data['status'])) {
                $group->setStatus($data['status']);
            }

            // ✅ When supplier changes, update both group and related products
            if (isset($data['supplierId'])) {
                $supplier = $supplierRepository->find($data['supplierId']);
                if (!$supplier) {
                    return $this->json(['error' => 'Supplier not found'], Response::HTTP_NOT_FOUND);
                }

                // Update the group's supplier
                $group->setSupplier($supplier);

                // ✅ Propagate supplier change to all products in this group
                foreach ($group->getProducts() as $product) {
                    $product->setSupplier($supplier);
                }
            }

            $em->flush();

            // Log activity for admin/staff
            $currentUser = $this->getCurrentUser($request, $em);
            if ($currentUser) {
                $this->activityLogService->logUpdate(
                    $currentUser,
                    'Group',
                    $group->getId(),
                    $group->getName(),
                    $request
                );
            }

            return $this->json([
                'message' => 'Group updated successfully',
                'group' => [
                    'id' => $group->getId(),
                    'name' => $group->getName(),
                    'debutYear' => $group->getDebutYear(),
                    'status' => $group->getStatus(),
                    'supplier' => $group->getSupplier() ? [
                        'id' => $group->getSupplier()->getId(),
                        'companyName' => $group->getSupplier()->getCompanyName()
                    ] : null
                ]
            ]);

        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to update group: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }


    #[Route('/{id}', name: 'group_delete', methods: ['DELETE'])]
    public function delete(Group $group, Request $request, EntityManagerInterface $em): JsonResponse
    {
        try {
            // Store info before deletion for logging
            $groupId = $group->getId();
            $groupName = $group->getName();

            $em->remove($group);
            $em->flush();

            // Log activity for admin/staff
            $currentUser = $this->getCurrentUser($request, $em);
            if ($currentUser) {
                $this->activityLogService->logDelete(
                    $currentUser,
                    'Group',
                    $groupId,
                    $groupName,
                    $request
                );
            }

            return $this->json(['message' => 'Group deleted successfully']);
        } catch (\Exception $e) {
            return $this->json([
                'error' => 'Failed to delete group: ' . $e->getMessage()
            ], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }
}