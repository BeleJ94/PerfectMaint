<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Core\HttpException;
use App\Core\Router;
use App\Domain\Role;
use PHPUnit\Framework\TestCase;

final class RouterTest extends TestCase
{
    public function testMatchesStaticAndParameterisedRoutes(): void
    {
        $router = new Router();
        $router->get('/equipment/create', ['C', 'create']);
        $router->get('/equipment/{id}', ['C', 'show'], ['permission' => 'equipment.view']);

        self::assertSame(['C', 'create'], $router->match('GET', '/equipment/create')['handler']);
        $match = $router->match('GET', '/equipment/42');
        self::assertSame(['id' => '42'], $match['params']);
        self::assertSame('equipment.view', $match['options']['permission']);
        self::assertTrue($match['options']['auth'], 'Les routes sont authentifiées par défaut');
    }

    public function testIdParametersAreNumericOnly(): void
    {
        $router = new Router();
        $router->get('/equipment/{id}', ['C', 'show']);
        $this->expectExceptionObject(HttpException::notFound());
        $router->match('GET', '/equipment/1%20OR%201=1');
    }

    public function testWrongMethodIs405(): void
    {
        $router = new Router();
        $router->post('/equipment', ['C', 'store']);
        try {
            $router->match('GET', '/equipment');
            self::fail('405 attendu');
        } catch (HttpException $e) {
            self::assertSame(405, $e->status);
        }
    }

    public function testEveryApplicationRouteUsesAKnownPermission(): void
    {
        $router = new Router();
        (require dirname(__DIR__, 3) . '/app/routes.php')($router);
        $routes = (fn () => $this->routes)->call($router);

        self::assertNotEmpty($routes);
        foreach ($routes as $route) {
            if (isset($route['options']['permission'])) {
                self::assertContains($route['options']['permission'], Role::permissions(), $route['pattern']);
            } else {
                // Seules les routes de connexion/langue/déconnexion se passent de permission.
                self::assertContains($route['pattern'], ['/login', '/logout', '/locale'], $route['pattern']);
            }
            self::assertTrue(method_exists($route['handler'][0], $route['handler'][1]), implode('::', $route['handler']));
        }
    }
}
