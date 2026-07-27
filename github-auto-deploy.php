<?php
/**
 * Plugin Name: GitHub Auto Deploy
 * Plugin URI:  https://github.com/digal2025/WordPress-Theme-Deploy-Plugin
 * Description: Auto-deploy themes/plugins from GitHub on push. OAuth-based setup — just connect your GitHub account, pick a repo, and go.
 * Version:     2.0.0
 * Author:      Simplicity Digital
 * License:     GPL-2.0+
 * Text Domain: github-auto-deploy
 */

defined('ABSPATH') || exit;

define('GHAD_VERSION', '2.0.0');
define('GHAD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GHAD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GHAD_OPTION_KEY', 'ghad_settings');
define('GHAD_LOG_KEY', 'ghad_deploy_log');

require_once GHAD_PLUGIN_DIR . 'includes/class-crypto.php';
require_once GHAD_PLUGIN_DIR . 'includes/class-deployer.php';
require_once GHAD_PLUGIN_DIR . 'includes/class-github-api.php';
require_once GHAD_PLUGIN_DIR . 'includes/class-webhook.php';
require_once GHAD_PLUGIN_DIR . 'includes/class-admin.php';

class GitHub_Auto_Deploy {

    private static $instance = null;

    public static function init() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        new GHAD_Admin();
        new GHAD_Webhook();
    }

    public static function activate() {
        $defaults = [
            'client_id'      => '',
            'client_secret'  => '',
            'access_token'   => '',
            'github_user'    => '',
            'repo_full_name' => '',
            'repo_ssh_url'   => '',
            'branch'         => 'main',
            'local_path'     => '',
            'ssh_key'        => '',
            'ssh_key_title'  => '',
            'webhook_secret' => '',
            'webhook_id'     => '',
            'deploy_key_id'  => '',
        ];
        if (!get_option(GHAD_OPTION_KEY, false)) {
            add_option(GHAD_OPTION_KEY, $defaults);
        }
        if (!get_option(GHAD_LOG_KEY, false)) {
            add_option(GHAD_LOG_KEY, []);
        }
    }

    public static function deactivate() {
        flush_rewrite_rules();
    }

    public static function uninstall() {
        delete_option(GHAD_OPTION_KEY);
        delete_option(GHAD_LOG_KEY);
    }
}

add_action('plugins_loaded', [GitHub_Auto_Deploy::class, 'init']);
register_activation_hook(__FILE__, [GitHub_Auto_Deploy::class, 'activate']);
register_deactivation_hook(__FILE__, [GitHub_Auto_Deploy::class, 'deactivate']);
