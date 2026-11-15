<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The new/edit category form; the payload is `category_form[name|slug|parent|_token]`.
 *
 * `parent_choices` is the outline (`DbalCategoryOutline::rows()`, minus the edited category on the
 * edit page). Choices are the ids and labels are looked up separately, because two categories may
 * share a name and an id-keyed `choices` array would silently merge them. An id that is not in the
 * outline is rejected by ChoiceType's own validation before any command is sent.
 *
 * @extends AbstractType<CategoryFormData>
 */
final class CategoryFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<array{id: string, name: string, depth: int}> $rows */
        $rows = $options['parent_choices'];
        $labels = [];

        foreach ($rows as $row) {
            $labels[$row['id']] = str_repeat('— ', $row['depth'] - 1).$row['name'];
        }

        $builder
            ->add('name', TextType::class, [
                'label' => 'Name',
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'required' => false,
                'help' => 'Lower-case letters, digits and single hyphens. Leave blank to derive it from the name.',
            ])
            ->add('parent', ChoiceType::class, [
                'label' => 'Parent',
                'required' => false,
                'placeholder' => '— none (root) —',
                'choices' => array_keys($labels),
                'choice_label' => static fn (string $id): string => $labels[$id],
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CategoryFormData::class,
            'allow_extra_fields' => false,
            'csrf_protection' => true,
        ]);
        $resolver->setRequired('parent_choices');
        $resolver->setAllowedTypes('parent_choices', 'array');
    }
}
