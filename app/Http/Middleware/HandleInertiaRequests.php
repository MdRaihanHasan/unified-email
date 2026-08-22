<?php

namespace App\Http\Middleware;

use App\Models\MailAccount;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [
            ...parent::share($request),

            'auth' => [
                'user' => $request->user()?->only('id', 'name', 'email'),
            ],

            // The account strip is on every screen, and so is the staleness warning.
            // Every failure mode in this app is silent — an expired cursor, a dead
            // IDLE daemon, a rotated app password — so "last synced" belongs in front
            // of the user rather than only in a log.
            'accounts' => fn () => $request->user() === null ? [] : MailAccount::query()
                ->orderBy('id')
                ->get()
                ->map(fn (MailAccount $account) => [
                    'id' => $account->id,
                    'label' => $account->label,
                    'email' => $account->email,
                    'provider' => $account->provider->value,
                    'provider_label' => $account->provider->label(),
                    'status' => $account->status->value,
                    'last_synced_at' => $account->last_synced_at?->toIso8601String(),
                    'last_synced_for_humans' => $account->last_synced_at?->diffForHumans(),
                    'is_stale' => $account->isStale((int) config('mail_providers.sync.stale_after_minutes')),
                    'backfilling' => ! $account->hasFinishedBackfill(),
                    'last_error' => $account->last_error,
                ])->values(),

            'flash' => [
                'message' => fn () => $request->session()->get('message'),
            ],
        ];
    }
}
