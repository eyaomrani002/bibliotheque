<?php

namespace App\Controller\Admin;

use App\Entity\Emprunt;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ChoiceField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;
use EasyCorp\Bundle\EasyAdminBundle\Context\AdminContext;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

class EmpruntCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Emprunt::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Emprunt')
            ->setEntityLabelInPlural('Emprunts')
            ->setPageTitle('index', '📥 Gestion des emprunts')
            ->setPageTitle('new', '➕ Nouvel emprunt')
            ->setPageTitle('edit', '✏️ Modifier l\'emprunt')
            ->setSearchFields(['user.email', 'livre.titre'])
            ->setDefaultSort(['dateEmprunt' => 'DESC'])
            ->setPaginatorPageSize(20);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            
            AssociationField::new('user')
                ->setLabel('Utilisateur')
                ->setRequired(true),
            
            AssociationField::new('livre')
                ->setLabel('Livre')
                ->setRequired(true),
            
            DateTimeField::new('dateEmprunt')
                ->setLabel('Date d\'emprunt')
                ->setFormat('dd/MM/yyyy HH:mm')
                ->setRequired(true),
            
            DateTimeField::new('dateRetourPrevue')
                ->setLabel('Retour prévu')
                ->setFormat('dd/MM/yyyy HH:mm')
                ->setRequired(true),
            
            DateTimeField::new('dateRetourEffective')
                ->setLabel('Retour effectif')
                ->setFormat('dd/MM/yyyy HH:mm')
                ->setRequired(false),
            
            ChoiceField::new('statut')
                ->setLabel('Statut')
                ->setChoices([
                    'Emprunté' => 'emprunté',
                    'Retourné' => 'retourné',
                    'En retard' => 'en_retard',
                ])
                ->renderAsBadges([
                    'emprunté' => 'warning',
                    'retourné' => 'success', 
                    'en_retard' => 'danger'
                ])
                ->setRequired(true),
            
            TextareaField::new('notes')
                ->setLabel('Notes')
                ->setRequired(false)
                ->hideOnIndex(),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        $returnAction = Action::new('returnBook', 'Marquer retourné', 'fa fa-check')
            ->linkToCrudAction('returnBook')
            ->setCssClass('btn btn-success')
            ->displayIf(static function (Emprunt $emprunt) {
                return $emprunt->getStatut() === 'emprunté';
            });

        return $actions
            ->add(Crud::PAGE_INDEX, $returnAction)
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->add(Crud::PAGE_DETAIL, $returnAction)
            ->update(Crud::PAGE_INDEX, Action::NEW, function (Action $action) {
                return $action->setIcon('fa fa-plus')->setLabel('Nouvel emprunt');
            });
    }

    public function returnBook(AdminContext $context, EntityManagerInterface $entityManager): RedirectResponse
    {
        $emprunt = $context->getEntity()->getInstance();
        
        $emprunt->marquerCommeRetourne();
        $entityManager->flush();

        $this->addFlash('success', 'Livre marqué comme retourné avec succès');

        $referrer = $context->getReferrer();
        if (!$referrer) {
            // fallback vers le dashboard admin si aucun referer n'est présent
            $referrer = $this->generateUrl('admin');
        }

        return $this->redirect($referrer);
    }
}