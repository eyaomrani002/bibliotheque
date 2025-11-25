<?php

namespace App\Controller\Admin;

use App\Entity\Avis;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\IntegerField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Field\BooleanField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;

class AvisCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Avis::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Avis')
            ->setEntityLabelInPlural('Avis')
            ->setPageTitle('index', '⭐ Gestion des avis')
            ->setPageTitle('new', '➕ Nouvel avis')
            ->setPageTitle('edit', '✏️ Modifier l\'avis')
            ->setSearchFields(['user.email', 'livre.titre', 'commentaire'])
            ->setDefaultSort(['dateCreation' => 'DESC'])
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
            
            IntegerField::new('note')
                ->setLabel('Note')
                ->setHelp('Note entre 1 et 5')
                ->setRequired(true)
                ->setFormTypeOptions([
                    'attr' => ['min' => 1, 'max' => 5],
                ])
                ->formatValue(function ($value, $entity) {
                    // Affiche des étoiles en lecture seule dans l'index/preview
                    if (null === $value) return '';
                    $stars = (int) $value;
                    return str_repeat('★', $stars) . str_repeat('☆', max(0, 5 - $stars));
                }),
            
            TextareaField::new('commentaire')
                ->setLabel('Commentaire')
                ->setRequired(false)
                ->hideOnIndex(),
            
            DateTimeField::new('dateCreation')
                ->setLabel('Date de création')
                ->setFormat('dd/MM/yyyy HH:mm')
                ->setRequired(true)
                ->onlyOnIndex(),
            
            BooleanField::new('isActive')
                ->setLabel('Actif')
                ->setRequired(true)
                ->renderAsSwitch(false),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::NEW, function (Action $action) {
                return $action->setIcon('fa fa-plus')->setLabel('Nouvel avis');
            });
    }
}