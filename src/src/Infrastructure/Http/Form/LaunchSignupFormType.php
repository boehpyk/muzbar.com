<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The "notify me" form on the pre-launch stub. CSRF stays on for the reason
 * `ForgotPasswordFormType` gives: without it a third-party page could enrol any address it likes
 * from a visitor's browser, on the visitor's IP and therefore under their rate-limit budget.
 *
 * @extends AbstractType<LaunchSignupFormData>
 */
final class LaunchSignupFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => false,
                'attr' => ['placeholder' => 'placeholder', 'autocomplete' => 'email'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LaunchSignupFormData::class,
            'translation_domain' => 'coming_soon',
            'allow_extra_fields' => false,
            'csrf_protection' => true,
        ]);
    }
}
