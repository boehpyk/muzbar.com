<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Http\Controller;

use App\Application\Catalog\Command\CreateCategory;
use App\Application\Catalog\Command\DeleteCategory;
use App\Application\Catalog\Command\EditCategory;
use App\Application\Catalog\Handler\CreateCategoryHandler;
use App\Application\Catalog\Handler\DeleteCategoryHandler;
use App\Application\Catalog\Handler\EditCategoryHandler;
use App\Domain\Catalog\Entity\Category;
use App\Domain\Catalog\Exception\CategoryHasChildren;
use App\Domain\Catalog\Exception\CategoryMoveWouldCreateCycle;
use App\Domain\Catalog\Exception\CategoryNotFound;
use App\Domain\Catalog\Exception\CategorySlugAlreadyTaken;
use App\Domain\Catalog\Exception\CategoryTreeTooDeep;
use App\Domain\Catalog\Exception\InvalidCategoryName;
use App\Domain\Catalog\Exception\InvalidCategorySlug;
use App\Domain\Catalog\ValueObject\CategoryName;
use App\Infrastructure\Catalog\Form\CategoryFormData;
use App\Infrastructure\Catalog\Form\CategoryFormType;
use App\Infrastructure\Catalog\Form\CategorySlugDeriver;
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
 * The admin screens for the category tree (FR-1). Unstyled on purpose (plan D9).
 *
 * `#[IsGranted]` is belt and braces with `security.yaml`'s anchored `^/admin(/|$)` rule, as for
 * `/account`: the attribute documents the requirement here, the rule survives a refactor.
 *
 * TWO RULES THIS CLASS KEEPS:
 * - **Every read goes through `DbalCategoryOutline`, never the EntityManager.** A slug race lost at
 *   the unique index closes the EM inside `flush()`, and the 422 re-render must still work (plan
 *   Risk 2). One outline query per page serves the index, the parent `<select>` and the edit
 *   pre-fill alike.
 * - **Domain refusals are not logged.** They are user errors (AC-33) and become field errors with
 *   the failure contract's wording; the domain messages are for operators. `CorruptCategoryTree`
 *   is deliberately not caught: it is a 500 and a Sentry event.
 */
#[Route('/admin/categories')]
#[IsGranted('ROLE_ADMIN')]
final class CategoryAdminController extends AbstractController
{
    private const string UNDERIVABLE_SLUG = 'Enter a slug: one could not be derived from this name.';

    public function __construct(
        private readonly DbalCategoryOutline $outline,
        private readonly CategorySlugDeriver $slugDeriver,
    ) {
    }

    #[Route('', name: 'admin_category_index', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('catalog/admin/index.html.twig', [
            'rows' => $this->outline->rows(),
            'maxDepth' => Category::MAX_DEPTH,
        ]);
    }

    #[Route('/new', name: 'admin_category_new', methods: ['GET', 'POST'])]
    public function new(Request $request, CreateCategoryHandler $createCategory): Response
    {
        $rows = $this->outline->rows();
        $data = new CategoryFormData();

        // An unknown or malformed `?parent=` is ignored, not an error: it only preselects.
        $parent = $request->query->all()['parent'] ?? null;

        if (\is_string($parent) && null !== $this->find($rows, $parent)) {
            $data->parent = mb_strtolower($parent);
        }

        $form = $this->createForm(CategoryFormType::class, $data, ['parent_choices' => $rows]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== ($slug = $this->slugFor($form, $data))) {
            try {
                $createCategory(new CreateCategory((string) $data->name, $slug, $data->parent));

                $this->addFlash('success', \sprintf('Created “%s”.', trim((string) $data->name)));

                return $this->redirectToRoute('admin_category_index', [], Response::HTTP_SEE_OTHER);
            } catch (InvalidCategoryName|InvalidCategorySlug|CategorySlugAlreadyTaken|CategoryTreeTooDeep|CategoryNotFound $e) {
                $this->addRefusal($form, $e, $slug);
            }
        }

        return $this->render('catalog/admin/new.html.twig', ['form' => $form], $this->statusFor($form));
    }

    #[Route('/{id}/edit', name: 'admin_category_edit', requirements: ['id' => Requirement::UUID], methods: ['GET', 'POST'])]
    public function edit(string $id, Request $request, EditCategoryHandler $editCategory): Response
    {
        $rows = $this->outline->rows();
        $category = $this->find($rows, $id) ?? throw $this->createNotFoundException();

        $data = new CategoryFormData();
        $data->name = $category['name'];
        $data->slug = $category['slug'];
        $data->parent = $category['parentId'];

        // Every category except the edited one. Its descendants stay listed: a move into one is
        // refused by the domain (C-4), which is the rule's single home (AC-27).
        $choices = array_values(array_filter($rows, static fn (array $row): bool => $row['id'] !== $category['id']));
        $form = $this->createForm(CategoryFormType::class, $data, ['parent_choices' => $choices]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid() && null !== ($slug = $this->slugFor($form, $data))) {
            try {
                $editCategory(new EditCategory($category['id'], (string) $data->name, $slug, $data->parent));

                $this->addFlash('success', \sprintf('Saved “%s”.', trim((string) $data->name)));

                return $this->redirectToRoute('admin_category_index', [], Response::HTTP_SEE_OTHER);
            } catch (CategoryNotFound $e) {
                // Either the new parent or the category itself vanished since this page was built.
                // Only the second is a 404; one more outline read tells them apart.
                if (null === $this->find($this->outline->rows(), $category['id'])) {
                    throw $this->createNotFoundException();
                }

                $this->addRefusal($form, $e, $slug);
            } catch (InvalidCategoryName|InvalidCategorySlug|CategorySlugAlreadyTaken|CategoryTreeTooDeep|CategoryMoveWouldCreateCycle $e) {
                $this->addRefusal($form, $e, $slug);
            }
        }

        return $this->render('catalog/admin/edit.html.twig', [
            'form' => $form,
            'category' => $category,
        ], $this->statusFor($form));
    }

    /** POST only: no GET may change state (ADR-0015's accepted risk, AC-28). */
    #[Route('/{id}/delete', name: 'admin_category_delete', requirements: ['id' => Requirement::UUID], methods: ['POST'])]
    public function delete(string $id, Request $request, DeleteCategoryHandler $deleteCategory): Response
    {
        if (!$this->isCsrfTokenValid('delete-category-'.$id, $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException('Invalid CSRF token.');
        }

        $category = $this->find($this->outline->rows(), $id) ?? throw $this->createNotFoundException();

        try {
            $deleteCategory(new DeleteCategory($category['id']));
            $this->addFlash('success', \sprintf('Deleted “%s”.', $category['name']));
        } catch (CategoryNotFound) {
            throw $this->createNotFoundException();
        } catch (CategoryHasChildren) {
            $this->addFlash('error', \sprintf('“%s” has subcategories. Move or delete them first.', $category['name']));
        }

        return $this->redirectToRoute('admin_category_index', [], Response::HTTP_SEE_OTHER);
    }

    /**
     * The slug to send: the submitted one, or one derived from the name (plan D11). Null, with a
     * field error, when neither exists — then no command is sent at all.
     *
     * @param FormInterface<CategoryFormData> $form
     */
    private function slugFor(FormInterface $form, CategoryFormData $data): ?string
    {
        $slug = trim((string) $data->slug);

        if ('' !== $slug) {
            return $slug;
        }

        $slug = $this->slugDeriver->derive((string) $data->name);

        if ('' !== $slug) {
            return $slug;
        }

        $form->get('slug')->addError(new FormError(self::UNDERIVABLE_SLUG));

        // No command is sent, so the name would otherwise go unjudged; ask its value object.
        try {
            CategoryName::fromString((string) $data->name);
        } catch (InvalidCategoryName $e) {
            $this->addRefusal($form, $e, $slug);
        }

        return null;
    }

    /**
     * The failure contract (feature spec): each refusal on its field, in the user's words.
     *
     * @param FormInterface<CategoryFormData> $form
     */
    private function addRefusal(FormInterface $form, \DomainException $refusal, string $slug): void
    {
        [$field, $message] = match (true) {
            $refusal instanceof InvalidCategoryName => ['name', 'Name must be 1–100 characters, without line breaks.'],
            $refusal instanceof InvalidCategorySlug => ['slug', 'Use lower-case letters, digits and single hyphens (max 64).'],
            $refusal instanceof CategorySlugAlreadyTaken => ['slug', \sprintf('“%s” is already used by another category at this level.', mb_strtolower($slug))],
            $refusal instanceof CategoryTreeTooDeep => ['parent', \sprintf('Categories can be at most %d levels deep.', Category::MAX_DEPTH)],
            $refusal instanceof CategoryMoveWouldCreateCycle => ['parent', 'A category cannot be moved inside itself.'],
            $refusal instanceof CategoryNotFound => ['parent', 'That parent no longer exists.'],
            default => throw $refusal,
        };

        $form->get($field)->addError(new FormError($message));
    }

    /** @param FormInterface<CategoryFormData> $form */
    private function statusFor(FormInterface $form): Response
    {
        // Reaching the render after a submit means it was refused, by the form or by the domain.
        return new Response(null, $form->isSubmitted() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK);
    }

    /**
     * @param list<array{id: string, parentId: ?string, name: string, slug: string, depth: int}> $rows
     *
     * @return array{id: string, parentId: ?string, name: string, slug: string, depth: int}|null
     */
    private function find(array $rows, string $id): ?array
    {
        $id = mb_strtolower($id);

        return array_find($rows, static fn (array $row): bool => $row['id'] === $id);
    }
}
