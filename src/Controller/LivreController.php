<?php

namespace App\Controller;

use App\Entity\Livre;
use App\Form\LivreType;
use App\Repository\LivreRepository;
use App\Service\NotificationService;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\String\Slugger\SluggerInterface;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;

#[Route('/livre')]
final class LivreController extends AbstractController
{
    public function __construct(
        private NotificationService $notificationService,
        private UserRepository $userRepository
    ) {}

    private function getUploadsDirectory(): string
    {
        // Use the livres uploads directory so templates referencing /uploads/livres/ work
        return $this->getParameter('uploads_livres');
    }

    #[Route(name: 'app_livre_index', methods: ['GET'])]
    public function index(LivreRepository $livreRepository): Response
    {
        return $this->render('livre/index.html.twig', [
            'livres' => $livreRepository->findAll(),
        ]);
    }

    #[Route('/new', name: 'app_livre_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $livre = new Livre();
        $form = $this->createForm(LivreType::class, $livre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleImageUpload($form, $livre, $slugger);
            $this->handlePdfUpload($form, $livre, $slugger);

            $em->persist($livre);
            $em->flush();

            // ✅ NOUVEAU : Notifier tous les utilisateurs lorsqu'un livre est ajouté via le formulaire public
            $title = '📚 Nouveau livre disponible !';
            $message = 'Le livre "' . $livre->getTitre() . '" a été ajouté à la bibliothèque.';
            
            $this->notificationService->createForAllUsers(
                $this->userRepository, 
                $title, 
                $message, 
                'success', 
                [
                    'livreId' => $livre->getId(),
                    'actionUrl' => $this->generateUrl('app_livre_show', ['id' => $livre->getId()])
                ], 
                false
            );

            $this->addFlash('success', 'Livre ajouté avec succès et notification envoyée aux utilisateurs !');
            return $this->redirectToRoute('app_livre_index');
        }

        return $this->render('livre/new.html.twig', [
            'livre' => $livre,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}/edit', name: 'app_livre_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, Livre $livre, EntityManagerInterface $em, SluggerInterface $slugger): Response
    {
        $form = $this->createForm(LivreType::class, $livre);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $this->handleImageUpload($form, $livre, $slugger);
            $this->handlePdfUpload($form, $livre, $slugger);
            $em->flush();

            $this->addFlash('success', 'Livre modifié avec succès !');
            return $this->redirectToRoute('app_livre_index');
        }

        return $this->render('livre/edit.html.twig', [
            'livre' => $livre,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_livre_delete', methods: ['POST'])]
    public function delete(Request $request, Livre $livre, EntityManagerInterface $em): Response
    {
        if ($this->isCsrfTokenValid('delete' . $livre->getId(), $request->request->get('_token'))) {
            if ($livre->getImage()) {
                $filePath = $this->getUploadsDirectory() . '/' . $livre->getImage();
                if (file_exists($filePath)) {
                    unlink($filePath);
                }
            }
            if ($livre->getPdf()) {
                $pdfPath = $this->getUploadsDirectory() . '/' . $livre->getPdf();
                if (file_exists($pdfPath)) {
                    unlink($pdfPath);
                }
            }
            $em->remove($livre);
            $em->flush();
            $this->addFlash('success', 'Livre supprimé.');
        }
        return $this->redirectToRoute('app_livre_index');
    }

    private function handleImageUpload($form, Livre $livre, SluggerInterface $slugger): void
    {
        // The ImageUploadType is a small form with a child 'file'.
        $file = null;
        if ($form->has('imageFile')) {
            $imageForm = $form->get('imageFile');
            if ($imageForm->has('file')) {
                $file = $imageForm->get('file')->getData();
            } else {
                // fallback: some setups return the file directly on the compound field
                $file = $imageForm->getData();
            }
        }

        // Suppression manuelle demandée ? (note: this checkbox is not part of the Symfony form,
        // so the template may submit a top-level 'delete_image' field; checking form won't find it.)
        // We'll ignore delete_image here because it's handled by request in the form template flow.
        if ($form->has('delete_image') && $form->get('delete_image')->getData()) {
            if ($livre->getImage()) {
                $oldPath = $this->getUploadsDirectory() . '/' . $livre->getImage();
                if (file_exists($oldPath)) unlink($oldPath);
                $livre->setImage(null);
            }
            return;
        }
        if ($file) {
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $slugger->slug($originalFilename);
            $newFilename = $safeFilename . '-' . uniqid() . '.' . $file->guessExtension();

            try {
                // Move into the livres folder (public/uploads/livres)
                $file->move($this->getUploadsDirectory(), $newFilename);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de l\'upload : ' . $e->getMessage());
                return;
            }

            // Supprimer l'ancienne image
            if ($oldImage = $livre->getImage()) {
                $oldPath = $this->getUploadsDirectory() . '/' . $oldImage;
                if (file_exists($oldPath)) unlink($oldPath);
            }

            $livre->setImage($newFilename);
        }
    }

    private function handlePdfUpload($form, Livre $livre, SluggerInterface $slugger): void
    {
        $file = null;
        if ($form->has('pdfFile')) {
            $pdfForm = $form->get('pdfFile');
            if ($pdfForm->has('file')) {
                $file = $pdfForm->get('file')->getData();
            } else {
                $file = $pdfForm->getData();
            }
        }

        if ($form->has('delete_pdf') && $form->get('delete_pdf')->getData()) {
            if ($livre->getPdf()) {
                $oldPath = $this->getUploadsDirectory() . '/' . $livre->getPdf();
                if (file_exists($oldPath)) unlink($oldPath);
                $livre->setPdf(null);
            }
            return;
        }

        if ($file) {
            $originalFilename = pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME);
            $safeFilename = $slugger->slug($originalFilename);
            $extension = $file->guessExtension() ?: 'pdf';
            $newFilename = $safeFilename . '-' . uniqid() . '.' . $extension;

            try {
                $file->move($this->getUploadsDirectory(), $newFilename);
            } catch (\Exception $e) {
                $this->addFlash('error', 'Erreur lors de l\'upload du PDF : ' . $e->getMessage());
                return;
            }

            if ($oldPdf = $livre->getPdf()) {
                $oldPath = $this->getUploadsDirectory() . '/' . $oldPdf;
                if (file_exists($oldPath)) unlink($oldPath);
            }

            $livre->setPdf($newFilename);
        }
    }

    #[Route('/{id}', name: 'app_livre_show', methods: ['GET'])]
    public function show(Livre $livre): Response
    {
        return $this->render('livre/show.html.twig', [
            'livre' => $livre,
        ]);
    }

    #[Route('/{id}/download', name: 'app_livre_download', methods: ['GET'])]
    public function download(Livre $livre): BinaryFileResponse
    {
        $pdf = $livre->getPdf();
        if (!$pdf) {
            throw $this->createNotFoundException('Aucun PDF disponible pour ce livre.');
        }

        $path = $this->getUploadsDirectory() . '/' . $pdf;
        if (!file_exists($path)) {
            throw $this->createNotFoundException('Fichier PDF introuvable.');
        }

        $response = new BinaryFileResponse($path);
        $safeFilename = preg_replace('/[^a-z0-9]+/i', '-', (string) $livre->getTitre());
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            $safeFilename . '.' . pathinfo($pdf, PATHINFO_EXTENSION)
        );

        return $response;
    }
}