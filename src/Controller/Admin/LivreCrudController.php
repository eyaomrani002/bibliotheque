<?php

namespace App\Controller\Admin;

use App\Entity\Livre;
use App\Repository\UserRepository;
use App\Service\NotificationService;
use Doctrine\ORM\EntityManagerInterface;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\NumberField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use Symfony\Component\Form\Extension\Core\Type\FileType;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class LivreCrudController extends AbstractCrudController
{
    public function __construct(
        private NotificationService $notificationService, 
        private UserRepository $userRepository
    ) {
    }

    public static function getEntityFqcn(): string
    {
        return Livre::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Livre')
            ->setEntityLabelInPlural('Livres')
            ->setPageTitle('index', '📚 Gestion des livres')
            ->setSearchFields(['titre', 'isbn', 'auteurs.nom', 'auteurs.prenom'])
            ->setDefaultSort(['titre' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('titre', 'Titre')->setRequired(true),
            TextField::new('isbn', 'ISBN')
                ->setHelp('Format: 978-2-1234-5680-3'),
            NumberField::new('prix', 'Prix (€)')
                ->setNumDecimals(2)
                ->setFormTypeOption('attr', ['step' => '0.01']),
            IntegerField::new('qte', 'Quantité')
                ->setHelp('Quantité en stock'),
            DateField::new('datpub', 'Date de publication')
                ->setFormat('dd/MM/yyyy'),
            TextareaField::new('resume', 'Résumé')
                ->hideOnIndex()
                ->setHelp('Résumé du livre'),
            
            // Champ image avec upload
            ImageField::new('image', 'Image du livre')
                ->setBasePath('/uploads/livres')
                ->setUploadDir('public/uploads/livres')
                ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
                ->setRequired(false)
                ->setHelp('Formats supportés: JPG, PNG, WebP. Taille max: 2MB'),

            // Champ PDF admin (non-mappé) — fallback: utiliser un champ textuel rendu comme FileType
            TextField::new('pdfFile', 'Fichier PDF')
                ->setFormType(FileType::class)
                ->setFormTypeOptions([
                    'mapped' => false,
                    // EasyAdmin/Form may render the file input as a nested child (e.g. pdfFile[file])
                    // set both common id/name variants so the generated label 'for' matches an input
                    'attr' => [
                        'id' => 'Livre_pdfFile_file',
                        'name' => 'Livre[pdfFile][file]'
                    ],
                    // also set the top-level row id for safety
                    'row_attr' => ['id' => 'form_Livre_pdfFile']
                ])
                ->setRequired(false)
                ->setHelp('Format: PDF. Taille max recommandée: 20MB')
                ->onlyOnForms(),

            // Afficher le nom/chemin du PDF uniquement en détail (et non comme champ upload)
            TextField::new('pdf', 'Fichier PDF')->onlyOnDetail(),
            
            IntegerField::new('nbPages', 'Nombre de pages'),
            TextField::new('langue', 'Langue')
                ->setHelp('Ex: Français, Anglais, etc.'),
            AssociationField::new('editeur', 'Éditeur')
                ->setRequired(true),
            AssociationField::new('categorie', 'Catégorie')
                ->setRequired(true),
            AssociationField::new('auteurs', 'Auteurs')
                ->setFormTypeOption('by_reference', false)
                ->autocomplete()
                ->setHelp('Sélectionnez un ou plusieurs auteurs'),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL);
    }

    public function persistEntity(EntityManagerInterface $entityManager, $entityInstance): void
    {
        // ✅ NOUVEAU : Notifier tous les utilisateurs lorsqu'un livre est ajouté
        if ($entityInstance instanceof Livre) {
            // Persister d'abord le livre pour avoir l'ID
            $entityManager->persist($entityInstance);
            $entityManager->flush();

            // Gérer l'upload PDF envoyé depuis l'admin (champ non-mappé 'pdfFile')
            $request = $this->container->get('request_stack')->getCurrentRequest();
            if ($request) {
                $filesAll = $request->files->all();
                $uploaded = $this->findUploadedFile($filesAll, 'pdfFile');

                if ($uploaded instanceof UploadedFile) {
                    try {
                        $original = pathinfo($uploaded->getClientOriginalName(), PATHINFO_FILENAME);
                        $safe = preg_replace('/[^a-z0-9]+/i', '-', $original);
                        $newName = $safe . '-' . uniqid() . '.' . ($uploaded->guessExtension() ?: 'pdf');
                        $uploadsDir = $this->getParameter('uploads_livres');
                        $uploaded->move($uploadsDir, $newName);

                        // Supprimer ancien pdf si présent
                        if ($old = $entityInstance->getPdf()) {
                            $oldPath = $uploadsDir . '/' . $old;
                            if (file_exists($oldPath)) @unlink($oldPath);
                        }

                        $entityInstance->setPdf($newName);
                        $entityManager->flush();
                    } catch (\Exception $e) {
                        $this->addFlash('error', 'Erreur upload PDF admin: ' . $e->getMessage());
                    }
                } else {
                    // fallback: chercher le premier UploadedFile dont le nom client se termine par .pdf
                    $uploaded = $this->findFirstPdfUploadedFile($filesAll);
                    if ($uploaded instanceof UploadedFile) {
                        try {
                            // ensure uploads dir exists
                            $uploadsDir = $this->getParameter('uploads_livres');
                            if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0775, true);

                            $original = pathinfo($uploaded->getClientOriginalName(), PATHINFO_FILENAME);
                            $safe = preg_replace('/[^a-z0-9]+/i', '-', $original);
                            $newName = $safe . '-' . uniqid() . '.' . ($uploaded->guessExtension() ?: 'pdf');
                            $uploaded->move($uploadsDir, $newName);

                            if ($old = $entityInstance->getPdf()) {
                                $oldPath = $uploadsDir . '/' . $old;
                                if (file_exists($oldPath)) @unlink($oldPath);
                            }
                            $entityInstance->setPdf($newName);
                            $entityManager->flush();
                        } catch (\Exception $e) {
                            $this->addFlash('error', 'Erreur upload PDF admin (fallback): ' . $e->getMessage());
                        }
                    } else {
                    // Aucun UploadedFile trouvé — logguer la structure pour debug
                    try {
                        $logger = $this->container->get('logger');
                        if ($logger) {
                            $summary = $this->summarizeFilesForLog($filesAll);
                            $logger->debug('LivreCrudController: aucun pdfFile trouvé dans request->files; structure: ' . json_encode($summary));
                        }
                    } catch (\Throwable $t) {
                        // ne pas interrompre l'exécution si le logger échoue
                    }
                    }
                }
            }

            // Envoyer la notification à tous les utilisateurs
            $title = '📚 Nouveau livre disponible !';
            $message = 'Le livre "' . $entityInstance->getTitre() . '" a été ajouté à la bibliothèque.';
            
            $this->notificationService->createForAllUsers(
                $this->userRepository, 
                $title, 
                $message, 
                'success', 
                [
                    'livreId' => $entityInstance->getId(),
                    'actionUrl' => $this->generateUrl('app_livre_show', ['id' => $entityInstance->getId()])
                ], 
                false // Ne pas envoyer d'email pour les nouveaux livres
            );

            $this->addFlash('success', 'Livre ajouté avec succès et notification envoyée aux utilisateurs !');
        } else {
            parent::persistEntity($entityManager, $entityInstance);
        }
    }

    // ✅ NOUVEAU : Méthode pour générer les URLs
    public function generateUrl(string $route, array $parameters = [], int $referenceType = \Symfony\Component\Routing\Generator\UrlGeneratorInterface::ABSOLUTE_PATH): string
    {
        return $this->container->get('router')->generate($route, $parameters, $referenceType);
    }

    // ✅ NOUVEAU : Méthode pour accéder au container
    public static function getSubscribedServices(): array
    {
        return array_merge(parent::getSubscribedServices(), [
            'router' => '?'. \Symfony\Component\Routing\RouterInterface::class,
        ]);
    }

    private function findUploadedFile(array $files, string $key): ?UploadedFile
    {
        foreach ($files as $k => $v) {
            if ($k === $key && $v instanceof UploadedFile) {
                return $v;
            }
            if (is_array($v)) {
                $result = $this->findUploadedFile($v, $key);
                if ($result instanceof UploadedFile) {
                    return $result;
                }
            }
            // Some frameworks may nest the UploadedFile under ['file'] or similar
            if ($k === $key && is_array($v) && isset($v['file']) && $v['file'] instanceof UploadedFile) {
                return $v['file'];
            }
        }
        return null;
    }

    private function summarizeFilesForLog(array $files): array
    {
        $out = [];
        foreach ($files as $k => $v) {
            if ($v instanceof UploadedFile) {
                $out[$k] = ['type' => 'UploadedFile', 'clientName' => $v->getClientOriginalName()];
            } elseif (is_array($v)) {
                $out[$k] = $this->summarizeFilesForLog($v);
            } else {
                $out[$k] = ['type' => is_object($v) ? get_class($v) : gettype($v)];
            }
        }
        return $out;
    }

    private function findFirstPdfUploadedFile(array $files): ?UploadedFile
    {
        foreach ($files as $k => $v) {
            if ($v instanceof UploadedFile) {
                $name = $v->getClientOriginalName();
                if ($name && str_ends_with(strtolower($name), '.pdf')) {
                    return $v;
                }
            } elseif (is_array($v)) {
                $result = $this->findFirstPdfUploadedFile($v);
                if ($result instanceof UploadedFile) {
                    return $result;
                }
            }
        }
        return null;
    }
}