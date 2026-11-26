<?php

declare(strict_types=1);

namespace App\Infrastructure\Listing\Http\Component;

use App\Infrastructure\Catalog\Persistence\Doctrine\DbalCategoryOutline;
use App\Infrastructure\Identity\Security\SecurityUser;
use App\Infrastructure\Listing\Form\ListingFormBuilder;
use App\Infrastructure\Listing\Form\ListingFormData;
use App\Infrastructure\Listing\Form\ListingFormType;
use App\Infrastructure\Listing\Persistence\Doctrine\DbalSellerDrafts;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Form\FormFactoryInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\ComponentWithFormTrait;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * The listing form on `/sell` and the edit page (listing-wizard, ADR-0027). **It renders; it never
 * writes.** Submitting is an ordinary full-page POST to `SellController` (the `<form>` has no
 * `action`, so it posts to the page it is on); a re-render only rebuilds the form for the chosen
 * category (a "morph", plan D6). Hence: no `#[LiveAction]`, and nothing injected but read models
 * and the form builder — never a handler, a repository or the `Categories` port (AC-22).
 *
 * `method: 'post'`: with `get`, `formValues` (the CSRF token, title, description) would ride in the
 * query string into the access logs (AC-21). No `url: true` prop: a morph is not a page view (AC-23).
 *
 * The first render is the controller's form, passed in as `form` (errors and all, on a 422). Every
 * **re**-render re-checks who is asking (D7): no user or someone else's listing is a 404 — never an
 * `AccessDenied`, whose firewall entry point would overwrite the stashed target path (AC-20).
 *
 * Why `ComponentWithFormTrait` and not a plain writable prop (plan Risk 1): the trait submits
 * `formValues` in a `#[PreReRender]` hook at priority 0, so `morph()` at a higher priority sees the
 * raw values first and can rewrite them before the trait builds and submits the form.
 */
#[AsLiveComponent(method: 'post')]
final class ListingForm
{
    use ComponentWithFormTrait;
    use DefaultActionTrait;

    /** The edit page's listing; null on `/sell`. Not writable: the checksum keeps it as mounted. */
    #[LiveProp]
    public ?string $listingId = null;

    /** @var list<array{label: string, action?: string}> */
    #[LiveProp]
    public array $buttons = [];

    /**
     * What `morph()` decided for this render, read by `instantiateForm()`.
     *
     * @var array{choices: array<string, string>, schema: list<array{id: string, label: string, type: string, required: bool, unit: ?string, options: list<array{id: string, label: string}>}>, detailsFor: string, published: bool, storedCategory: ?string}|null
     */
    private ?array $spec = null;

    public function __construct(
        private readonly DbalCategoryOutline $outline,
        private readonly DbalSellerDrafts $drafts,
        private readonly ListingFormBuilder $forms,
        private readonly FormFactoryInterface $formFactory,
        private readonly Security $security,
    ) {
    }

    #[PreReRender(priority: 10)]
    public function morph(): void
    {
        $user = $this->security->getUser();

        if (!$user instanceof SecurityUser) {
            throw new NotFoundHttpException();
        }

        $listing = null;
        $stored = [];

        if (null !== $this->listingId) {
            // Re-read on every render, never carried in props (D7): ownership, status and values.
            $listing = $this->drafts->find($this->listingId, $user->id()) ?? throw new NotFoundHttpException();
            $stored = $this->drafts->valuesOf($listing['id']);
        }

        $storedCategory = $listing['categoryId'] ?? null;
        $published = 'published' === ($listing['status'] ?? null);
        $choices = $this->forms->choices($this->outline->rows(), $storedCategory);

        // D6.1: the selected category. A published listing keeps its own (L-15), whatever is
        // forced into the state (AC-7). `details()` reads nothing for a value it does not offer (AC-6).
        $selected = $this->formValues['category'] ?? null;
        $category = $published ? $storedCategory : (\is_string($selected) ? $selected : null);
        [$schema] = $this->forms->details($choices, $category, $stored);

        // D6.3: one value per field of the new schema — the submitted one when the field was on the
        // page (even `''`: a deliberate clear is not undone, AC-4), else the stored one, else none.
        // Nothing outside the schema survives (AC-3), or the form would refuse it as an extra field.
        $submitted = \is_array($this->formValues['attributes'] ?? null) ? $this->formValues['attributes'] : [];
        $attributes = [];

        foreach ($schema as $field) {
            if (\array_key_exists($field['id'], $submitted)) {
                $attributes[$field['id']] = $submitted[$field['id']];
            } elseif (isset($stored[$field['id']])) {
                $attributes[$field['id']] = $stored[$field['id']];
            }
        }

        if ([] === $schema) {
            unset($this->formValues['attributes']);
        } else {
            $this->formValues['attributes'] = $attributes;
        }

        // D6.4: what the Details on this render are for — the POST builds its form for it (D4).
        $detailsFor = null !== $category && isset($choices[$category]) ? $category : '';
        $this->formValues['details_for'] = $detailsFor;

        // A morph shows no validation errors: a half-typed price must not turn red while the
        // seller is choosing a category (D6). Not even after a 422 page, whose errors it replaces.
        $this->isValidated = false;
        $this->validatedFields = [];

        $this->spec = [
            'choices' => $choices,
            'schema' => $schema,
            'detailsFor' => $detailsFor,
            'published' => $published,
            'storedCategory' => $storedCategory,
        ];
    }

    /** @return FormInterface<ListingFormData> */
    protected function instantiateForm(): FormInterface
    {
        $spec = $this->spec ?? throw new \LogicException('The listing form is built by morph() on a re-render.');
        $data = new ListingFormData();
        // A disabled category (L-15) ignores what is submitted and keeps the model's.
        $data->category = $spec['storedCategory'];

        return $this->formFactory->create(ListingFormType::class, $data, [
            'category_choices' => $spec['choices'],
            'schema' => $spec['schema'],
            'published' => $spec['published'],
            'details_for' => $spec['detailsFor'],
        ]);
    }

    /**
     * Overrides the trait's `on(change)|*`: every field syncs its value, none re-renders. The
     * category select alone asks for a morph (AC-9). Protected, not private, only so that PHPStan
     * sees the trait's call to it.
     */
    protected function getDataModelValue(): string
    {
        return 'norender|*';
    }
}
