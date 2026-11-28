<?php

namespace App\Http\Controllers;

use App\Mail\LoginCodeMail;
use App\Models\Customer;
use App\Models\LoginCode;
use App\Models\PrivacyRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

/**
 * Inloggen zonder wachtwoord: een eenmalige code van 6 cijfers per e-mail (10 minuten geldig, 5 pogingen, één keer bruikbaar).
 * Wie de code kan invoeren, beheert het mailadres. Een account wordt bij de eerste geslaagde login aangemaakt.
 */
class CustomerAuthController extends Controller
{
    private const LANGS = ['nl', 'en', 'de', 'fr', 'es', 'it', 'pl', 'ar'];

    /** Antwoord altijd hetzelfde (ook bij een onbekend adres: iedereen kan een account krijgen, dus er valt niets te achterhalen). */
    public function requestCode(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:200'], 'lang' => ['nullable', 'string', 'in:'.implode(',', self::LANGS)]]);
        $email = strtolower(trim($data['email']));
        // Per adres maximaal 5 codes per uur: voorkomt dat iemand andermans mailbox volspamt.
        if (! RateLimiter::attempt('login-code:'.PrivacyRequest::hashEmail($email), 5, fn () => true, 3600)) {
            return response()->json(['ok' => true]);
        }
        $code = (string) random_int(100000, 999999);
        LoginCode::where('email', $email)->delete(); // alleen de nieuwste code is geldig
        LoginCode::create(['email' => $email, 'code_hash' => LoginCode::hash($email, $code), 'expires_at' => now()->addMinutes(10), 'created_at' => now()]);
        try {
            Mail::to($email)->send(new LoginCodeMail($code, $data['lang'] ?? 'nl'));
        } catch (Throwable $e) {
            Log::error('Inlogcode mailen mislukt', ['error' => $e->getMessage()]);
        }

        return response()->json(['ok' => true]);
    }

    public function verify(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => ['required', 'email:rfc', 'max:200'], 'code' => ['required', 'digits:6']]);
        $email = strtolower(trim($data['email']));
        $row = LoginCode::where('email', $email)->latest('id')->first();

        $valid = false;
        if ($row && $row->expires_at->isFuture() && $row->attempts < 5) {
            $valid = hash_equals($row->code_hash, LoginCode::hash($email, $data['code']));
            if (! $valid) {
                $row->increment('attempts');
            }
        }
        if (! $valid) {
            return response()->json(['error' => 'invalid_code', 'message' => 'De code is onjuist of verlopen.'], 422);
        }

        LoginCode::where('email', $email)->delete(); // één keer bruikbaar
        $customer = Customer::firstOrCreate(['email' => $email]);
        $customer->forceFill(['last_login_at' => now()])->save();
        $token = $customer->createToken('shop', ['customer'], now()->addDays(30))->plainTextToken;

        return response()->json(['token' => $token, 'customer' => ['email' => $customer->email, 'name' => $customer->name]]);
    }

    public function logout(Request $request): JsonResponse
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['ok' => true]);
    }
}
