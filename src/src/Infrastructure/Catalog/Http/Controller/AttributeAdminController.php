<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Http\Controller;

use App\Application\Catalog\Command\AddAttributeOption;
use App\Application\Catalog\Command\ArchiveAttribute;
use App\Application\Catalog\Command\ArchiveAttributeOption;
use App\Application\Catalog\Command\DefineAttribute;
use App\Application\Catalog\Command\DeleteAttribute;
use App\Application\Catalog\Command\EditAttribute;
use App\Application\Catalog\Command\RelabelAttributeOption;
use App\Application\Catalog\Command\RemoveAttributeOption;
use App\Application\Catalog\Command\RestoreAttribute;
use App\Application\Catalog\Command\RestoreAttributeOption;
use App\Application\Catalog\Handler\AddAttributeOptionHandler;
use App\Application\Catalog\Handler\ArchiveAttributeHandler;
use App\Application\Catalog\Handler\ArchiveAttributeOptionHandler;
use App\Application\Catalog\Handler\DefineAttributeHandler;
use App\Application\Catalog\Handler\DeleteAttributeHandler;
use App\Application\Catalog\Handler\EditAttributeHandler;
use App\Application\Catalog\Handler\RelabelAttributeOptionHandler;
use App\Application\Catalog\Handler\RemoveAttributeOptionHandler;
use App\Application\Catalog\Handler\RestoreAttributeHandler;
use App\Application\Catalog\Handler\RestoreAttributeOptionHandler;
use App\Domain\Catalog\Exception\AttributeCodeAlreadyTaken;
use App\Domain\Catalog\Exception\AttributeInUse;
use App\Domain\Catalog\Exception\AttributeNeedsAnOption;
use App\Domain\Catalog\Exception\AttributeNotFound;
use App\Domain\Catalog\Exception\AttributeOptionNotFound;
use App\Domain\Catalog\Exception\AttributeRuleNotApplicable;
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
            'attributes' => $this->schema->effectiveFor($category['id']),
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
                $this->addRefusal($form, $e, (string) $data->label);
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
                $this->addRefusal($form, $e, (string) $data->label);
            }
        }

        return $this->render('catalog/admin/attribute_edit.html.twig', [
            'form' => $form,
            'attribute' => $attribute,
            // Tolerant, not `category()`: a missing category is no reason to 404 the edit page
            // (C-13 prevents it, but no FK does, and factory-made attributes have none).
            'categoryName' => array_find($this->outline->rows(), static fn (array $row): bool => $row['id'] === $attribute['categoryId'])['name'] ?? null,
            'type' => $attributeType,
            'options' => AttributeType::Choice === $attributeType ? $this->schema->optionsOf($attribute['id']) : [],
        ], $this->statusFor($form));
    }

    /** POST only: no GET may change state (ADR-0015's accepted risk, AC-38). */
    #[Route('/attributes/{id}/delete', name: 'admin_attribute_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request, DeleteAttributeHandler $deleteAttribute): Response
    {
        if (!$this->isCsrfTokenValid('delete-attribute-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $attribute = $this->schema->find($id) ?? throw $this->createNotFoundException();

        try {
            $deleteAttribute(new DeleteAttribute($attribute['id']));
        } catch (AttributeNotFound) {
            throw $this->createNotFoundException();
        } catch (AttributeInUse) {
            // C-25: a user error, flashed and never logged (failure contract, AC-24/AC-27).
            $this->addFlash('error', \sprintf('Listings use “%s”, so it cannot be deleted. Archive it instead — listings keep their values.', $attribute['label']));

            return $this->redirectToRoute('admin_attribute_index', ['id' => $attribute['categoryId']], Response::HTTP_SEE_OTHER);
        }

        $this->addFlash('success', \sprintf('Deleted “%s” and its options.', $attribute['label']));

        return $this->redirectToRoute('admin_attribute_index', ['id' => $attribute['categoryId']], Response::HTTP_SEE_OTHER);
    }

    /**
     * Archive and restore (ADR-0022 C-28/C-29): POST only, CSRF before any lookup (403), then 303 to
     * the defining category's list. Both are idempotent no-ops in the aggregate, so a double-submit
     * still gets the success flash. Neither can be refused: restore takes no facts (C-29).
     */
    #[Route('/attributes/{id}/archive', name: 'admin_attribute_archive', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function archive(string $id, Request $request, ArchiveAttributeHandler $archiveAttribute): Response
    {
        $attribute = $this->attributeForAction('archive-attribute-', $id, $request);

        try {
            $archiveAttribute(new ArchiveAttribute($attribute['id']));
        } catch (AttributeNotFound) {
            throw $this->createNotFoundException();
        }

        $this->addFlash('success', \sprintf('Archived “%s”. Sellers no longer see it; listings keep their values.', $attribute['label']));

        return $this->redirectToRoute('admin_attribute_index', ['id' => $attribute['categoryId']], Response::HTTP_SEE_OTHER);
    }

    #[Route('/attributes/{id}/restore', name: 'admin_attribute_restore', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function restore(string $id, Request $request, RestoreAttributeHandler $restoreAttribute): Response
    {
        $attribute = $this->attributeForAction('restore-attribute-', $id, $request);

        try {
            $restoreAttribute(new RestoreAttribute($attribute['id']));
        } catch (AttributeNotFound) {
            throw $this->createNotFoundException();
        }

        $this->addFlash('success', \sprintf('Restored “%s”.', $attribute['label']));

        return $this->redirectToRoute('admin_attribute_index', ['id' => $attribute['categoryId']], Response::HTTP_SEE_OTHER);
    }

    /**
     * The three option actions answer 303 back to the edit page, with a success or an error flash
     * (plan D11). A fresh request means a fresh EntityManager, so a duplicate label lost at the
     * unique index — which closes the EM inside `flush()` — cannot affect the page that follows.
     */
    #[Route('/attributes/{id}/options', name: 'admin_attribute_option_add', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function addOption(string $id, Request $request, AddAttributeOptionHandler $addOption): Response
    {
        $attribute = $this->attributeForOptionAction($id, $request);
        $label = $request->request->getString('label');

        $this->optionAction(
            static fn () => $addOption(new AddAttributeOption($attribute['id'], $label)),
            \sprintf('Added “%s”.', trim($label)),
        );

        return $this->redirectToRoute('admin_attribute_edit', ['id' => $attribute['id']], Response::HTTP_SEE_OTHER);
    }

    #[Route('/attributes/{id}/options/{optionId}', name: 'admin_attribute_option_relabel', requirements: ['id' => Requirement::UUID, 'optionId' => Requirement::UUID], methods: ['POST'])]
    public function relabelOption(string $id, string $optionId, Request $request, RelabelAttributeOptionHandler $relabelOption): Response
    {
        $attribute = $this->attributeForOptionAction($id, $request);
        $label = $request->request->getString('label');

        $this->optionAction(
            static fn () => $relabelOption(new RelabelAttributeOption($attribute['id'], $optionId, $label)),
            \sprintf('Renamed to “%s”.', trim($label)),
        );

        return $this->redirectToRoute('admin_attribute_edit', ['id' => $attribute['id']], Response::HTTP_SEE_OTHER);
    }

    #[Route('/attributes/{id}/options/{optionId}/delete', name: 'admin_attribute_option_remove', requirements: ['id' => Requirement::UUID, 'optionId' => Requirement::UUID], methods: ['POST'])]
    public function removeOption(string $id, string $optionId, Request $request, RemoveAttributeOptionHandler $removeOption): Response
    {
        $attribute = $this->attributeForOptionAction($id, $request);

        try {
            $this->optionAction(
                static fn () => $removeOption(new RemoveAttributeOption($attribute['id'], $optionId)),
                'Removed the option.',
            );
        } catch (AttributeInUse) {
            // C-26. The label is looked up only on this rare path; the option still exists (refused).
            $this->addFlash('error', \sprintf('Listings use “%s”, so it cannot be deleted. Archive it instead.', $this->optionLabel($optionId)));
        }

        return $this->redirectToRoute('admin_attribute_edit', ['id' => $attribute['id']], Response::HTTP_SEE_OTHER);
    }

    /**
     * Option archive / restore share the option token (`attribute-options-{id}`, plan D11 of
     * catalog-attribute-schema): the edit page may list thousands of options, one token each would be
     * thousands of session entries. The one refusal is C-18 on archive — the last active option.
     */
    #[Route('/attributes/{id}/options/{optionId}/archive', name: 'admin_attribute_option_archive', requirements: ['id' => Requirement::UUID, 'optionId' => Requirement::UUID], methods: ['POST'])]
    public function archiveOption(string $id, string $optionId, Request $request, ArchiveAttributeOptionHandler $archiveOption): Response
    {
        $attribute = $this->attributeForOptionAction($id, $request);

        $this->optionAction(
            static fn () => $archiveOption(new ArchiveAttributeOption($attribute['id'], $optionId)),
            \sprintf('Archived “%s”.', $this->optionLabel($optionId)),
            'A choice needs at least one option sellers can pick.',
        );

        return $this->redirectToRoute('admin_attribute_edit', ['id' => $attribute['id']], Response::HTTP_SEE_OTHER);
    }

    #[Route('/attributes/{id}/options/{optionId}/restore', name: 'admin_attribute_option_restore', requirements: ['id' => Requirement::UUID, 'optionId' => Requirement::UUID], methods: ['POST'])]
    public function restoreOption(string $id, string $optionId, Request $request, RestoreAttributeOptionHandler $restoreOption): Response
    {
        $attribute = $this->attributeForOptionAction($id, $request);

        $this->optionAction(
            static fn () => $restoreOption(new RestoreAttributeOption($attribute['id'], $optionId)),
            \sprintf('Restored “%s”.', $this->optionLabel($optionId)),
        );

        return $this->redirectToRoute('admin_attribute_edit', ['id' => $attribute['id']], Response::HTTP_SEE_OTHER);
    }

    /**
     * One token per attribute serves all three option actions (`attribute-options-{id}`, plan D11):
     * the edit page may list thousands of options.
     *
     * @return array{id: string, categoryId: string, code: string, label: string, type: string, required: bool, unit: ?string, minimum: ?int, maximum: ?int, optionCount: int, archivedAt: ?string}
     */
    private function attributeForOptionAction(string $id, Request $request): array
    {
        return $this->attributeForAction('attribute-options-', $id, $request);
    }

    /**
     * The CSRF token first — a 403 before any lookup — then the attribute, or a 404.
     *
     * @return array{id: string, categoryId: string, code: string, label: string, type: string, required: bool, unit: ?string, minimum: ?int, maximum: ?int, optionCount: int, archivedAt: ?string}
     */
    private function attributeForAction(string $tokenPrefix, string $id, Request $request): array
    {
        if (!$this->isCsrfTokenValid($tokenPrefix.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        return $this->schema->find($id) ?? throw $this->createNotFoundException();
    }

    /** One option's label by primary key, whatever its state; a placeholder when it is unknown. */
    private function optionLabel(string $optionId): string
    {
        return $this->schema->optionLabels([$optionId])[$optionId] ?? 'This option';
    }

    /** Runs one option command and turns the outcome into a flash (failure contract). */
    private function optionAction(\Closure $command, string $success, string $lastOption = 'A choice attribute needs at least one option. Delete the attribute instead.'): void
    {
        try {
            $command();
            $this->addFlash('success', $success);
        } catch (AttributeNotFound|AttributeOptionNotFound) {
            // Unknown, malformed or another attribute's option, or gone since the page loaded.
            throw $this->createNotFoundException();
        } catch (InvalidAttributeLabel) {
            $this->addFlash('error', 'Each option must be 1–100 characters.');
        } catch (DuplicateAttributeOption $e) {
            $this->addFlash('error', \sprintf('“%s” is already an option.', $e->label()->toString()));
        } catch (AttributeNeedsAnOption) {
            $this->addFlash('error', $lastOption);
        } catch (AttributeRuleNotApplicable) {
            // Only a tampered request reaches this: the page offers option forms for a choice only.
            $this->addFlash('error', 'That does not apply to this attribute type.');
        }
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
    private function addRefusal(FormInterface $form, \DomainException $refusal, string $label): void
    {
        [$field, $message] = match (true) {
            $refusal instanceof InvalidAttributeLabel => $this->isValidLabel($label)
                ? ['options', 'Each option must be 1–100 characters.']
                : ['label', self::INVALID_LABEL],
            $refusal instanceof InvalidAttributeCode => ['code', 'Use lower-case letters, digits and single hyphens (max 64).'],
            // One message for both named constructors: `inBranch()` (the aggregate, C-21) and
            // `inCategory()` (the adapter, a lost race at the `(category_id, code)` index).
            $refusal instanceof AttributeCodeAlreadyTaken => ['code', \sprintf('“%s” is already used by an attribute of this category or of a category above or below it.', $refusal->code()->toString())],
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
