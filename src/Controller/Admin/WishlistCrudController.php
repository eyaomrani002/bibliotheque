<?php

namespace App\Controller\Admin;

use App\Entity\Wishlist;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\AssociationField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateTimeField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;
use EasyCorp\Bundle\EasyAdminBundle\Config\Actions;
use EasyCorp\Bundle\EasyAdminBundle\Config\Action;

class WishlistCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Wishlist::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Wishlist')
            ->setEntityLabelInPlural('Wishlists')
            ->setPageTitle('index', '💖 Gestion des wishlists')
            ->setPageTitle('new', '➕ Nouvelle wishlist')
            ->setPageTitle('edit', '✏️ Modifier la wishlist')
            ->setSearchFields(['user.email', 'livre.titre'])
            ->setDefaultSort(['dateAjout' => 'DESC'])
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
            
            DateTimeField::new('dateAjout')
                ->setLabel('Date d\'ajout')
                ->setFormat('dd/MM/yyyy HH:mm')
                ->setRequired(true),
        ];
    }

    public function configureActions(Actions $actions): Actions
    {
        return $actions
            ->add(Crud::PAGE_INDEX, Action::DETAIL)
            ->update(Crud::PAGE_INDEX, Action::NEW, function (Action $action) {
                return $action->setIcon('fa fa-plus')->setLabel('Nouvelle wishlist');
            });
    }
}