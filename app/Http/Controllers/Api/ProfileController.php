<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Dashboard\DashboardData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProfileController extends Controller
{
    public function me(Request $request): JsonResponse
    {
        return response()->json([
            'user' => $request->user()?->only(['id', 'name', 'email', 'role', 'tenant_id']),
        ]);
    }

    public function dashboard(Request $request, DashboardData $dashboard): JsonResponse
    {
        return response()->json($dashboard->forUser($request->user()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => ['sometimes', 'string', 'max:160'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:40'],
        ]);

        $user = $request->user();
        $user->update($data);

        return response()->json($user->refresh());
    }

    public function uploadAvatar(Request $request): JsonResponse
    {
        $data = $request->validate([
            'photo' => ['required', 'image', 'max:4096'],
        ]);

        $user = $request->user();
        $path = $data['photo']->store('avatars/'.$user->id, 'public');

        $user->update(['avatar_path' => $path]);

        return response()->json($user->refresh());
    }
}