<?php

declare(strict_types=1);

namespace App\Tests\Functional\Search;

use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Routing\RouterInterface;

/** search-ui AC-2: `app_browse` is the only route served from `Infrastructure/Search`. */
final class SearchRouteTest extends KernelTestCase
{
    public function testTheOnlySearchRouteIsGetBrowse(): void
    {
        $router = self::getContainer()->get('router');
        self::assertInstanceOf(RouterInterface::class, $router);

        $search = [];
        $all = 0;
        foreach ($router->getRouteCollection() as $name => $route) {
            ++$all;
            $controller = $route->getDefault('_controller');
            if (\is_string($controller) && str_starts_with($controller, 'App\\Infrastructure\\Search\\')) {
                $search[$name] = [$route->getPath(), $route->getMethods()];
            }
        }

        self::assertGreaterThan(10, $all, 'The router is empty: the scan would pass vacuously.');
        self::assertSame(['app_browse' => ['/browse', ['GET']]], $search);
    }
}
