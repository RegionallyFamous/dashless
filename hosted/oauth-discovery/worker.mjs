// Public OAuth discovery only. Consent, tokens and account data stay on the Hub.
const issuer = 'https://auth.dashless.blog';
const hub = 'https://dashless.blog';
const scopes = ['blog:read', 'blog:write', 'blog:publish'];
const challenge = '2V4qbqcRbfU0LSyni7KdaWyrKf1HqBnyUZKlxzow80I';
export default {
  fetch(request) {
    if (!['GET', 'HEAD'].includes(request.method)) return new Response(null, { status: 405, headers: { Allow: 'GET, HEAD' } });
    const path = new URL(request.url).pathname;
    if (path === '/.well-known/openai-apps-challenge') return new Response(request.method === 'HEAD' ? null : challenge, {
      headers: { 'Content-Type': 'text/plain; charset=utf-8', 'Cache-Control': 'public, max-age=300', 'X-Content-Type-Options': 'nosniff' }
    });
    let data;
    if (path === '/.well-known/oauth-authorization-server') data = {
      issuer: issuer + '/', authorization_endpoint: issuer + '/authorize', token_endpoint: issuer + '/oauth/token',
      revocation_endpoint: issuer + '/oauth/revoke', registration_endpoint: issuer + '/oidc/register', response_types_supported: ['code'],
      grant_types_supported: ['authorization_code', 'refresh_token'], code_challenge_methods_supported: ['S256'],
      // ChatGPT connector implementations have used both public PKCE and
      // confidential-client token authentication. Keep the metadata broad
      // enough for predefined and dynamically registered clients; Auth0's
      // token endpoint validates the actual method and credentials.
      token_endpoint_auth_methods_supported: ['none', 'client_secret_basic', 'client_secret_post', 'private_key_jwt'], scopes_supported: scopes,
      authorization_response_iss_parameter_supported: true, client_id_metadata_document_supported: true
    };
    else if (['/.well-known/oauth-protected-resource', '/.well-known/oauth-protected-resource/mcp'].includes(path))
      data = { resource: hub + '/mcp', authorization_servers: [issuer + '/'], scopes_supported: scopes };
    else return fetch(request);
    return new Response(request.method === 'HEAD' ? null : JSON.stringify(data), {
      headers: { 'Content-Type': 'application/json', 'Cache-Control': 'public, max-age=60', 'Access-Control-Allow-Origin': '*', 'X-Content-Type-Options': 'nosniff' }
    });
  }
};
