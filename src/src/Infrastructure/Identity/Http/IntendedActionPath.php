<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Http;

/**
 * The one validator between an untrusted `_target_path` and the firewall's session target path
 * (ADR-0015).
 *
 * **There is exactly one writer: `SecurityController::login()` on `GET`.** Add a second writer and
 * it must go through here — a value that reaches the session unvalidated is an open redirect waiting
 * for a reader that forgets `HttpUtils`.
 *
 * Accepts only a same-origin path: one that, placed in a `Location` header, cannot leave our origin
 * in any browser. "Starts with `/`" is not that test, because browsers strip TAB/CR/LF from URLs and
 * treat `\` as `/`, so `/\t/evil.example` *becomes* `//evil.example`. Hence the rules below, each
 * of which kills a real bypass.
 *
 * Infrastructure, not Domain: the rule is a property of URLs and browsers, with no Identity
 * vocabulary in it. No Symfony import either, so it is unit-tested without a kernel.
 */
final class IntendedActionPath
{
    public const int MAX_BYTES = 2048;

    /**
     * Enough for any decoder chain we can imagine downstream (PHP, a proxy, a browser). A value
     * still changing after this many rounds is rejected: no honest link is encoded four times.
     */
    private const int MAX_DECODE_ROUNDS = 3;

    private function __construct()
    {
    }

    /**
     * @return string|null the value unchanged if it is a safe same-origin path, otherwise null
     */
    public static function fromUntrusted(mixed $raw): ?string
    {
        // Rule 1 — `_target_path[]=` arrives as an array.
        if (!\is_string($raw)) {
            return null;
        }

        // Rule 2 — bytes, not characters: the limit is about header and session size.
        $length = \strlen($raw);
        if ($length < 1 || $length > self::MAX_BYTES) {
            return null;
        }

        // Rule 3 — exactly one leading slash: kills `//host`, `/\host`, schemes and bare hosts.
        if ('/' !== $raw[0] || ($length > 1 && ('/' === $raw[1] || '\\' === $raw[1]))) {
            return null;
        }

        // Rule 4 — no space, control byte, DEL or backslash anywhere. A browser strips TAB/CR/LF
        // and turns `\` into `/`, so any of them can reshape the start of the path after we looked.
        if (1 === preg_match('/[\x00-\x20\x7F\\\\]/', $raw)) {
            return null;
        }

        // Rule 5 — the same start check against every decoded form a downstream decoder might
        // produce (`/%2F/evil`, `/%5Cevil`, `/%09/evil`, `/%252F%252Fevil`). Only the *start* is
        // checked: `%20` or `%2F` later in the path or query is legitimate and must survive.
        $decoded = $raw;
        for ($round = 0; $round < self::MAX_DECODE_ROUNDS; ++$round) {
            $next = rawurldecode($decoded);
            if ($next === $decoded) {
                return $raw;
            }
            $decoded = $next;
            if (str_starts_with(self::asBrowserSeesIt($decoded), '//')) {
                return null;
            }
        }

        return rawurldecode($decoded) === $decoded ? $raw : null;
    }

    /**
     * What the WHATWG URL parser does to an http(s) URL before reading it: drop TAB/CR/LF, read `\`
     * as `/`.
     */
    private static function asBrowserSeesIt(string $value): string
    {
        return str_replace(["\t", "\r", "\n", '\\'], ['', '', '', '/'], $value);
    }
}
