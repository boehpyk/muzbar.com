<?php

declare(strict_types=1);

namespace App\Infrastructure\Identity\Presentation;

/**
 * A challenge lifetime, as a phrase a human reads. One implementation, every surface that states it.
 *
 * WHY THIS IS A CLASS AND NOT AN EXPRESSION IN A TEMPLATE. `PasswordResetRequest::LIFETIME_SECONDS`
 * is the source of truth for how long a reset link lives, and the point of a source of truth is that
 * **nothing may state the number independently**. Three surfaces state it — the HTML mail, the text
 * mail, and the "check your inbox" page — and the page originally typed "one hour" as a literal,
 * which meant tightening the constant (something ADR-0011 decision 3 explicitly weighs and could
 * revisit) would have left one of the three lying, with every test still green because no assertion
 * connects a paragraph of prose to a domain constant. Derivation is what makes that unrepresentable.
 *
 * WHY IT PLURALISES IN PHP RATHER THAN IN TWIG. `TwigVerificationMailer` gets away with passing an
 * integer number of hours and letting its templates write the word "hours" after it, because 24 is
 * never 1. This lifetime is 3600 seconds, so the same `intdiv(…, 3600)` renders "expires in 1
 * hours"; and at 1800 it would render "expires in 0 hours", which is not merely ungrammatical but
 * **false**, in the one sentence a locked-out user acts on. A formatting scheme that breaks when the
 * constant changes gives away exactly the guarantee the constant was supposed to provide. Putting
 * the rule in Twig would also mean the identical expression in three template files — three copies
 * that can drift while all three stay green.
 *
 * STATIC, AND NOT MEANT TO BE A SERVICE — WHICH TAKES THE PRIVATE CONSTRUCTOR BELOW, BECAUSE BEING
 * STATIC DOES NOT ACHIEVE IT ON ITS OWN. `services.yaml` registers `App\` from `../src/` and excludes
 * only `Domain/`, `Kernel.php` and the DBAL `Type/` directories, so this class *does* get a service
 * definition — autowired, autoconfigured, and merely pruned afterwards as unused (`debug:container`
 * says so in as many words). "Static, therefore not a service" was wrong about the container, and a
 * class that is instantiable is a class somebody will eventually inject, at which point the "no
 * state, no collaborators" argument below is one constructor argument away from being false. The
 * private constructor is what makes the sentence true rather than aspirational; the `Type/`
 * exclusion is the other way of doing it, and is right there for the case where a whole directory
 * needs it.
 *
 * The argument itself: it has no collaborators, no configuration and no state — it is a pure
 * function of an integer. Injecting it would add a constructor argument to two adapters in order to
 * make a total function look like a dependency. It lives in `Presentation/` rather than next to
 * either caller because both a mail adapter and an HTTP controller use it, and neither should have
 * to import from the other's directory.
 */
final class LifetimePhrase
{
    private function __construct()
    {
    }

    /**
     * Whole hours where the duration divides evenly, whole minutes otherwise — always with the noun
     * agreeing with the number.
     *
     * Rounds **up** to the minute on a remainder (`ceil`), because the alternative under-states how
     * long a link lives, and of the two lies that is the one that makes a user abandon a link that
     * still works.
     */
    public static function forSeconds(int $seconds): string
    {
        if (0 === $seconds % 3600) {
            $hours = intdiv($seconds, 3600);

            return \sprintf('%d %s', $hours, 1 === $hours ? 'hour' : 'hours');
        }

        $minutes = (int) ceil($seconds / 60);

        return \sprintf('%d %s', $minutes, 1 === $minutes ? 'minute' : 'minutes');
    }
}
