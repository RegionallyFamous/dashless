# Security policy

## Supported releases

Security fixes are provided for the latest `1.0.x` release. Upgrade both the Codex plugin and the WordPress companion when a security release changes either component.

## Reporting a vulnerability

Use the private vulnerability-reporting or Security Advisory feature of the GitHub repository from which Dashless was obtained. Do not publish credentials, private site URLs, unpublished content, release manifests, filesystem paths, or exploit details in a public issue.

Include the affected Dashless version, operating system, Node/PHP/WordPress versions, deployment type, reproduction steps, and whether unpublished content or credentials may have been exposed. Redact Application Passwords, API keys, SSH private keys, cookies, and authorization headers.

## Immediate containment

If a WordPress Application Password may be exposed, revoke it in WordPress immediately and disconnect the site from Dashless. If an SSH key or WP Cloud API key may be exposed, remove or rotate it at its provider. Preserve logs and the affected package checksum without posting them publicly.

## Security boundaries

- Dashless is local software and has no telemetry or hosted Dashless account.
- WordPress Application Passwords are accepted only by a loopback setup page. macOS stores them in Login Keychain; other supported systems use a mode-`0600` local file.
- The generated Astro project never receives WordPress credentials.
- WordPress publication requires the exact successfully built, single-use preview lock.
- WP Cloud activation verifies every release file against a SHA-256 manifest and refuses server-executable files.
- Failed builds, transfers, integrity checks, and activations preserve the previous static release.

See [the publishing contract](docs/publishing-contract.md), [privacy and local data](docs/privacy.md), and [deployment architecture](docs/deployment.md) for the complete model.
