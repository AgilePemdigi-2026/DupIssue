# OTP Password Reset: security and local operations

## Session revocation
After successful OTP reset, `users.auth_version` increases in the same database
transaction as the password change. `EnforceAuthVersion` checks it against the
version stored at login on each web request. Any prior authenticated session
is logged out and redirected to Login. This works across configured session
backends, not just the `database` driver. Existing authenticated sessions
without an `auth_version` are also asked to login again after deployment.
The remember-me token is rotated on reset.

## SMTP and queue
Use a private local `.env` (NEVER commit it). For local Gmail SMTP:

```text
MAIL_MAILER=smtp
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_SCHEME=smtp
MAIL_USERNAME=<your-sender-email>
MAIL_PASSWORD=<your-Google-App-Password>
MAIL_FROM_ADDRESS=<your-sender-email>
QUEUE_CONNECTION=database
```

Run `php artisan migrate` after validating/backing up the selected database.
Run **two** terminal processes in `backend`:

```sh
php artisan serve --host=127.0.0.1 --port=8003
php artisan queue:work database --queue=default --tries=1
```

The OTP notification is queued on `database`, and its serialized payload
(including the 6-digit OTP) is encrypted with `APP_KEY`.
Do not rotate `APP_KEY` with pending jobs. The worker validates that the OTP is
still valid, unused and has not been superseded. OTP validity is five minutes,
so delayed queue processing may cause an OTP to expire before delivery.
Restart workers after changing code/config: `php artisan queue:restart`.

Avoid `MAIL_MAILER=log` and `MAIL_MAILER=array` in running applications:
they cannot deliver OTP and can disclose OTPs in log output. OTP sending is
blocked with those transports outside unit tests.

## Security and verification
Public responses for registered and unknown email addresses remain generic.
Queuing removes synchronous SMTP latency but DOES NOT guarantee constant-time
responses; keep request/email rate limits and review timing under deployment load.

Check: `php artisan test --filter=PasswordResetOtpTest` and `php artisan test`.
Manually use separate browser profiles to confirm that resetting in one browser
revokes existing Reporter/Maintainer sessions in others. The Behat feature
alone is not proof that Behat was run.
