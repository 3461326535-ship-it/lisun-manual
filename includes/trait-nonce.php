<?php
/**
 * Nonce 验证 Trait
 * 供各模块共用，避免重复实现
 */

if (!defined('ABSPATH')) {
    exit;
}

trait Lisun_Manual_Nonce_Trait {

    /**
     * 验证 AJAX nonce，失败则直接输出 JSON 错误并终止
     */
    private static function check_nonce() {
        if (!wp_verify_nonce($_POST['_nonce'] ?? '', 'lisun_manual_nonce')) {
            wp_send_json_error(['message' => __('安全验证失败', 'lisun-manual')]);
        }
    }
}
