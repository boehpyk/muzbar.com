<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Form;

use App\Domain\Listing\ValueObject\Currency;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\FormType;
use Symfony\Component\Form\Extension\Core\Type\HiddenType;
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
 * `published` (edit page only) renders `category` disabled with the reason (L-15, listing-publish D12).
 * The submit buttons live in the templates (`action=save|publish`), so the type never varies by status.
 *
 * `schema` (edit page only; null on `/sell`, so a posted `attributes` there is an extra field) adds
 * the "Details" sub-form `listing_form[attributes][<attribute id>]`, built from Catalog's admin
 * read model (plan D11), never from the Domain fact. **No field answers before the Domain** (D12):
 * number and text are `TextType` — an `IntegerType` would parse through the locale and answer in
 * its own words — and no field carries a constraint. The two `ChoiceType`s' `invalid_message` is the
 * failure contract's text, so a vanished option reads the same as a tampered one.
 *
 * `details_for` (unmapped, hidden) carries the category the rendered Details were built for, `''`
 * when none were (listing-wizard D4): the POST must build the form the page showed. It is
 * untrusted on the way back — the controller validates it against the offered choices.
 *
 * @phpstan-type SchemaField array{id: string, label: string, type: string, required: bool, unit: ?string, options: list<array{id: string, label: string}>}
 *
 * @extends AbstractType<ListingFormData>
 */
final class ListingFormType extends AbstractType
{
    public const string UNKNOWN_CATEGORY = 'Choose a category from the list.';

    public const string UNSUPPORTED_CURRENCY = 'Choose one of the listed currencies.';

    public const string NOT_AN_OPTION = 'Choose one of the listed options.';

    public const string NOT_YES_OR_NO = 'Choose Yes or No.';

    public const string PUBLISHED_CATEGORY = 'Withdraw the listing to move it to another category.';

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
                // L-15: a published listing keeps its category. Disabled, Symfony ignores a submitted
                // value and keeps the model's; the Domain's refusal is the backstop.
                'disabled' => $options['published'],
                'help' => $options['published'] ? self::PUBLISHED_CATEGORY : null,
            ])
            ->add('details_for', HiddenType::class, [
                'mapped' => false,
                'data' => $options['details_for'],
            ])
        ;

        /** @var list<SchemaField>|null $schema */
        $schema = $options['schema'];

        if (null !== $schema && [] !== $schema) {
            $builder->add(self::details($builder, $schema));
        }
    }

    /**
     * One child per attribute, named by its id, in the schema's order (root first, then label).
     *
     * @param FormBuilderInterface<ListingFormData|null> $builder
     * @param list<SchemaField>                          $schema
     *
     * @return FormBuilderInterface<mixed>
     */
    private static function details(FormBuilderInterface $builder, array $schema): FormBuilderInterface
    {
        $details = $builder->create('attributes', FormType::class, ['label' => false]);

        foreach ($schema as $field) {
            $common = [
                'label' => null !== $field['unit'] && 'number' === $field['type']
                    ? \sprintf('%s (%s)', $field['label'], $field['unit'])
                    : $field['label'],
                'help' => $field['required'] ? 'required to publish' : null,
                // A draft may leave any detail empty (L-11): "required" is a publish rule.
                'required' => false,
            ];

            $details->add($field['id'], ...match ($field['type']) {
                'choice' => [ChoiceType::class, $common + [
                    'placeholder' => '—',
                    'choices' => array_column($field['options'], 'id'),
                    'choice_label' => static fn (string $id): string => array_column($field['options'], 'label', 'id')[$id] ?? $id,
                    'invalid_message' => self::NOT_AN_OPTION,
                ]],
                'boolean' => [ChoiceType::class, $common + [
                    'placeholder' => '—',
                    'choices' => ['Yes' => 'yes', 'No' => 'no'],
                    'invalid_message' => self::NOT_YES_OR_NO,
                ]],
                default => [TextType::class, $common],
            });
        }

        return $details;
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
        $resolver->setDefault('published', false);
        $resolver->setAllowedTypes('published', 'bool');
        $resolver->setDefault('schema', null);
        $resolver->setAllowedTypes('schema', ['null', 'array']);
        $resolver->setDefault('details_for', '');
        $resolver->setAllowedTypes('details_for', 'string');
    }
}
