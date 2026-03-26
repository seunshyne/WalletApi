<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\Admin\AdminUserResource;
use App\Models\User;
use Illuminate\Http\Request;

class AdminUserController extends Controller
{
    public function index(Request $request)
    {
        $users = User::with('wallet')
            ->where('role', 'user') // never return other admins in the list
            ->when(
                $request->search,
                fn($q) =>
                $q->where('name', 'like', "%{$request->search}%")
                    ->orWhere('email', 'like', "%{$request->search}%")
            )
            ->when(
                $request->status,
                fn($q) =>
                $q->where('status', $request->status)
            )
            ->latest()
            ->paginate(20);

        return AdminUserResource::collection($users);
    }

    public function show(User $user)
    {
        $user->load([
            'wallet',
            'wallet.transactions' => fn($q) => $q->latest()->limit(10)
        ]);
        return new AdminUserResource($user);
    }

    public function suspend(User $user)
    {
        if ($user->isAdmin()) {
        return response()->json(['message' => 'Cannot suspend an admin'], 403);
    }

        $user->update(['status' => 'suspended']);
        return response()->json(['message' => "User {$user->name} suspended"]);
    }

    public function unsuspend(User $user)
    {
        if ($user->isAdmin()) {
        return response()->json(['message' => 'Cannot modify an admin'], 403);
    }

        $user->update(['status' => 'active']);
        return response()->json(['message' => "User {$user->name} restored"]);
    }
}
