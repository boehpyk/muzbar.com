<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Form;

use App\Domain\Listing\ValueObject\Currency;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * The listing form; the payload is `listing_form[title|description|price|currency|category|_token]`.
 *
 * `price` is a `TextType`, not `MoneyType`: `MoneyType` converts through a float (plan D7).
 * `category_choices` is `LeafCategoryChoices::leaves()` (id => path label), keyed by id because
 * names are not unique. A choice that is not offered — a tampered value, a category deleted since
 * the page loaded — is refused by ChoiceType with the failure contract's wording; the controller
 * re-words the one case ChoiceType cannot tell apart (a leaf that has since become a branch).
 *
 * @extends AbstractType<ListingFormData>
 */
final class ListingFormType extends AbstractType
{
    public const string UNKNOWN_CATEGORY = 'Choose a category from the list.';

    public const string UNSUPPORTED_CURRENCY = 'Choose one of the listed currencies.';

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, string> $categories */
        $categories = $options['category_choices'];
        $currencies = array_map(static fn (Currency $c): string => $c->value, Currency::cases());

        $builder
            ->add('title', TextType::class, [
                'label' => 'Title',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Description',
                'required' => false,
                'empty_data' => '',
            ])
            ->add('price', TextType::class, [
                'label' => 'Price',
                'help' => 'For example 1250 or 1250.50.',
            ])
            ->add('currency', ChoiceType::class, [
                'label' => 'Currency',
                'choices' => array_combine($currencies, $currencies),
                'invalid_message' => self::UNSUPPORTED_CURRENCY,
            ])
            ->add('category', ChoiceType::class, [
                'label' => 'Category',
                'placeholder' => 'Choose a category',
                'choices' => array_keys($categories),
                'choice_label' => static fn (string $id): string => $categories[$id],
                'invalid_message' => self::UNKNOWN_CATEGORY,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => ListingFormData::class,
            'allow_extra_fields' => false,
            'csrf_protection' => true,
        ]);
        $resolver->setRequired('category_choices');
        $resolver->setAllowedTypes('category_choices', 'array');
    }
}
