<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use Doctrine\DBAL\Connection;
use Predis\ClientInterface as RedisClient;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Liveness vs readiness — never a bare `return 'ok'` (Constitution §7, infrastructure.md).
 *
 * - /health/live  : the process is up. No dependency calls. For Docker/Traefik liveness.
 * - /health/ready : actively probes Postgres + Redis; 503 if any dependency is down.
 */
final class HealthController extends AbstractController
{
    #[Route('/health/live', name: 'health_live', methods: ['GET'])]
    public function live(): JsonResponse
    {
        return $this->json(['status' => 'alive']);
    }

    #[Route('/health/ready', name: 'health_ready', methods: ['GET'])]
    public function ready(Connection $db, RedisClient $redis): JsonResponse
    {
        $checks = [
            'postgres' => $this->probe(static fn () => $db->executeQuery('SELECT 1')),
            'redis' => $this->probe(static fn () => $redis->ping()),
        ];

        $ready = !\in_array(false, array_column($checks, 'ok'), true);

        return $this->json(
            ['status' => $ready ? 'ready' : 'unavailable', 'checks' => $checks],
            $ready ? JsonResponse::HTTP_OK : JsonResponse::HTTP_SERVICE_UNAVAILABLE,
        );
    }

    /**
     * @param callable():mixed $probe
     *
     * @return array{ok: bool, error?: string}
     */
    private function probe(callable $probe): array
    {
        try {
            $probe();

            return ['ok' => true];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }
    }
}
