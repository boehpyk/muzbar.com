<?php

declare(strict_types=1);

namespace App\Infrastructure\Shared\Observability;

use Sentry\Event;
use Sentry\EventHint;
use Sentry\Stacktrace;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/**
 * Sentry's `before_send` (ADR-0013, Decision 4): the last thing an event passes before it leaves
 * the box for a third party.
 *
 * WRITTEN AS IF `send_default_pii` WERE TRUE. `sentry.yaml` sets it false and bodies to `never`,
 * but the SDK's own sanitising is treated as a bonus, not a guarantee — a future SDK default
 * change, a new integration, or someone flipping the option must not be able to leak. So every
 * rule below holds on its own, whatever the SDK did first.
 *
 * THE RESET TOKEN'S JOURNEY (ADR-0011 decision 8) is the case this class is shaped around. A live
 * account-takeover credential appears in `GET /reset-password/{token}` — the request URL, the
 * route-derived transaction name, and the `Referer` of the very next request — and is then
 * stashed in the session, which makes the session cookie a credential too. Each hop is closed:
 * token path segments become `[Filtered]` in every string, `Referer`/`Cookie` are dropped by the
 * header allowlist, and cookies, body and query string are removed outright.
 *
 * WHY `user_id` IS ALLOWED. It is a UUIDv7 — opaque outside our database, and the one thing that
 * makes an alert actionable ("which account did the password-changed notice fail for?"). It is
 * pseudonymous personal data, sent knowingly under Sentry's DPA (owner's call, 2026-11-13). The key
 * denylist deliberately does not match it; the `user` object, by contrast, is removed entirely,
 * because our user identifier *is* an e-mail address.
 *
 * TOTAL OVER THE EVENT SHAPE. Nothing here may throw: if it did, the SDK would drop the event —
 * the safe direction, but an alert lost. Unknown value types are stringified or reduced to their
 * type name, never trusted.
 *
 * The scrubber is the second line, not the first: code must still never log an address or secret.
 */
final class SentryEventScrubber
{
    private const string FILTERED = '[Filtered]';

    /** Byte-wise (no `u` flag) so malformed UTF-8 cannot make `preg_replace()` fail. */
    private const string EMAIL_PATTERN = '/[^\s@<>()\[\]"\',;:]+@[^\s@<>()\[\]"\',;:]+\.[^\s@<>()\[\]"\',;:]+/';

    private const string TOKEN_PATH_PATTERN = '#(/(?:reset-password|verify-email)/)[^/?\#\s"\'<>]+#';

    /** `user_id` is deliberately not matched — see the class docblock. */
    private const string SENSITIVE_KEY_PATTERN = '/pass|token|secret|session|cookie|csrf|auth|email|dsn/i';

    /** Lower-cased. `Referer`, `Cookie`, `Authorization`, `X-Forwarded-For` are absent on purpose. */
    private const array HEADER_ALLOWLIST = ['user-agent', 'accept', 'accept-language', 'content-type', 'host'];

    public function __invoke(Event $event, ?EventHint $hint = null): ?Event
    {
        // 1. A 4xx is a client fault, not ours. Symfony logs 4xx HttpExceptions at `error`, so
        //    without this every 404 scan and 429 would page the operator. A 5xx is kept.
        $exception = $hint?->exception;
        if ($exception instanceof HttpExceptionInterface && $exception->getStatusCode() < 500) {
            return null;
        }

        // 2. Request.
        $event->setRequest($this->scrubRequest($event->getRequest()));

        // 3. User: removed entirely.
        $event->setUser(null);

        // 4. Strings everywhere.
        $message = $event->getMessage();
        if (null !== $message) {
            $formatted = $event->getMessageFormatted();
            $event->setMessage(
                $this->scrubString($message),
                array_map($this->scrubString(...), $event->getMessageParams()),
                null === $formatted ? null : $this->scrubString($formatted),
            );
        }

        $transaction = $event->getTransaction();
        if (null !== $transaction) {
            $event->setTransaction($this->scrubString($transaction));
        }

        // 4 + 5. Values scrubbed, sensitive keys filtered — recursively, because the Monolog
        // handler nests the log context under `extra['monolog.context']`.
        $tags = [];
        foreach ($event->getTags() as $key => $tag) {
            $tags[$key] = $this->isSensitiveKey($key) ? self::FILTERED : $this->scrubString($tag);
        }
        $event->setTags($tags);
        $event->setExtra($this->scrubArray($event->getExtra()));
        foreach ($event->getContexts() as $name => $data) {
            $event->setContext(
                (string) $name,
                $this->isSensitiveKey($name) ? ['value' => self::FILTERED] : $this->scrubArray($data),
            );
        }

        // 4 + 6. Exception values (every one in the chain) and frame `vars`. Args are captured
        // because `zend.exception_ignore_args` is Off; source context lines are our code and stay.
        foreach ($event->getExceptions() as $exceptionData) {
            $exceptionData->setValue($this->scrubString($exceptionData->getValue()));
            $this->dropFrameVars($exceptionData->getStacktrace());
        }
        $this->dropFrameVars($event->getStacktrace());

        // 7. Breadcrumbs: none are configured; emptied so none can leak if one is ever added.
        $event->setBreadcrumb([]);

        return $event;
    }

    /**
     * @param array<string, mixed> $request
     *
     * @return array<string, mixed>
     */
    private function scrubRequest(array $request): array
    {
        unset($request['query_string'], $request['cookies'], $request['data'], $request['env']);

        if (isset($request['url']) && \is_string($request['url'])) {
            // Drop any query string or fragment that rode along in the URL itself.
            $request['url'] = $this->scrubString(strtok($request['url'], '?#') ?: '');
        }

        if (isset($request['headers'])) {
            $headers = [];
            if (\is_array($request['headers'])) {
                foreach ($request['headers'] as $name => $value) {
                    if (\in_array(strtolower((string) $name), self::HEADER_ALLOWLIST, true)) {
                        $headers[$name] = $this->scrubValue($value);
                    }
                }
            }
            $request['headers'] = $headers;
        }

        return $this->scrubArray($request);
    }

    /**
     * @template K of array-key
     *
     * @param array<K, mixed> $data
     *
     * @return array<K, mixed>
     */
    private function scrubArray(array $data): array
    {
        $scrubbed = [];
        foreach ($data as $key => $value) {
            $scrubbed[$key] = $this->isSensitiveKey($key) ? self::FILTERED : $this->scrubValue($value);
        }

        return $scrubbed;
    }

    private function scrubValue(mixed $value): mixed
    {
        if (\is_string($value)) {
            return $this->scrubString($value);
        }
        if (\is_array($value)) {
            return $this->scrubArray($value);
        }
        if ($value instanceof \Stringable) {
            try {
                return $this->scrubString((string) $value);
            } catch (\Throwable) {
                return self::FILTERED;
            }
        }
        if (\is_object($value)) {
            // The SDK would serialise its public properties; we cannot know what they hold.
            return get_debug_type($value);
        }

        return $value;
    }

    private function scrubString(string $value): string
    {
        $value = preg_replace(self::EMAIL_PATTERN, '[email]', $value);
        $value = null === $value ? null : preg_replace(self::TOKEN_PATH_PATTERN, '$1'.self::FILTERED, $value);

        // `preg_replace()` returns null only on a PCRE failure — then nothing of the string is safe.
        return $value ?? self::FILTERED;
    }

    private function isSensitiveKey(int|string $key): bool
    {
        return 1 === preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key);
    }

    private function dropFrameVars(?Stacktrace $stacktrace): void
    {
        foreach ($stacktrace?->getFrames() ?? [] as $frame) {
            $frame->setVars([]);
        }
    }
}
