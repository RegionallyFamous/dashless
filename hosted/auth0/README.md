# Sign-in emails without another service

Auth0 creates and checks the sign-in codes. Its Custom Email Provider Action sends the finished message to Dashless, which delivers it using the existing WordPress hosting. No separate email-service account is needed for this design.

**Status:** implemented and tested locally; not activated in Auth0, deployed to the Hub, or verified in a real inbox. The host's acceptance of a message does not prove delivery. Do not enable passwordless login until the full journey works.

## Connect it

1. Deploy the complete compatible Hub after completing the [Auth0 cutover checklist](../docs/auth0.md). Do not replace a working email provider with a route that is not ready.
2. Generate a dedicated 32-byte random key encoded as 64 lowercase hex characters. Store it in protected Hub configuration as `DASHLESS_AUTH0_EMAIL_SECRET` and in Auth0 Action secrets as `DASHLESS_EMAIL_SECRET`. Never reuse the Auth0 application secret, hosting key or Hub encryption key. Obtain approval before granting this new connection access to send mail.
3. Set the Hub's `DASHLESS_AUTH0_CHATGPT_CLIENT_ID` to the exact existing ChatGPT registration; the browser application is already read from `DASHLESS_AUTH0_CLIENT_ID`. The canonical `DASHLESS_AUTH0_MANAGEMENT_AUDIENCE` determines the allowed tenant.
4. Set `DASHLESS_AUTH0_EMAIL_FROM` to the verified sender address on the Hub's domain, initially `signin@dashless.blog`. The relay rejects senders on another domain. Check sender authentication and actual inbox placement with WP Cloud; an existing SMTP credential alone is insufficient evidence.
5. In Auth0 **Branding → Email Provider**, choose **Custom Provider** and use `email-provider.cjs`. It needs no npm packages. Its HTTPS destination is fixed to `https://dashless.blog/wp-json/dashless-hub/v1/auth0/email`. Set the default From address to the same sender. Preserve any existing provider before changing it.
6. Send one approved test email. Auth0 dashboard test messages are allowed only to the Hub's existing administrator email address. Keep that address unchanged; do not broaden the relay for a test.
7. Confirm inbox receipt, then test a fresh browser login and ChatGPT connection. Enable the email connection for the intended applications only. Third-party app/domain-level connection promotion requires separate approval because Auth0 exposes the method to all third-party apps in that tenant.

This uses WP Cloud's existing mail transport only on the Hub. Do not expose or copy the platform SMTP password into Auth0. No customer WordPress site needs the signing key or the relay code.

## Safeguards

- Requests have a body signature and a five-minute timestamp window. Missing configuration leaves the endpoint disabled. Browser cookies do not authorize sending mail.
- Only the configured tenant and the two intended applications are accepted. Only Auth0's documented authentication-message types are supported; no attachments, arbitrary headers, CC or BCC.
- The sender is fixed, messages are size-limited, and sending is limited to 10 requests per recipient per 10 minutes and 60 total per minute. These are conservative starting limits, not proof of the hosting plan's mail allowance.
- A keyed message digest suppresses successful retries for one day. The relay stores no recipient, content or sign-in codes. It cannot promise exactly-once delivery if the process dies between mail acceptance and saving the result.
- Transport failures are returned to Auth0 for bounded retries. The Action does not follow redirects or log codes, recipients or private response bodies. Inspect success/failure metadata without enabling payload logging.
- Disabling or removing the mail signing key stops this connection. It does not delete users, reset credentials, or revoke ChatGPT. Restore a previously working sender only if it is production-ready; Auth0's test sender is not a production fallback.

## Local verification

Run only against a disposable WordPress configured with `WP_ENVIRONMENT_TYPE=local`:

```sh
php hosted/tests/auth0-mail.php /path/to/disposable-wordpress
node --test hosted/tests/auth0-mail-action.test.cjs
```

The PHP test intercepts every mail call and covers the actual WordPress REST route. The Action tests intercept every HTTP call. Neither test sends real mail or calls Auth0. Run `npm run check:hosted` for syntax and tool-contract checks.

References: [Auth0 custom email Actions](https://auth0.com/docs/customize/email/smtp-email-providers/custom/configure-action), [message fields](https://auth0.com/docs/actions/reference/custom-email-provider/custom-email-provider-event-object), [retry behavior](https://auth0.com/docs/actions/reference/custom-email-provider/custom-email-provider-api-object).
