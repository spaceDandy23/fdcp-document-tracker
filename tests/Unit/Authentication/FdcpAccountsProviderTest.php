<?php

namespace Tests\Unit\Authentication;

use App\Providers\FdcpAccountsProvider;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Laravel\Socialite\Two\InvalidStateException;
use Tests\TestCase;
use UnexpectedValueException;

class FdcpAccountsProviderTest extends TestCase
{
    public function test_it_exchanges_the_code_with_client_authentication_and_pkce_then_fetches_userinfo(): void
    {
        $request = $this->callbackRequest();
        $history = [];
        $handler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'access_token' => 'access-token',
                'token_type' => 'Bearer',
                'expires_in' => 3600,
            ], JSON_THROW_ON_ERROR)),
            new Response(200, ['Content-Type' => 'application/json'], json_encode([
                'employee' => ['id' => 'employee-42'],
            ], JSON_THROW_ON_ERROR)),
        ]);
        $stack = HandlerStack::create($handler);
        $stack->push(Middleware::history($history));

        $provider = (new FdcpAccountsProvider(
            $request,
            'client-id',
            'client-secret',
            'https://tracker.fdcp.ph/auth/callback',
        ))->setHttpClient(new Client(['handler' => $stack]));

        $user = $provider->user();

        $this->assertSame(['employee' => ['id' => 'employee-42']], $user->getRaw());
        $this->assertCount(2, $history);

        $tokenRequest = $history[0]['request'];
        parse_str((string) $tokenRequest->getBody(), $tokenFields);

        $this->assertSame('https://sso.fdcp.ph/oauth/token', (string) $tokenRequest->getUri());
        $this->assertSame('authorization_code', $tokenFields['grant_type']);
        $this->assertSame('client-id', $tokenFields['client_id']);
        $this->assertSame('client-secret', $tokenFields['client_secret']);
        $this->assertSame('authorization-code', $tokenFields['code']);
        $this->assertSame(str_repeat('v', 96), $tokenFields['code_verifier']);

        $userinfoRequest = $history[1]['request'];
        $this->assertSame('https://sso.fdcp.ph/api/userinfo', (string) $userinfoRequest->getUri());
        $this->assertSame('Bearer access-token', $userinfoRequest->getHeaderLine('Authorization'));
    }

    public function test_it_rejects_a_token_response_without_an_access_token(): void
    {
        $request = $this->callbackRequest();
        $handler = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json'], '{"expires_in":3600}'),
        ]);
        $provider = (new FdcpAccountsProvider(
            $request,
            'client-id',
            'client-secret',
            'https://tracker.fdcp.ph/auth/callback',
        ))->setHttpClient(new Client(['handler' => HandlerStack::create($handler)]));

        $this->expectException(UnexpectedValueException::class);

        $provider->user();
    }

    public function test_it_rejects_a_callback_when_the_pkce_verifier_is_missing_from_the_session(): void
    {
        $request = $this->callbackRequest();
        $request->session()->forget('code_verifier');
        $provider = (new FdcpAccountsProvider(
            $request,
            'client-id',
            'client-secret',
            'https://tracker.fdcp.ph/auth/callback',
        ))->setHttpClient(new Client(['handler' => HandlerStack::create(new MockHandler)]));

        $this->expectException(InvalidStateException::class);

        $provider->user();
    }

    private function callbackRequest(): Request
    {
        $session = new Store('test', new ArraySessionHandler(120));
        $session->start();
        $session->put('state', 'expected-state');
        $session->put('code_verifier', str_repeat('v', 96));

        $request = Request::create('/auth/callback', 'GET', [
            'code' => 'authorization-code',
            'state' => 'expected-state',
        ]);
        $request->setLaravelSession($session);

        return $request;
    }
}
