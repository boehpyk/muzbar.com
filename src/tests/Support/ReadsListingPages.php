<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

/**
 * Helpers shared by the listing-publish functional tests (`SellPublishTest`, `PublicListingTest`).
 * For use in a `WebTestCase` that has a `$client` of type `KernelBrowser`.
 */
trait ReadsListingPages
{
    /**
     * The test-env 404 page embeds ids, line numbers and per-render dump ids; strip what varies per
     * request so what is left is what the page *says* (the `SellTest` technique).
     *
     * @param list<string> $ids
     */
    private function normalised(string $body, array $ids): string
    {
        $body = str_replace($ids, 'ID', $body);
        $body = preg_replace('#<(script|style)\b.*?</\1>#s', '', $body) ?? '';

        return preg_replace('/sf-dump-\d+|\d+/', 'N', $body) ?? '';
    }

    /**
     * A CSRF token for `$tokenId`, valid in `$client`'s current session — minted by the real token
     * manager against the last request's session, then saved, so a page need not have rendered it.
     * (A bad-token test needs the opposite and just posts garbage.).
     */
    private function mintToken(KernelBrowser $client, string $tokenId): string
    {
        $request = $client->getRequest();
        $stack = self::getContainer()->get('request_stack');
        self::assertInstanceOf(RequestStack::class, $stack);
        $manager = self::getContainer()->get('security.csrf.token_manager');
        self::assertInstanceOf(CsrfTokenManagerInterface::class, $manager);

        $stack->push($request);
        try {
            $value = $manager->getToken($tokenId)->getValue();
            $request->getSession()->save();
        } finally {
            $stack->pop();
        }

        return $value;
    }
}
