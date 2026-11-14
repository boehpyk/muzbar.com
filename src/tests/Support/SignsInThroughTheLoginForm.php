<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Signs a browser in the way a visitor does: GET `/login` for the CSRF token, POST the form. Keeps
 * the whole flow inside the one `KernelBrowser` (the session lives and dies with it).
 *
 * For use in a `WebTestCase`.
 */
trait SignsInThroughTheLoginForm
{
    private function signInThroughTheLoginForm(KernelBrowser $client, string $email, string $password): void
    {
        $crawler = $client->request('GET', '/login');
        $token = $crawler->filter('input[name="_csrf_token"]')->attr('value');
        self::assertNotNull($token);
        $client->request('POST', '/login', [
            '_username' => $email,
            '_password' => $password,
            '_csrf_token' => $token,
        ]);
        self::assertResponseRedirects();
    }
}
