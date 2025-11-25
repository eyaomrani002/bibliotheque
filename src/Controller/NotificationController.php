<?php

namespace App\Controller;

use App\Repository\NotificationRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/notifications')]
class NotificationController extends AbstractController
{
    public function __construct(
        private NotificationRepository $notificationRepository, 
        private EntityManagerInterface $em
    ) {
    }

    #[Route('/unread', name: 'notifications_unread', methods: ['GET'])]
    public function unread(): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $notifications = $this->notificationRepository->findUnreadByUser($user, 20);

        $data = array_map(function ($n) {
            return [
                'id' => $n->getId(),
                'title' => $n->getTitle(),
                'message' => $n->getMessage(),
                'type' => $n->getType(),
                'createdAt' => $n->getCreatedAt()->format('c'),
                'data' => $n->getData(),
            ];
        }, $notifications);

        return new JsonResponse(['count' => count($data), 'items' => $data]);
    }

    #[Route('/{id}/read', name: 'notifications_mark_read', methods: ['POST'])]
    public function markRead(Request $request, int $id): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $notification = $this->notificationRepository->find($id);
        
        if (!$notification || $notification->getUser() !== $this->getUser()) {
            return new JsonResponse(['ok' => false], 404);
        }

        $notification->setIsRead(true);
        $this->em->flush();

        return new JsonResponse(['ok' => true]);
    }

    #[Route('/mark-all-read', name: 'notifications_mark_all_read', methods: ['POST'])]
    public function markAllRead(): JsonResponse
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        $notifications = $this->notificationRepository->findUnreadByUser($user);
        
        foreach ($notifications as $notification) {
            $notification->setIsRead(true);
        }
        
        $this->em->flush();

        return new JsonResponse(['ok' => true, 'marked' => count($notifications)]);
    }
}