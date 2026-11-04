<?php

namespace App\Providers;

use GuzzleHttp\RequestOptions;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\InvalidStateException;
use Laravel\Socialite\Two\User;
use UnexpectedValueException;

class FdcpAccountsProvider extends AbstractProvider
{
    protected $usesPKCE = true;

    protected function getAuthUrl($state): string
    {
        return $this->buildAuthUrlFromBase(
            (string) config('services.fdcp_accounts.authorization_url'),
            $state,
        );
    }

    protected function getTokenUrl(): string
    {
        return (string) config('services.fdcp_accounts.token_url');
    }

    protected function getTokenFields($code): array
    {
        $fields = parent::getTokenFields($code);

        if (! is_string($fields['code_verifier'] ?? null) || strlen($fields['code_verifier']) < 43) {
            throw new InvalidStateException;
        }

        return $fields;
    }

    public function getAccessTokenResponse($code): array
    {
        $response = parent::getAccessTokenResponse($code);

        if (! is_array($response) || ! is_string($response['access_token'] ?? null) || $response['access_token'] === '') {
            throw new UnexpectedValueException('FDCP Accounts returned an invalid token response.');
        }

        return $response;
    }

    /**
     * @return array<string, mixed>
     */
    protected function getUserByToken($token): array
    {
        if (! is_string($token) || $token === '') {
            throw new UnexpectedValueException('FDCP Accounts did not return an access token.');
        }

        $response = $this->getHttpClient()->get(
            (string) config('services.fdcp_accounts.userinfo_url'),
            [
                RequestOptions::HEADERS => [
                    'Accept' => 'application/json',
                    'Authorization' => 'Bearer '.$token,
                ],
            ],
        );

        $userinfo = json_decode((string) $response->getBody(), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($userinfo)) {
            throw new UnexpectedValueException('FDCP Accounts returned an invalid userinfo response.');
        }

        return $userinfo;
    }

    protected function mapUserToObject(array $user): User
    {
        return (new User)->setRaw($user)->map([
            'id' => null,
            'nickname' => null,
            'name' => null,
            'email' => null,
            'avatar' => null,
        ]);
    }
}
