<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

/**
 * The authorisation levels a User can carry (ADR-0005).
 *
 * A closed set of named constants is exactly what a backed enum models, so nothing here can hold
 * `ROLE_SUPERADMIN` by typo. The case names are TitleCase per CLAUDE.md while the backed values
 * are the literal strings Symfony Security expects — the mapping happens once, here, so no
 * `'ROLE_' . strtoupper(...)` string-building exists anywhere else in the codebase.
 *
 * A role is authorisation, not commerce: a paid *tier* is a `Billing` concept and must never be
 * added to this enum.
 */
enum Role: string
{
    case User = 'ROLE_USER';
    case Business = 'ROLE_BUSINESS';
    case Admin = 'ROLE_ADMIN';
}
