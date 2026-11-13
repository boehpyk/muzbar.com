<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Shared\Observability;

use App\Infrastructure\Shared\Observability\SentryEventScrubber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\ExceptionDataBag;
use Sentry\Frame;
use Sentry\Stacktrace;
use Sentry\UserDataBag;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * AC-3 (scrubber half), AC-9, AC-10 and the totality contract. Every canary is asserted PRESENT in
 * the serialised input before scrubbing — otherwise "absent after" would prove nothing — and
 * ABSENT from the serialised output after. `serialize()` is used because it walks every property
 * of the event, including ones with no getter-based JSON form.
 */
final class SentryEventScrubberTest extends TestCase
{
    /** @var array<string, string> field => canary */
    private const array CANARIES = [
        'message' => 'CANARY-MSG@canary.invalid',
        'message param' => 'CANARY-PARAM@canary.invalid',
        'formatted message' => 'CANARY-FMT@canary.invalid',
        'exception value' => 'CANARY-EXC@canary.invalid',
        'previous exception value' => 'CANARY-PREV@canary.invalid',
        'extra value' => 'CANARY-EXTRA@canary.invalid',
        'nested extra value' => 'CANARY-NESTED@canary.invalid',
        'context value' => 'CANARY-CTX@canary.invalid',
        'tag value' => 'CANARY-TAG@canary.invalid',
        'transaction path' => 'CANARYTXTOKEN',
        'url reset token' => 'CANARYRESETURLTOKEN',
        'url verify token' => 'CANARYVERIFYURLTOKEN',
        'referer' => 'CANARYREFERERTOKEN',
        'query string' => 'CANARYQUERYSTRING',
        'url query' => 'CANARYURLQUERY',
        'url fragment' => 'CANARYURLFRAGMENT',
        'cookie' => 'CANARYCOOKIEVALUE',
        'authorization header' => 'CANARYBEARER',
        'set-cookie header' => 'CANARYSETCOOKIE',
        'x-forwarded-for header' => '203.0.113.77',
        'body' => 'CANARYBODYPASSWORD',
        'request env' => 'CANARYENVVALUE',
        'user email' => 'CANARY-USER@canary.invalid',
        'user username' => 'CANARYUSERNAME',
        'user ip' => '198.51.100.99',
        'frame vars' => 'CANARYFRAMEVAR',
        'breadcrumb' => 'CANARYBREADCRUMB',
        'sensitive extra key value' => 'CANARYSENSITIVEKEYVALUE',
        'sensitive context key value' => 'CANARYSENSITIVECTXVALUE',
        'sensitive tag key value' => 'CANARYSENSITIVETAGVALUE',
    ];

    public function testNoCanaryFromAnyFieldSurvivesScrubbing(): void
    {
        $scrubber = new SentryEventScrubber();
        $event = $this->loadedEvent();

        $before = serialize($event);
        foreach (self::CANARIES as $field => $canary) {
            self::assertStringContainsString($canary, $before, "Fixture is broken: canary for '$field' is not in the input.");
        }

        $scrubbed = $scrubber($event, null);

        self::assertNotNull($scrubbed);
        $after = serialize($scrubbed);
        foreach (self::CANARIES as $field => $canary) {
            self::assertStringNotContainsString($canary, $after, "Canary for '$field' leaked.");
        }
    }

    public function testEveryEmailAddressInAStringBecomesTheEmailPlaceholder(): void
    {
        $event = Event::createEvent();
        $event->setMessage('send to a@b.invalid and c@d.invalid failed', ['x@y.invalid'], 'send to a@b.invalid failed');
        $event->setExtra(['note' => 'contact x@y.invalid please']);
        $event->setTransaction('POST for o@p.invalid');

        $scrubbed = $this->scrub($event);

        self::assertSame('send to [email] and [email] failed', $scrubbed->getMessage());
        self::assertSame(['[email]'], $scrubbed->getMessageParams());
        self::assertSame('send to [email] failed', $scrubbed->getMessageFormatted());
        self::assertSame(['note' => 'contact [email] please'], $scrubbed->getExtra());
        self::assertSame('POST for [email]', $scrubbed->getTransaction());
    }

    public function testAnSmtpStyleExceptionMessageLosesTheAddress(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('550 <victim@example.invalid>: Recipient address rejected'))]);

        $scrubbed = $this->scrub($event);

        self::assertSame('550 <[email]>: Recipient address rejected', $scrubbed->getExceptions()[0]->getValue());
        self::assertSame(\RuntimeException::class, $scrubbed->getExceptions()[0]->getType());
    }

    #[DataProvider('tokenPaths')]
    public function testTheSegmentAfterATokenRouteBecomesFiltered(string $url, string $expected): void
    {
        $event = Event::createEvent();
        $event->setRequest(['url' => $url]);

        self::assertSame($expected, $this->scrub($event)->getRequest()['url']);
    }

    /** @return iterable<string, array{string, string}> */
    public static function tokenPaths(): iterable
    {
        yield 'reset' => ['https://muzbar.test/reset-password/TOKEN123', 'https://muzbar.test/reset-password/[Filtered]'];
        yield 'verify' => ['https://muzbar.test/verify-email/TOKEN123', 'https://muzbar.test/verify-email/[Filtered]'];
        yield 'query and fragment are cut' => ['https://muzbar.test/login?next=%2Fx#frag', 'https://muzbar.test/login'];
    }

    public function testRequestKeepsOnlyAllowlistedHeadersAndDropsCookiesBodyQueryAndEnv(): void
    {
        $event = Event::createEvent();
        $event->setRequest($this->loadedRequest());

        $request = $this->scrub($event)->getRequest();

        self::assertSame(
            ['User-Agent' => 'PHPUnit', 'Accept' => 'text/html', 'Accept-Language' => 'en', 'Content-Type' => 'text/html', 'Host' => 'muzbar.test'],
            $request['headers'],
        );
        foreach (['query_string', 'cookies', 'data', 'env'] as $removed) {
            self::assertArrayNotHasKey($removed, $request);
        }
        self::assertSame('GET', $request['method']);
    }

    public function testTheUserObjectIsRemovedEntirely(): void
    {
        $event = Event::createEvent();
        $user = new UserDataBag('user-1', 'u@canary.invalid', '198.51.100.99', 'CANARYUSERNAME');
        $event->setUser($user);
        self::assertNotNull($event->getUser());

        self::assertNull($this->scrub($event)->getUser());
    }

    public function testFrameVarsAndBreadcrumbsAreRemovedButSourceLocationStays(): void
    {
        $event = Event::createEvent();
        $event->setExceptions([new ExceptionDataBag(new \RuntimeException('x'), $this->stacktrace())]);
        $event->setStacktrace($this->stacktrace());
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'cat', 'CANARYBREADCRUMB')]);

        $scrubbed = $this->scrub($event);

        $frames = [...$scrubbed->getExceptions()[0]->getStacktrace()?->getFrames() ?? [], ...$scrubbed->getStacktrace()?->getFrames() ?? []];
        self::assertCount(2, $frames);
        foreach ($frames as $frame) {
            self::assertSame([], $frame->getVars());
            self::assertSame(42, $frame->getLine());
        }
        self::assertSame([], $scrubbed->getBreadcrumbs());
    }

    public function testSensitiveKeysAreFilteredAtAnyDepthCaseInsensitively(): void
    {
        $event = Event::createEvent();
        $event->setExtra([
            'Password' => 'a',
            'reset_TOKEN' => 'b',
            'client_secret' => 'c',
            'session_id' => 'd',
            'PHPSESSID' => 'd2',
            'cookie' => 'e',
            'csrf' => 'f',
            'authorization' => 'g',
            'email' => 'h',
            'sentry_dsn' => 'i',
            'nested' => ['deep' => ['api_token' => 'j', 'ok' => 'fine']],
        ]);

        $extra = $this->scrub($event)->getExtra();

        foreach (['Password', 'reset_TOKEN', 'client_secret', 'session_id', 'PHPSESSID', 'cookie', 'csrf', 'authorization', 'email', 'sentry_dsn'] as $key) {
            self::assertSame('[Filtered]', $extra[$key], $key);
        }
        self::assertSame(['deep' => ['api_token' => '[Filtered]', 'ok' => 'fine']], $extra['nested']);
    }

    /** AC-10: the denylist deliberately does not match `user_id`, and the value is untouched. */
    public function testUserIdIsRetainedInExtraAtTopLevelAndUnderMonologContext(): void
    {
        $uuid = '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b';
        $event = Event::createEvent();
        $event->setExtra(['user_id' => $uuid, 'monolog.context' => ['user_id' => $uuid, 'reason' => 'mail_down']]);

        $extra = $this->scrub($event)->getExtra();

        self::assertSame($uuid, $extra['user_id']);
        self::assertSame(['user_id' => $uuid, 'reason' => 'mail_down'], $extra['monolog.context']);
    }

    public function testSensitiveContextAndTagKeysAreFiltered(): void
    {
        $event = Event::createEvent();
        $event->setContext('session', ['id' => 'CANARYSESSION']);
        $event->setContext('runtime', ['name' => 'php', 'secret_note' => 'CANARYCTX']);
        $event->setTags(['token_kind' => 'CANARYTAG', 'channel' => 'app']);

        $scrubbed = $this->scrub($event);

        self::assertSame(['value' => '[Filtered]'], $scrubbed->getContexts()['session']);
        self::assertSame('php', $scrubbed->getContexts()['runtime']['name']);
        self::assertSame('[Filtered]', $scrubbed->getContexts()['runtime']['secret_note']);
        self::assertSame(['token_kind' => '[Filtered]', 'channel' => 'app'], $scrubbed->getTags());
    }

    public function testAnObjectInExtraThatCannotBeStringifiedIsReducedToItsTypeName(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['obj' => new \ArrayObject(['secret-ish' => 'CANARYOBJ'])]);

        $extra = $this->scrub($event)->getExtra();

        self::assertSame(\ArrayObject::class, $extra['obj']);
    }

    public function testAThrowingStringableIsFilteredRatherThanPropagated(): void
    {
        $event = Event::createEvent();
        $event->setExtra(['s' => new class implements \Stringable {
            public function __toString(): string
            {
                throw new \LogicException('nope');
            }
        }]);

        self::assertSame('[Filtered]', $this->scrub($event)->getExtra()['s']);
    }

    /** AC-3 scrubber half: every 4xx kind is dropped. */
    #[DataProvider('clientErrors')]
    public function testAClientErrorIsDropped(\Throwable $exception): void
    {
        self::assertNull((new SentryEventScrubber())(Event::createEvent(), EventHint::fromArray(['exception' => $exception])));
    }

    /** @return iterable<string, array{\Throwable}> */
    public static function clientErrors(): iterable
    {
        yield '400' => [new BadRequestHttpException()];
        yield '403' => [new AccessDeniedHttpException()];
        yield '404' => [new NotFoundHttpException()];
        yield '429' => [new TooManyRequestsHttpException()];
        yield '499' => [new HttpException(499)];
    }

    /** AC-3: a 5xx HttpException is NOT dropped, nor is a plain exception or no hint at all. */
    #[DataProvider('keptCases')]
    public function testAServerErrorOrNonHttpExceptionIsKept(?\Throwable $exception): void
    {
        $hint = null === $exception ? null : EventHint::fromArray(['exception' => $exception]);

        self::assertInstanceOf(Event::class, (new SentryEventScrubber())(Event::createEvent(), $hint));
    }

    /** @return iterable<string, array{?\Throwable}> */
    public static function keptCases(): iterable
    {
        yield '500' => [new HttpException(500)];
        yield '503' => [new ServiceUnavailableHttpException()];
        yield 'plain exception' => [new \RuntimeException('boom')];
        yield 'no exception' => [null];
    }

    public function testAMinimalEmptyEventDoesNotThrow(): void
    {
        $scrubbed = (new SentryEventScrubber())(Event::createEvent(), null);

        self::assertInstanceOf(Event::class, $scrubbed);
        self::assertNull($scrubbed->getMessage());
        self::assertSame([], $scrubbed->getExtra());
    }

    public function testMalformedUtf8DoesNotMakeTheScrubberThrowOrLeakTheAddress(): void
    {
        $event = Event::createEvent();
        $event->setMessage("bad \xB1\x31 bytes and tail@canary.invalid");

        $message = $this->scrub($event)->getMessage();

        self::assertNotNull($message);
        self::assertStringNotContainsString('tail@canary.invalid', $message);
    }

    private function scrub(Event $event): Event
    {
        $scrubbed = (new SentryEventScrubber())($event, null);
        self::assertNotNull($scrubbed);

        return $scrubbed;
    }

    private function stacktrace(): Stacktrace
    {
        return new Stacktrace([new Frame('fn', '/app/File.php', 42, 'fn', '/app/File.php', ['arg' => 'CANARYFRAMEVAR'])]);
    }

    /** @return array<string, mixed> */
    private function loadedRequest(): array
    {
        return [
            'method' => 'GET',
            'url' => 'https://muzbar.test/reset-password/CANARYRESETURLTOKEN?x=CANARYURLQUERY#CANARYURLFRAGMENT',
            'query_string' => 'a=CANARYQUERYSTRING',
            'cookies' => ['PHPSESSID' => 'CANARYCOOKIEVALUE'],
            'data' => ['password' => 'CANARYBODYPASSWORD'],
            'env' => ['REMOTE_ADDR' => 'CANARYENVVALUE'],
            'headers' => [
                'User-Agent' => 'PHPUnit',
                'Accept' => 'text/html',
                'Accept-Language' => 'en',
                'Content-Type' => 'text/html',
                'Host' => 'muzbar.test',
                'Referer' => 'https://muzbar.test/verify-email/CANARYREFERERTOKEN',
                'Cookie' => 'PHPSESSID=CANARYCOOKIEVALUE',
                'Set-Cookie' => 'PHPSESSID=CANARYSETCOOKIE',
                'Authorization' => 'Bearer CANARYBEARER',
                'X-Forwarded-For' => '203.0.113.77',
            ],
        ];
    }

    private function loadedEvent(): Event
    {
        $event = Event::createEvent();
        $event->setMessage(
            'failed for '.self::CANARIES['message'],
            [self::CANARIES['message param']],
            'failed for '.self::CANARIES['formatted message'],
        );
        $event->setTransaction('GET /verify-email/'.self::CANARIES['transaction path']);

        $previous = new ExceptionDataBag(new \LogicException(self::CANARIES['previous exception value']), $this->stacktrace());
        $outer = new ExceptionDataBag(new \RuntimeException(self::CANARIES['exception value']), $this->stacktrace());
        $event->setExceptions([$outer, $previous]);
        $event->setStacktrace($this->stacktrace());

        $event->setExtra([
            'note' => self::CANARIES['extra value'],
            'link' => '/verify-email/'.self::CANARIES['url verify token'],
            'monolog.context' => ['inner' => self::CANARIES['nested extra value'], 'user_id' => '0190a1b2-c3d4-7e5f-8a9b-0c1d2e3f4a5b'],
            'api_token' => self::CANARIES['sensitive extra key value'],
        ]);
        $event->setContext('app', ['detail' => self::CANARIES['context value'], 'csrf_state' => self::CANARIES['sensitive context key value']]);
        $event->setTags(['who' => self::CANARIES['tag value'], 'session_kind' => self::CANARIES['sensitive tag key value']]);

        $request = $this->loadedRequest();
        $event->setRequest($request);
        $event->setUser(new UserDataBag('id-1', self::CANARIES['user email'], self::CANARIES['user ip'], self::CANARIES['user username']));
        $event->setBreadcrumb([new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_DEFAULT, 'cat', self::CANARIES['breadcrumb'])]);

        return $event;
    }
}
