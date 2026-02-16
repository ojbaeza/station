<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Security;

use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Station\Dashboard\Http\Middleware\Authorize;
use Station\Tests\TestCase;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class AuthorizeMiddlewareTest extends TestCase
{
    private Authorize $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new Authorize();
    }

    public function testDeniesAccessWhenDashboardDisabled(): void
    {
        config(['station.dashboard.enabled' => false]);

        $request = Request::create('/station/dashboard');

        $this->expectException(NotFoundHttpException::class);

        $this->middleware->handle($request, static fn() => response('OK'));
    }

    public function testAllowsAccessInLocalEnvironment(): void
    {
        config(['station.dashboard.enabled' => true]);

        $this->app['env'] = 'local';

        $request = Request::create('/station/dashboard');
        $called = false;

        $response = $this->middleware->handle($request, static function () use (&$called) {
            $called = true;

            return response('OK');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testLocalEnvironmentBypassesGateCheck(): void
    {
        config([
            'station.dashboard.enabled' => true,
            'station.dashboard.authorization' => 'viewStation',
        ]);

        Gate::define('viewStation', static fn($user = null) => false);

        $this->app['env'] = 'local';

        $request = Request::create('/station/dashboard');
        $called = false;

        $response = $this->middleware->handle($request, static function () use (&$called) {
            $called = true;

            return response('OK');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllowsAccessWhenNoAuthorizationGateConfigured(): void
    {
        config(['station.dashboard.authorization' => null]);

        $request = Request::create('/station/dashboard');
        $called = false;

        $response = $this->middleware->handle($request, static function () use (&$called) {
            $called = true;

            return response('OK');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAllowsAccessWhenGateAllowsWithAuthenticatedUser(): void
    {
        config(['station.dashboard.authorization' => 'viewStation']);

        // Gate definition that always allows
        Gate::define('viewStation', static fn($user = null) => true);

        $user = new User();
        $user->id = 1;

        $request = Request::create('/station/dashboard');
        $request->setUserResolver(static fn() => $user);
        $called = false;

        $response = $this->middleware->handle($request, static function () use (&$called) {
            $called = true;

            return response('OK');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testDeniesAccessWhenGateDenies(): void
    {
        config(['station.dashboard.authorization' => 'viewStation']);

        Gate::define('viewStation', static fn($user = null) => false);

        $user = new User();
        $user->id = 1;

        $request = Request::create('/station/dashboard');
        $request->setUserResolver(static fn() => $user);

        $this->expectException(HttpException::class);

        $this->middleware->handle($request, static fn() => response('OK'));
    }

    public function testDeniesAccessForNonAdminUser(): void
    {
        config(['station.dashboard.authorization' => 'adminOnly']);

        Gate::define('adminOnly', static fn($user = null) => false);

        $user = new User();
        $user->id = 1;

        $request = Request::create('/station/dashboard');
        $request->setUserResolver(static fn() => $user);

        $this->expectException(HttpException::class);

        $this->middleware->handle($request, static fn() => response('OK'));
    }

    public function testAllowsAdminAccess(): void
    {
        config(['station.dashboard.authorization' => 'adminOnly']);

        // Simulating an admin check that passes
        Gate::define('adminOnly', static fn($user = null) => true);

        $user = new User();
        $user->id = 1;

        $request = Request::create('/station/dashboard');
        $request->setUserResolver(static fn() => $user);
        $called = false;

        $response = $this->middleware->handle($request, static function () use (&$called) {
            $called = true;

            return response('OK');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }
}
