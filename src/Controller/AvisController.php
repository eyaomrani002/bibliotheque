<?php

namespace App\Controller;

use App\Entity\Avis;
use App\Form\AvisType;
use App\Repository\AvisRepository;
use App\Repository\LivreRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/avis')]
final class AvisController extends AbstractController
{
    #[Route(name: 'app_avis_index', methods: ['GET'])]
    public function index(AvisRepository $avisRepository): Response
    {
        return $this->render('avis/index.html.twig', [
            'avis' => $avisRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_avis_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager, LivreRepository $livreRepository): Response
    {
        $avi = new Avis();
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // if a livre id is provided as query parameter, prefill the livre
        $livreId = $request->query->get('livre');
        if ($livreId) {
            $livre = $livreRepository->find((int) $livreId);
            if ($livre) {
                $avi->setLivre($livre);
            }
        }

        $form = $this->createForm(AvisType::class, $avi, ['admin' => $this->isGranted('ROLE_ADMIN')]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // assign the current user automatically
            $user = $this->getUser();
            if ($user) {
                $avi->setUser($user);
            }

            // dateCreation is set in the entity constructor, no need to set here

            $entityManager->persist($avi);
            $entityManager->flush();

            return $this->redirectToRoute('app_avis_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('avis/new.html.twig', [
            'avi' => $avi,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_avis_show', methods: ['GET'])]
    public function show(Avis $avi): Response
    {
        return $this->render('avis/show.html.twig', [
            'avi' => $avi,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_avis_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Avis $avi, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(AvisType::class, $avi, ['admin' => $this->isGranted('ROLE_ADMIN')]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_avis_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('avis/edit.html.twig', [
            'avi' => $avi,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_avis_delete', methods: ['POST'])]
    public function delete(Request $request, Avis $avi, EntityManagerInterface $entityManager): Response
    {
        $token = $request->request->get('_token');
        if ($this->isCsrfTokenValid('delete'.$avi->getId(), $token)) {
            $entityManager->remove($avi);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_avis_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/ajouter/{livreId}', name: 'app_avis_ajouter', methods: ['POST'])]
    public function ajouter(int $livreId, Request $request, EntityManagerInterface $entityManager, LivreRepository $livreRepository): JsonResponse
    {
        $data = json_decode($request->getContent(), true);
        $note = isset($data['rating']) ? (int) $data['rating'] : null;
        $comment = $data['comment'] ?? null;

        if (!$note || $note < 1 || $note > 5) {
            return new JsonResponse(['success' => false, 'error' => 'Note invalide'], 400);
        }

        $livre = $livreRepository->find($livreId);
        if (!$livre) {
            return new JsonResponse(['success' => false, 'error' => 'Livre introuvable'], 404);
        }

        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'Authentification requise'], 401);
        }

        $avis = new Avis();
        $avis->setUser($user)
             ->setLivre($livre)
             ->setNote($note)
             ->setCommentaire($comment);

        $entityManager->persist($avis);
        $entityManager->flush();

        return new JsonResponse(['success' => true]);
    }

    #[Route('/modifier/{id}', name: 'app_avis_modifier', methods: ['POST'])]
    public function modifier(int $id, Request $request, EntityManagerInterface $entityManager, AvisRepository $avisRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['success' => false, 'error' => 'Authentification requise'], 401);
        }

        $avis = $avisRepository->find($id);
        if (!$avis) {
            return new JsonResponse(['success' => false, 'error' => 'Avis introuvable'], 404);
        }

        // Autorisation: propriétaire ou admin
        if ($avis->getUser() !== $user && !$this->isGranted('ROLE_ADMIN')) {
            return new JsonResponse(['success' => false, 'error' => 'Accès refusé'], 403);
        }

        // Vérifier token CSRF envoyé dans l'en-tête X-CSRF-TOKEN
        $csrfToken = $request->headers->get('X-CSRF-TOKEN');
        if (!$this->isCsrfTokenValid('edit_avis'.$avis->getId(), $csrfToken)) {
            return new JsonResponse(['success' => false, 'error' => 'Token CSRF invalide'], 400);
        }

        $data = json_decode($request->getContent(), true);
        $note = isset($data['rating']) ? (int) $data['rating'] : null;
        $comment = $data['comment'] ?? null;

        if (!$note || $note < 1 || $note > 5) {
            return new JsonResponse(['success' => false, 'error' => 'Note invalide'], 400);
        }

        $avis->setNote($note);
        $avis->setCommentaire($comment);

        $entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Avis modifié avec succès']);
    }
}
