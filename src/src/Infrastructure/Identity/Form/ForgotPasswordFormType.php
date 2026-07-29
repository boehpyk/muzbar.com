<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The forgot-password form: one field and a CSRF token.
 *
 * The block prefix is derived from the class name by `StringUtil::fqcnToBlockPrefix()` — strip the
 * `Type` suffix, snake_case the rest — so the request payload is `forgot_password_form[email]` and
 * `forgot_password_form[_token]`. That is the wire contract the functional tests and the technical
 * plan both name, and it is worth knowing it comes from the *class name*: renaming this class
 * silently renames every field the browser posts.
 *
 * @extends AbstractType<ForgotPasswordFormData>
 */
final class ForgotPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('email', EmailType::class, [
                'label' => 'Email',
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ForgotPasswordFormData::class,

            // Symfony's default, restated because on this form it is load-bearing rather than
            // incidental — the same argument `ResendVerificationFormType` makes. This endpoint is
            // anonymous, unauthenticated and reachable by anyone, and it is the one an attacker
            // probes address-by-address. With extra fields rejected, a request carrying
            // `forgot_password_form[userId]` fails validation instead of being silently dropped:
            // the difference between "we ignored what you tried" and "we noticed".
            'allow_extra_fields' => false,

            // Session-backed CSRF, per config/packages/csrf.yaml. No `csrf_token_id` is set, so the
            // default (the block prefix) applies and the field is `forgot_password_form[_token]`.
            //
            // WORTH DEFENDING, BECAUSE THE ARGUMENT AGAINST IT SOUNDS REASONABLE: this form is
            // anonymous and has no session to protect, so "CSRF is meaningless here" is a tempting
            // conclusion. It is wrong twice over, and more sharply than on the resend form. A token
            // means a cross-site page cannot silently make a visitor's browser send a chosen address
            // an alarming "someone asked to reset your password" mail — a spam-amplification
            // primitive with somebody else's IP on it, and one that also **destroys that account's
            // outstanding reset link** (ADR-0011 decision 4), so the forgery has a real effect on a
            // third party rather than merely costing us an email. The per-IP limiter, which is the
            // other defence, is keyed on the *victim's* address in exactly that attack. A token also
            // makes the endpoint unusable from a script that has not first fetched the form, which
            // is a real cost to casual abuse for one hidden input.
            'csrf_protection' => true,
        ]);
    }
}
