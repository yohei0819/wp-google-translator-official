<?php
/**
 * Google Cloud Translation API 公式版
 */

if (!defined('ABSPATH')) {
    exit;
}

class WPGTO_Official_Translator {
    
    private string $api_key;
    private string $api_endpoint = 'https://translation.googleapis.com/language/translate/v2';
    private const MAX_CHARS_PER_REQUEST = 5000;
    
    public function __construct() {
        $this->api_key = get_option('wpgto_api_key', '');
    }
    
    /**
     * テキスト翻訳（公式API）
     */
    public function translate(
        string $text,
        string $target_lang,
        string $source_lang = ''
    ): array|WP_Error {
        
        // APIキーチェック
        if (empty($this->api_key)) {
            return new WP_Error(
                'no_api_key',
                'Google Translation APIキーが設定されていません。管理画面から設定してください。'
            );
        }
        
        // キャッシュチェック
        if (get_option('wpgto_cache_enabled', true)) {
            $cached = $this->get_cached_translation($text, $target_lang, $source_lang);
            if ($cached !== false) {
                return $cached;
            }
        }
        
        // 文字数制限チェック
        $char_count = mb_strlen($text);
        if ($char_count > self::MAX_CHARS_PER_REQUEST) {
            return $this->translate_long_text($text, $target_lang, $source_lang);
        }
        
        // APIリクエスト
        $params = [
            'q' => $text,
            'target' => $target_lang,
            'format' => 'text',
            'key' => $this->api_key,
        ];
        
        if (!empty($source_lang)) {
            $params['source'] = $source_lang;
        }
        
        $response = wp_remote_post($this->api_endpoint, [
            'body' => $params,
            'timeout' => 30,
            'headers' => [
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
        ]);
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // エラーチェック
        if ($code !== 200) {
            return $this->handle_api_error($data, $code);
        }
        
        if (!isset($data['data']['translations'][0])) {
            return new WP_Error('invalid_response', 'APIから無効な応答が返されました');
        }
        
        $result = [
            'translated_text' => $data['data']['translations'][0]['translatedText'],
            'detected_language' => $data['data']['translations'][0]['detectedSourceLanguage'] ?? $source_lang,
            'original_text' => $text,
            'char_count' => $char_count,
        ];
        
        // キャッシュ保存
        if (get_option('wpgto_cache_enabled', true)) {
            $this->cache_translation($text, $target_lang, $source_lang, $result);
        }
        
        return $result;
    }
    
    /**
     * APIエラーハンドリング
     */
    private function handle_api_error(?array $data, int $code): WP_Error {
        
        $error_message = $data['error']['message'] ?? 'APIエラーが発生しました';
        $error_code = $data['error']['code'] ?? $code;
        
        $messages = [
            400 => 'リクエストが無効です。パラメータを確認してください。',
            401 => 'APIキーが無効です。管理画面で正しいAPIキーを設定してください。',
            403 => 'APIへのアクセスが拒否されました。APIキーの権限を確認してください。',
            429 => 'リクエスト制限を超えました。しばらく待ってから再試行してください。',
            500 => 'Google APIサーバーエラーが発生しました。',
            503 => 'Google APIが一時的に利用できません。',
        ];
        
        $user_message = $messages[$error_code] ?? $error_message;
        
        if (current_user_can('manage_options')) {
            error_log(sprintf(
                '[WP Google Translator Official] API Error %d: %s',
                $error_code,
                $error_message
            ));
        }
        
        return new WP_Error(
            'api_error_' . $error_code,
            $user_message,
            ['status' => $error_code, 'details' => $error_message]
        );
    }
    
    /**
     * キャッシュ管理
     */
    private function get_cache_key(string $text, string $target_lang, string $source_lang): string {
        return 'wpgto_' . md5($text . $target_lang . $source_lang);
    }
    
    private function get_cached_translation(
        string $text,
        string $target_lang,
        string $source_lang
    ): array|false {
        $key = $this->get_cache_key($text, $target_lang, $source_lang);
        return get_transient($key);
    }
    
    private function cache_translation(
        string $text,
        string $target_lang,
        string $source_lang,
        array $result
    ): void {
        $key = $this->get_cache_key($text, $target_lang, $source_lang);
        set_transient($key, $result, WEEK_IN_SECONDS);
    }
    
    /**
     * デフォルト言語リスト（国旗付き）
     */
    public static function get_default_languages(): array {
        return [
            'af' => ['name' => 'Afrikaans', 'native' => 'Afrikaans', 'flag' => '🇿🇦'],
            'ar' => ['name' => 'Arabic', 'native' => 'العربية', 'flag' => '🇸🇦'],
            'bn' => ['name' => 'Bengali', 'native' => 'বাংলা', 'flag' => '🇧🇩'],
            'de' => ['name' => 'German', 'native' => 'Deutsch', 'flag' => '🇩🇪'],
            'en' => ['name' => 'English', 'native' => 'English', 'flag' => '🇺🇸'],
            'es' => ['name' => 'Spanish', 'native' => 'Español', 'flag' => '🇪🇸'],
            'fr' => ['name' => 'French', 'native' => 'Français', 'flag' => '🇫🇷'],
            'hi' => ['name' => 'Hindi', 'native' => 'हिन्दी', 'flag' => '🇮🇳'],
            'id' => ['name' => 'Indonesian', 'native' => 'Bahasa Indonesia', 'flag' => '🇮🇩'],
            'it' => ['name' => 'Italian', 'native' => 'Italiano', 'flag' => '🇮🇹'],
            'ja' => ['name' => 'Japanese', 'native' => '日本語', 'flag' => '🇯🇵'],
            'ko' => ['name' => 'Korean', 'native' => '한국어', 'flag' => '🇰🇷'],
            'nl' => ['name' => 'Dutch', 'native' => 'Nederlands', 'flag' => '🇳🇱'],
            'pl' => ['name' => 'Polish', 'native' => 'Polski', 'flag' => '🇵🇱'],
            'pt' => ['name' => 'Portuguese', 'native' => 'Português', 'flag' => '🇵🇹'],
            'ru' => ['name' => 'Russian', 'native' => 'Русский', 'flag' => '🇷🇺'],
            'th' => ['name' => 'Thai', 'native' => 'ไทย', 'flag' => '🇹🇭'],
            'tr' => ['name' => 'Turkish', 'native' => 'Türkçe', 'flag' => '🇹🇷'],
            'vi' => ['name' => 'Vietnamese', 'native' => 'Tiếng Việt', 'flag' => '🇻🇳'],
            'zh-CN' => ['name' => 'Chinese (Simplified)', 'native' => '简体中文', 'flag' => '🇨🇳'],
            'zh-TW' => ['name' => 'Chinese (Traditional)', 'native' => '繁體中文', 'flag' => '🇹🇼'],
        ];
    }
    
    private function translate_long_text(
        string $text,
        string $target_lang,
        string $source_lang = ''
    ): array|WP_Error {
        $sentences = preg_split('/(?<=[。！？\.?!])\s*/u', $text, -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $current_chunk = '';
        
        foreach ($sentences as $sentence) {
            if (mb_strlen($current_chunk . $sentence) > self::MAX_CHARS_PER_REQUEST) {
                if (!empty($current_chunk)) {
                    $chunks[] = trim($current_chunk);
                    $current_chunk = '';
                }
            }
            $current_chunk .= $sentence . ' ';
        }
        
        if (!empty($current_chunk)) {
            $chunks[] = trim($current_chunk);
        }
        
        $translated_chunks = [];
        
        foreach ($chunks as $chunk) {
            $result = $this->translate($chunk, $target_lang, $source_lang);
            
            if (is_wp_error($result)) {
                return $result;
            }
            
            $translated_chunks[] = $result['translated_text'];
        }
        
        return [
            'translated_text' => implode(' ', $translated_chunks),
            'detected_language' => $source_lang,
            'original_text' => $text,
            'char_count' => mb_strlen($text),
        ];
    }
}