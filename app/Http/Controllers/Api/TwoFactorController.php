<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use PragmaRX\Google2FA\Google2FA;

class TwoFactorController extends Controller
{
    public function setup(Request $request): JsonResponse
    {
        $google2fa = new Google2FA();
        $secret = $google2fa->generateSecretKey();

        $request->user()->forceFill(['two_factor_secret' => $secret, 'two_factor_enabled' => false])->save();

        $qrUrl = $google2fa->getQRCodeUrl(config('app.name'), $request->user()->email, $secret);
        $renderer = new ImageRenderer(new RendererStyle(220), new SvgImageBackEnd());

        return response()->json([
            'secret' => $secret,
            'qr_svg' => (new Writer($renderer))->writeString($qrUrl),
        ]);
    }

    public function confirm(Request $request): JsonResponse
    {
        $data = $request->validate(['code' => ['required', 'string']]);
        $user = $request->user();

        abort_unless($user->two_factor_secret, 422, 'Сначала начните настройку двухфакторной аутентификации.');
        abort_unless((new Google2FA())->verifyKey($user->two_factor_secret, $data['code']), 422, 'Неверный код. Попробуйте ещё раз.');

        $recoveryCodes = collect(range(1, 8))->map(fn () => Str::upper(Str::random(4).'-'.Str::random(4)))->all();

        $user->forceFill([
            'two_factor_enabled' => true,
            'two_factor_recovery_codes' => $recoveryCodes,
        ])->save();

        return response()->json(['recovery_codes' => $recoveryCodes]);
    }

    public function disable(Request $request): JsonResponse
    {
        $data = $request->validate(['password' => ['required', 'string']]);
        $user = $request->user();

        abort_unless(Hash::check($data['password'], $user->password), 422, 'Неверный пароль.');

        $user->forceFill([
            'two_factor_enabled' => false,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
        ])->save();

        return response()->json(['ok' => true]);
    }
}
