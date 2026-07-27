<?php

defined('ABSPATH') || exit;

class GHAD_Crypto {

    public static function encrypt($plaintext) {
        if (empty($plaintext)) {
            return '';
        }
        $key  = self::key();
        $iv   = random_bytes(16);
        $tag  = '';
        $cipher = openssl_encrypt($plaintext, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return 'ghad_enc:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt($encrypted) {
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
        $key = self::key();
        $decrypted = openssl_decrypt($key_cipher, 'AES-256-GCM', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return false === $decrypted ? '' : $decrypted;
    }

    private static function key() {
        $salt = defined('AUTH_SALT') ? AUTH_SALT : wp_salt('auth');
        return hash('sha256', $salt . 'ghad-encryption-key', true);
    }
}
