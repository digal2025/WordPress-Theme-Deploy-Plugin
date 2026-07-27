<?php

defined('ABSPATH') || exit;

class GHAD_Admin {

    private $settings;

    public function __construct() {
        $this->settings = get_option(GHAD_OPTION_KEY, []);

        add_action('admin_menu', [$this, 'add_menu']);
        add_action('admin_init', [$this, 'register_settings']);
        add_action('admin_post_ghad_manual_deploy', [$this, 'manual_deploy']);
        add_action('admin_post_ghad_clear_log', [$this, 'clear_log']);
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
            'repo_url'       => sanitize_text_field($input['repo_url'] ?? ''),
            'branch'         => sanitize_text_field($input['branch'] ?? 'main'),
            'local_path'     => sanitize_text_field($input['local_path'] ?? ''),
            'webhook_secret' => sanitize_text_field($input['webhook_secret'] ?? ''),
            'ssh_key'        => $input['ssh_key'] ?? '',
        ];

        if (!empty($output['ssh_key']) && $output['ssh_key'] !== ($existing['ssh_key'] ?? '')) {
            $output['ssh_key'] = GHAD_Webhook::encrypt_ssh_key($output['ssh_key']);
        } elseif (!empty($existing['ssh_key'])) {
            $output['ssh_key'] = $existing['ssh_key'];
        }

        return $output;
    }

    public function render_page() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }

        $settings   = $this->settings;
        $webhook_url = rest_url('gh-deploy/v1/webhook');
        $logs       = get_option(GHAD_LOG_KEY, []);
        $has_git    = $this->check_git();
        ?>
        <div class="wrap">
            <h1>GitHub Auto Deploy</h1>

            <?php if (!$has_git): ?>
                <div class="notice notice-error"><p>Git is not installed on this server. The deployer cannot run.</p></div>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php settings_fields('ghad_settings'); ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row"><label for="ghad_repo_url">GitHub Repo URL (SSH)</label></th>
                        <td>
                            <input type="text" id="ghad_repo_url" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[repo_url]"
                                   value="<?php echo esc_attr($settings['repo_url'] ?? ''); ?>" class="regular-text"
                                   placeholder="git@github.com:user/repo.git">
                            <p class="description">SSH URL of the repository to deploy.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ghad_branch">Branch</label></th>
                        <td>
                            <input type="text" id="ghad_branch" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[branch]"
                                   value="<?php echo esc_attr($settings['branch'] ?? 'main'); ?>" class="regular-text">
                            <p class="description">Branch that triggers auto-deploy on push.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ghad_local_path">Local Path</label></th>
                        <td>
                            <input type="text" id="ghad_local_path" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[local_path]"
                                   value="<?php echo esc_attr($settings['local_path'] ?? ''); ?>" class="regular-text code"
                                   placeholder="/path/to/repo-on-server">
                            <p class="description">Absolute path to the repo directory on the server.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ghad_ssh_key">SSH Private Key</label></th>
                        <td>
                            <textarea id="ghad_ssh_key" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[ssh_key]"
                                      rows="8" class="large-text code" placeholder="-----BEGIN OPENSSH PRIVATE KEY-----"><?php
                                echo esc_textarea($settings['ssh_key'] ?? '');
                            ?></textarea>
                            <p class="description">
                                Private key with access to the GitHub repo. Stored encrypted in the database.
                                Leave blank to keep the existing key.
                            </p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="ghad_webhook_secret">Webhook Secret</label></th>
                        <td>
                            <input type="text" id="ghad_webhook_secret" name="<?php echo esc_attr(GHAD_OPTION_KEY); ?>[webhook_secret]"
                                   value="<?php echo esc_attr($settings['webhook_secret'] ?? ''); ?>" class="regular-text">
                            <p class="description">Secret used to verify GitHub webhook payloads (HMAC-SHA256).</p>
                        </td>
                    </tr>
                </table>

                <?php submit_button('Save Settings'); ?>
            </form>

            <hr>

            <h2>Webhook URL</h2>
            <p>Add this URL as a webhook in your GitHub repository (<strong>Settings → Webhooks → Add webhook</strong>):</p>
            <p><code style="font-size:14px;word-break:break-all;background:#f0f0f1;padding:8px 12px;display:inline-block;border-radius:4px;"><?php echo esc_url($webhook_url); ?></code></p>
            <table class="wp-list-table widefat fixed striped" style="max-width:600px;margin-top:10px;">
                <tbody>
                    <tr><td><strong>Content type</strong></td><td><code>application/json</code></td></tr>
                    <tr><td><strong>Secret</strong></td><td>Your webhook secret (above)</td></tr>
                    <tr><td><strong>Events</strong></td><td>Just the push event</td></tr>
                    <tr><td><strong>SSL</strong></td><td>Enable. For local dev, use a tool like ngrok.</td></tr>
                </tbody>
            </table>

            <hr>

            <h2>Manual Deploy</h2>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-bottom:20px;">
                <input type="hidden" name="action" value="ghad_manual_deploy">
                <?php wp_nonce_field('ghad_manual_deploy'); ?>
                <p>Runs the deploy now regardless of webhook.</p>
                <?php submit_button('Run Deploy Now', 'primary', 'submit', false); ?>
            </form>

            <hr>

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
        </div>
        <style>
            .ghad-badge {
                display: inline-block;
                padding: 2px 10px;
                border-radius: 3px;
                font-size: 12px;
                font-weight: 600;
                text-transform: uppercase;
            }
            .ghad-badge--success {
                background: #d4edda;
                color: #155724;
            }
            .ghad-badge--failed {
                background: #f8d7da;
                color: #721c24;
            }
        </style>
        <?php
    }

    public function manual_deploy() {
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized.');
        }
        check_admin_referer('ghad_manual_deploy');

        $settings = get_option(GHAD_OPTION_KEY, []);
        $ssh_key  = $this->decrypt_ssh_key($settings['ssh_key'] ?? '');

        if (empty($settings['repo_url']) || empty($settings['local_path']) || empty($ssh_key)) {
            wp_redirect(add_query_arg('ghad_msg', 'incomplete', wp_get_referer()));
            exit;
        }

        $deployer = new GHAD_Deployer(
            $settings['repo_url'],
            $settings['branch'],
            $settings['local_path'],
            $ssh_key
        );
        $result = $deployer->run();

        $logs = get_option(GHAD_LOG_KEY, []);
        $entry = [
            'time'    => current_time('mysql'),
            'status'  => $result['success'] ? 'success' : 'failed',
            'repo'    => $settings['repo_url'],
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
            'deploy_ok'    => ['Deploy completed successfully.', 'success'],
            'deploy_fail'  => ['Deploy failed. Check the log for details.', 'error'],
            'log_cleared'  => ['Deployment log cleared.', 'info'],
            'incomplete'   => ['Settings incomplete. Fill in repo URL, local path, and SSH key.', 'warning'],
        ];

        if (isset($notices[$msg])) {
            add_action('admin_notices', function () use ($notices, $msg) {
                echo '<div class="notice notice-' . esc_attr($notices[$msg][1]) . ' is-dismissible"><p>' . esc_html($notices[$msg][0]) . '</p></div>';
            });
        }
    }

    private function check_git() {
        $output = shell_exec('git --version 2>/dev/null');
        return $output && strpos($output, 'git version') === 0;
    }

    private function decrypt_ssh_key($encrypted) {
        if (empty($encrypted)) {
            return '';
        }

        if (0 !== strpos($encrypted, 'ghad_enc:')) {
            return $encrypted;
        }

        $data = base64_decode(substr($encrypted, 9));
        if (false === $data || strlen($data) < 48) {
            return '';
        }

        $iv  = substr($data, 0, 16);
        $tag = substr($data, 16, 16);
        $key_cipher = substr($data, 32);

        $key = hash('sha256', (defined('AUTH_SALT') ? AUTH_SALT : wp_salt('auth')) . 'ghad-encryption-key', true);
        $decrypted = openssl_decrypt($key_cipher, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return false === $decrypted ? '' : $decrypted;
    }
}
