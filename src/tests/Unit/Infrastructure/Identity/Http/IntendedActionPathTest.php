<?php

declare(strict_types=1);

namespace App\Tests\Unit\Infrastructure\Identity\Http;

use App\Infrastructure\Identity\Http\IntendedActionPath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The one validator between an untrusted `_target_path` and a redirect (ADR-0015). Table-driven:
 * every hostile value of the feature spec must come back `null`, every honest one unchanged.
 */
final class IntendedActionPathTest extends TestCase
{
    #[DataProvider('hostileValues')]
    public function testAHostileValueIsRejected(string $value): void
    {
        self::assertNull(IntendedActionPath::fromUntrusted($value));
    }

    /**
     * The spec's hostile list, verbatim.
     *
     * @return iterable<string, array{string}>
     */
    public static function hostileValues(): iterable
    {
        yield 'protocol-relative' => ['//evil.example'];
        yield 'triple slash' => ['///evil.example'];
        yield 'slash backslash' => ['/\\evil.example'];
        yield 'backslash slash' => ['\\/evil.example'];
        yield 'double backslash' => ['\\\\evil.example'];
        yield 'absolute https' => ['https://evil.example/'];
        yield 'absolute own origin' => ['http://localhost/profile'];
        yield 'scheme without slashes' => ['http:evil.example'];
        yield 'javascript scheme' => ['javascript:alert(1)'];
        yield 'data scheme' => ['data:text/html,x'];
        yield 'bare host' => ['evil.example'];
        yield 'no leading slash' => ['profile'];
        yield 'empty' => [''];
        yield 'leading space' => [' /profile'];
        yield 'literal TAB after slash' => ["/\t/evil.example"];
        yield 'literal LF after slash' => ["/\n/evil.example"];
        yield 'literal CR after slash' => ["/\r/evil.example"];
        yield 'NUL byte' => ["/pro\0file"];
        yield 'DEL byte' => ["/pro\x7Ffile"];
        yield 'backslash deep in the path' => ['/profile\\x'];
        yield 'encoded slash' => ['/%2F/evil.example'];
        yield 'encoded slash lowercase' => ['/%2f%2fevil.example'];
        yield 'encoded backslash' => ['/%5Cevil.example'];
        yield 'encoded TAB' => ['/%09/evil.example'];
        yield 'encoded LF' => ['/%0a/evil.example'];
        yield 'double-encoded slashes' => ['/%252F%252Fevil.example'];
        yield 'triple-encoded slashes' => ['/%25252F%25252Fevil.example'];
        yield 'quadruple-encoded slashes (still changing after three rounds)' => ['/%2525252F%2525252Fevil.example'];
        yield '2049 bytes' => ['/'.str_repeat('a', IntendedActionPath::MAX_BYTES)];
    }

    #[DataProvider('acceptedValues')]
    public function testAnHonestSameOriginPathIsReturnedUnchanged(string $value): void
    {
        self::assertSame($value, IntendedActionPath::fromUntrusted($value));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function acceptedValues(): iterable
    {
        yield 'root' => ['/'];
        yield 'profile' => ['/profile'];
        yield 'root with query' => ['/?from=overlay'];
        yield 'path with padding query' => ['/profile?pad=aaa'];
        yield 'encoded space in the query' => ['/a?x=%20y'];
        yield 'encoded slash in the query' => ['/search?q=a%2Fb'];
        yield 'nested path with query' => ['/listings/new?category=bass'];
        yield 'encoded slash later in the path' => ['/a%2Fb'];
    }

    public function testExactlyTheByteLimitIsAccepted(): void
    {
        $value = '/'.str_repeat('a', IntendedActionPath::MAX_BYTES - 1);

        self::assertSame(2048, \strlen($value));
        self::assertSame($value, IntendedActionPath::fromUntrusted($value));
    }

    public function testOneByteOverTheLimitIsRejected(): void
    {
        $value = '/'.str_repeat('a', IntendedActionPath::MAX_BYTES);

        self::assertSame(2049, \strlen($value));
        self::assertNull(IntendedActionPath::fromUntrusted($value));
    }

    public function testTheLimitCountsBytesNotCharacters(): void
    {
        // 1025 two-byte characters + the slash = 2051 bytes, but only 1026 characters.
        $value = '/'.str_repeat('é', 1025);

        self::assertNull(IntendedActionPath::fromUntrusted($value));
    }

    #[DataProvider('nonStrings')]
    public function testANonStringIsRejected(mixed $value): void
    {
        self::assertNull(IntendedActionPath::fromUntrusted($value));
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function nonStrings(): iterable
    {
        yield 'null' => [null];
        yield 'empty array' => [[]];
        yield 'array holding a valid path' => [['/profile']];
        yield 'keyed array holding a valid path' => [['x' => '/profile']];
        yield 'int' => [123];
        yield 'bool' => [true];
    }
}
