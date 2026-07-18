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
}