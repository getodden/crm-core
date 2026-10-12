<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Odden\Core\Http\Middleware\RequireApiToken;

beforeEach(function (): void {
    Route::post('/_test/protected', fn () => response()->json(['ok' => true]))
        ->middleware(RequireApiToken::class.':odden-test.api.token');
    Route::post('/_test/protected-url', fn () => response()->json(['ok' => true]))
        ->middleware(RequireApiToken::class.':odden-test.api.token,query');
});

it('keeps protected endpoints disabled until a token is configured', function (): void {
    $this->withToken('anything')->postJson('/_test/protected')->assertForbidden();
});

it('rejects a missing token', function (): void {
    config(['odden-test.api.token' => 'secret-token']);

    $this->postJson('/_test/protected')->assertUnauthorized();
});

it('rejects a wrong token', function (): void {
    config(['odden-test.api.token' => 'secret-token']);

    $this->withToken('wrong-token')->postJson('/_test/protected')->assertUnauthorized();
    $this->flushHeaders()->withHeader('X-Odden-Token', 'wrong-token')->postJson('/_test/protected')->assertUnauthorized();
    $this->flushHeaders()->postJson('/_test/protected-url?token=wrong-token')->assertUnauthorized();
});

it('accepts the token as a bearer token or a header', function (): void {
    config(['odden-test.api.token' => 'secret-token']);

    $this->withToken('secret-token')->postJson('/_test/protected')->assertOk();
    $this->flushHeaders()->withHeader('X-Odden-Token', 'secret-token')->postJson('/_test/protected')->assertOk();
});

it('ignores the query parameter unless the route opts in', function (): void {
    config(['odden-test.api.token' => 'secret-token']);

    $this->postJson('/_test/protected?token=secret-token')->assertUnauthorized();
    $this->postJson('/_test/protected-url?token=secret-token')->assertOk();
});

it('rate limits public routes per IP using the configured limit', function (): void {
    config(['odden-core.rate_limits.public' => 2]);
    Route::post('/_test/public', fn () => 'ok')->middleware('throttle:odden-public');

    $this->post('/_test/public')->assertOk();
    $this->post('/_test/public')->assertOk();
    $this->post('/_test/public')->assertTooManyRequests();
});

it('rate limits authenticated API routes separately', function (): void {
    config(['odden-core.rate_limits.api' => 1]);
    Route::post('/_test/api', fn () => 'ok')->middleware('throttle:odden-api');

    $this->post('/_test/api')->assertOk();
    $this->post('/_test/api')->assertTooManyRequests();
});
