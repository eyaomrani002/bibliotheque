<?php

namespace App\Form;

use App\Entity\Avis;
use App\Entity\Livre;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class AvisType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $admin = $options['admin'] ?? false;

        $builder
            ->add('note')
            ->add('commentaire')
        ;

        // if admin mode, expose admin-only fields
        if ($admin) {
            $builder
                ->add('dateCreation')
                ->add('isActive')
                ->add('user', EntityType::class, [
                    'class' => User::class,
                    'choice_label' => 'id',
                ])
                ->add('livre', EntityType::class, [
                    'class' => Livre::class,
                    'choice_label' => 'titre',
                ])
            ;
        } else {
            // public form: only allow selecting the book by title
            $builder->add('livre', EntityType::class, [
                'class' => Livre::class,
                'choice_label' => 'titre',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Avis::class,
            'admin' => false,
        ]);
    }
}
