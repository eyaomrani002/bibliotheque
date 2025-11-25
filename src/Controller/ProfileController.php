<?php

namespace App\Controller;

use App\Entity\User;
use App\Form\ProfileType;
use App\Form\ChangePasswordFormType;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Form\FormError;

#[Route('/profile')]
class ProfileController extends AbstractController
{
    #[Route('', name: 'app_profile')]
    #[IsGranted('ROLE_USER')]
    public function show(): Response
    {
        /** @var User $user */
        $user = $this->getUser();
        
        return $this->render('profile/show.html.twig', [
            'user' => $user,
        ]);
    }

    #[Route('/edit', name: 'app_profile_edit')]
    #[IsGranted('ROLE_USER')]
    public function edit(Request $request, EntityManagerInterface $em, UserPasswordHasherInterface $passwordHasher): Response
    {
        /** @var User $user */
        $user = $this->getUser();

        $form = $this->createForm(ProfileType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $plainPassword = $user->getPlainPassword();
            
            // Si un nouveau mot de passe est fourni
            if ($plainPassword && trim($plainPassword) !== '') {
                $currentPassword = $form->get('currentPassword')->getData();
                
                // Vérifier si le mot de passe actuel est fourni
                if (!$currentPassword || trim($currentPassword) === '') {
                    $form->get('currentPassword')->addError(
                        new FormError('Veuillez fournir votre mot de passe actuel pour changer le mot de passe.')
                    );
                } 
                // Vérifier si le mot de passe actuel est correct
                elseif (!$passwordHasher->isPasswordValid($user, $currentPassword)) {
                    $form->get('currentPassword')->addError(
                        new FormError('Le mot de passe actuel est incorrect.')
                    );
                } 
                // Tout est bon, on peut changer le mot de passe
                else {
                    $hashedPassword = $passwordHasher->hashPassword($user, $plainPassword);
                    $user->setPassword($hashedPassword);
                    $user->setPlainPassword(null); // Nettoyer le mot de passe en clair
                }
            }

            // S'il n'y a pas d'erreurs, on sauvegarde
            if ($form->isValid()) {
                // Mettre à jour la date de modification si elle existe
                if (method_exists($user, 'setUpdatedAt')) {
                    $user->setUpdatedAt(new \DateTimeImmutable());
                }
                
                $em->flush();

                $this->addFlash('success', 'Votre profil a été mis à jour avec succès.');
                return $this->redirectToRoute('app_profile');
            }
        }

        return $this->render('profile/edit.html.twig', [
            'profileForm' => $form->createView(),
        ]);
    }

    #[Route('/change-password', name: 'app_change_password')]
    #[IsGranted('ROLE_USER')]
    public function changePassword(
        Request $request, 
        EntityManagerInterface $em, 
        UserPasswordHasherInterface $passwordHasher
    ): Response {
        /** @var User $user */
        $user = $this->getUser();
        
        $form = $this->createForm(ChangePasswordFormType::class);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            
            // Vérifier l'ancien mot de passe
            if (!$passwordHasher->isPasswordValid($user, $data['currentPassword'])) {
                $form->get('currentPassword')->addError(
                    new FormError('Le mot de passe actuel est incorrect.')
                );
            } else {
                // Hasher et sauvegarder le nouveau mot de passe
                $hashedPassword = $passwordHasher->hashPassword($user, $data['newPassword']);
                $user->setPassword($hashedPassword);
                
                if (method_exists($user, 'setUpdatedAt')) {
                    $user->setUpdatedAt(new \DateTimeImmutable());
                }
                
                $em->flush();

                $this->addFlash('success', 'Votre mot de passe a été modifié avec succès.');
                return $this->redirectToRoute('app_profile');
            }
        }

        return $this->render('profile/change_password.html.twig', [
            'form' => $form->createView(),
        ]);
    }
}