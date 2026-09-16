<?php
/**
 * Plugin Name: Dashless Hosted Site
 * Description: Private editorial workflows and bounded native builds for one Dashless subscriber site.
 * Version: 0.1.0
 * Requires at least: 6.5
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 */
if ( ! defined( 'ABSPATH' ) ) { exit; }
if ( ! defined( 'DASHLESS_WPCLOUD_BRIDGE_VERSION' ) ) {
    require_once __DIR__ . '/dashless-wpcloud.php';
}
require_once __DIR__ . '/hosted/Support.php';
require_once __DIR__ . '/hosted/Store.php';
require_once __DIR__ . '/hosted/Vault.php';
require_once __DIR__ . '/hosted/Content.php';
require_once __DIR__ . '/hosted/RemoteRuntime.php';
require_once __DIR__ . '/hosted/Runtime.php';
require_once __DIR__ . '/hosted/Export.php';
require_once __DIR__ . '/hosted/Jobs.php';
require_once __DIR__ . '/hosted/App.php';
require_once __DIR__ . '/hosted/Routes.php';
require_once __DIR__ . '/hosted/Cli.php';
\Dashless\Site\App::boot();
