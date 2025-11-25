<?php

namespace App\Controller\Admin;

use App\Entity\Auteur;
use EasyCorp\Bundle\EasyAdminBundle\Controller\AbstractCrudController;
use EasyCorp\Bundle\EasyAdminBundle\Field\IdField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextField;
use EasyCorp\Bundle\EasyAdminBundle\Field\DateField;
use EasyCorp\Bundle\EasyAdminBundle\Field\TextareaField;
use EasyCorp\Bundle\EasyAdminBundle\Field\EmailField;
use EasyCorp\Bundle\EasyAdminBundle\Field\ImageField;
use EasyCorp\Bundle\EasyAdminBundle\Config\Crud;

class AuteurCrudController extends AbstractCrudController
{
    public static function getEntityFqcn(): string
    {
        return Auteur::class;
    }

    public function configureCrud(Crud $crud): Crud
    {
        return $crud
            ->setEntityLabelInSingular('Auteur')
            ->setEntityLabelInPlural('Auteurs')
            ->setSearchFields(['nom', 'prenom', 'email'])
            ->setDefaultSort(['nom' => 'ASC']);
    }

    public function configureFields(string $pageName): iterable
    {
        return [
            IdField::new('id')->onlyOnIndex(),
            TextField::new('nom', 'Nom')->setRequired(true),
            TextField::new('prenom', 'Prénom')->setRequired(true),
            DateField::new('dateNaissance', 'Date de naissance')
                ->setFormat('dd/MM/yyyy')
                ->setRequired(false),
            TextField::new('nationalite', 'Nationalité')
                ->setHelp('Ex: Française, Américaine, etc.'),
            TextareaField::new('biographie', 'Biographie')
                ->hideOnIndex()
                ->setHelp('Biographie de l\'auteur'),
            
            // Champ image avec upload
            ImageField::new('photo', 'Photo de l\'auteur')
                ->setBasePath('/uploads/auteurs')
                ->setUploadDir('public/uploads/auteurs')
                ->setUploadedFileNamePattern('[slug]-[timestamp].[extension]')
                ->setRequired(false)
                ->setHelp('Formats supportés: JPG, PNG, WebP. Taille max: 2MB'),
            
            EmailField::new('email', 'Email')
                ->setRequired(false),
        ];
    }
}