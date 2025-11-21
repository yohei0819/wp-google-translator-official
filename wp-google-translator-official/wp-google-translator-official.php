<?php
/**
 * Plugin Name: WP Google Translator Official API
 * Plugin URI: https://github.com/yohei0819/wp-google-translator-official
 * Description: Google Cloud Translation API公式版。月50万文字まで無料、それ以降は従量課金。
 * Version: 1.0.0
 * Author: yohei0819
 * Author URI: https://github.com/yohei0819
 * Requires at least: 5.8
 * Requires PHP: 8.0
 * Text Domain: wp-google-translator-official
 * License: GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */

if (!defined('ABSPATH')) {
    exit;
}

// プラグイン定数
define('WPGTO_VERSION', '1.0.0');
define('WPGTO_PLUGIN_DIR', plugin_dir_path(__FILE__));
define('WPGTO_PLUGIN_URL', plugin_dir_url(__FILE__));
define('WPGTO_PLUGIN_FILE', __FILE__);

// メインクラス
class WP_Google_Translator_Official {
    
    private static ?self $instance = null;
    
    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->load_dependencies();
        $this->init_hooks();
    }
    
    private function load_dependencies(): void {
        require_once WPGTO_PLUGIN_DIR . 'includes/class-official-translator.php';
        require_once WPGTO_PLUGIN_DIR . 'includes/class-widget.php';
        require_once WPGTO_PLUGIN_DIR . 'includes/class-admin.php';
        require_once WPGTO_PLUGIN_DIR . 'includes/class-usage-tracker.php';
    }
    
    private function init_hooks(): void {
        // フロントエンド
        add_action('wp_enqueue_scripts', [$this, 'enqueue_frontend_assets']);
        add_action('wp_footer', [$this, 'render_floating_widget'], 999);
        
        // AJAX
        add_action('wp_ajax_wpgto_translate', [$this, 'ajax_translate']);
        add_action('wp_ajax_nopriv_wpgto_translate', [$this, 'ajax_translate']);
        
        // ショートコード
        add_shortcode('translator_widget', [WPGTO_Widget::class, 'render']);
        add_shortcode('translate', [WPGTO_Widget::class, 'translate_text']);
        
        // 管理画面
        if (is_admin()) {
            new WPGTO_Admin();
        }
        
        // 使用量トラッキング
        new WPGTO_Usage_Tracker();
        
        // アクティベーション
        register_activation_hook(WPGTO_PLUGIN_FILE, [$this, 'activate']);
    }
    
    public function enqueue_frontend_assets(): void {
        wp_enqueue_style(
            'wpgto-main',
            WPGTO_PLUGIN_URL . 'assets/css/main.css',
            [],
            WPGTO_VERSION
        );
        
        $custom_css = get_option('wpgto_custom_css', '');
        if (!empty($custom_css)) {
            wp_add_inline_style('wpgto-main', $custom_css);
        }
        
        wp_enqueue_script(
            'wpgto-main',
            WPGTO_PLUGIN_URL . 'assets/js/main.js',
            ['jquery'],
            WPGTO_VERSION,
            true
        );
        
        wp_localize_script('wpgto-main', 'wpgtoConfig', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wpgto_translate'),
            'default_lang' => get_option('wpgto_default_lang', 'ja'),
            'enabled_langs' => get_option('wpgto_enabled_langs', ['en', 'ja', 'zh-CN', 'ko']),
            'widget_position' => get_option('wpgto_widget_position', 'bottom-right'),
            'auto_translate' => get_option('wpgto_auto_translate', false),
            'cache_enabled' => get_option('wpgto_cache_enabled', true),
        ]);
    }
    
    public function render_floating_widget(): void {
        if (!get_option('wpgto_show_floating_widget', true)) {
            return;
        }
        
        echo WPGTO_Widget::render(['type' => 'floating']);
    }
    
    public function ajax_translate(): void {
        check_ajax_referer('wpgto_translate', 'nonce');
        
        $text = sanitize_textarea_field($_POST['text'] ?? '');
        $to = sanitize_text_field($_POST['to'] ?? 'en');
        $from = sanitize_text_field($_POST['from'] ?? 'auto');
        
        if (empty($text)) {
            wp_send_json_error(['message' => '翻訳するテキストが空です']);
        }
        
        $translator = new WPGTO_Official_Translator();
        $result = $translator->translate($text, $to, $from);
        
        if (is_wp_error($result)) {
            wp_send_json_error(['message' => $result->get_error_message()]);
        } else {
            $tracker = new WPGTO_Usage_Tracker();
            $tracker->track_usage(mb_strlen($text));
            
            wp_send_json_success($result);
        }
    }
    
    public function activate(): void {
        $defaults = [
            'wpgto_api_key' => '',
            'wpgto_default_lang' => 'ja',
            'wpgto_enabled_langs' => ['en', 'ja', 'zh-CN', 'ko', 'es', 'fr', 'de'],
            'wpgto_widget_position' => 'bottom-right',
            'wpgto_widget_style' => 'dropdown',
            'wpgto_show_floating_widget' => true,
            'wpgto_cache_enabled' => true,
            'wpgto_usage_alert_80' => true,
            'wpgto_usage_alert_95' => true,
            'wpgto_custom_css' => '',
        ];
        
        foreach ($defaults as $key => $value) {
            if (get_option($key) === false) {
                add_option($key, $value);
            }
        }
        
        $this->create_usage_table();
    }
    
    private function create_usage_table(): void {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wpgto_usage';
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS $table_name (
            id bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            translation_date date NOT NULL,
            char_count int(11) NOT NULL,
            api_calls int(11) NOT NULL DEFAULT 1,
            source_lang varchar(10) NOT NULL,
            target_lang varchar(10) NOT NULL,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY translation_date (translation_date),
            KEY target_lang (target_lang)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
}

WP_Google_Translator_Official::get_instance();
