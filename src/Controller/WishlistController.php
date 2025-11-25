<?php

namespace App\Controller;

use App\Entity\Wishlist;
use App\Form\WishlistType;
use App\Repository\WishlistRepository;
use App\Repository\LivreRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/wishlist')]
final class WishlistController extends AbstractController
{
    #[Route(name: 'app_wishlist_index', methods: ['GET'])]
    public function index(WishlistRepository $wishlistRepository): Response
    {
        return $this->render('wishlist/index.html.twig', [
            'wishlists' => $wishlistRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_wishlist_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $wishlist = new Wishlist();
        $form = $this->createForm(WishlistType::class, $wishlist);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($wishlist);
            $entityManager->flush();

            return $this->redirectToRoute('app_wishlist_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('wishlist/new.html.twig', [
            'wishlist' => $wishlist,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_wishlist_show', methods: ['GET'])]
    public function show(Wishlist $wishlist): Response
    {
        return $this->render('wishlist/show.html.twig', [
            'wishlist' => $wishlist,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_wishlist_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Wishlist $wishlist, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(WishlistType::class, $wishlist);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_wishlist_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('wishlist/edit.html.twig', [
            'wishlist' => $wishlist,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_wishlist_delete', methods: ['POST'])]
    public function delete(Request $request, Wishlist $wishlist, EntityManagerInterface $entityManager): Response
    {
        if ($this->isCsrfTokenValid('delete'.$wishlist->getId(), $request->getPayload()->getString('_token'))) {
            $entityManager->remove($wishlist);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_wishlist_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/add/{livreId}', name: 'app_wishlist_add', methods: ['POST'])]
    public function add(int $livreId, Request $request, EntityManagerInterface $entityManager, LivreRepository $livreRepository, WishlistRepository $wishlistRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'Authentification requise'], 401);
        }

        $livre = $livreRepository->find($livreId);
        if (!$livre) {
            return new JsonResponse(['success' => false, 'message' => 'Livre introuvable'], 404);
        }

        // check existing
        $existing = $wishlistRepository->findOneBy(['user' => $user, 'livre' => $livre]);
        if ($existing) {
            return new JsonResponse(['success' => false, 'message' => 'Déjà dans la wishlist'], 400);
        }

        $wishlist = new Wishlist();
        $wishlist->setUser($user)->setLivre($livre);

        $entityManager->persist($wishlist);
        $entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Ajouté à la wishlist']);
    }

    #[Route('/remove/{livreId}', name: 'app_wishlist_remove', methods: ['POST'])]
    public function remove(int $livreId, Request $request, EntityManagerInterface $entityManager, LivreRepository $livreRepository, WishlistRepository $wishlistRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'Authentification requise'], 401);
        }

        $livre = $livreRepository->find($livreId);
        if (!$livre) {
            return new JsonResponse(['success' => false, 'message' => 'Livre introuvable'], 404);
        }

        $existing = $wishlistRepository->findOneBy(['user' => $user, 'livre' => $livre]);
        if (!$existing) {
            return new JsonResponse(['success' => false, 'message' => 'Pas dans la wishlist'], 400);
        }

        $entityManager->remove($existing);
        $entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Retiré de la wishlist']);
    }
}
