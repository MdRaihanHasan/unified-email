<?php

namespace App\Http\Controllers;

use App\Enums\Provider;
use App\Mail\Providers\Gmail\ClientFactory;
use Inertia\Inertia;
use Inertia\Response;

class AccountController
{
    public function __construct(private readonly ClientFactory $google) {}

    public function index(): Response
    {
        // Accounts themselves come through the Inertia shared props, since the
        // sidebar needs them on every page.
        return Inertia::render('Accounts/Index', [
            'googleConfigured' => $this->google->configured(),
            'providers' => collect(Provider::cases())->map(fn (Provider $provider) => [
                'value' => $provider->value,
                'label' => $provider->label(),
                'supports_idle' => $provider->supportsIdle(),
            ]),
        ]);
    }
}
