<?php

defined('ABSPATH') || exit;

class GHAD_Updater {

    private $file;
    private $plugin;
    private $basename;
    private $slug;
    private $github_repo;
    private $github_url;

    public function __construct($file) {
        $this->file        = $file;
        $this->plugin      = get_file_data($file, ['Version' => 'Version', 'PluginURI' => 'PluginURI']);
        $this->basename    = plugin_basename($file);
        $this->slug        = dirname($this->basename);
        $this->github_repo = 'digal2025/WordPress-Theme-Deploy-Plugin';
        $this->github_url  = 'https://github.com/' . $this->github_repo;

        add_filter('pre_set_site_transient_update_plugins', [$this, 'check_update']);
        add_filter('site_transient_update_plugins', [$this, 'check_update']);
        add_filter('plugins_api', [$this, 'plugin_info'], 20, 3);
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_latest_release();
        if (!$release || is_wp_error($release)) {
            return $transient;
        }

        $latest_version = $this->parse_version($release['tag_name'] ?? '');
        $current_version = $this->plugin['Version'];

        if (empty($latest_version)) {
            return $transient;
        }

        if (version_compare($latest_version, $current_version, '>')) {
            $download_url = $this->github_url . '/archive/refs/tags/' . $release['tag_name'] . '.zip';

            $transient->response[$this->basename] = (object) [
                'slug'        => $this->slug,
                'plugin'      => $this->basename,
                'new_version' => $latest_version,
                'url'         => $this->github_url,
                'package'     => $download_url,
                'tested'      => $this->get_tested_wp_version(),
                'icons'       => $this->get_plugin_icons(),
                'banners'     => [],
            ];
        }

        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ('plugin_information' !== $action || !isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_latest_release();
        $version = $release ? $this->parse_version($release['tag_name'] ?? '') : $this->plugin['Version'];

        $info = [
            'name'          => $this->get_plugin_name(),
            'slug'          => $this->slug,
            'version'       => $version,
            'author'        => '<a href="https://github.com/digal2025">Simplicity Digital</a>',
            'author_profile' => 'https://github.com/digal2025',
            'requires'      => '5.0',
            'tested'        => $this->get_tested_wp_version(),
            'requires_php'  => '7.4',
            'last_updated'  => $release['published_at'] ?? '',
            'homepage'      => $this->github_url,
            'short_description' => 'Auto-deploy themes/plugins from GitHub on push. OAuth-based setup — just connect your GitHub account, pick a repo, and go.',
            'sections'      => [
                'description' => $this->get_description(),
                'changelog'   => $release ? $this->format_changelog($release) : 'See <a href="' . esc_url($this->github_url . '/releases') . '">GitHub releases</a>.',
            ],
            'download_link' => $release
                ? $this->github_url . '/archive/refs/tags/' . $release['tag_name'] . '.zip'
                : '',
        ];

        return (object) $info;
    }

    private function get_latest_release() {
        $cache = get_transient('ghad_github_release');
        if (false !== $cache) {
            return $cache;
        }

        $settings = get_option(GHAD_OPTION_KEY, []);
        $token    = $settings['access_token'] ?? '';

        $args = [
            'headers' => [
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-GitHub-Auto-Deploy/2.0',
            ],
            'timeout' => 10,
        ];

        if (!empty($token)) {
            $args['headers']['Authorization'] = 'Bearer ' . GHAD_Crypto::decrypt($token);
        }

        $url      = 'https://api.github.com/repos/' . $this->github_repo . '/releases/latest';
        $response = wp_remote_get($url, $args);

        if (is_wp_error($response) || 200 !== wp_remote_retrieve_response_code($response)) {
            set_transient('ghad_github_release', [], HOUR_IN_SECONDS);
            return [];
        }

        $release = json_decode(wp_remote_retrieve_body($response), true);

        set_transient('ghad_github_release', $release, HOUR_IN_SECONDS);
        return $release;
    }

    private function parse_version($tag) {
        return ltrim($tag, 'vV');
    }

    private function get_plugin_name() {
        $data = get_file_data($this->file, ['Name' => 'Plugin Name']);
        return $data['Name'] ?: 'GitHub Auto Deploy';
    }

    private function get_description() {
        return '<p>Automatically deploy themes and plugins from GitHub to your WordPress server.</p>
<ul>
  <li><strong>OAuth Setup</strong> — Connect your GitHub account, pick a repo from a dropdown.</li>
  <li><strong>Auto Deploy Key</strong> — Plugin generates an SSH key and installs it as a deploy key.</li>
  <li><strong>Auto Webhook</strong> — Plugin creates a push webhook on GitHub automatically.</li>
  <li><strong>Zero Config</strong> — No manual SSH key or webhook setup needed.</li>
  <li><strong>Encrypted Storage</strong> — Tokens and keys encrypted with AES-256-GCM.</li>
</ul>
<p>After setup, every push to your branch automatically deploys via <code>git fetch && git reset --hard</code>.</p>';
    }

    private function format_changelog($release) {
        $body  = $release['body'] ?? '';
        $tag   = $release['tag_name'] ?? '';
        $date  = $release['published_at'] ?? '';
        $url   = $this->github_url . '/releases/tag/' . $tag;

        $date_fmt = $date ? date_i18n(get_option('date_format'), strtotime($date)) : '';

        $html = '<h3>' . esc_html($tag) . ($date_fmt ? ' — ' . $date_fmt : '') . '</h3>';
        $html .= '<pre style="background:#f6f7f7;padding:15px;font-size:13px;line-height:1.5;white-space:pre-wrap;">' . esc_html($body) . '</pre>';
        $html .= '<p><a href="' . esc_url($url) . '" target="_blank">View on GitHub</a></p>';

        return $html;
    }

    private function get_tested_wp_version() {
        global $wp_version;
        return $wp_version;
    }

    private function get_plugin_icons() {
        $svg = 'data:image/svg+xml;base64,' . base64_encode(
            '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 96 96"><rect width="96" height="96" rx="16" fill="#2d3748"/><path d="M48 24c-5 0-9 2-12 5l-1 1a16 16 0 000 22l18 18 18-18a16 16 0 000-22l-1-1c-3-3-7-5-12-5zm0 8a8 8 0 015 3l1 1a8 8 0 010 11l-6 6-6-6a8 8 0 010-11l1-1a8 8 0 015-3z" fill="#fff"/></svg>'
        );
        return [
            '1x' => $svg,
            '2x' => $svg,
        ];
    }
}
