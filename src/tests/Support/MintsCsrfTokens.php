<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Puts a genuine CSRF token for `$tokenId` into the browser's session (creating the session and its
 * cookie if the browser has none yet) and returns it — for a test of a route whose page the caller
 * may not even see, so a 403, 404 or redirect can only come from the rule under test, never from the
 * CSRF check. For a `WebTestCase`, with `disableReboot()` on the client.
 */
trait MintsCsrfTokens
{
    private function mintCsrfToken(KernelBrowser $client, string $tokenId): string
    {
        $container = self::getContainer();
        $factory = $container->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $stack = $container->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $tokens = $container->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $tokens);

        $session = $factory->createSession();
        $cookie = $client->getCookieJar()->get($session->getName());
        if (null !== $cookie) {
            $session->setId($cookie->getValue());
        }
        $session->start();
        $request = new Request();
        $request->setSession($session);
        $stack->push($request);
        try {
            $value = $tokens->getToken($tokenId)->getValue();
        } finally {
            $stack->pop();
        }
        $session->save();
        if (null === $cookie) {
            $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
        }

        return $value;
    }
}
