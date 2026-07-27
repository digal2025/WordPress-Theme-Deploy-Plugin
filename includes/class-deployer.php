<?php

defined('ABSPATH') || exit;

class GHAD_Deployer {

    private $repo_url;
    private $branch;
    private $local_path;
    private $ssh_key;

    private $log = [];

    public function __construct($repo_url, $branch, $local_path, $ssh_key) {
        $this->repo_url   = $repo_url;
        $this->branch     = $branch;
        $this->local_path = rtrim($local_path, '/');
        $this->ssh_key    = $ssh_key;
    }

    public function run() {
        $this->log('Starting deploy...');

        if (!$this->repo_url)   { return $this->error('Repo URL is empty.'); }
        if (!$this->branch)     { return $this->error('Branch is empty.'); }
        if (!$this->local_path) { return $this->error('Local path is empty.'); }
        if (!$this->ssh_key)    { return $this->error('SSH key is empty.'); }

        if (!function_exists('proc_open')) {
            return $this->error('proc_open is required but not available.');
        }

        $ssh_wrapper = $this->write_ssh_key();
        if (!$ssh_wrapper) {
            return $this->error('Failed to write SSH key.');
        }

        $env = [
            'GIT_SSH_COMMAND' => 'ssh -i ' . escapeshellarg($ssh_wrapper) . ' -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null',
        ];

        if (is_dir($this->local_path . '/.git')) {
            $result = $this->git_pull($env);
        } else {
            $result = $this->git_clone($env);
        }

        $this->cleanup_ssh_key($ssh_wrapper);

        $this->log($result['success'] ? 'Deploy complete.' : 'Deploy failed.');
        $this->log('');

        return $result;
    }

    public function get_log() {
        return $this->log;
    }

    private function git_pull($env) {
        $this->log("Directory exists. Running git fetch + reset for branch: {$this->branch}");

        $fetch = $this->run_command('git fetch origin', $this->local_path, $env);
        if (!$fetch['success']) { return $fetch; }

        $reset = $this->run_command('git reset --hard origin/' . escapeshellarg($this->branch), $this->local_path, $env);
        if (!$reset['success']) { return $reset; }

        $this->log("Pulled latest {$this->branch}.");
        return ['success' => true, 'message' => 'Deploy complete via git pull.'];
    }

    private function git_clone($env) {
        $parent = dirname($this->local_path);
        if (!is_dir($parent)) {
            return $this->error("Parent directory does not exist: {$parent}");
        }

        $this->log("Directory does not exist. Cloning {$this->repo_url} (branch: {$this->branch})...");

        $cmd = sprintf(
            'git clone --depth 1 --branch %s %s %s',
            escapeshellarg($this->branch),
            escapeshellarg($this->repo_url),
            escapeshellarg($this->local_path)
        );

        $result = $this->run_command($cmd, $parent, $env);
        if ($result['success']) {
            $this->log('Clone complete.');
        }
        return $result;
    }

    private function write_ssh_key() {
        $tmp_dir = sys_get_temp_dir();
        $key_file = $tmp_dir . '/ghad_deploy_key_' . uniqid();

        $written = file_put_contents($key_file, $this->ssh_key);
        if (false === $written) {
            return false;
        }

        chmod($key_file, 0600);

        return $key_file;
    }

    private function cleanup_ssh_key($path) {
        if ($path && file_exists($path)) {
            @unlink($path);
        }
    }

    private function run_command($cmd, $cwd, $env) {
        $descriptors = [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ];

        $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env);

        if (!is_resource($process)) {
            return $this->error("Failed to execute: {$cmd}");
        }

        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        $code   = proc_close($process);

        if ($stdout) { $this->log(trim($stdout)); }
        if ($stderr) { $this->log(trim($stderr)); }

        if (0 !== $code) {
            return $this->error("Command exited with code {$code}: {$stderr}");
        }

        return ['success' => true, 'message' => trim($stdout)];
    }

    private function log($msg) {
        $this->log[] = '[' . current_time('H:i:s') . '] ' . $msg;
    }

    private function error($msg) {
        $this->log($msg);
        return ['success' => false, 'message' => $msg];
    }
}
