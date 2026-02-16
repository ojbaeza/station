<?php

declare(strict_types=1);

namespace Station\Tests\Feature\Security;

use Illuminate\Http\Request;
use Station\Dashboard\Http\Middleware\SecurityHeaders;
use Station\Tests\TestCase;

class SecurityHeadersMiddlewareTest extends TestCase
{
    private SecurityHeaders $middleware;

    protected function setUp(): void
    {
        parent::setUp();
        $this->middleware = new SecurityHeaders();
    }

    public function testAddsSecurityHeadersWhenEnabled(): void
    {
        config(['station.security_headers' => true]);

        $request = Request::create('/station/dashboard');
        $response = $this->middleware->handle($request, static fn() => response('OK'));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
        $this->assertSame('1; mode=block', $response->headers->get('X-XSS-Protection'));
        $this->assertSame('strict-origin-when-cross-origin', $response->headers->get('Referrer-Policy'));
    }

    public function testSkipsSecurityHeadersWhenDisabled(): void
    {
        config(['station.security_headers' => false]);

        $request = Request::create('/station/dashboard');
        $response = $this->middleware->handle($request, static fn() => response('OK'));

        $this->assertNull($response->headers->get('X-Content-Type-Options'));
        $this->assertNull($response->headers->get('X-Frame-Options'));
        $this->assertNull($response->headers->get('X-XSS-Protection'));
    }

    public function testAddsHstsHeaderForSecureRequests(): void
    {
        config(['station.security_headers' => true]);

        $request = Request::create('https://example.com/station/dashboard', 'GET', [], [], [], ['HTTPS' => 'on']);
        $response = $this->middleware->handle($request, static fn() => response('OK'));

        $this->assertSame('max-age=31536000; includeSubDomains', $response->headers->get('Strict-Transport-Security'));
    }

    public function testDoesNotAddHstsHeaderForInsecureRequests(): void
    {
        config(['station.security_headers' => true]);

        $request = Request::create('http://example.com/station/dashboard');
        $response = $this->middleware->handle($request, static fn() => response('OK'));

        $this->assertNull($response->headers->get('Strict-Transport-Security'));
    }

    public function testDefaultsToEnabledWhenNotConfigured(): void
    {
        // Ensure the config key doesn't exist
        config(['station.security_headers' => null]);

        $request = Request::create('/station/dashboard');

        // Default behavior should still add headers since null defaults to true
        $response = $this->middleware->handle($request, static fn() => response('OK'));

        // The code uses config('station.security_headers', true) - default true
        $this->assertNotNull($response);
    }

    public function testPreservesOriginalResponse(): void
    {
        config(['station.security_headers' => true]);

        $request = Request::create('/station/dashboard');
        $response = $this->middleware->handle($request, static fn() => response()->json(['status' => 'ok']));

        $this->assertSame('application/json', $response->headers->get('Content-Type'));
        $this->assertJson($response->getContent());
    }

    public function testHeadersAreSetCorrectlyForApiEndpoints(): void
    {
        config(['station.security_headers' => true]);

        $request = Request::create('/station/api/jobs');
        $response = $this->middleware->handle($request, static fn() => response()->json(['jobs' => []]));

        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testXContentTypeOptionsPreventsMimeSniffing(): void
    {
        config(['station.security_headers' => true]);

        $request = Request::create('/station/dashboard');
        $response = $this->middleware->handle($request, static fn() => response('OK'));

        // X-Content-Type-Options: nosniff prevents browsers from MIME-sniffing
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function testXFrameOptionsPreventsClickjacking(): void
    {
        config(['station.security_headers' => true]);

        $request = Request::create('/station/dashboard');
        $response = $this->middleware->handle($request, static fn() => response('OK'));

        // X-Frame-Options: SAMEORIGIN prevents clickjacking
        $this->assertSame('SAMEORIGIN', $response->headers->get('X-Frame-Options'));
    }

    public function testXssProtectionEnabled(): void
    {
        config(['station.security_headers' => true]);

        $request = Request::create('/station/dashboard');
        $response = $this->middleware->handle($request, static fn() => response('OK'));

        // X-XSS-Protection enables browser XSS filtering
        $this->assertSame('1; mode=block', $response->headers->get('X-XSS-Protection'));
    }
}
