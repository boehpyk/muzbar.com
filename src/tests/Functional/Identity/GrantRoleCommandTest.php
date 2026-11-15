<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Domain\Identity\ValueObject\Email;
use App\Tests\Factory\UserFactory;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * AC-31: `muzbar:identity:grant-role` against the real database (DAMA rollback). Roles are read
 * back from the row through DBAL, never from an identity map.
 */
final class GrantRoleCommandTest extends KernelTestCase
{
    private CommandTester $command;
    private Connection $db;

    protected function setUp(): void
    {
        self::bootKernel();
        $db = self::getContainer()->get(Connection::class);
        self::assertInstanceOf(Connection::class, $db);
        $this->db = $db;
        $this->command = new CommandTester((new Application(self::$kernel ?? self::fail('Kernel not booted.')))->find('muzbar:identity:grant-role'));
    }

    /**
     * @return list<string>
     */
    private function rolesOf(string $email): array
    {
        $json = $this->db->fetchOne('SELECT roles FROM identity_user WHERE email = ?', [$email]);
        self::assertIsString($json);
        $roles = json_decode($json, true, flags: \JSON_THROW_ON_ERROR);
        self::assertIsArray($roles);

        return array_values(array_map(static fn (mixed $role): string => \is_string($role) ? $role : self::fail('Non-string role.'), $roles));
    }

    private function user(string $email): void
    {
        UserFactory::createOne(['email' => Email::fromString($email)]);
    }

    public function testGrantingAdminSucceedsAndStoresTheRole(): void
    {
        $this->user('grant@example.com');

        $exit = $this->command->execute(['email' => 'grant@example.com', 'role' => 'ROLE_ADMIN']);

        self::assertSame(0, $exit);
        self::assertContains('ROLE_ADMIN', $this->rolesOf('grant@example.com'));
    }

    public function testGrantingTwiceIsIdempotent(): void
    {
        $this->user('twice@example.com');
        $this->command->execute(['email' => 'twice@example.com', 'role' => 'ROLE_ADMIN']);
        $after = $this->rolesOf('twice@example.com');

        $exit = $this->command->execute(['email' => 'twice@example.com', 'role' => 'ROLE_ADMIN']);

        self::assertSame(0, $exit);
        self::assertSame($after, $this->rolesOf('twice@example.com'));
        self::assertSame(1, \count(array_keys($after, 'ROLE_ADMIN', true)));
    }

    public function testRevokingRemovesTheRole(): void
    {
        $this->user('revoke@example.com');
        $this->command->execute(['email' => 'revoke@example.com', 'role' => 'ROLE_ADMIN']);

        $exit = $this->command->execute(['email' => 'revoke@example.com', 'role' => 'ROLE_ADMIN', '--revoke' => true]);

        self::assertSame(0, $exit);
        self::assertNotContains('ROLE_ADMIN', $this->rolesOf('revoke@example.com'));
    }

    public function testAnUnknownEmailExitsOneWithTheSpecifiedMessage(): void
    {
        $exit = $this->command->execute(['email' => 'nobody@example.com', 'role' => 'ROLE_ADMIN']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No user found with email "nobody@example.com"', $this->command->getDisplay());
    }

    public function testAMalformedEmailIsAnUnknownEmailNotACrash(): void
    {
        $exit = $this->command->execute(['email' => 'not-an-email', 'role' => 'ROLE_ADMIN']);

        self::assertSame(1, $exit);
        self::assertStringContainsString('No user found with email "not-an-email"', $this->command->getDisplay());
    }

    public function testAnUnknownRoleExitsOneListsTheValidRolesAndChangesNothing(): void
    {
        $this->user('badrole@example.com');
        $before = $this->rolesOf('badrole@example.com');

        $exit = $this->command->execute(['email' => 'badrole@example.com', 'role' => 'ROLE_SUPERHERO']);

        self::assertSame(1, $exit);
        $display = $this->command->getDisplay();
        foreach (['ROLE_USER', 'ROLE_BUSINESS', 'ROLE_ADMIN'] as $valid) {
            self::assertStringContainsString($valid, $display);
        }
        self::assertSame($before, $this->rolesOf('badrole@example.com'));
    }

    public function testRevokingRoleUserExitsOneWithTheBaseRoleMessageAndChangesNothing(): void
    {
        $this->user('base@example.com');
        $before = $this->rolesOf('base@example.com');

        $exit = $this->command->execute(['email' => 'base@example.com', 'role' => 'ROLE_USER', '--revoke' => true]);

        self::assertSame(1, $exit);
        self::assertStringContainsString('ROLE_USER is the base role every user holds and cannot be revoked.', $this->command->getDisplay());
        self::assertSame($before, $this->rolesOf('base@example.com'));
        self::assertContains('ROLE_USER', $this->rolesOf('base@example.com'));
    }
}
