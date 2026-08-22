<?php

namespace Tests\Support;

use App\Mail\Providers\GmailApiProvider;
use App\Mail\Providers\GraphProvider;
use App\Mail\Providers\ImapProvider;

trait UsesFakeProvider
{
    protected FakeProvider $provider;

    protected function fakeProvider(): FakeProvider
    {
        $this->provider = new FakeProvider;

        // Bound for all three so a test can pick any provider enum and still get the
        // fake — the point is to exercise our pipeline, not their protocols.
        foreach ([GmailApiProvider::class, ImapProvider::class, GraphProvider::class] as $driver) {
            $this->app->instance($driver, $this->provider);
        }

        return $this->provider;
    }
}
