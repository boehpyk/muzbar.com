<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\Form;

/**
 * What a browser may submit to the define and edit attribute forms.
 *
 * Mutable and nullable so the Form component can re-render half-filled input beside a field error.
 * **No business constraints** (the `CategoryFormData` precedent): the value objects and the handlers
 * are the single arbiter, and the controller maps their refusals onto these fields with the failure
 * contract's messages.
 */
final class AttributeFormData
{
    public ?string $label = null;

    /** Blank means "derive one from the label" (plan D9). */
    public ?string $code = null;

    public bool $required = false;

    /** Number attributes only. Blank is sent as null: an empty `Unit` is refused (C-16). */
    public ?string $unit = null;

    public ?int $minimum = null;

    public ?int $maximum = null;

    /** Choice attributes, define only: one label per line, split by the controller. */
    public ?string $options = null;
}
