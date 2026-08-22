<?php

namespace App\Enums;

enum AccountStatus: string
{
    case Connecting = 'connecting';
    case Active = 'active';

    /** Refresh token revoked, or a Google app password invalidated by a password change. */
    case AuthError = 'auth_error';

    case Disabled = 'disabled';

    public function shouldSync(): bool
    {
        return $this === self::Active || $this === self::Connecting;
    }
}
