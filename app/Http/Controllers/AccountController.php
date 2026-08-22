<?php

namespace App\Http\Controllers;

use App\Enums\Provider;
use Inertia\Inertia;
use Inertia\Response;

class AccountController
{
    public function index(): Response
    {
        // Accounts themselves come through the Inertia shared props, since the
        // sidebar needs them on every page.
        return Inertia::render('Accounts/Index', [
            'providers' => collect(Provider::cases())->map(fn (Provider $provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'supports_idle' => $provider->supportsIdle(),
            ]),
        ]);
    }
}
