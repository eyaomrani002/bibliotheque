<?php

namespace App\Form;

use App\Entity\Emprunt;
use App\Entity\Livre;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;

class EmpruntType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('dateEmprunt')
            ->add('dateRetourPrevue')
            ->add('dateRetourEffective')
            ->add('statut', ChoiceType::class, [
                'choices' => [
                    'Emprunté' => 'emprunté',
                    'Retourné' => 'retourné',
                    'Annulé' => 'annule',
                    'En attente' => 'en_attente',
                ],
                'placeholder' => 'Choisir le statut',
                'required' => true,
            ])
            ->add('notes')
        ;

        // Le champ 'user' est optionnel selon le contexte (admin vs public)
        if ($options['include_user'] ?? true) {
            $builder->add('user', EntityType::class, [
                'class' => User::class,
                'choice_label' => 'email',
            ]);
        }

        $builder->add('livre', EntityType::class, [
            'class' => Livre::class,
            'choice_label' => 'titre',
        ]);
        
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Emprunt::class,
            // include_user true by default (admin forms). Set to false in public controller.
            'include_user' => true,
        ]);
    }
}
