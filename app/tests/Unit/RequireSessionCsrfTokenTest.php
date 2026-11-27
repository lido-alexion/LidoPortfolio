<?php

namespace Tests\Unit;

use App\Http\Middleware\RequireSessionCsrfToken;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use PHPUnit\Framework\TestCase;

class RequireSessionCsrfTokenTest extends TestCase
{
    public function test_same_origin_fetch_metadata_does_not_replace_the_session_token(): void
    {
        $middlewareClass = new class extends RequireSessionCsrfToken
        {
            public function __construct()
            {
                // The origin decision reads only request headers.
            }

            public function acceptsOrigin(Request $request): bool
            {
                return $this->hasValidOrigin($request);
            }

            public function acceptsToken(Request $request): bool
            {
                return $this->tokensMatch($request);
            }
        };
        $middleware = $middlewareClass;

        $request = Request::create('/api/portfolios/1', 'PUT');
        $request->headers->set('Sec-Fetch-Site', 'same-origin');
        $session = new Store('csrf-test', new ArraySessionHandler(120));
        $session->start();
        $request->setLaravelSession($session);

        $this->assertFalse($middleware->acceptsOrigin($request));
        $this->assertFalse($middleware->acceptsToken($request));
        $request->headers->set('X-CSRF-TOKEN', str_repeat('x', 40));
        $this->assertFalse($middleware->acceptsToken($request));
        $request->headers->set('X-CSRF-TOKEN', $session->token());
        $this->assertTrue($middleware->acceptsToken($request));
    }
}
