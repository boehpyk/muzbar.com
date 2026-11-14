<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\BrowserKit\Cookie;
use Symfony\Component\HttpFoundation\Session\SessionFactoryInterface;

/**
 * Simulates a careless future writer: puts a value straight into the firewall's target path,
 * bypassing `IntendedActionPath`, and hands the browser the session cookie. The one place a test
 * touches `_security.main.target_path` (identity-login-overlay AC-14), because its purpose is to
 * prove the readers are safe even when the single validated writer is bypassed.
 *
 * For use in a `WebTestCase`; call before the first request of the scenario.
 */
trait StashesTargetPath
{
    private function stashTargetPathDirectly(KernelBrowser $client, string $value): void
    {
        $factory = self::getContainer()->get('session.factory');
        self::assertInstanceOf(SessionFactoryInterface::class, $factory);
        $session = $factory->createSession();
        $session->set('_security.main.target_path', $value);
        $session->save();
        $client->getCookieJar()->set(new Cookie($session->getName(), $session->getId()));
    }
}
