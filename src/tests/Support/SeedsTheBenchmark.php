<?php

declare(strict_types=1);

namespace App\Tests\Support;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpKernel\KernelInterface;

/**
 * Runs `muzbar:search:seed-benchmark` inside the current (DAMA-wrapped) test, stdout and stderr apart.
 */
trait SeedsTheBenchmark
{
    /**
     * @param array<string, string> $options
     *
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function seed(KernelInterface $kernel, string $listings = '60', string $seed = '7', array $options = []): array
    {
        $tester = new CommandTester((new Application($kernel))->find('muzbar:search:seed-benchmark'));
        $exit = $tester->execute(['--listings' => $listings, '--seed' => $seed] + $options, ['capture_stderr_separately' => true]);

        return ['exit' => $exit, 'stdout' => $tester->getDisplay(), 'stderr' => $tester->getErrorOutput()];
    }
}
