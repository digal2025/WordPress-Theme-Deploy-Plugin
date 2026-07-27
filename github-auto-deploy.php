<?php
/**
 * Plugin Name: GitHub Auto Deploy
 * Plugin URI:  https://github.com/digal2025/github-auto-deploy
 * Description: Auto-deploy themes/plugins from GitHub on push via webhook. Supports SSH key auth and multiple repos.
 * Version:     1.0.0
 * Author:      Simplicity Digital
 * License:     GPL-2.0+
 * Text Domain: github-auto-deploy
 */

defined('ABSPATH') || exit;

define('GHAD_VERSION', '1.0.0');
define('GHAD_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('GHAD_PLUGIN_URL', plugin_dir_url(__FILE__));
define('GHAD_OPTION_KEY', 'ghad_settings');
define('GHAD_LOG_KEY', 'ghad_deploy_log');

require_once GHAD_PLUGIN_DIR . 'includes/class-deployer.php';
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
            'repo_url'       => '',
            'branch'         => 'main',
            'local_path'     => '',
            'ssh_key'        => '',
            'webhook_secret' => '',
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
