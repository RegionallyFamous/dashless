'use strict';

// Auth0 Custom Email Provider Action. No npm dependencies and no new email vendor.
// Keep the signing key in Action secrets, never in this file or in logs.
const { createHmac } = require('node:crypto');
const endpoint = 'https://dashless.blog/wp-json/dashless-hub/v1/auth0/email';

exports.onExecuteCustomEmailProvider = async (event, api) => {
  const secret = event.secrets?.DASHLESS_EMAIL_SECRET;
  if (!/^[a-f0-9]{64}$/.test(secret ?? '')) {
    api.notification.drop('Dashless email signing key is not configured.');
    return;
  }
  const notification = event.notification ?? {};
  const body = JSON.stringify({
    tenant: event.tenant?.id ?? '',
    client_id: event.client?.client_id ?? '',
    message_type: notification.message_type ?? '',
    to: notification.to ?? '',
    subject: notification.subject ?? '',
    html: notification.html ?? '',
    text: notification.text ?? '',
  });
  if (Buffer.byteLength(body, 'utf8') > 131072) {
    api.notification.drop('Dashless email exceeds the size limit.');
    return;
  }
  const timestamp = String(Math.floor(Date.now() / 1000));
  const signature = createHmac('sha256', secret).update(`${timestamp}\n${body}`).digest('hex');
  try {
    const response = await fetch(endpoint, {
      method: 'POST',
      redirect: 'error',
      signal: AbortSignal.timeout(10000),
      headers: {
        'Content-Type': 'application/json',
        'X-Dashless-Mail-Time': timestamp,
        'X-Dashless-Mail-Signature': signature,
      },
      body,
    });
    if (response.status === 200 && (await response.json()).accepted === true) return;
    if (response.status >= 500 || [408,409,429].includes(response.status)) {
      api.notification.retry('Dashless email is temporarily unavailable.');
    } else {
      api.notification.drop('Dashless did not accept this email request.');
    }
  } catch {
    // Auth0 owns bounded retries. Do not log recipient addresses, codes or response bodies.
    api.notification.retry('Dashless email could not be reached.');
  }
};
