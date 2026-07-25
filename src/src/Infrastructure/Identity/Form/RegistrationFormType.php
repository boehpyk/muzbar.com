<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The registration form.
 *
 * Its block prefix is derived from the class name, so the request payload is
 * `registration_form[email]`, `registration_form[plainPassword][first|second]` and
 * `registration_form[_token]` — the contract the functional tests and the technical plan name.
 *
 * @extends AbstractType<RegistrationFormData>
 */
final class RegistrationFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
            ])
            // `RepeatedType` renders two children and compares them itself, so "the passwords do
            // not match" is a form-level rule that never reaches the DTO — the DTO only ever sees
            // a single agreed value. `PasswordType` defaults to `always_empty: true`, so neither
            // box is re-populated when the page re-renders after an error: a secret that survives
            // a round trip is a secret sitting in the HTML of a page a browser may cache.
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The two passwords must match.',
                'first_options' => ['label' => 'Password'],
                'second_options' => ['label' => 'Repeat password'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => RegistrationFormData::class,

            // Symfony's default, restated because it is load-bearing here rather than incidental
            // (AC-20). With extra fields rejected, a request carrying `registration_form[roles][]`
            // fails validation instead of being quietly dropped — the difference between "we
            // ignored your escalation attempt" and "we noticed it".
            'allow_extra_fields' => false,

            // Session-backed CSRF, per config/packages/csrf.yaml. The token field is
            // `registration_form[_token]`; no `csrf_token_id` is set, so the default (the block
            // prefix) applies.
            'csrf_protection' => true,
        ]);
    }
}
