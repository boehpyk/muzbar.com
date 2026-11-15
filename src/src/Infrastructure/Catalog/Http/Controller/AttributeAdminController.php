<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Http\Controller;

use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\CategoryNotFound;
use App\Domain\Catalog\Exception\DuplicateAttributeOption;
use App\Domain\Catalog\Exception\InvalidAttributeCode;
use App\Domain\Catalog\Exception\InvalidAttributeLabel;
use App\Domain\Catalog\Exception\InvalidAttributeOptions;
use App\Domain\Catalog\Exception\InvalidNumberRules;
use App\Domain\Catalog\Exception\InvalidUnit;
use App\Domain\Catalog\ValueObject\AttributeLabel;
use App\Domain\Catalog\ValueObject\AttributeType;
use App\Infrastructure\Catalog\Form\AttributeFormData;
use App\Infrastructure\Catalog\Form\AttributeFormType;
use App\Infrastructure\Catalog\Form\CategorySlugDeriver;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalAttributeSchema;
use App\Infrastructure\Catalog\Persistence\Doctrine\DbalCategoryOutline;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Routing\Requirement\Requirement;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The admin screens for a category's attribute schema (FR-1, US-1). Unstyled on purpose.
 *
 * The same two rules as `CategoryAdminController`:
 * - **Every read goes through the DBAL read models** (`DbalAttributeSchema`, `DbalCategoryOutline`),
 *   never the EntityManager. A code or label race lost at a unique index closes the EM inside
 *   `flush()`, and the 422 re-render must still work (plan D11).
 * - **Domain refusals are not logged.** They are user errors (AC-42) and become field errors or
 *   flashes in the failure contract's wording. A corrupt `number_rules` is deliberately not caught:
 *   it is a 500 and a Sentry event.
 */
#[Route('/admin')]
#[IsGranted('ROLE_ADMIN')]
final class AttributeAdminController extends AbstractController
{
    private const string TYPES = 'choice|number|boolean|text';
    private const string UNDERIVABLE_CODE = 'Enter a code: one could not be derived from this label.';
    private const string INVALID_LABEL = 'Label must be 1–100 characters, without line breaks.';

    public function __construct(
        private readonly DbalAttributeSchema $schema,
        private readonly DbalCategoryOutline $outline,
        private readonly CategorySlugDeriver $codeDeriver,
    ) {
    }

    #[Route('/categories/{id}/attributes', name: 'admin_attribute_index', requirements: ['id' => Requirement::UUID], methods: ['GET'])]
    public function index(string $id): Response
    {
        $category = $this->category($id);

        return $this->render('catalog/admin/attributes.html.twig', [
            'category' => $category,
            'attributes' => $this->schema->forCategory($category['id']),
            'types' => AttributeType::cases(),
        ]);
    }

    #[Route('/categories/{id}/attributes/new/{type}', name: 'admin_attribute_new', requirements: ['id' => Requirement::UUID, 'type' => self::TYPES], methods: ['GET', 'POST'])]
    public function new(string $id, string $type, Request $request, DefineAttributeHandler $defineAttribute): Response
    {
        $category = $this->category($id);
        $attributeType = AttributeType::from($type);
        $data = new AttributeFormData();

        $form = $this->createForm(AttributeFormType::class, $data, ['attribute_type' => $attributeType, 'with_options' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== ($code = $this->codeFor($form, $data))) {
            try {
                $defineAttribute(new DefineAttribute(
                    $category['id'],
                    $attributeType->value,
                    (string) $data->label,
                    $code,
                    $data->required,
                    $this->unit($data),
                    $data->minimum,
                    $data->maximum,
                    $this->lines((string) $data->options),
                ));

                $this->addFlash('success', \sprintf('Defined “%s”.', trim((string) $data->label)));

                return $this->redirectToRoute('admin_attribute_index', ['id' => $category['id']], Response::HTTP_SEE_OTHER);
            } catch (CategoryNotFound) {
                // Deleted between page load and submit (failure contract).
                throw $this->createNotFoundException();
            } catch (InvalidAttributeLabel|InvalidAttributeCode|AttributeCodeAlreadyTaken|InvalidUnit|InvalidNumberRules|InvalidAttributeOptions|DuplicateAttributeOption $e) {
                $this->addRefusal($form, $e, $code, (string) $data->label);
            }
        }

        return $this->render('catalog/admin/attribute_new.html.twig', [
            'form' => $form,
            'category' => $category,
            'type' => $attributeType,
        ], $this->statusFor($form));
    }

    #[Route('/attributes/{id}/edit', name: 'admin_attribute_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, EditAttributeHandler $editAttribute): Response
    {
        $attribute = $this->schema->find($id) ?? throw $this->createNotFoundException();
        $attributeType = AttributeType::from($attribute['type']);

        $data = new AttributeFormData();
        $data->label = $attribute['label'];
        $data->code = $attribute['code'];
        $data->required = $attribute['required'];
        $data->unit = $attribute['unit'];
        $data->minimum = $attribute['minimum'];
        $data->maximum = $attribute['maximum'];

        $form = $this->createForm(AttributeFormType::class, $data, ['attribute_type' => $attributeType]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== ($code = $this->codeFor($form, $data))) {
            try {
                $editAttribute(new EditAttribute(
                    $attribute['id'],
                    (string) $data->label,
                    $code,
                    $data->required,
                    $this->unit($data),
                    $data->minimum,
                    $data->maximum,
                ));

                $this->addFlash('success', \sprintf('Saved “%s”.', trim((string) $data->label)));

                return $this->redirectToRoute('admin_attribute_index', ['id' => $attribute['categoryId']], Response::HTTP_SEE_OTHER);
            } catch (AttributeNotFound) {
                throw $this->createNotFoundException();
            } catch (InvalidAttributeLabel|InvalidAttributeCode|AttributeCodeAlreadyTaken|InvalidUnit|InvalidNumberRules $e) {
                $this->addRefusal($form, $e, $code, (string) $data->label);
            }
        }

        return $this->render('catalog/admin/attribute_edit.html.twig', [
            'form' => $form,
            'attribute' => $attribute,
            'type' => $attributeType,
            'options' => AttributeType::Choice === $attributeType ? $this->schema->optionsOf($attribute['id']) : [],
        ], $this->statusFor($form));
    }

    /**
     * @return array{id: string, parentId: ?string, name: string, slug: string, depth: int}
     */
    private function category(string $id): array
    {
        $id = mb_strtolower($id);

        return array_find($this->outline->rows(), static fn (array $row): bool => $row['id'] === $id)
            ?? throw $this->createNotFoundException();
    }

    /**
     * The code to send: the submitted one, or one derived from the label (plan D9, AC-35). Null,
     * with a field error, when neither exists — then no command is sent at all.
     *
     * @param FormInterface<AttributeFormData> $form
     */
    private function codeFor(FormInterface $form, AttributeFormData $data): ?string
    {
        $code = trim((string) $data->code);

        if ('' !== $code) {
            return $code;
        }

        $code = $this->codeDeriver->derive((string) $data->label);

        if ('' !== $code) {
            return $code;
        }

        $form->get('code')->addError(new FormError(self::UNDERIVABLE_CODE));

        // No command is sent, so the label would otherwise go unjudged; ask its value object.
        try {
            AttributeLabel::fromString((string) $data->label);
        } catch (InvalidAttributeLabel) {
            $form->get('label')->addError(new FormError(self::INVALID_LABEL));
        }

        return null;
    }

    /** A blank unit means "no unit": an empty string would be refused by `Unit` (C-16). */
    private function unit(AttributeFormData $data): ?string
    {
        return '' === trim((string) $data->unit) ? null : $data->unit;
    }

    /**
     * "One per line" is a property of the widget, so it is undone here: split on any line break,
     * trim, drop blank lines (AC-34).
     *
     * @return list<string>
     */
    private function lines(string $text): array
    {
        $lines = preg_split('/\R/u', $text);

        return array_values(array_filter(
            array_map(trim(...), false === $lines ? [$text] : $lines),
            static fn (string $line): bool => '' !== $line,
        ));
    }

    /**
     * The failure contract (feature spec): each refusal on its field, in the user's words.
     *
     * `InvalidAttributeLabel` is ambiguous at define — the attribute's label and every option line
     * are `AttributeLabel`s. The handler judges the attribute's label first, so asking the VO again
     * here tells the two apart without a second copy of the rule.
     *
     * @param FormInterface<AttributeFormData> $form
     */
    private function addRefusal(FormInterface $form, \DomainException $refusal, string $code, string $label): void
    {
        [$field, $message] = match (true) {
            $refusal instanceof InvalidAttributeLabel => $this->isValidLabel($label)
                ? ['options', 'Each option must be 1–100 characters.']
                : ['label', self::INVALID_LABEL],
            $refusal instanceof InvalidAttributeCode => ['code', 'Use lower-case letters, digits and single hyphens (max 64).'],
            $refusal instanceof AttributeCodeAlreadyTaken => ['code', \sprintf('“%s” is already used by another attribute of this category.', mb_strtolower($code))],
            $refusal instanceof InvalidUnit => ['unit', 'Unit must be 1–16 characters, without line breaks.'],
            $refusal instanceof InvalidNumberRules => ['maximum', 'Maximum must not be lower than minimum.'],
            $refusal instanceof InvalidAttributeOptions => ['options', 'A choice attribute needs at least one option.'],
            $refusal instanceof DuplicateAttributeOption => ['options', \sprintf('“%s” is listed twice.', $refusal->label()->toString())],
            default => throw $refusal,
        };

        $form->get($field)->addError(new FormError($message));
    }

    private function isValidLabel(string $label): bool
    {
        try {
            AttributeLabel::fromString($label);

            return true;
        } catch (InvalidAttributeLabel) {
            return false;
        }
    }

    /** @param FormInterface<AttributeFormData> $form */
    private function statusFor(FormInterface $form): Response
    {
        // Reaching the render after a submit means it was refused, by the form or by the domain.
        return new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }
}
