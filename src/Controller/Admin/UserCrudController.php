<?php

namespace App\Controller\Admin;

use App\Entity\User;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ArrayField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Filters;
use EasyCorp\Bundle\EasyAdminBundle\Router\AdminUrlGenerator;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Psr\Log\LoggerInterface;
use SymfonyCasts\Bundle\ResetPassword\ResetPasswordHelperInterface;
use SymfonyCasts\Bundle\ResetPassword\Exception\TooManyPasswordRequestsException;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Address;

class UserCrudController extends AbstractCrudController
{
    public function __construct(
        private AdminUrlGenerator $adminUrlGenerator,
        private UserPasswordHasherInterface $passwordHasher,
        private ResetPasswordHelperInterface $resetPasswordHelper,
        private MailerInterface $mailer,
        private LoggerInterface $logger,
        private string $adminEmail = 'admin@biblio.com'
    ) {}

    public static function getEntityFqcn(): string
    {
        return User::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Utilisateur')
            ->setEntityLabelInPlural('Utilisateurs')
            ->setPageTitle('index', '👥 Gestion des utilisateurs')
            ->setPageTitle('detail', '👤 Détails de l\'utilisateur')
            ->setPageTitle('new', '➕ Créer un utilisateur')
            ->setPageTitle('edit', '✏️ Modifier l\'utilisateur')
            ->setSearchFields(['email', 'nom', 'prenom', 'telephone'])
            ->setDefaultSort(['createdAt' => 'DESC'])
            ->setPaginatorPageSize(30)
            ->showEntityActionsInlined()
            ->setHelp('new', 'Créez un nouvel utilisateur avec un rôle approprié')
            ->setHelp('edit', 'Modifiez les informations de l\'utilisateur');
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = [
            IdField::new('id')
                ->onlyOnIndex()
                ->setLabel('ID'),
            
            EmailField::new('email')
                ->setLabel('Email')
                ->setRequired(true)
                ->setHelp('L\'email servira également d\'identifiant de connexion'),
            
            TextField::new('nom')
                ->setLabel('Nom')
                ->setRequired(false)
                ->setHelp('Nom de famille'),
            
            TextField::new('prenom')
                ->setLabel('Prénom')
                ->setRequired(false)
                ->setHelp('Prénom'),
            
            TextField::new('telephone')
                ->setLabel('Téléphone')
                ->setRequired(false)
                ->hideOnIndex()
                ->setHelp('Numéro de téléphone'),
            
            TextField::new('adresse')
                ->setLabel('Adresse')
                ->setRequired(false)
                ->hideOnIndex()
                ->setHelp('Adresse postale complète'),
        ];

        // Champs pour les pages de liste et détail
        if (in_array($pageName, [Crud::PAGE_INDEX, Crud::PAGE_DETAIL])) {
            $fields[] = ChoiceField::new('roles', 'Rôles')
                ->setChoices([
                    '👤 Utilisateur' => 'ROLE_USER',
                    '👑 Administrateur' => 'ROLE_ADMIN',
                ])
                ->allowMultipleChoices()
                ->renderAsBadges([
                    'ROLE_USER' => 'success',
                    'ROLE_ADMIN' => 'danger',
                ])
                ->setHelp('Rôles de l\'utilisateur dans l\'application');
        }

        // Champs pour les pages de formulaire (new/edit)
        if (in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT])) {
            $fields[] = ChoiceField::new('roles', 'Rôles')
                ->setChoices([
                    'Utilisateur' => 'ROLE_USER',
                    'Administrateur' => 'ROLE_ADMIN',
                ])
                ->allowMultipleChoices()
                ->setRequired(true)
                ->setHelp('Sélectionnez au moins un rôle');
        }

        $fields[] = BooleanField::new('isVerified', 'Email vérifié')
            ->setHelp('Indique si l\'email a été vérifié')
            ->renderAsSwitch(in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT]));

        // Champ mot de passe pour les formulaires
        if (in_array($pageName, [Crud::PAGE_NEW, Crud::PAGE_EDIT])) {
            $fields[] = TextField::new('plainPassword', 'Mot de passe')
                ->setRequired($pageName === Crud::PAGE_NEW)
                ->onlyOnForms()
                ->setHelp($pageName === Crud::PAGE_EDIT 
                    ? 'Laissez vide pour ne pas modifier le mot de passe (min. 6 caractères)' 
                    : 'Mot de passe initial (minimum 6 caractères)');
        }

        // Champs supplémentaires pour la page détail
        if ($pageName === Crud::PAGE_DETAIL) {
            $fields[] = DateTimeField::new('createdAt', 'Date de création')
                ->setFormat('dd/MM/yyyy HH:mm');
            
            $fields[] = DateTimeField::new('updatedAt', 'Dernière modification')
                ->setFormat('dd/MM/yyyy HH:mm');
            
            $fields[] = AssociationField::new('emprunts', 'Emprunts en cours')
                ->onlyOnDetail()
                ->setTemplatePath('admin/field/user_emprunts.html.twig');
            
            $fields[] = ArrayField::new('avis', 'Avis donnés')
                ->onlyOnDetail();
        }

        return $fields;
    }

    public function configureActions(Actions $actions): Actions
    {
        // Action pour envoyer un lien de réinitialisation
        $sendResetPassword = Action::new('sendResetPassword', 'Reset MDP', 'fas fa-key')
            ->linkToCrudAction('sendResetPassword')
            ->setCssClass('btn btn-warning text-white btn-sm')
            ->addCssClass('px-3')
            ->setHtmlAttributes([
                'title' => 'Envoyer un lien de réinitialisation par email',
                'data-bs-toggle' => 'tooltip'
            ])
            ->displayIf(static function (User $user) {
                return !empty($user->getEmail());
            });

        // Action pour réinitialiser manuellement le mot de passe
        $manualResetPassword = Action::new('manualResetPassword', 'Nouveau MDP', 'fas fa-lock')
            ->linkToCrudAction('manualResetPassword')
            ->setCssClass('btn btn-info text-white btn-sm')
            ->addCssClass('px-3')
            ->setHtmlAttributes([
                'title' => 'Générer un nouveau mot de passe temporaire',
                'data-bs-toggle' => 'tooltip'
            ]);

        // Action pour activer/désactiver un utilisateur
        $toggleUser = Action::new('toggleUser', 'Activer/Désactiver', 'fas fa-power-off')
            ->linkToCrudAction('toggleUser')
            ->setCssClass('btn btn-secondary btn-sm')
            ->addCssClass('px-3')
            ->setHtmlAttributes([
                'title' => 'Activer ou désactiver l\'utilisateur',
                'data-bs-toggle' => 'tooltip'
            ]);

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $sendResetPassword)
            ->add(Crud::PAGE_INDEX, $manualResetPassword)
            ->add(Crud::PAGE_INDEX, $toggleUser)
            ->add(Crud::PAGE_DETAIL, $sendResetPassword)
            ->add(Crud::PAGE_DETAIL, $manualResetPassword)
            ->add(Crud::PAGE_DETAIL, $toggleUser)
            ->update(Crud::PAGE_INDEX, Action::NEW, function (Action $action) {
                return $action
                    ->setIcon('fas fa-plus')
                    ->setLabel('Nouvel utilisateur')
                    ->setCssClass('btn btn-primary');
            })
            ->update(Crud::PAGE_INDEX, Action::EDIT, function (Action $action) {
                return $action
                    ->setIcon('fas fa-edit')
                    ->setLabel('')
                    ->setCssClass('btn btn-sm btn-outline-primary')
                    ->setHtmlAttributes(['title' => 'Modifier']);
            })
            ->update(Crud::PAGE_INDEX, Action::DELETE, function (Action $action) {
                return $action
                    ->setIcon('fas fa-trash')
                    ->setLabel('')
                    ->setCssClass('btn btn-sm btn-outline-danger')
                    ->setHtmlAttributes(['title' => 'Supprimer']);
            })
            ->update(Crud::PAGE_INDEX, Action::DETAIL, function (Action $action) {
                return $action
                    ->setIcon('fas fa-eye')
                    ->setLabel('')
                    ->setCssClass('btn btn-sm btn-outline-secondary')
                    ->setHtmlAttributes(['title' => 'Voir les détails']);
            })
            ->reorder(Crud::PAGE_INDEX, [Action::DETAIL, Action::EDIT, 'sendResetPassword', 'manualResetPassword', 'toggleUser', Action::DELETE])
            ->reorder(Crud::PAGE_DETAIL, [Action::INDEX, Action::EDIT, 'sendResetPassword', 'manualResetPassword', 'toggleUser', Action::DELETE]);
    }

    public function configureFilters(Filters $filters): Filters
    {
        return $filters
            ->add('email')
            ->add('nom')
            ->add('prenom')
            ->add('isVerified')
            ->add('roles')
            ->add('createdAt');
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // Définir les dates de création et mise à jour
        if (method_exists($entityInstance, 'setCreatedAt')) {
            $entityInstance->setCreatedAt(new \DateTime());
        }
        if (method_exists($entityInstance, 'setUpdatedAt')) {
            $entityInstance->setUpdatedAt(new \DateTime());
        }

        $this->handlePassword($entityInstance);
        
        try {
            parent::persistEntity($entityManager, $entityInstance);
            
            $this->addFlash('success', sprintf(
                '✅ Utilisateur <strong>%s</strong> créé avec succès',
                $entityInstance->getEmail()
            ));
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur création utilisateur', [
                'email' => $entityInstance->getEmail(),
                'error' => $e->getMessage()
            ]);
            
            $this->addFlash('error', sprintf(
                '❌ Erreur lors de la création de l\'utilisateur: %s',
                $e->getMessage()
            ));
            
            throw $e;
        }
    }

    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // Mettre à jour la date de modification
        if (method_exists($entityInstance, 'setUpdatedAt')) {
            $entityInstance->setUpdatedAt(new \DateTime());
        }

        $this->handlePassword($entityInstance);
        
        try {
            parent::updateEntity($entityManager, $entityInstance);
            
            $this->addFlash('success', sprintf(
                '✅ Utilisateur <strong>%s</strong> modifié avec succès',
                $entityInstance->getEmail()
            ));
            
        } catch (\Exception $e) {
            $this->logger->error('Erreur modification utilisateur', [
                'email' => $entityInstance->getEmail(),
                'error' => $e->getMessage()
            ]);
            
            $this->addFlash('error', sprintf(
                '❌ Erreur lors de la modification de l\'utilisateur: %s',
                $e->getMessage()
            ));
            
            throw $e;
        }
    }

    /**
     * Gestion du mot de passe (hachage si plainPassword est défini)
     */
    private function handlePassword(User $user): void
    {
        $plainPassword = $user->getPlainPassword();
        
        if ($plainPassword) {
            if (strlen($plainPassword) < 6) {
                throw new \RuntimeException('Le mot de passe doit contenir au moins 6 caractères');
            }
            
            $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);
            $user->setPassword($hashedPassword);
            $user->setPlainPassword(null);
            
            $this->logger->info('Mot de passe mis à jour', [
                'user' => $user->getEmail(),
                'action' => 'password_update'
            ]);
        }
    }

    /**
     * Envoie un lien de réinitialisation par email
     */
    public function sendResetPassword(AdminContext $context): Response
    {
        $user = $context->getEntity()->getInstance();
        
        if (!$user->getEmail()) {
            $this->addFlash('error', "❌ L'utilisateur n'a pas d'adresse email configurée");
            return $this->redirectToReferrer($context);
        }

        try {
            $resetToken = $this->resetPasswordHelper->generateResetToken($user);
            $resetUrl = $this->generateUrl('app_reset_password', 
                ['token' => $resetToken->getToken()], 
                UrlGeneratorInterface::ABSOLUTE_URL
            );

            // Envoyer l'email de réinitialisation
            $email = (new Email())
                ->from(new Address($this->adminEmail, 'Administration Bibliothèque'))
                ->to($user->getEmail())
                ->subject('Réinitialisation de votre mot de passe')
                ->html($this->renderView('emails/reset_password.html.twig', [
                    'resetUrl' => $resetUrl,
                    'user' => $user,
                    'expirationDate' => $resetToken->getExpiresAt()
                ]));

            $this->mailer->send($email);

            $this->addFlash('success', sprintf(
                '✅ Lien de réinitialisation envoyé à <strong>%s</strong><br>
                 📧 Email envoyé avec succès<br>
                 ⏰ Lien valable 1 heure<br>
                 🔗 <a href="%s" target="_blank" class="alert-link">Lien de test</a>',
                $user->getEmail(),
                $resetUrl
            ));

            $this->logger->info('Lien reset password envoyé', [
                'user' => $user->getEmail(),
                'action' => 'reset_password_sent'
            ]);

        } catch (TooManyPasswordRequestsException $e) {
            $retryMessage = $this->getRetryMessage($e);
            $this->addFlash('warning', sprintf(
                '⚠️ Trop de tentatives pour <strong>%s</strong><br>%s',
                $user->getEmail(),
                $retryMessage
            ));

        } catch (\Throwable $e) {
            $errorMessage = $this->getErrorMessage($e);
            $this->addFlash('error', sprintf(
                '❌ Erreur pour <strong>%s</strong>: %s',
                $user->getEmail(),
                $errorMessage
            ));

            $this->logger->error('Erreur envoi reset password', [
                'user' => $user->getEmail(),
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }

        return $this->redirectToReferrer($context);
    }

    /**
     * Réinitialise manuellement le mot de passe
     */
    public function manualResetPassword(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $user = $context->getEntity()->getInstance();
        
        try {
            $temporaryPassword = $this->generateTemporaryPassword();
            $user->setPlainPassword($temporaryPassword);
            $this->handlePassword($user);
            
            $entityManager->flush();
            // IMPORTANT: Do NOT send the temporary password by email here.
            // The admin requested a manual reset for a user who cannot access their email,
            // so we display the generated temporary password in admin UI so the admin
            // can communicate it securely to the user.
            $this->addFlash('success', sprintf(
                '✅ Mot de passe temporaire généré pour <strong>%s</strong>. Mot de passe : <strong>%s</strong><br><small>Communiquez ce mot de passe au utilisateur de manière sécurisée et invitez-le à le changer après la connexion.</small>',
                $user->getEmail() ?? 'utilisateur',
                $temporaryPassword
            ));

            $this->logger->info('Mot de passe temporaire généré (affiché à l\'admin)', [
                'user' => $user->getEmail(),
                'action' => 'manual_password_reset_displayed'
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', sprintf(
                '❌ Erreur lors de la réinitialisation: %s',
                $e->getMessage()
            ));
            
            $this->logger->error('Erreur reset manuel mot de passe', [
                'user' => $user->getEmail(),
                'error' => $e->getMessage()
            ]);
        }

        return $this->redirectToReferrer($context);
    }

    /**
     * Active/désactive un utilisateur
     */
    public function toggleUser(AdminContext $context, EntityManagerInterface $entityManager): Response
    {
        $user = $context->getEntity()->getInstance();
        
        try {
            // Ici vous pouvez implémenter la logique d'activation/désactivation
            // Par exemple, baser sur un champ isActive ou désactiver le compte
            $newStatus = !$user->isVerified(); // Exemple basé sur isVerified
            $user->setIsVerified($newStatus);
            
            $entityManager->flush();

            $statusText = $newStatus ? 'activé' : 'désactivé';
            $statusIcon = $newStatus ? '✅' : '⏸️';
            
            $this->addFlash('success', sprintf(
                '%s Utilisateur <strong>%s</strong> %s avec succès',
                $statusIcon,
                $user->getEmail(),
                $statusText
            ));

            $this->logger->info('Statut utilisateur modifié', [
                'user' => $user->getEmail(),
                'new_status' => $statusText,
                'action' => 'toggle_user'
            ]);

        } catch (\Exception $e) {
            $this->addFlash('error', sprintf(
                '❌ Erreur lors du changement de statut: %s',
                $e->getMessage()
            ));
        }

        return $this->redirectToReferrer($context);
    }

    /**
     * Génère un mot de passe temporaire sécurisé
     */
    private function generateTemporaryPassword(int $length = 12): string
    {
        $uppercase = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $lowercase = 'abcdefghjkmnpqrstuvwxyz';
        $numbers = '23456789';
        $symbols = '!@#$%&*';
        
        // Au moins un caractère de chaque type
        $password = $uppercase[random_int(0, strlen($uppercase) - 1)];
        $password .= $lowercase[random_int(0, strlen($lowercase) - 1)];
        $password .= $numbers[random_int(0, strlen($numbers) - 1)];
        $password .= $symbols[random_int(0, strlen($symbols) - 1)];
        
        // Remplir le reste
        $allChars = $uppercase . $lowercase . $numbers . $symbols;
        for ($i = 4; $i < $length; $i++) {
            $password .= $allChars[random_int(0, strlen($allChars) - 1)];
        }
        
        // Mélanger le mot de passe
        return str_shuffle($password);
    }

    /**
     * Gestion des messages de réessai
     */
    private function getRetryMessage(TooManyPasswordRequestsException $e): string
    {
        $message = 'Veuillez patienter avant de réessayer.';
        
        try {
            if (method_exists($e, 'getRetryAfter')) {
                $retry = $e->getRetryAfter();
                if ($retry instanceof \DateTimeInterface) {
                    $seconds = $retry->getTimestamp() - time();
                    if ($seconds > 0) {
                        $minutes = (int) ceil($seconds / 60);
                        $message = sprintf('Réessayez dans %d minute(s).', $minutes);
                    }
                } elseif (is_int($retry)) {
                    $minutes = (int) ceil($retry / 60);
                    $message = sprintf('Réessayez dans %d minute(s).', $minutes);
                }
            }
        } catch (\Throwable $inner) {
            // Ignorer les erreurs de formatage
        }
        
        return $message;
    }

    /**
     * Gestion des messages d'erreur
     */
    private function getErrorMessage(\Throwable $e): string
    {
        $message = $e->getMessage();
        
        // Messages d'erreur plus explicites
        if (str_contains($message, 'interface') || str_contains($message, 'ResetPasswordRequestInterface')) {
            return "Problème de configuration ResetPassword. Vérifiez l'entité ResetPasswordRequest.";
        }
        
        if (str_contains($message, 'too many requests')) {
            return "Trop de tentatives de réinitialisation.";
        }
        
        if (str_contains($message, 'Could not find')) {
            return "Utilisateur non trouvé dans le système.";
        }
        
        return $message ?: 'Erreur inconnue';
    }

    /**
     * Redirection sécurisée vers la page précédente
     */
    private function redirectToReferrer(AdminContext $context): Response
    {
        $redirectUrl = $context->getReferrer() 
            ?? $this->adminUrlGenerator->setController(self::class)->setAction('index')->generateUrl();
            
        return $this->redirect($redirectUrl);
    }
}