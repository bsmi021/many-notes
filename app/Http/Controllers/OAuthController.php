<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use Exception;

class OAuthController extends Controller
{
    /**
     * Redirect the user to the Google authentication page.
     */
    public function redirectToGoogle(): RedirectResponse
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Obtain the user information from Google.
     */
    public function handleGoogleCallback(): RedirectResponse
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            $user = User::updateOrCreate(
                ['google_id' => $googleUser->getId()],
                [
                    'name' => $googleUser->getName(),
                    'email' => $googleUser->getEmail(),
                    'password' => Hash::make(Str::random(24)), // Or mark as unusable
                    'google_access_token' => encrypt($googleUser->token),
                    'google_refresh_token' => $googleUser->refreshToken ? encrypt($googleUser->refreshToken) : null,
                    'google_token_expires_at' => $googleUser->expiresIn ? now()->addSeconds($googleUser->expiresIn) : null,
                    'email_verified_at' => now(), // Assuming email from Google is verified
                ]
            );

            Auth::login($user, true); // Remember the user

            return redirect()->intended('/dashboard'); // Or your desired redirect path

        } catch (Exception $e) {
            // TODO: Log error, show user-friendly message
            report($e); // Helper to log the exception
            return redirect('/login')->with('error', 'Unable to login using Google. Please try again.');
        }
    }
}
