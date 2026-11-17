<?php

declare(strict_types=1);

namespace App\Tests\Functional\Identity;

use App\Tests\Support\ClearsRateLimiters;
use App\Tests\Support\RegistersAUserWithKnownCredentials;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The markup contract the overlay script and the no-JS path both rely on (identity-login-overlay
 * AC-21 to AC-24): the auth panel, the Google links, the layout's "Profile" trigger, and the whole
 * thing working with JavaScript off.
 */
final class LoginOverlayMarkupTest extends WebTestCase
{
    use ClearsRateLimiters;
    use RegistersAUserWithKnownCredentials;

    private const string EMAIL = 'overlay@example.com';
    private const string PASSWORD = 'a-correct-password-1';

    private KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->clearRateLimiterPool();
    }

    // ---- AC-21: the panel contract ------------------------------------------------------------

    public function testLoginHasExactlyOneAuthPanelLabelledByAHeadingInsideIt(): void
    {
        $crawler = $this->client->request('GET', '/login');

        self::assertCount(1, $crawler->filter('[data-auth-panel]'));
        $labelledBy = $crawler->filter('[data-auth-panel]')->attr('aria-labelledby');
        self::assertNotNull($labelledBy);
        self::assertNotSame('', $labelledBy);
        self::assertCount(1, $crawler->filter('[data-auth-panel] [id="'.$labelledBy.'"]'), 'The labelling element must live inside the panel.');
        self::assertSame('Sign in', trim($crawler->filter('[data-auth-panel] [id="'.$labelledBy.'"]')->text()));
    }

    public function testThePanelHoldsTheSignInFormWithItsThreeInputs(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $form = $crawler->filter('[data-auth-panel] form[method="post"][action="/login"]');
        self::assertCount(1, $form);
        foreach (['_username', '_password', '_csrf_token'] as $name) {
            self::assertCount(1, $form->filter('input[name="'.$name.'"]'), $name);
        }
    }

    public function testThePanelHoldsTheGoogleRegisterResetAndResendLinks(): void
    {
        $crawler = $this->client->request('GET', '/login');

        foreach (['/connect/google', '/register', '/forgot-password', '/verify-email/resend'] as $href) {
            self::assertCount(1, $crawler->filter('[data-auth-panel] a[href="'.$href.'"]'), $href);
        }
    }

    public function testNoLinkInThePanelCarriesATargetPath(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $hrefs = $crawler->filter('[data-auth-panel] a')->each(static fn (Crawler $a): string => (string) $a->attr('href'));
        self::assertNotEmpty($hrefs, 'The panel must have links, or this assertion is vacuous.');
        foreach ($hrefs as $href) {
            self::assertStringNotContainsString('_target_path', $href);
        }
    }

    // ---- AC-22: "Continue with Google" on both pages (identity-google-oauth AC-4) -------------

    public function testLoginOffersContinueWithGoogle(): void
    {
        $crawler = $this->client->request('GET', '/login');

        $link = $crawler->filter('a[href="/connect/google"]');
        self::assertCount(1, $link);
        self::assertSame('Continue with Google', trim($link->text()));
    }

    public function testRegisterOffersContinueWithGoogle(): void
    {
        $crawler = $this->client->request('GET', '/register');

        $link = $crawler->filter('a[href="/connect/google"]');
        self::assertCount(1, $link);
        self::assertSame('Continue with Google', trim($link->text()));
    }

    // ---- AC-23: the layout trigger ------------------------------------------------------------

    public function testAGuestSeesExactlyOneProfileTriggerPointingAtLoginWithTheTarget(): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');

        // Selected by text on purpose (listing-draft AC-22): "Sell Gear" is a second trigger now.
        $trigger = $crawler->filter('a[data-auth-overlay]')->reduce(static fn (Crawler $a): bool => 'Profile' === trim($a->text()));
        self::assertCount(1, $trigger);

        $href = $trigger->attr('href');
        self::assertIsString($href);
        self::assertSame('/login', parse_url($href, \PHP_URL_PATH));
        $query = parse_url($href, \PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertSame('/profile', $params['_target_path'] ?? null);
    }

    public function testTheSignInAndRegisterPagesHaveNoTrigger(): void
    {
        foreach (['/login', '/register'] as $page) {
            $crawler = $this->client->request('GET', $page);
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('a[data-auth-overlay]'), $page);
        }
    }

    public function testASignedInUserSeesAPlainProfileLinkAndNoTrigger(): void
    {
        $this->registerUserWithKnownCredentials(self::EMAIL, self::PASSWORD, verified: true);
        $this->signInFromTheForm();

        $crawler = $this->client->request('GET', '/account');

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('a[data-auth-overlay]'));
        self::assertCount(1, $crawler->filter('a[href="/profile"]'));
    }

    // ---- listing-draft AC-22: "Sell Gear" is the second trigger ---------------------------------

    public function testAGuestSeesExactlyOneSellGearTriggerPointingAtLoginWithTheSellTarget(): void
    {
        $crawler = $this->client->request('GET', '/forgot-password');

        $trigger = $crawler->filter('a[data-auth-overlay]')->reduce(static fn (Crawler $a): bool => 'Sell Gear' === trim($a->text()));
        self::assertCount(1, $trigger);
        self::assertCount(2, $crawler->filter('a[data-auth-overlay]'), 'Sell Gear and Profile, nothing else.');

        $href = $trigger->attr('href');
        self::assertIsString($href);
        self::assertSame('/login', parse_url($href, \PHP_URL_PATH));
        $query = parse_url($href, \PHP_URL_QUERY);
        self::assertIsString($query);
        parse_str($query, $params);
        self::assertSame('/sell', $params['_target_path'] ?? null);
    }

    public function testASignedInUserSeesAPlainSellGearLinkAndNoTrigger(): void
    {
        $this->registerUserWithKnownCredentials(self::EMAIL, self::PASSWORD, verified: true);
        $this->signInFromTheForm();

        $crawler = $this->client->request('GET', '/account');

        self::assertCount(0, $crawler->filter('a[data-auth-overlay]'));
        $link = $crawler->filter('a[href="/sell"]');
        self::assertCount(1, $link);
        self::assertSame('Sell Gear', trim($link->text()));
    }

    public function testTheSignInAndRegisterPagesShowNoSellGearLink(): void
    {
        foreach (['/login', '/register'] as $page) {
            $crawler = $this->client->request('GET', $page);

            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->selectLink('Sell Gear'), $page);
        }
    }

    // ---- AC-24: no JavaScript, end to end -----------------------------------------------------

    public function testWithoutJavaScriptTheProfileLinkLeadsThroughSignInToProfile(): void
    {
        $this->registerUserWithKnownCredentials(self::EMAIL, self::PASSWORD, verified: true);

        $this->client->request('GET', '/forgot-password');
        $crawler = $this->client->clickLink('Profile');
        self::assertResponseStatusCodeSame(200);
        self::assertCount(1, $crawler->filter('[data-auth-panel]'));

        $this->client->submitForm('Sign in', ['_username' => self::EMAIL, '_password' => self::PASSWORD]);

        self::assertResponseStatusCodeSame(302);
        self::assertSame('http://localhost/profile', $this->client->getResponse()->headers->get('Location'));
        $this->client->followRedirect();
        self::assertResponseStatusCodeSame(200);
        self::assertSelectorTextSame('h1', 'Profile');
    }

    private function signInFromTheForm(): void
    {
        $this->client->request('GET', '/login');
        $this->client->submitForm('Sign in', ['_username' => self::EMAIL, '_password' => self::PASSWORD]);
        self::assertResponseRedirects();
    }
}
