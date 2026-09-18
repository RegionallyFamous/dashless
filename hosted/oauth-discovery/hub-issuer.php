<?php
/**
 * Retired compatibility shim. Do not install this file as a MU plugin.
 *
 * The Hub now reads Auth0's issuer from protected `auth0_issuer` settings and
 * public discovery is served by the Cloudflare route. Keeping this file
 * inert prevents a stale workers.dev issuer from overriding the live tenant.
 */
