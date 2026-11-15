<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Form;

use App\Domain\Catalog\ValueObject\AttributeType;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\IntegerType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The define/edit attribute form; the payload is `attribute_form[label|code|required|…|_token]`.
 *
 * The fields depend on the attribute's type, which is chosen before the form (`/new/{type}`) and
 * fixed afterwards (C-14), so no JavaScript is needed (plan D11):
 * - always `label`, `code` (blank = derive) and `required`;
 * - a number adds `unit`, `minimum` and `maximum`;
 * - a choice adds the `options` textarea, **at define only** — afterwards options are their own
 *   aggregates with their own small forms.
 *
 * Extra fields stay disallowed (Symfony's default), so posting number fields to a non-number
 * attribute's form is a form error and a 422 before any command is sent (AC-37).
 *
 * @extends AbstractType<AttributeFormData>
 */
final class AttributeFormType extends AbstractType
{
    private const string NOT_A_WHOLE_NUMBER = 'Enter a whole number.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('label', TextType::class, [
                'label' => 'Label',
            ])
            ->add('code', TextType::class, [
                'label' => 'Code',
                'required' => false,
                'help' => 'Lower-case letters, digits and single hyphens. Leave blank to derive it from the label.',
            ])
            ->add('required', CheckboxType::class, [
                'label' => 'Required',
                'required' => false,
            ])
        ;

        if (AttributeType::Number === $options['attribute_type']) {
            $builder
                ->add('unit', TextType::class, [
                    'label' => 'Unit',
                    'required' => false,
                    'help' => 'Optional, e.g. mm, kg, W.',
                ])
                ->add('minimum', IntegerType::class, [
                    'label' => 'Minimum',
                    'required' => false,
                    'invalid_message' => self::NOT_A_WHOLE_NUMBER,
                ])
                ->add('maximum', IntegerType::class, [
                    'label' => 'Maximum',
                    'required' => false,
                    'invalid_message' => self::NOT_A_WHOLE_NUMBER,
                ])
            ;
        }

        if (AttributeType::Choice === $options['attribute_type'] && $options['with_options']) {
            $builder->add('options', TextareaType::class, [
                'label' => 'Options',
                'required' => false,
                'help' => 'One per line.',
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AttributeFormData::class,
            'allow_extra_fields' => false,
            'csrf_protection' => true,
            'with_options' => false,
        ]);
        $resolver->setRequired('attribute_type');
        $resolver->setAllowedTypes('attribute_type', AttributeType::class);
        $resolver->setAllowedTypes('with_options', 'bool');
    }
}
