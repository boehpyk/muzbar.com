<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Form;

/**
 * What a browser may submit to the new and edit category forms.
 *
 * Mutable and nullable so the Form component can re-render half-filled input beside a field
 * error. **No business constraints**: `CategoryName`, `CategorySlug` and the handlers are the single
 * arbiter (the `VerifyUserEmailCommand` precedent), and the controller maps their refusals onto
 * these fields with the failure contract's messages. A second copy of C-1/C-2 here could only drift.
 */
final class CategoryFormData
{
    public ?string $name = null;

    /** Blank means "derive one from the name" (plan D11). */
    public ?string $slug = null;

    /** A category id from the outline, or null for a root category. */
    public ?string $parent = null;
}
