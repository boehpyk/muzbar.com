<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\RepeatedType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The new-password form: one repeated password field and a CSRF token. Nothing else, on purpose.
 *
 * Its block prefix is derived from the class name, so the request payload is
 * `new_password_form[plainPassword][first]`, `new_password_form[plainPassword][second]` and
 * `new_password_form[_token]` — the contract the technical plan and the functional tests both name.
 *
 * **THERE IS NO TOKEN FIELD OF ANY KIND** (AC-14). See `NewPasswordFormData`, where the absence is
 * argued: the reset token lives in the session, and the form is built so it *cannot* carry one even
 * if a later change wanted it to.
 *
 * @extends AbstractType<NewPasswordFormData>
 */
final class NewPasswordFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            // Identical in shape to `RegistrationFormType`'s field, deliberately: `RepeatedType`
            // renders two children and compares them itself, so "the passwords do not match" is a
            // form-level rule that never reaches the DTO — the DTO only ever sees a single agreed
            // value. `PasswordType` defaults to `always_empty: true`, so neither box is re-populated
            // when the page re-renders after an error, and a secret does not survive a round trip
            // into the HTML of a page a browser may cache. That default matters more here than on
            // registration, because AC-23 guarantees this page *will* re-render on a weak password
            // with the same live token still stashed behind it.
            ->add('plainPassword', RepeatedType::class, [
                'type' => PasswordType::class,
                'invalid_message' => 'The two passwords must match.',
                'first_options' => ['label' => 'New password'],
                'second_options' => ['label' => 'Repeat new password'],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => NewPasswordFormData::class,

            // Symfony's default, restated because it is load-bearing here rather than incidental.
            // This is what turns `NewPasswordFormData`'s missing `token` property from "ignored" into
            // "rejected": a POST carrying `new_password_form[token]` or `new_password_form[email]`
            // fails validation instead of being silently dropped, so an attempt to name a different
            // target is a visible failure rather than a request that appears to work.
            'allow_extra_fields' => false,

            // Session-backed CSRF, per config/packages/csrf.yaml. The token field is
            // `new_password_form[_token]`; no `csrf_token_id` is set, so the default (the block
            // prefix) applies.
            //
            // THIS IS THE ONE FORM IN THE CONTEXT WHERE CSRF IS UNARGUABLE. The other two are
            // anonymous forms whose worst forged outcome is an email; this one **sets a password on
            // a real account**, and by the time it is rendered the flow already depends on a session
            // (the token stash), so there is a session to protect and a state-changing effect to
            // protect it from. Without a token, a cross-site page could submit a password of its
            // choosing on behalf of a browser that had just opened a reset link — turning a leaked
            // link into a takeover without the attacker ever reading the link.
            'csrf_protection' => true,
        ]);
    }
}
