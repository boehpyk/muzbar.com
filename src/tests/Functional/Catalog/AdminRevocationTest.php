<?php

declare(strict_types=1);

namespace App\Tests\Functional\Catalog;

use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use App\Tests\Support\SignsInThroughTheLoginForm;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * AC-32: revoking `ROLE_ADMIN` from the console bites an admin who is already signed in. The test
 * asserts Symfony's user refresh does this; it does not assume it. One `KernelBrowser` throughout:
 * the session is the sign-in.
 */
final class AdminRevocationTest extends WebTestCase
{
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;
    use SignsInThroughTheLoginForm;

    private const string EMAIL = 'revoked-admin@example.com';
    private const string PASSWORD = 'a-correct-password-1';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->clearRateLimiterPool();
    }

    public function testASignedInAdminWhoseRoleIsRevokedNoLongerGetsTheAdminPage(): void
    {
        $this->registerUserWithKnownCredentials(self::EMAIL, self::PASSWORD, verified: true, admin: true);
        $this->signInThroughTheLoginForm($this->client, self::EMAIL, self::PASSWORD);
        $this->client->request('GET', '/admin/categories');
        self::assertResponseStatusCodeSame(200, 'Control: the admin must be able to see the page before revocation.');

        $command = new CommandTester((new Application(self::$kernel ?? self::fail('Kernel not booted.')))->find('muzbar:identity:grant-role'));
        self::assertSame(0, $command->execute(['email' => self::EMAIL, 'role' => 'ROLE_ADMIN', '--revoke' => true]));

        $this->client->request('GET', '/admin/categories');

        self::assertNotSame(200, $this->client->getResponse()->getStatusCode());
        self::assertStringNotContainsString('Add root category', (string) $this->client->getResponse()->getContent());
    }
}
