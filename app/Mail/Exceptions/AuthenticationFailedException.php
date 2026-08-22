<?php

namespace App\Mail\Exceptions;

/**
 * Credentials were rejected: a revoked OAuth refresh token, or a Google app
 * password invalidated because the account password changed. Not retryable —
 * the account moves to auth_error and waits for the user to reconnect.
 */
class AuthenticationFailedException extends MailProviderException {}
