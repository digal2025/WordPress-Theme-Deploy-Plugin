<?php

defined('ABSPATH') || exit;

class GHAD_GitHub_API {

    private $access_token;

    public function __construct($access_token) {
        $this->access_token = $access_token;
    }

    public function get_user() {
        $res = $this->get('/user');
        if (is_wp_error($res)) {
            return $res;
        }
        return json_decode($res);
    }

    public function get_repos() {
        $page  = 1;
        $repos = [];
        while (true) {
            $res = $this->get('/user/repos?per_page=100&page=' . $page);
            if (is_wp_error($res)) {
                return $res;
            }
            $data = json_decode($res, true);
            if (empty($data)) {
                break;
            }
            $repos = array_merge($repos, $data);
            if (count($data) < 100) {
                break;
            }
            $page++;
        }
        return $repos;
    }

    public function create_deploy_key($owner, $repo, $title, $public_key) {
        $body = [
            'title' => $title,
            'key'   => $public_key,
            'read_only' => false,
        ];
        $res = $this->post("/repos/{$owner}/{$repo}/keys", $body);
        if (is_wp_error($res)) {
            return $res;
        }
        return json_decode($res);
    }

    public function create_webhook($owner, $repo, $url, $secret) {
        $body = [
            'name'   => 'web',
            'active' => true,
            'events' => ['push'],
            'config' => [
                'url'          => $url,
                'content_type' => 'json',
                'secret'       => $secret,
                'insecure_ssl' => '0',
            ],
        ];
        $res = $this->post("/repos/{$owner}/{$repo}/hooks", $body);
        if (is_wp_error($res)) {
            return $res;
        }
        return json_decode($res);
    }

    public function delete_webhook($owner, $repo, $hook_id) {
        $res = $this->delete("/repos/{$owner}/{$repo}/hooks/{$hook_id}");
        if (is_wp_error($res)) {
            return $res;
        }
        return json_decode($res);
    }

    public function get_webhooks($owner, $repo) {
        $res = $this->get("/repos/{$owner}/{$repo}/hooks");
        if (is_wp_error($res)) {
            return $res;
        }
        return json_decode($res, true);
    }

    private function get($path) {
        return $this->request('GET', $path);
    }

    private function post($path, $body) {
        return $this->request('POST', $path, $body);
    }

    private function delete($path) {
        return $this->request('DELETE', $path);
    }

    private function request($method, $path, $body = null) {
        $url = 'https://api.github.com' . $path;
        $args = [
            'method'  => $method,
            'headers' => [
                'Authorization' => 'Bearer ' . $this->access_token,
                'Accept'        => 'application/vnd.github.v3+json',
                'User-Agent'    => 'WordPress-GitHub-Auto-Deploy/1.0',
            ],
            'timeout' => 15,
        ];

        if (null !== $body) {
            $args['body'] = wp_json_encode($body);
        }

        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            $msg = wp_remote_retrieve_body($response);
            return new WP_Error('github_api_error', "GitHub API error ({$code}): {$msg}");
        }

        return wp_remote_retrieve_body($response);
    }

    public static function get_authorize_url($client_id, $redirect_uri, $state) {
        $params = http_build_query([
            'client_id'    => $client_id,
            'redirect_uri' => $redirect_uri,
            'scope'        => 'repo,admin:repo_hooks,admin:public_key',
            'state'        => $state,
        ]);
        return 'https://github.com/login/oauth/authorize?' . $params;
    }

    public static function exchange_code($client_id, $client_secret, $code, $redirect_uri) {
        $url = 'https://github.com/login/oauth/access_token';
        $args = [
            'method'  => 'POST',
            'headers' => [
                'Accept'     => 'application/json',
                'User-Agent' => 'WordPress-GitHub-Auto-Deploy/1.0',
            ],
            'body' => [
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'code'          => $code,
                'redirect_uri'  => $redirect_uri,
            ],
            'timeout' => 15,
        ];

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            return $response;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);

        if (isset($body['error'])) {
            return new WP_Error('oauth_error', $body['error_description'] ?? $body['error']);
        }

        return $body['access_token'] ?? '';
    }

    public static function generate_ssh_key() {
        $key_file  = tempnam(sys_get_temp_dir(), 'ghad_key_');
        $pub_file  = $key_file . '.pub';

        $cmd = sprintf(
            'ssh-keygen -t ed25519 -f %s -N "" -C "ghad-deploy-key" 2>/dev/null',
            escapeshellarg($key_file)
        );

        exec($cmd, $output, $code);
        if (0 !== $code || !file_exists($key_file) || !file_exists($pub_file)) {
            if (file_exists($key_file)) { @unlink($key_file); }
            if (file_exists($pub_file)) { @unlink($pub_file); }
            return new WP_Error('keygen_error', 'Failed to generate SSH key pair.');
        }

        $private_key = file_get_contents($key_file);
        $public_key  = file_get_contents($pub_file);

        @unlink($key_file);
        @unlink($pub_file);

        return [
            'private' => $private_key,
            'public'  => trim($public_key),
        ];
    }
}
