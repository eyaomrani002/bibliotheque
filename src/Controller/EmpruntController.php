<?php

namespace App\Controller;

use App\Entity\Emprunt;
use App\Form\EmpruntType;
use App\Repository\EmpruntRepository;
use App\Repository\LivreRepository;
use Symfony\Component\HttpFoundation\JsonResponse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/emprunt')]
final class EmpruntController extends AbstractController
{
    #[Route(name: 'app_emprunt_index', methods: ['GET'])]
    public function index(EmpruntRepository $empruntRepository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();

        // récupérer tous les emprunts de l'utilisateur
        $all = $empruntRepository->findBy(['user' => $user], ['dateEmprunt' => 'DESC']);

        $empruntsEnCours = array_filter($all, function(Emprunt $e) {
            return $e->getStatut() === 'emprunté';
        });

        $historique = array_filter($all, function(Emprunt $e) {
            return $e->getStatut() !== 'emprunté';
        });

        return $this->render('emprunt/index.html.twig', [
            'empruntsEnCours' => $empruntsEnCours,
            'historique' => $historique,
        ]);
    }

    #[Route('/new', name: 'app_emprunt_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $emprunt = new Emprunt();
        // On crée le formulaire public sans le champ 'user' (sera défini automatiquement)
        $form = $this->createForm(EmpruntType::class, $emprunt, ['include_user' => false]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            // Associer l'emprunt à l'utilisateur connecté
            $user = $this->getUser();
            if (!$user) {
                $this->addFlash('error', 'Vous devez être connecté pour emprunter un livre.');
                return $this->redirectToRoute('app_login');
            }
            $emprunt->setUser($user);

            // Si le statut n'a pas été renseigné, définir 'emprunté' par défaut
            if (!$emprunt->getStatut()) {
                $emprunt->setStatut('emprunté');
            }

            $entityManager->persist($emprunt);
            $entityManager->flush();

            return $this->redirectToRoute('app_emprunt_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('emprunt/new.html.twig', [
            'emprunt' => $emprunt,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_emprunt_show', methods: ['GET'])]
    public function show(Emprunt $emprunt): Response
    {
        return $this->render('emprunt/show.html.twig', [
            'emprunt' => $emprunt,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_emprunt_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Emprunt $emprunt, EntityManagerInterface $entityManager): Response
    {
        $form = $this->createForm(EmpruntType::class, $emprunt);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            return $this->redirectToRoute('app_emprunt_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('emprunt/edit.html.twig', [
            'emprunt' => $emprunt,
            'form' => $form,
        ]);
    }

    #[Route('/{id}', name: 'app_emprunt_delete', methods: ['POST'])]
    public function delete(Request $request, Emprunt $emprunt, EntityManagerInterface $entityManager): Response
    {
        $token = $request->request->get('_token');
        if ($this->isCsrfTokenValid('delete'.$emprunt->getId(), $token)) {
            $entityManager->remove($emprunt);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_emprunt_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/{id}/prolonger', name: 'app_emprunt_prolonger', methods: ['POST'])]
    public function prolonger(Request $request, Emprunt $emprunt, EntityManagerInterface $entityManager): Response
    {
        $token = $request->request->get('_token');
        if (!$this->isCsrfTokenValid('prolonger'.$emprunt->getId(), $token)) {
            $this->addFlash('error', 'Jeton CSRF invalide.');
            return $this->redirectToRoute('app_emprunt_index', [], Response::HTTP_SEE_OTHER);
        }

        $user = $this->getUser();
        if ($user !== $emprunt->getUser() && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas prolonger cet emprunt.');
        }

        if ($emprunt->estEnRetard()) {
            $this->addFlash('error', 'Impossible de prolonger un emprunt en retard.');
            return $this->redirectToRoute('app_emprunt_index', [], Response::HTTP_SEE_OTHER);
        }

        // Prolonger de 7 jours
        $dateRetour = $emprunt->getDateRetourPrevue();
        $newDate = (new \DateTime())->setTimestamp($dateRetour->getTimestamp());
        $newDate->modify('+7 days');
        $emprunt->setDateRetourPrevue($newDate);

        $entityManager->flush();

        $this->addFlash('success', 'Date de retour prolongée de 7 jours.');

        return $this->redirectToRoute('app_emprunt_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/emprunter/{livreId}', name: 'app_emprunt_emprunter', methods: ['POST'])]
    public function emprunter(int $livreId, Request $request, EntityManagerInterface $entityManager, LivreRepository $livreRepository): JsonResponse
    {
        $user = $this->getUser();
        if (!$user) {
            return new JsonResponse(['success' => false, 'message' => 'Authentification requise'], 401);
        }

        $livre = $livreRepository->find($livreId);
        if (!$livre) {
            return new JsonResponse(['success' => false, 'message' => 'Livre introuvable'], 404);
        }

        // Vérifier disponibilité
        if ($livre->getExemplairesDisponibles() <= 0) {
            return new JsonResponse(['success' => false, 'message' => 'Aucun exemplaire disponible'], 400);
        }

        $emprunt = new Emprunt();
        $emprunt->setUser($user)->setLivre($livre);

        $entityManager->persist($emprunt);
        $entityManager->flush();

        return new JsonResponse(['success' => true, 'message' => 'Emprunt créé avec succès']);
    }
}
