<?php

namespace App\Http\Controllers;

use App\Actions\Authentication\FdcpUserInfoException;
use App\Actions\Authentication\SyncFdcpUser;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\AbstractUser;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Throwable;

class FdcpAuthenticationController extends Controller
{
    public function redirect(): RedirectResponse
    {
        return Socialite::driver('fdcp_accounts')->redirect();
    }

    public function callback(Request $request, SyncFdcpUser $syncFdcpUser): RedirectResponse|Response
    {
        try {
            $ssoUser = Socialite::driver('fdcp_accounts')->user();

            if (! $ssoUser instanceof AbstractUser) {
                throw new DomainException('FDCP Accounts returned an unsupported user response.');
            }

            $user = $syncFdcpUser->handle($ssoUser->getRaw());

            if (! $user->is_active) {
                return response('This FDCP account is not active.', 403);
            }

            Auth::login($user);
            $request->session()->regenerate();

            return redirect()->intended('/');
        } catch (InvalidStateException) {
            return response('Authentication session expired. Please try again.', 419);
        } catch (FdcpUserInfoException $exception) {
            Log::warning('FDCP SSO userinfo rejected.', [
                'field' => $exception->field,
                'category' => $exception->category,
            ]);

            return response('FDCP account information is incomplete or invalid.', 422);
        } catch (DomainException) {
            Log::warning('FDCP SSO user synchronization rejected.', [
                'field' => 'identity',
                'category' => 'conflict',
            ]);

            return response('FDCP account information is incomplete or invalid.', 422);
        } catch (Throwable $exception) {
            Log::warning('FDCP SSO authentication failed.', [
                'exception_class' => $exception::class,
            ]);

            return response('FDCP Accounts is temporarily unavailable. Please try again.', 503);
        }
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->away((string) config('services.fdcp_accounts.logout_url'));
    }
}
