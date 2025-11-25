<?php

namespace App\Form;

use App\Entity\Contact;
use App\Entity\User;
use Symfony\Bridge\Doctrine\Form\Type\EntityType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ContactAdminType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('destinataire', EntityType::class, [
                'class' => User::class,
                'choice_label' => function (User $user) {
                    return sprintf('%s %s (%s)', $user->getPrenom(), $user->getNom(), $user->getEmail());
                },
                'placeholder' => 'Sélectionnez un utilisateur',
                'attr' => ['class' => 'select2'],
                'required' => true,
            ])
            ->add('sujet', TextType::class, [
                'attr' => [
                    'placeholder' => 'Sujet du message',
                    'maxlength' => 255
                ]
            ])
            ->add('categorie', ChoiceType::class, [
                'choices' => [
                    'ℹ️ Information' => 'information',
                    '⚠️ Important' => 'important',
                    '🔧 Technique' => 'technique',
                    '❓ Question' => 'question',
                ],
                'attr' => ['class' => 'select2'],
                'placeholder' => 'Choisir une catégorie'
            ])
            ->add('message', TextareaType::class, [
                'attr' => [
                    'rows' => 8,
                    'placeholder' => 'Contenu de votre message...',
                    'class' => 'tinymce'
                ]
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Contact::class,
        ]);
    }
}