<?php

namespace App\Controller\Admin;

use App\Entity\Contact;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextEditorField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Email as MailMessage;
use App\Service\NotificationService;
use App\Repository\UserRepository;
use Symfony\Component\HttpFoundation\RequestStack;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;

class ContactCrudController extends AbstractCrudController
{
    public function __construct(
        private MailerInterface $mailer,
        private NotificationService $notificationService,
        private UserRepository $userRepository,
        private RequestStack $requestStack
    ) {}

    public static function getEntityFqcn(): string
    {
        return Contact::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Message')
            ->setEntityLabelInPlural('Messages')
            ->setPageTitle('index', '📧 Messages (Utilisateurs → Admin)')
            ->setPageTitle('new', '📤 Nouveau message aux utilisateurs')
            ->setSearchFields(['nom', 'prenom', 'email', 'sujet'])
            ->setDefaultSort(['dateEnvoi' => 'DESC']);
    }

    public function configureFields(string $pageName): iterable
    {
        $fields = [
            IdField::new('id')->hideOnForm(),
        ];

        if ($pageName === Crud::PAGE_NEW) {
            // Formulaire de création : message admin→user
            $fields[] = AssociationField::new('destinataire', 'Destinataire')
                ->setRequired(true);
            // Permettre d'indiquer plusieurs emails séparés par des virgules
            $fields[] = TextEditorField::new('destinataires', 'Destinataires (emails)')
                ->onlyOnForms()
                ->setFormTypeOptions(['mapped' => false, 'required' => false])
                ->setHelp('Emails séparés par des virgules. Laisser vide pour utiliser le champ Destinataire.');
            $fields[] = TextField::new('sujet')->setRequired(true);
            $fields[] = ChoiceField::new('categorie', 'Catégorie')
                ->setChoices([
                    'ℹ️ Information' => 'information',
                    '⚠️ Important' => 'important',
                    '🔧 Technique' => 'technique',
                    '❓ Question' => 'question',
                ]);
            $fields[] = TextEditorField::new('message')->hideOnIndex()->setRequired(true);
        } else {
            // Liste et détail : affichage normal
            $fields[] = TextField::new('nomComplet', 'Nom complet')->onlyOnIndex();
            $fields[] = TextField::new('nom')->onlyOnForms();
            $fields[] = TextField::new('prenom')->onlyOnForms();
            $fields[] = EmailField::new('email');
            $fields[] = AssociationField::new('destinataire', 'Destinataire')->onlyOnDetail();
            $fields[] = TextField::new('sujet');
            $fields[] = TextEditorField::new('message')->hideOnIndex();
            $fields[] = ChoiceField::new('type', 'Type')
                ->setChoices([
                    'User → Admin' => 'user_to_admin',
                    'Admin → User' => 'admin_to_user',
                ])
                ->renderAsBadges();
            $fields[] = ChoiceField::new('categorie', 'Catégorie')
                ->setChoices([
                    'Information' => 'information',
                    'Important' => 'important',
                    'Technique' => 'technique',
                    'Question' => 'question',
                ])
                ->renderAsBadges();
        }

        $fields[] = TextEditorField::new('reponse')->hideOnIndex();
        $fields[] = DateTimeField::new('dateEnvoi')->onlyOnIndex();
        $fields[] = DateTimeField::new('dateReponse')->onlyOnDetail();
        $fields[] = BooleanField::new('estLu');
        $fields[] = ChoiceField::new('statut')->setChoices([
            'Nouveau' => 'nouveau',
            'En cours' => 'en_cours',
            'Résolu' => 'resolu',
        ]);

        return $fields;
    }

    public function configureActions(Actions $actions): Actions
    {
        $sendToUser = Action::new('sendToUser', 'Message à un user', 'fas fa-paper-plane')
            ->linkToRoute('app_contact_admin_new')
            ->setCssClass('btn btn-success')
            ->createAsGlobalAction();

        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_INDEX, $sendToUser)
            ->update(Crud::PAGE_INDEX, Action::NEW, function (Action $action) {
                return $action->setLabel('📤 Message aux users');
            });
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if ($entityInstance instanceof Contact) {
            $request = $this->requestStack->getCurrentRequest();
            $multipleEmails = null;
            if ($request) {
                $data = $request->request->get('Contact');
                if (is_array($data) && !empty($data['destinataires'])) {
                    $multipleEmails = $data['destinataires'];
                }
            }

            // Si des emails multiples sont fournis, créer un Contact par destinataire
            if ($multipleEmails) {
                $emails = array_filter(array_map('trim', explode(',', $multipleEmails)));
                foreach ($emails as $email) {
                    $user = $this->userRepository->findOneBy(['email' => $email]);
                    if (!$user) {
                        // si l'email ne correspond pas à un user, on peut l'ignorer ou créer un contact sans destinataire
                        continue;
                    }

                    $c = new Contact();
                    $c->setType('admin_to_user');
                    $c->setNom('Administrateur');
                    $c->setPrenom('Système');
                    $adminEmail = $this->getUser()?->getUserIdentifier() ?? $this->getParameter('app.support_email');
                    $c->setEmail($adminEmail);
                    $c->setDestinataire($user);
                    $c->setSujet($entityInstance->getSujet());
                    $c->setMessage($entityInstance->getMessage());
                    $c->setEstLu(false);
                    $c->setStatut('nouveau');

                    $entityManager->persist($c);
                    $entityManager->flush();

                    // Notifier l'utilisateur
                    $this->notificationService->createForUser(
                        $user,
                        '📧 ' . $c->getSujet(),
                        $c->getMessage(),
                        'info',
                        [
                            'contactId' => $c->getId(),
                            'actionUrl' => $this->generateUrl('app_contact_show_message', ['id' => $c->getId()])
                        ],
                        true
                    );
                }

                // Ne pas persister l'entité d'origine (qui représentait le formulaire global)
                return;
            }

            // Sinon comportement normal pour un seul destinataire
            if (!$entityInstance->getId() && $entityInstance->getDestinataire()) {
                $entityInstance->setType('admin_to_user');
                $entityInstance->setNom('Administrateur');
                $entityInstance->setPrenom('Système');
                $adminEmail = $this->getUser()?->getUserIdentifier() ?? $this->getParameter('app.support_email');
                $entityInstance->setEmail($adminEmail);
                $entityInstance->setEstLu(false);
                $entityInstance->setStatut('nouveau');
            }

            $entityManager->persist($entityInstance);
            $entityManager->flush();

            // Notifier l'utilisateur si c'est un message admin→user
            if ($entityInstance->isFromAdmin() && $entityInstance->getDestinataire()) {
                $this->notificationService->createForUser(
                    $entityInstance->getDestinataire(),
                    '📧 ' . $entityInstance->getSujet(),
                    $entityInstance->getMessage(),
                    'info',
                    [
                        'contactId' => $entityInstance->getId(),
                        'actionUrl' => $this->generateUrl('app_contact_show_message', ['id' => $entityInstance->getId()])
                    ],
                    true
                );
            }
        }
    }

    /**
     * Called when an existing Contact is updated from EasyAdmin.
     */
    public function updateEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        if (!$entityInstance instanceof Contact) {
            parent::updateEntity($entityManager, $entityInstance);
            return;
        }

        // ✅ CORRECTION : Si ce contact n'avait pas de réponse avant mais en a une maintenant
        if ($entityInstance->getReponse() && $entityInstance->getDateReponse() === null) {
            $entityInstance->setDateReponse(new \DateTime());
            $entityInstance->setEstLu(true);

            // Envoyer l'email
            $support = $this->getParameter('app.support_email');

            $mail = (new MailMessage())
                ->from($support)
                ->replyTo($support)
                ->to($entityInstance->getEmail())
                ->subject('Réponse à votre message - Biblio-Symfony')
                ->html($this->renderView('emails/contact_response.html.twig', [
                    'contact' => $entityInstance,
                ]));

            try {
                $this->mailer->send($mail);
            } catch (\Throwable $e) {
                $this->addFlash('warning', 'Erreur lors de l\'envoi de l\'email de notification.');
            }

            // ✅ CORRECTION : Créer une notification in-app pour l'utilisateur
            $user = $this->userRepository->findOneBy(['email' => $entityInstance->getEmail()]);
            if ($user) {
                $title = '📩 Réponse à votre message';
                $messageText = 'L\'administrateur a répondu à votre message : "' . $entityInstance->getSujet() . '"';
                
                $this->notificationService->createForUser(
                    $user, 
                    $title, 
                    $messageText, 
                    'info', 
                    [
                        'contactId' => $entityInstance->getId(),
                        'actionUrl' => $this->generateUrl('app_contact_my', [], \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_URL)
                    ], 
                    false
                );
            }
        }

        $entityManager->persist($entityInstance);
        $entityManager->flush();
    }

    public function generateUrl(string $route, array $parameters = [], int $referenceType = \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->container->get('router')->generate($route, $parameters, $referenceType);
    }

    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'router' => '?'. \Symfony\Component\Routing\RouterInterface::class,
        ]);
    }
}