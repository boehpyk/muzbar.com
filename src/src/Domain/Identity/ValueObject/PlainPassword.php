<?php

declare(strict_types=1);

namespace App\Domain\Identity\ValueObject;

use App\Domain\Identity\Exception\WeakPassword;

/**
 * The secret the user typed, alive only between the HTTP boundary and the hasher.
 *
 * It is a value object because the **password policy is domain knowledge**, not a form
 * annotation: the rule "at least twelve characters" must hold for a console command and an OAuth
 * link-up too, not only for the one HTML form that happens to exist today. The form's constraints
 * duplicate it on purpose — they exist for *message quality*, this exists for *correctness*.
 *
 * Everything about this class is arranged to keep the secret out of logs: the constructor and the
 * factory mark their parameter `#[\SensitiveParameter]` so PHP redacts it in stack traces,
 * `__debugInfo()` masks it, and there is deliberately **no `__toString()`** — an accidental
 * string interpolation must be a TypeError, not a leak (AC-10 asserts its absence by reflection).
 */
final readonly class PlainPassword
{
    /**
     * Twelve characters, measured in *characters*: a passphrase in Cyrillic or a handful of emoji
     * should be judged by what the human typed, not by how UTF-8 happens to encode it.
     */
    public const int MIN_LENGTH = 12;

    /**
     * Four kilobytes, measured in *bytes*: this bound is not a policy but a hard limit of
     * Symfony's `NativePasswordHasher`, which rejects longer input outright. A byte limit is
     * therefore the honest unit here — the asymmetry with MIN_LENGTH is intentional, because the
     * two numbers answer two different questions (is this password strong enough for us? / can
     * the hasher physically accept it?).
     */
    public const int MAX_LENGTH = 4096;

    private function __construct(
        #[\SensitiveParameter]
        private string $value,
    ) {
    }

    public static function fromString(#[\SensitiveParameter] string $value): self
    {
        if (mb_strlen($value) < self::MIN_LENGTH) {
            throw WeakPassword::tooShort(self::MIN_LENGTH);
        }

        if (\strlen($value) > self::MAX_LENGTH) {
            throw WeakPassword::tooLong(self::MAX_LENGTH);
        }

        return new self($value);
    }

    /**
     * The one and only reader, named to be uncomfortable.
     *
     * `toString()` would read as harmless at a call site; `reveal()` makes every place that
     * unwraps the secret conspicuous in a diff and in a grep. There should only ever be one such
     * place — the `PasswordHasher` adapter.
     */
    public function reveal(): string
    {
        return $this->value;
    }

    /**
     * @return array{value: string}
     */
    public function __debugInfo(): array
    {
        return ['value' => '***'];
    }
}
