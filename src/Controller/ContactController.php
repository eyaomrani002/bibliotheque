<?php

namespace App\Controller;

use App\Entity\Contact;
use App\Form\ContactType;
use App\Form\ContactPublicType;
use App\Form\ContactAdminType;
use App\Repository\ContactRepository;
use App\Service\NotificationService;
use App\Repository\UserRepository;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/contact')]
final class ContactController extends AbstractController
{
    #[Route(name: 'app_contact_index', methods: ['GET'])]
    public function index(ContactRepository $contactRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('contact/index.html.twig', [
            'contacts' => $contactRepository->findAll(),
        ]);
    }

    #[Route('/mes-messages', name: 'app_contact_my', methods: ['GET'])]
    public function myMessages(ContactRepository $contactRepository): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Messages envoyés par l'utilisateur
        $messagesEnvoyes = $contactRepository->findBy([
            'email' => $user->getEmail(),
            'type' => 'user_to_admin'
        ], ['dateEnvoi' => 'DESC']);

        // Messages reçus de l'admin
        $messagesRecus = $contactRepository->findMessagesToUser($user);

        // Fusionner messages envoyés et reçus pour que le template montre
        // l'historique complet (y compris les messages admin->user).
        $contacts = array_merge($messagesEnvoyes, $messagesRecus);

        // Trier par date d'envoi desc (sécuriser si dateEnvoi est null)
        usort($contacts, function ($a, $b) {
            $da = $a->getDateEnvoi();
            $db = $b->getDateEnvoi();
            if ($da === $db) return 0;
            if ($da === null) return 1;
            if ($db === null) return -1;
            return $da < $db ? 1 : -1;
        });

        return $this->render('contact/my_messages.html.twig', [
            'messagesEnvoyes' => $messagesEnvoyes,
            'messagesRecus' => $messagesRecus,
            'contacts' => $contacts,
        ]);
    }

    #[Route('/new', name: 'app_contact_new', methods: ['GET', 'POST'])]
    public function new(Request $request, EntityManagerInterface $entityManager): Response
    {
        $contact = new Contact();
        // public form: don't expose admin-only fields
        $form = $this->createForm(ContactPublicType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($contact);
            $entityManager->flush();

            $this->addFlash('success', 'Votre message a bien été envoyé. Merci.');
            return $this->redirectToRoute('app_contact_my');
        }

        return $this->render('contact/new.html.twig', [
            'contact' => $contact,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_contact_show', methods: ['GET'])]
    public function show(?Contact $contact): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$contact) {
            $this->addFlash('error', 'Message introuvable.');
            return $this->redirectToRoute('app_contact_index');
        }

        return $this->render('contact/show.html.twig', [
            'contact' => $contact,
        ]);
    }

    #[Route('/{id}/edit', name: 'app_contact_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, ?Contact $contact, EntityManagerInterface $entityManager, MailerInterface $mailer, NotificationService $notificationService, UserRepository $userRepository): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$contact) {
            $this->addFlash('error', 'Message introuvable.');
            return $this->redirectToRoute('app_contact_index');
        }

        $originalReponse = $contact->getReponse();

        $form = $this->createForm(ContactType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $newReponse = $contact->getReponse();

            // ✅ CORRECTION : Si l'admin a fourni une réponse où il n'y en avait pas ou l'a modifiée
            if ($newReponse && $newReponse !== $originalReponse) {
                $contact->setDateReponse(new \DateTime());
                $contact->setEstLu(true);

                // Envoyer l'email à l'utilisateur
                $support = $this->getParameter('app.support_email');

                $email = (new Email())
                    ->from($support)
                    ->replyTo($support)
                    ->to($contact->getEmail())
                    ->subject('Réponse à votre message - Biblio-Symfony')
                    ->html($this->renderView('emails/contact_response.html.twig', [
                        'contact' => $contact,
                    ]));

                try {
                    $mailer->send($email);
                } catch (\Throwable $e) {
                    $this->addFlash('warning', 'Erreur lors de l\'envoi de l\'email de notification.');
                }

                // ✅ CORRECTION : Créer une notification in-app pour l'utilisateur s'il existe dans le système
                $user = $userRepository->findOneBy(['email' => $contact->getEmail()]);
                if ($user) {
                    $title = '📩 Réponse à votre message';
                    $messageText = 'L\'administrateur a répondu à votre message : "' . $contact->getSujet() . '"';
                    
                    // ✅ NOUVEAU : Créer la notification avec un lien vers le message
                    $notificationService->createForUser(
                        $user, 
                        $title, 
                        $messageText, 
                        'info', 
                        [
                            'contactId' => $contact->getId(),
                            'actionUrl' => $this->generateUrl('app_contact_my', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)
                        ], 
                        false // Ne pas envoyer d'email car on l'a déjà fait
                    );
                    
                    $this->addFlash('info', 'Notification envoyée à l\'utilisateur.');
                }
            }

            $entityManager->flush();

            $this->addFlash('success', 'Message mis à jour avec succès.');

            return $this->redirectToRoute('app_contact_index', [], Response::HTTP_SEE_OTHER);
        }

        return $this->render('contact/edit.html.twig', [
            'contact' => $contact,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/{id}', name: 'app_contact_delete', methods: ['POST'])]
    public function delete(Request $request, ?Contact $contact, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$contact) {
            $this->addFlash('error', 'Message introuvable.');
            return $this->redirectToRoute('app_contact_index');
        }

        $token = $request->request->get('_token');
        if ($this->isCsrfTokenValid('delete'.$contact->getId(), $token)) {
            $entityManager->remove($contact);
            $entityManager->flush();
        }

        return $this->redirectToRoute('app_contact_index', [], Response::HTTP_SEE_OTHER);
    }

    #[Route('/thank-you', name: 'app_contact_thankyou', methods: ['GET'])]
    public function thankyou(): Response
    {
        return $this->render('contact/thankyou.html.twig');
    }

    #[Route('/mes-messages/{id}/edit', name: 'app_contact_my_edit', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function myEdit(Request $request, Contact $contact, EntityManagerInterface $entityManager): Response
    {
        // Vérifier que l'utilisateur est propriétaire du message
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        
        if ($contact->getEmail() !== $user->getEmail()) {
            $this->addFlash('error', 'Vous ne pouvez pas modifier ce message.');
            return $this->redirectToRoute('app_contact_my');
        }

        // Vérifier que le message n'a pas encore de réponse
        if ($contact->getReponse()) {
            $this->addFlash('warning', 'Vous ne pouvez pas modifier un message qui a déjà reçu une réponse.');
            return $this->redirectToRoute('app_contact_my');
        }

        $form = $this->createForm(ContactPublicType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->flush();

            $this->addFlash('success', 'Votre message a été modifié avec succès.');
            return $this->redirectToRoute('app_contact_my');
        }

        return $this->render('contact/edit.html.twig', [
            'contact' => $contact,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/mes-messages/{id}', name: 'app_contact_my_delete', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function myDelete(Request $request, Contact $contact, EntityManagerInterface $entityManager): Response
    {
        // Vérifier que l'utilisateur est propriétaire du message
        /** @var \App\Entity\User $user */
        $user = $this->getUser();
        
        if ($contact->getEmail() !== $user->getEmail()) {
            $this->addFlash('error', 'Vous ne pouvez pas supprimer ce message.');
            return $this->redirectToRoute('app_contact_my');
        }

        $token = $request->request->get('_token');
        if ($this->isCsrfTokenValid('delete'.$contact->getId(), $token)) {
            $entityManager->remove($contact);
            $entityManager->flush();
            
            $this->addFlash('success', 'Votre message a été supprimé avec succès.');
        } else {
            $this->addFlash('error', 'Token de sécurité invalide.');
        }

        return $this->redirectToRoute('app_contact_my');
    }

    // ✅ NOUVELLES METHODES : Envoi de messages admin→user

    #[Route('/admin/new-to-user', name: 'app_contact_admin_new', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminNewToUser(Request $request, EntityManagerInterface $entityManager, NotificationService $notificationService): Response
    {
        $contact = new Contact();
        $contact->setType('admin_to_user');
        // Les champs nom, prenom, email seront automatiquement remplis avec les infos admin
        $contact->setNom('Administrateur');
        $contact->setPrenom('Système');
        $contact->setEmail($this->getUser()->getUserIdentifier());

        $form = $this->createForm(ContactAdminType::class, $contact);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entityManager->persist($contact);
            $entityManager->flush();

            // Notifier l'utilisateur
            if ($contact->getDestinataire()) {
                $notificationService->createForUser(
                    $contact->getDestinataire(),
                    '📧 ' . $contact->getSujet(),
                    $contact->getMessage(),
                    'info',
                    [
                        'contactId' => $contact->getId(),
                        'actionUrl' => $this->generateUrl('app_contact_show_message', ['id' => $contact->getId()])
                    ],
                    true // Envoyer un email
                );
            }

            $this->addFlash('success', sprintf(
                'Message envoyé à %s avec succès !',
                $contact->getDestinataire()->getEmail()
            ));

            return $this->redirectToRoute('app_contact_admin_messages');
        }

        return $this->render('contact/admin_new.html.twig', [
            'contact' => $contact,
            'form' => $form->createView(),
        ]);
    }

    #[Route('/admin/messages', name: 'app_contact_admin_messages', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function adminMessages(ContactRepository $contactRepository): Response
    {
        $messages = $contactRepository->createQueryBuilder('c')
            ->andWhere('c.type = :type')
            ->setParameter('type', 'admin_to_user')
            ->orderBy('c.dateEnvoi', 'DESC')
            ->getQuery()
            ->getResult();

        return $this->render('contact/admin_messages.html.twig', [
            'messages' => $messages,
        ]);
    }

    #[Route('/message/{id}', name: 'app_contact_show_message', methods: ['GET'])]
    #[IsGranted('IS_AUTHENTICATED_FULLY')]
    public function showMessage(Contact $contact, EntityManagerInterface $entityManager): Response
    {
        /** @var \App\Entity\User $user */
        $user = $this->getUser();

        // Vérifier les permissions
        if ($contact->isFromAdmin() && $contact->getDestinataire() !== $user) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas accéder à ce message.');
        }

        if ($contact->isFromUser() && !$this->isGranted('ROLE_ADMIN') && $contact->getEmail() !== $user->getEmail()) {
            throw $this->createAccessDeniedException('Vous ne pouvez pas accéder à ce message.');
        }

        // Marquer comme lu si c'est un message admin→user
        if ($contact->isFromAdmin() && !$contact->isEstLu()) {
            $contact->setEstLu(true);
            $entityManager->flush();
        }

        return $this->render('contact/show_message.html.twig', [
            'contact' => $contact,
        ]);
    }
}