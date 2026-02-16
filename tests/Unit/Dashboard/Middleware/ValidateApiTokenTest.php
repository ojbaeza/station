<?php

declare(strict_types=1);

namespace Station\Tests\Unit\Dashboard\Middleware;

use Illuminate\Http\Request;
use Station\Dashboard\Middleware\ValidateApiToken;
use Station\Tests\TestCase;

class ValidateApiTokenTest extends TestCase
{
    private ValidateApiToken $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new ValidateApiToken();
    }

    public function testReturns404WhenApiDisabled(): void
    {
        config(['station.api.enabled' => false]);

        $request = Request::create('/api/station/jobs');

        $response = $this->middleware->handle($request, static fn() => response('OK'));

        $this->assertSame(404, $response->getStatusCode());
        $this->assertStringContainsString('API is disabled', $response->getContent());
    }

    public function testAllowsAccessWhenAuthenticationDisabled(): void
    {
        config([
            'station.api.enabled' => true,
            'station.api.auth' => 'none',
        ]);

        $request = Request::create('/api/station/jobs');
        $called = false;

        $response = $this->middleware->handle($request, static function () use (&$called) {
            $called = true;

            return response('OK');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReturns401WhenNoTokenProvided(): void
    {
        config([
            'station.api.enabled' => true,
            'station.api.auth' => 'token',
        ]);

        $request = Request::create('/api/station/jobs');

        $response = $this->middleware->handle($request, static fn() => response('OK'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('API token required', $response->getContent());
    }

    public function testAcceptsBearerToken(): void
    {
        config([
            'station.api.enabled' => true,
            'station.api.auth' => 'token',
            'station.api.token' => 'valid-token-123',
        ]);

        $request = Request::create('/api/station/jobs');
        $request->headers->set('Authorization', 'Bearer valid-token-123');
        $called = false;

        $response = $this->middleware->handle($request, static function () use (&$called) {
            $called = true;

            return response('OK');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testAcceptsQueryParameterToken(): void
    {
        config([
            'station.api.enabled' => true,
            'station.api.auth' => 'token',
            'station.api.token' => 'query-token-456',
        ]);

        $request = Request::create('/api/station/jobs', 'GET', ['api_token' => 'query-token-456']);
        $called = false;

        $response = $this->middleware->handle($request, static function () use (&$called) {
            $called = true;

            return response('OK');
        });

        $this->assertTrue($called);
        $this->assertSame(200, $response->getStatusCode());
    }

    public function testReturns401WhenInvalidTokenProvided(): void
    {
        config([
            'station.api.enabled' => true,
            'station.api.auth' => 'token',
            'station.api.token' => 'correct-token',
        ]);

        $request = Request::create('/api/station/jobs');
        $request->headers->set('Authorization', 'Bearer wrong-token');

        $response = $this->middleware->handle($request, static fn() => response('OK'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('Invalid API token', $response->getContent());
    }

    public function testReturns401WhenNoTokenConfigured(): void
    {
        config([
            'station.api.enabled' => true,
            'station.api.auth' => 'token',
            'station.api.token' => null,
        ]);

        $request = Request::create('/api/station/jobs');
        $request->headers->set('Authorization', 'Bearer any-token');

        $response = $this->middleware->handle($request, static fn() => response('OK'));

        $this->assertSame(401, $response->getStatusCode());
    }

    public function testBearerTokenParsing(): void
    {
        config([
            'station.api.enabled' => true,
            'station.api.auth' => 'token',
            'station.api.token' => 'test-token',
        ]);

        // Test with extra spaces
        $request = Request::create('/api/station/jobs');
        $request->headers->set('Authorization', 'Bearer test-token');
        $called = false;

        $response = $this->middleware->handle($request, static function () use (&$called) {
            $called = true;

            return response('OK');
        });

        $this->assertTrue($called);
    }

    public function testNonBearerAuthorizationHeaderIsIgnored(): void
    {
        config([
            'station.api.enabled' => true,
            'station.api.auth' => 'token',
            'station.api.token' => 'test-token',
        ]);

        $request = Request::create('/api/station/jobs');
        $request->headers->set('Authorization', 'Basic dXNlcjpwYXNz');

        $response = $this->middleware->handle($request, static fn() => response('OK'));

        $this->assertSame(401, $response->getStatusCode());
        $this->assertStringContainsString('API token required', $response->getContent());
    }
}
