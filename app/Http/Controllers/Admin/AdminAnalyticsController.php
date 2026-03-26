<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class AdminAnalyticsController extends Controller
{
    public function summary()
    {
        $data = Cache::remember('admin.analytics.summary', now()->addMinutes(5), function () {
            $users = User::where('role', 'user')
                ->selectRaw("
                    COUNT(*) as total,
                    SUM(status = 'active') as active,
                    SUM(status = 'suspended') as suspended
                ")
                ->first();

            return [
                'total_users'          => $users->total,
                'active_users'         => $users->active,
                'suspended_users'      => $users->suspended,
                'total_transactions'   => Transaction::count(),
                'total_volume'         => Transaction::where('status', 'success')->sum('amount'),
                'flagged_transactions' => Transaction::where('flagged', true)->count(),
            ];
        });

        return response()->json($data);
    }

    public function transactions()
    {
        $data = Cache::remember('admin.analytics.transactions', now()->addMinutes(10), function () {
            return Transaction::select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('COUNT(*) as count'),
                    DB::raw('SUM(amount) as volume')
                )
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        });

        return response()->json($data);
    }

    public function users()
    {
        $data = Cache::remember('admin.analytics.users', now()->addMinutes(10), function () {
            return User::select(
                    DB::raw('DATE(created_at) as date'),
                    DB::raw('COUNT(*) as count')
                )
                ->where('role', 'user')
                ->where('created_at', '>=', now()->subDays(30))
                ->groupBy('date')
                ->orderBy('date')
                ->get();
        });

        return response()->json($data);
    }
}