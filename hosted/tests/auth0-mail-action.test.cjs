'use strict';
const { test } = require('node:test');
const assert = require('node:assert/strict');
const { createHmac } = require('node:crypto');
const { onExecuteCustomEmailProvider: deliver } = require('../auth0/email-provider.cjs');
const secret = 'a'.repeat(64);
const event = {
  secrets: { DASHLESS_EMAIL_SECRET: secret },
  tenant: { id: 'tenant-fixture' }, client: { client_id: 'app-fixture' },
  notification: { from: 'ignored@example.test', to: 'owner@example.test', subject: 'Sign in', html: '<p>123456</p>', text: '123456', message_type: 'verification_code' },
};
async function run(t, response, input = event) {
  const calls = [], decisions = [];
  t.mock.method(globalThis, 'fetch', async (...args) => {
    calls.push(args);
    if (response instanceof Error) throw response;
    return new Response(response.body ?? '', { status: response.status });
  });
  await deliver(input, { notification: {
    retry: reason => decisions.push(['retry', reason]), drop: reason => decisions.push(['drop', reason]),
  } });
  return { calls, decisions };
}
test('signs a fixed-destination request without sending its secret or provider credentials', async t => {
  const { calls, decisions } = await run(t, { status: 200, body: '{"accepted":true}' });
  assert.deepEqual(decisions, []);
  assert.equal(calls.length, 1);
  const [url, request] = calls[0];
  assert.equal(url, 'https://dashless.blog/wp-json/dashless-hub/v1/auth0/email');
  assert.equal(request.method, 'POST'); assert.equal(request.redirect, 'error');
  assert.ok(request.signal instanceof AbortSignal);
  const timestamp = request.headers['X-Dashless-Mail-Time'];
  assert.equal(request.headers['X-Dashless-Mail-Signature'], createHmac('sha256', secret).update(`${timestamp}\n${request.body}`).digest('hex'));
  assert.deepEqual(JSON.parse(request.body), {
    tenant: 'tenant-fixture', client_id: 'app-fixture', message_type: 'verification_code',
    to: 'owner@example.test', subject: 'Sign in', html: '<p>123456</p>', text: '123456',
  });
  assert.ok(!JSON.stringify(request).includes(secret));
});
for (const status of [408,409,429,500,502,503]) test(`temporary ${status} is retried`, async t => {
  const result = await run(t, { status }); assert.equal(result.decisions[0][0], 'retry');
});
for (const status of [200,301,400,401,403,404,413]) test(`unaccepted ${status} is not treated as success`, async t => {
  const result = await run(t, { status, body: '{}' }); assert.equal(result.decisions[0][0], 'drop');
});
test('network errors retry without logging private error details', async t => {
  const result = await run(t, new Error('secret code 123456 and recipient address'));
  assert.equal(result.decisions[0][0], 'retry'); assert.ok(!JSON.stringify(result.decisions).includes('123456'));
});
test('invalid success response retries', async t => {
  const result = await run(t, { status: 200, body: '<html>Error</html>' }); assert.equal(result.decisions[0][0], 'retry');
});
test('missing key cannot send', async t => {
  const result = await run(t, { status: 200 }, { ...event, secrets: {} });
  assert.equal(result.calls.length, 0); assert.equal(result.decisions[0][0], 'drop');
});
test('oversized message cannot send', async t => {
  const result = await run(t, { status: 200 }, { ...event, notification: { ...event.notification, html: 'a'.repeat(131072) } });
  assert.equal(result.calls.length, 0); assert.equal(result.decisions[0][0], 'drop');
});
