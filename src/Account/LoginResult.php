<?php

declare(strict_types=1);

/*
 * The result of a login attempt.
 *
 *   - token:            the JWT returned by the account service;
 *   - otpRequired:      whether 2FA is enabled (an intermediate JWT is returned);
 *   - intermediateJwt:  the intermediate JWT to pass to validateOtp() when 2FA
 *                       is enabled (equal to the token for non-2FA logins).
 */

namespace LumeWeb\Portal\Account;

final class LoginResult
{
    public function __construct(
        public string $token,
        public bool $otpRequired,
        public string $intermediateJwt,
    ) {
    }
}
