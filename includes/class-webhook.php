<?php

defined('ABSPATH') || exit;

class GHAD_Webhook {

    public function __construct() {
        add_action('rest_api_init', [$this, 'register_route']);
    }

    public function register_route() {
        register_rest_route('gh-deploy/v1', '/webhook', [
            'methods'             => 'POST',
            'callback'            => [$this, 'handle'],
            'permission_callback' => '__return_true',
        ]);
    }

    public function handle($request) {
        $payload = $request->get_body();
        $headers = $request->get_headers();

        $settings = get_option(GHAD_OPTION_KEY, []);
        $secret   = $settings['webhook_secret'] ?? '';

        if (empty($secret)) {
            return $this->respond('Webhook secret not configured.', 500);
        }

        $signature = $headers['x_hub_signature_256'][0] ?? '';
        if (!$this->verify_signature($payload, $secret, $signature)) {
            return $this->respond('Invalid signature.', 403);
        }

        $event = $headers['x_github_event'][0] ?? '';

        if ('ping' === $event) {
            return $this->respond('Pong — webhook is working.');
        }

        if ('push' !== $event) {
            return $this->respond("Ignored event: {$event}", 200);
        }

        $data      = json_decode($payload, true);
        $ref       = $data['ref'] ?? '';
        $repo_name = $data['repository']['full_name'] ?? 'unknown';

        if (!preg_match('#^refs/heads/(.+)$#', $ref, $m)) {
            return $this->respond("Not a branch push: {$ref}", 200);
        }

        $branch = $m[1];

        if ($branch !== $settings['branch']) {
            return $this->respond("Branch {$branch} does not match configured branch {$settings['branch']}.", 200);
        }

        $repo_url   = $settings['repo_url'];
        $local_path = $settings['local_path'];
        $ssh_key    = $this->decrypt_ssh_key($settings['ssh_key']);

        if (empty($repo_url) || empty($local_path) || empty($ssh_key)) {
            return $this->respond('Plugin settings incomplete.', 500);
        }

        $deployer = new GHAD_Deployer($repo_url, $branch, $local_path, $ssh_key);
        $result   = $deployer->run();

        $this->save_log($result, $deployer->get_log(), $repo_name, $branch);

        if (!$result['success']) {
            return $this->respond('Deploy failed: ' . $result['message'], 500);
        }

        return $this->respond('Deploy complete.');
    }

    private function verify_signature($payload, $secret, $signature) {
        if (empty($signature)) {
            return false;
        }
        $expected = 'sha256=' . hash_hmac('sha256', $payload, $secret);
        return hash_equals($expected, $signature);
    }

    private function save_log($result, $log_lines, $repo_name, $branch) {
        $logs   = get_option(GHAD_LOG_KEY, []);
        $status = $result['success'] ? 'success' : 'failed';

        $entry = [
            'time'    => current_time('mysql'),
            'status'  => $status,
            'repo'    => $repo_name,
            'branch'  => $branch,
            'message' => $result['message'],
            'details' => implode("\n", $log_lines),
        ];

        array_unshift($logs, $entry);
        $logs = array_slice($logs, 0, 100);

        update_option(GHAD_LOG_KEY, $logs);
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

        $key = $this->encryption_key();

        $decrypted = openssl_decrypt($key_cipher, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return false === $decrypted ? '' : $decrypted;
    }

    private function respond($msg, $code = 200) {
        return new WP_REST_Response(['message' => $msg], $code);
    }

    public static function encrypt_ssh_key($plaintext) {
        if (empty($plaintext)) {
            return '';
        }

        $key  = self::encryption_key();
        $iv   = random_bytes(16);
        $tag  = '';

        $cipher = openssl_encrypt($plaintext, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);

        $data = 'ghad_enc:' . base64_encode($iv . $tag . $cipher);
        return $data;
    }

    private static function encryption_key() {
        $salt = defined('AUTH_SALT') ? AUTH_SALT : wp_salt('auth');
        return hash('sha256', $salt . 'ghad-encryption-key', true);
    }
}
