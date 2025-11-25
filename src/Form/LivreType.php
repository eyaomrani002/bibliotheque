<?php

namespace App\Form;

use App\Entity\Livre;
use App\Entity\Auteur;
use App\Entity\Categorie;
use App\Entity\Editeur;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use App\Form\ImageUploadType;
use App\Form\PdfUploadType;

class LivreType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('titre')
            ->add('isbn')
            ->add('qte')
            ->add('prix')
            ->add('datpub')
            ->add('resume')
            ->add('imageFile', ImageUploadType::class, [
                'mapped' => false,
                'required' => false,
        ])
               ->add('pdfFile', PdfUploadType::class, [
                   'mapped' => false,
                   'required' => false,
               ])
            ->add('nbPages') // ✅ CORRIGÉ: nbpages → nbPages
            ->add('langue')
            
            // ✅ CHAMPS DE RELATION À AJOUTER
            ->add('auteurs', EntityType::class, [
                'class' => Auteur::class,
                'choice_label' => 'nom',
                'multiple' => true,
                'expanded' => false,
            ])
            ->add('categorie', EntityType::class, [
                'class' => Categorie::class,
                'choice_label' => 'designation',
                'multiple' => false,
                'expanded' => false,
            ])
            ->add('editeur', EntityType::class, [
                'class' => Editeur::class,
                'choice_label' => 'nom',
                'multiple' => false,
                'expanded' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Livre::class,
        ]);
    }
}