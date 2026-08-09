# Privacy and local data

Dashless has no hosted service, analytics, advertising, telemetry, or Dashless account. It sends data only where the requested workflow requires it: the connected WordPress site, the configured deployment host, and npm during the first locked Astro dependency installation.

## Stored locally

Dashless stores:

- WordPress site URL, username, discovered capabilities, and connection metadata;
- the Application Password in macOS Login Keychain or a mode-`0600` local secrets file;
- stable draft-creation keys used to prevent duplicates;
- staged changes and single-use preview locks;
- frontend and deployment configuration; and
- temporary preview and SFTP runtime records.

The default data locations are:

- macOS: `~/Library/Application Support/Dashless`
- Linux: `$XDG_DATA_HOME/dashless` or `~/.local/share/dashless`
- Windows experimental support: `%LOCALAPPDATA%\Dashless`

`DASHLESS_DATA_DIR` overrides the location.

## Removal

The normal removal path is the guarded `disconnect_site` tool. By default it revokes the current WordPress Application Password, deletes the local secret and connection, and erases the site's internal staging records. It does not delete WordPress content, deployed releases, or the generated Astro source directory.

After all sites are disconnected, the remaining Dashless data directory may be removed manually if the user also wants shared runtime history removed. A normally installed WordPress plugin deletes its Dashless options through `uninstall.php`; an automatically installed must-use companion must be removed by the host or SFTP administrator after another router is restored. Static releases are intentionally not deleted automatically.

## CI credentials

`DASHLESS_WORDPRESS_URL`, `DASHLESS_WORDPRESS_USERNAME`, and `DASHLESS_WORDPRESS_APP_PASSWORD` provide an ephemeral connection. They are read from the process environment and are never persisted by Dashless.
