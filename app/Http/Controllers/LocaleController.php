<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;

class LocaleController extends Controller
{
    public function update(Request $request, string $locale): RedirectResponse
    {
        if (! in_array($locale, ['ru', 'en'], true)) {
            abort(404);
        }

        $request->session()->put('locale', $locale);
        App::setLocale($locale);

        $redirect = $request->input('redirect');
        $redirect = is_string($redirect) && str_starts_with($redirect, '/') ? $redirect : null;

        return redirect($redirect ?? url()->previous());
    }
}
