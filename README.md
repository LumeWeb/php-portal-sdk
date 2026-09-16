# PHP Portal SDK

PHP port of the LumeWeb Portal Go SDK (`~/projects/portal-sdk`) for the
**account** (core auth) and **admin** (quota) APIs.

Client code is generated from the same OpenAPI specs the Go SDK uses
(`openapitools/openapi-generator`) and wrapped behind a thin handwritten layer
that **centralizes status/error handling** so individual calls never repeat the
per-call status checks the Go SDK performs on every endpoint.

## Scope

Core account operations (parity with the Go SDK `account.go` core surface):

| Operation         | Method                          | Endpoint                  | Success codes |
|-------------------|---------------------------------|---------------------------|---------------|
| Login             | `Client::login()`               | `POST /api/auth/login`    | 200, 302      |
| API-key login     | `Client::loginWithApiKey()`     | `POST /api/auth/key`      | 200           |
| OTP validation    | `Client::validateOtp()`         | `POST /api/auth/otp/validate` | 302      |
| Ping              | `Client::ping()`                | `POST /api/auth/ping`     | 200           |
| OTP generate      | `Client::generateOtp()`         | `POST /api/auth/otp/generate` | 200      |
| OTP verify        | `Client::verifyOtp()`           | `POST /api/auth/otp/verify` | 204         |
| OTP disable       | `Client::disableOtp()`          | `POST /api/auth/otp/disable` | 204       |
| Registration      | `Client::register()`            | `POST /api/auth/register` | 201, 200     |

## Architecture

```
src/                        Handwritten SDK layer (namespace LumeWeb\Portal\)
  Account/
    Client.php              Account client wrapping the generated DefaultApi
    LoginResult.php         Value object returned by login()
    ApiKey.php              API-key value object
    OperationStatus.php     Operation status constants + IsSettled() helpers
  Http/
    OpHandler.php           Centralized status/error handling (parity with Go
                            internal/http.OpHandler)
    ErrorFactory.php        Operation -> status error factories
    PortalException.php     Base exception (extends RuntimeException)
    (Unauthorized|Forbidden|BadRequest|NotFound|Conflict|InternalServer|
     Unavailable)Exception.php  Typed exceptions

generated/account/lib/      Generated client (namespace LumeWeb\Portal\
                            Generated\Account — DO NOT EDIT)
generated/admin/lib/        Generated admin client (namespace LumeWeb\Portal\
                            Generated\Admin — DO NOT EDIT)
specs/                      Vendored OpenAPI specs used for generation
config/                     openapi-generator PHP configs (invokerPackage)
scripts/generate.php        Docker-based regeneration script
tests/                      PHPUnit parity tests
```

### Error handling

Every client call eventually goes through
`LumeWeb\Portal\Http\OpHandler::handleResponse()`, which mirrors the Go SDK's
`internal/http.OpHandler`:

1. A status code in the operation's success list passes through (returns `null`);
2. an operation → status error factory maps to a typed exception;
3. unhandled HTTP sentinel statuses (400/401/403/404/409/500/503) fall back to
   typed `PortalException` subclasses carrying the response body;
4. anything else becomes a generic `PortalException` with the operation name,
   status code and body.

The operation → status error maps match the Go SDK's `httpErrorMessages`:

| Operation          | Status | Exception / message                              |
|--------------------|--------|--------------------------------------------------|
| Login              | 401    | Unauthorized — "invalid login credentials"       |
| OTP validation     | 400    | Portal (base) — "invalid OTP code"               |
|                    | 401    | Unauthorized — "invalid or expired 2FA session"  |
| Ping               | 401    | Unauthorized — "invalid JWT token"               |
| OTP generate       | 401    | Unauthorized — "authentication required"         |
| OTP verify         | 401    | Unauthorized — "authentication required"         |
|                    | 400    | Portal (base) — "invalid OTP code"               |
| OTP disable        | 401    | Unauthorized — "authentication required or invalid password" |
| API-key login      | 401    | Unauthorized — "invalid API key"                 |
|                    | 403    | Forbidden — "account is pending deletion"        |
| Registration       | 409    | Portal (base) — "user already exists with this email" |

## Usage

```php
use LumeWeb\Portal\Account\Client;

$client = new Client();                     // https://account.pinner.xyz
$client = new Client('https://account.pinner.xyz', $jwt);

$result = $client->login('user@example.com', 'password');
if ($result->otpRequired) {
    $token = $client->validateOtp($result->intermediateJwt, '123456');
} else {
    $token = $result->token;
    $client->setAuthToken($token);
}

$client->ping();                            // validates the JWT
$client->generateOtp();
$client->verifyOtp('123456');
$client->disableOtp('password');
$client->register('a@b.com', 'Jane', 'Doe', 'password');

$token = $client->loginWithApiKey('ak-...'); // API key sent raw in Authorization
```

Redirect logins: without 2FA the service responds `302` with the JWT in the
`auth_token` cookie (preferred) or the `auth_token` Location query parameter.
`Client::login()`/`validateOtp()` extract it for you.

## Generation

```bash
composer generate            # regenerates account + admin from specs/ via Docker
composer generate account    # or just the account client
```

The PHP generator derives the model/api namespaces from `invokerPackage`
(`LumeWeb\Portal\Generated\Account` → `...\Account\Model` and
`...\Account\Api`); only `invokerPackage` is set in
`config/openapi-generator-*.yaml`.

## Tests

```bash
composer test                # PHPUnit 10
composer lint                # php -l over src/ and tests/
```

## License

MIT (see `LICENSE`).
