<?php

defined('ABSPATH') || exit;

class GHAD_Admin {

    private $settings;

    public function __construct() {
        $this->settings = get_option(GHAD_OPTION_KEY, []);

        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_init', [$this, 'handle_oauth_callback']);
        add_action('admin_post_ghad_manual_deploy', [$this, 'manual_deploy']);
        add_action('admin_post_ghad_clear_log', [$this, 'clear_log']);
        add_action('admin_post_ghad_disconnect', [$this, 'disconnect']);
        add_action('wp_ajax_ghad_fetch_repos', [$this, 'ajax_fetch_repos']);
        add_action('wp_ajax_ghad_setup_deploy_key', [$this, 'ajax_setup_deploy_key']);
        add_action('wp_ajax_ghad_create_webhook', [$this, 'ajax_create_webhook']);
        add_action('admin_enqueue_scripts', [$this, 'admin_assets']);
    }

    public function add_menu() {
        add_management_page(
            'GitHub Auto Deploy',
            'GitHub Deploy',
            'manage_options',
            'github-auto-deploy',
            [$this, 'render_page']
        );
    }

    public function register_settings() {
        register_setting('ghad_settings', GHAD_OPTION_KEY, [$this, 'sanitize_settings']);
    }

    public function sanitize_settings($input) {
        $existing = get_option(GHAD_OPTION_KEY, []);

        $output = [
            'client_id'         => sanitize_text_field($input['client_id'] ?? ''),
            'client_secret'     => sanitize_text_field($input['client_secret'] ?? ''),
            'access_token'      => $existing['access_token'] ?? '',
            'github_user'       => $existing['github_user'] ?? '',
            'repo_full_name'    => sanitize_text_field($input['repo_full_name'] ?? ''),
            'repo_ssh_url'      => sanitize_text_field($input['repo_ssh_url'] ?? ''),
            'branch'            => sanitize_text_field($input['branch'] ?? 'main'),
            'local_path'        => sanitize_text_field($input['local_path'] ?? ''),
            'ssh_key'           => $existing['ssh_key'] ?? '',
            'ssh_key_title'     => $existing['ssh_key_title'] ?? '',
            'webhook_secret'    => $existing['webhook_secret'] ?? '',
            'webhook_id'        => $existing['webhook_id'] ?? '',
            'deploy_key_id'     => $existing['deploy_key_id'] ?? '',
        ];

        if (!empty($input['client_secret']) && $input['client_secret'] !== ($existing['client_secret'] ?? '')) {
            $output['client_secret'] = GHAD_Crypto::encrypt($input['client_secret']);
        } elseif (!empty($existing['client_secret'])) {
            $output['client_secret'] = $existing['client_secret'];
        }

        return $output;
    }

    public function handle_oauth_callback() {
        if (!isset($_GET['page'], $_GET['ghad_oauth'], $_GET['code'], $_GET['state']) || 'github-auto-deploy' !== $_GET['page']) {
            return;
        }

        if (!current_user_can('manage_options')) {
            return;
        }

        $settings_uri = admin_url('tools.php?page=github-auto-deploy');

        $state = $_GET['state'];
        $stored = get_transient('ghad_oauth_state');
        if (!$stored || $stored !== $state) {
            wp_redirect(add_query_arg('ghad_msg', 'oauth_state_mismatch', $settings_uri));
            exit;
        }
        delete_transient('ghad_oauth_state');

        $settings = get_option(GHAD_OPTION_KEY, []);
        $client_id     = $settings['client_id'] ?? '';
        $client_secret = GHAD_Crypto::decrypt($settings['client_secret'] ?? '');
        $code          = $_GET['code'];
        $redirect_uri  = $settings_uri . '&ghad_oauth=1';

        if (empty($client_id) || empty($client_secret)) {
            wp_redirect(add_query_arg('ghad_msg', 'oauth_no_creds', $settings_uri));
            exit;
        }

        $token = GHAD_GitHub_API::exchange_code($client_id, $client_secret, $code, $redirect_uri);
        if (is_wp_error($token)) {
            wp_redirect(add_query_arg('ghad_msg', 'oauth_fail', $redirect_uri));
            exit;
        }

        $api = new GHAD_GitHub_API($token);
        $user = $api->get_user();
        if (is_wp_error($user)) {
            wp_redirect(add_query_arg('ghad_msg', 'oauth_fail', $redirect_uri));
            exit;
        }

        $settings['access_token'] = GHAD_Crypto::encrypt($token);
        $settings['github_user']  = $user->login;
        update_option(GHAD_OPTION_KEY, $settings);

        wp_redirect(add_query_arg('ghad_msg', 'oauth_ok', $redirect_uri));
        exit;
    }

    public function disconnect() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer('ghad_disconnect');

        $settings = get_option(GHAD_OPTION_KEY, []);
        $settings['access_token'] = '';
        $settings['github_user']  = '';
        $settings['repo_full_name'] = '';
        $settings['repo_ssh_url']   = '';
        $settings['ssh_key']        = '';
        $settings['ssh_key_title']  = '';
        $settings['webhook_id']     = '';
        $settings['deploy_key_id']  = '';
        update_option(GHAD_OPTION_KEY, $settings);

        wp_redirect(add_query_arg('ghad_msg', 'disconnected', wp_get_referer()));
        exit;
    }

    public function ajax_fetch_repos() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }
        check_ajax_referer('ghad_fetch_repos');

        $token = GHAD_Crypto::decrypt($this->settings['access_token'] ?? '');
        if (empty($token)) {
            wp_send_json_error('Not connected to GitHub.');
        }

        $api   = new GHAD_GitHub_API($token);
        $repos = $api->get_repos();
        if (is_wp_error($repos)) {
            wp_send_json_error($repos->get_error_message());
        }

        $data = array_map(function ($r) {
            return [
                'name'     => $r['full_name'],
                'ssh_url'  => $r['ssh_url'],
                'clone_url' => $r['clone_url'],
                'private'  => $r['private'],
            ];
        }, $repos);

        usort($data, function ($a, $b) {
            return strcmp(strtolower($a['name']), strtolower($b['name']));
        });

        wp_send_json_success($data);
    }

    public function ajax_setup_deploy_key() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }
        check_ajax_referer('ghad_setup_deploy_key');

        $settings = get_option(GHAD_OPTION_KEY, []);
        $token    = GHAD_Crypto::decrypt($settings['access_token'] ?? '');
        $repo     = $settings['repo_full_name'] ?? '';

        if (empty($token)) {
            wp_send_json_error('Not connected to GitHub.');
        }
        if (empty($repo)) {
            wp_send_json_error('No repo selected.');
        }

        $parts = explode('/', $repo, 2);
        if (count($parts) !== 2) {
            wp_send_json_error('Invalid repo name.');
        }

        $keypair = GHAD_GitHub_API::generate_ssh_key();
        if (is_wp_error($keypair)) {
            wp_send_json_error($keypair->get_error_message());
        }

        $api   = new GHAD_GitHub_API($token);
        $title = 'ghad-deploy-' . parse_url(site_url(), PHP_URL_HOST);
        $result = $api->create_deploy_key($parts[0], $parts[1], $title, $keypair['public']);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $settings['ssh_key']       = GHAD_Crypto::encrypt($keypair['private']);
        $settings['ssh_key_title'] = $title;
        $settings['deploy_key_id'] = $result->id;
        update_option(GHAD_OPTION_KEY, $settings);

        wp_send_json_success([
            'title' => $title,
            'id'    => $result->id,
        ]);
    }

    public function ajax_create_webhook() {
        if (!current_user_can('manage_options')) {
            wp_send_json_error('Unauthorized.');
        }
        check_ajax_referer('ghad_create_webhook');

        $settings = get_option(GHAD_OPTION_KEY, []);
        $token    = GHAD_Crypto::decrypt($settings['access_token'] ?? '');
        $repo     = $settings['repo_full_name'] ?? '';

        if (empty($token)) {
            wp_send_json_error('Not connected to GitHub.');
        }
        if (empty($repo)) {
            wp_send_json_error('No repo selected.');
        }

        $parts = explode('/', $repo, 2);
        if (count($parts) !== 2) {
            wp_send_json_error('Invalid repo name.');
        }

        require_once ABSPATH . WPINC . '/class-phpass.php';
        $secret = wp_generate_password(32, false);

        $api   = new GHAD_GitHub_API($token);
        $url   = rest_url('gh-deploy/v1/webhook');
        $result = $api->create_webhook($parts[0], $parts[1], $url, $secret);

        if (is_wp_error($result)) {
            wp_send_json_error($result->get_error_message());
        }

        $settings['webhook_secret'] = $secret;
        $settings['webhook_id']     = $result->id;
        update_option(GHAD_OPTION_KEY, $settings);

        wp_send_json_success([
            'id'     => $result->id,
            'url'    => $url,
            'secret' => $secret,
        ]);
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        $settings   = get_option(GHAD_OPTION_KEY, []);
        $has_git    = $this->check_git();
        $connected  = !empty($settings['access_token']);
        $logs       = get_option(GHAD_LOG_KEY, []);
        $webhook_url = rest_url('gh-deploy/v1/webhook');
        ?>
        <div class="wrap">
            <h1>GitHub Auto Deploy</h1>

            <?php if (!$has_git): ?>
                <div class="notice notice-error"><p>Git is not installed on this server. The deployer cannot run.</p></div>
            <?php endif; ?>

            <?php $this->render_oauth_section($settings); ?>

            <hr>

            <?php if ($connected): ?>
                <?php $this->render_connected_section($settings, $webhook_url); ?>
                <hr>
                <?php $this->render_deploy_section($settings); ?>
                <hr>
                <?php $this->render_log_section($logs); ?>
            <?php endif; ?>
        </div>
        <?php
    }

    private function render_oauth_section($settings) {
        $client_id     = $settings['client_id'] ?? '';
        $connected     = !empty($settings['access_token']);
        $github_user   = $settings['github_user'] ?? '';
        $redirect_uri  = admin_url('tools.php?page=github-auto-deploy&ghad_oauth=1');

        $state = wp_generate_password(32, false);
        set_transient('ghad_oauth_state', $state, 600);
        ?>
        <h2>1. GitHub OAuth App</h2>
        <p>Create a <a href="https://github.com/settings/developers" target="_blank">GitHub OAuth App</a> and enter the credentials below.</p>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Callback URL</th>
                <td>
                    <code style="background:#f0f0f1;padding:4px 8px;border-radius:3px;font-size:13px;"><?php echo esc_url($redirect_uri); ?></code>
                    <p class="description">Register this in your OAuth App under <strong>Authorization callback URL</strong>.</p>
                </td>
            </tr>
        </table>

        <form method="post" action="options.php">
            <?php settings_fields('ghad_settings'); ?>
            <table class="form-table" role="presentation">
                <tr>
                    <th scope="row"><label for="ghad_client_id">Client ID</label></th>
                    <td>
                        <input type="text" id="ghad_client_id" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[client_id]"
                               value="<?php echo esc_attr($client_id); ?>" class="regular-text"
                               placeholder="Iv1...">
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="ghad_client_secret">Client Secret</label></th>
                    <td>
                        <input type="password" id="ghad_client_secret" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[client_secret]"
                               value="<?php echo esc_attr($settings['client_secret'] ? '********' : ''); ?>" class="regular-text"
                               placeholder="Leave blank to keep existing">
                    </td>
                </tr>
            </table>
            <?php submit_button('Save OAuth Credentials'); ?>
        </form>

        <?php if (!empty($client_id)): ?>
            <?php if ($connected): ?>
                <p style="font-size:14px;">
                    Connected as <strong><?php echo esc_html($github_user); ?></strong>
                    <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=ghad_disconnect'), 'ghad_disconnect')); ?>"
                       style="color:#b32d2e;margin-left:12px;">Disconnect</a>
                </p>
            <?php else: ?>
                <a href="<?php echo esc_url(GHAD_GitHub_API::get_authorize_url($client_id, $redirect_uri, $state)); ?>"
                   class="button button-primary" style="font-size:14px;padding:6px 20px;">
                    Connect with GitHub
                </a>
            <?php endif; ?>
        <?php endif; ?>
        <?php
    }

    private function render_connected_section($settings, $webhook_url) {
        $repo_name = $settings['repo_full_name'] ?? '';
        $has_key   = !empty($settings['ssh_key']);
        $has_hook  = !empty($settings['webhook_id']);
        $branch    = $settings['branch'] ?? 'main';
        $local_path = $settings['local_path'] ?? '';
        ?>
        <h2>2. Repository</h2>

        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="ghad_repo">Select Repo</label></th>
                <td>
                    <select id="ghad_repo" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[repo_full_name]" style="min-width:350px;">
                        <option value="">— Select a repository —</option>
                        <?php if ($repo_name): ?>
                            <option value="<?php echo esc_attr($repo_name); ?>" selected><?php echo esc_html($repo_name); ?></option>
                        <?php endif; ?>
                    </select>
                    <input type="hidden" id="ghad_repo_ssh_url" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[repo_ssh_url]"
                           value="<?php echo esc_attr($settings['repo_ssh_url'] ?? ''); ?>">
                    <button type="button" id="ghad_refresh_repos" class="button" style="margin-left:6px;">Refresh</button>
                    <p class="description">Select a repo from your GitHub account.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ghad_branch">Branch</label></th>
                <td>
                    <input type="text" id="ghad_branch" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[branch]"
                           value="<?php echo esc_attr($branch); ?>" class="regular-text">
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="ghad_local_path">Local Path</label></th>
                <td>
                    <input type="text" id="ghad_local_path" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[local_path]"
                           value="<?php echo esc_attr($local_path); ?>" class="regular-text code" style="width:450px;"
                           placeholder="/home/user/public_html/wp-content/themes/my-theme">
                </td>
            </tr>
        </table>

        <p><button type="button" id="ghad_save_repo" class="button button-primary">Save Repo Settings</button></p>

        <hr>

        <h2>3. SSH Deploy Key</h2>
        <?php if ($has_key): ?>
            <p style="font-size:14px;">
                <span style="color:#46b450;">&#10003;</span> Deploy key <strong><?php echo esc_html($settings['ssh_key_title'] ?? ''); ?></strong> is installed.
                <button type="button" id="ghad_setup_key" class="button" style="margin-left:10px;">Re-generate</button>
            </p>
        <?php else: ?>
            <p>An SSH key is needed for secure git access. Generate one now — the public key will be added as a deploy key to your repo, and the private key will be stored encrypted.</p>
            <button type="button" id="ghad_setup_key" class="button button-primary">Generate &amp; Add Deploy Key</button>
        <?php endif; ?>
        <p class="description" id="ghad_key_status"></p>

        <hr>

        <h2>4. Webhook</h2>
        <?php if ($has_hook): ?>
            <p style="font-size:14px;">
                <span style="color:#46b450;">&#10003;</span> Webhook created (ID: <?php echo esc_html($settings['webhook_id']); ?>).
                <button type="button" id="ghad_create_hook" class="button" style="margin-left:10px;">Re-create</button>
            </p>
        <?php else: ?>
            <p>Create a webhook on GitHub so pushes trigger automatic deploys.</p>
            <button type="button" id="ghad_create_hook" class="button button-primary">Create Webhook on GitHub</button>
        <?php endif; ?>
        <p class="description" id="ghad_hook_status"></p>

        <p>Webhook endpoint URL (for manual reference): <code style="background:#f0f0f1;padding:2px 6px;"><?php echo esc_url($webhook_url); ?></code></p>
        <?php
    }

    private function render_deploy_section($settings) {
        ?>
        <h2>Manual Deploy</h2>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:20px;">
            <input type="hidden" name="action" value="ghad_manual_deploy">
            <?php wp_nonce_field('ghad_manual_deploy'); ?>
            <p>Run a deploy now regardless of webhook.</p>
            <?php submit_button('Run Deploy Now', 'primary', 'submit', false); ?>
        </form>
        <?php
    }

    private function render_log_section($logs) {
        ?>
        <h2>Deployment Log</h2>
        <?php if (empty($logs)): ?>
            <p>No deployments yet.</p>
        <?php else: ?>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:15px;">
                <input type="hidden" name="action" value="ghad_clear_log">
                <?php wp_nonce_field('ghad_clear_log'); ?>
                <?php submit_button('Clear Log', 'delete', 'submit', false); ?>
            </form>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th style="width:160px;">Time</th>
                        <th style="width:80px;">Status</th>
                        <th style="width:200px;">Repo</th>
                        <th style="width:100px;">Branch</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $entry): ?>
                        <tr>
                            <td><?php echo esc_html($entry['time']); ?></td>
                            <td>
                                <span class="ghad-badge ghad-badge--<?php echo esc_attr($entry['status']); ?>">
                                    <?php echo esc_html($entry['status']); ?>
                                </span>
                            </td>
                            <td><?php echo esc_html($entry['repo']); ?></td>
                            <td><?php echo esc_html($entry['branch']); ?></td>
                            <td>
                                <details>
                                    <summary style="cursor:pointer;"><?php echo esc_html($entry['message']); ?></summary>
                                    <pre style="background:#f6f7f7;padding:10px;margin-top:5px;font-size:12px;max-height:300px;overflow:auto;white-space:pre-wrap;"><?php echo esc_textarea($entry['details'] ?? ''); ?></pre>
                                </details>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    public function manual_deploy() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer('ghad_manual_deploy');

        $settings = get_option(GHAD_OPTION_KEY, []);
        $ssh_key  = GHAD_Crypto::decrypt($settings['ssh_key'] ?? '');
        $repo_url = $settings['repo_ssh_url'] ?? $settings['repo_full_name'] ?? '';

        if (empty($repo_url) || empty($settings['local_path']) || empty($ssh_key)) {
            wp_redirect(add_query_arg('ghad_msg', 'incomplete', wp_get_referer()));
            exit;
        }

        $deployer = new GHAD_Deployer($repo_url, $settings['branch'], $settings['local_path'], $ssh_key);
        $result   = $deployer->run();

        $logs = get_option(GHAD_LOG_KEY, []);
        $entry = [
            'time'    => current_time('mysql'),
            'status'  => $result['success'] ? 'success' : 'failed',
            'repo'    => $settings['repo_full_name'] ?: $repo_url,
            'branch'  => $settings['branch'],
            'message' => $result['message'],
            'details' => implode("\n", $deployer->get_log()),
        ];
        array_unshift($logs, $entry);
        $logs = array_slice($logs, 0, 100);
        update_option(GHAD_LOG_KEY, $logs);

        $msg = $result['success'] ? 'deploy_ok' : 'deploy_fail';
        wp_redirect(add_query_arg('ghad_msg', $msg, wp_get_referer()));
        exit;
    }

    public function clear_log() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer('ghad_clear_log');

        update_option(GHAD_LOG_KEY, []);
        wp_redirect(add_query_arg('ghad_msg', 'log_cleared', wp_get_referer()));
        exit;
    }

    public function admin_assets($hook) {
        if ('tools_page_github-auto-deploy' !== $hook) {
            return;
        }

        $msg = $_GET['ghad_msg'] ?? '';
        $notices = [
            'deploy_ok'           => ['Deploy completed successfully.', 'success'],
            'deploy_fail'         => ['Deploy failed. Check the log for details.', 'error'],
            'log_cleared'         => ['Deployment log cleared.', 'info'],
            'incomplete'          => ['Settings incomplete. Select a repo, set local path, and install the deploy key.', 'warning'],
            'oauth_ok'            => ['Connected to GitHub successfully!', 'success'],
            'oauth_fail'          => ['GitHub OAuth failed. Check your credentials and try again.', 'error'],
            'oauth_no_creds'      => ['Save your OAuth Client ID and Secret first.', 'warning'],
            'oauth_state_mismatch' => ['OAuth state mismatch. Try again.', 'error'],
            'disconnected'        => ['Disconnected from GitHub.', 'info'],
        ];
        if (isset($notices[$msg])) {
            add_action('admin_notices', function () use ($notices, $msg) {
                echo '<div class="notice notice-' . esc_attr($notices[$msg][1]) . ' is-dismissible"><p>' . esc_html($notices[$msg][0]) . '</p></div>';
            });
        }

        wp_enqueue_script('ghad-admin', GHAD_PLUGIN_URL . 'assets/admin.js', ['jquery'], GHAD_VERSION, true);
        wp_localize_script('ghad-admin', 'ghad', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce_fetch_repos' => wp_create_nonce('ghad_fetch_repos'),
            'nonce_setup_key'   => wp_create_nonce('ghad_setup_deploy_key'),
            'nonce_create_hook' => wp_create_nonce('ghad_create_webhook'),
        ]);
        ?>
        <style>
            .ghad-badge {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .ghad-badge--success { background: #d4edda; color: #155724; }
            .ghad-badge--failed  { background: #f8d7da; color: #721c24; }
            #ghad_key_status, #ghad_hook_status { font-style: italic; }
            #ghad_key_status.ghad-success,
            #ghad_hook_status.ghad-success { color: #46b450; font-style: normal; }
            #ghad_key_status.ghad-error,
            #ghad_hook_status.ghad-error { color: #b32d2e; font-style: normal; }
        </style>
        <?php
    }

    private function check_git() {
        $output = shell_exec('git --version 2>/dev/null');
        return $output && strpos($output, 'git version') === 0;
    }
}
