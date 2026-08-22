<?php

namespace App\Mail;

use App\Enums\Provider;
use App\Mail\Contracts\MailboxProvider;
use App\Mail\Providers\GmailApiProvider;
use App\Mail\Providers\GraphProvider;
use App\Mail\Providers\ImapProvider;
use App\Models\MailAccount;
use Illuminate\Contracts\Container\Container;

class ProviderManager
{
    /** @var array<class-string<MailboxProvider>> */
    private const DRIVERS = [
        Provider::GmailApi->value => GmailApiProvider::class,
        Provider::Imap->value => ImapProvider::class,
        Provider::Graph->value => GraphProvider::class,
    ];

    public function __construct(private readonly Container $container) {}

    public function for(MailAccount $account): MailboxProvider
    {
        return $this->driver($account->provider);
    }

    public function driver(Provider $provider): MailboxProvider
    {
        return $this->container->make(self::DRIVERS[$provider->value]);
    }
}
