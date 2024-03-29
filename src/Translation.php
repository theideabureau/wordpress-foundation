<?php

namespace TheIdeaBureau\WordPress\Foundation;

/**
 * A WordPress specific class that bootstraps various useful features
 * for multilingual functionality.
 */
class Translation
{
    private static $instance = null;

    private function __construct()
    {
    }

    public static function getInstance()
    {

        if (self::$instance == null) {
            self::$instance = new Translation();
        }

        return self::$instance;
    }

    public function initHooks()
    {

        add_action('save_post', [$this, 'deleteCachedTranslations']);

        // ACF integration
        add_action('acf/render_field_settings', [$this, 'addFieldTranslationOption']);
        add_action('acf/load_field', [$this, 'addTranslatableFieldLabel']);

        if (! is_admin()) {
            // set all appropriate filters
            add_filter('the_title', [$this, 'translateTheTitle'], 999, 2);
            add_filter('the_content', [$this, 'translateTheContent'], 999, 2);
            add_filter('acf/format_value', [$this, 'translateCustomFields'], 999, 3);
        }
    }

    /**
     * translate title filter
     * @param  string $title   initial value from `the_title` function
     * @param  int    $post_id `post_id` from `the_title` function
     * @return string          the translated title
     */
    public function translateTheTitle(string $title, int $post_id = null)
    {
        return $this->translateFilter($title, $post_id, 'title');
    }

    /**
     * translate content filter
     * @param  string $content initial value from `the_content` function
     * @param  int    $post_id `post_id` from `the_content` function
     * @return string          the translated content
     */
    public function translateTheContent(string $content, int $post_id = null)
    {

        global $post;

        // check if the post should be excluded from this translation…
        $exclude_post = apply_filters('translation_exclude_post_content', false, $post->ID);

        // …and return the original content
        if ($exclude_post) {
            return $content;
        }

        // we cannot rely on the ID passed into the filter callback, so fetch it ourselves
        return $this->translateFilter($content, $post->ID, 'content');
    }

    /**
     * translate custom fields filter
     * @param  string $value   intial value from `get_field` function
     * @param  int    $post_id `post_id` from `get_field` function
     * @param  array  $field   the ACF field pbject (read: array)
     * @return string          the translated content
     */
    public function translateCustomFields($value, $post_id, $field)
    {

        if (isset($field['translate_field']) && $field['translate_field'] == 1) {
            switch ($field['type']) {
                case 'link':
                    if (is_array($value)) {
                        $value['title'] = $this->translateFilter($value['title'], $post_id, $field['name']);
                    }

                    break;

                case 'textarea':
                    $value = $this->translateFilter($value, $post_id, $field['name']);
                    break;

                case 'text':
                    $value = $this->translateFilter($value, $post_id, $field['name']);
                    break;

                case 'wysiwyg':
                    $value = $this->translateFilter(apply_filters('acf_the_content', $value), $post_id, $field['name']);
                    break;
            }
        }

        return $value;
    }

    /**
     * returns the current language code
     * @return string the language code
     */
    public function getLanguageCode()
    {

        // the default will always be english
        $language = 'en';

        // if another has been set, use that instead
        if (defined('ICL_LANGUAGE_CODE') && ! empty(ICL_LANGUAGE_CODE)) {
            $language = ICL_LANGUAGE_CODE;
        }

        return $language;
    }

    /**
     * returns an array of all languages
     * @return array of languages
     */
    public function getLanguages()
    {

        // fetch the active languages directly from wpml
        return apply_filters('wpml_active_languages', [], [
            'skip_missing' => 1,
            'orderby' => 'code',
            'order' => 'desc'
        ]);
    }

    /**
     * returns the current language url converted to a given language
     * @param  string $language_code the language code to translate
     * @return string the translated url
     */
    public function getLanguageUrl($language_code)
    {

        $languages = $this->getLanguages();

        if (isset($languages[$language_code])) {
            return $languages[$language_code]['url'];
        }
    }

    /**
     * deletes the translations for a given post_id
     * @param  int    $post_id the wordpress post id
     * @return void
     */
    public function deleteCachedTranslations(int $post_id)
    {

        // we don't want to delete translations if we're saving a revision
        if (wp_is_post_revision($post_id)) {
            return;
        }

        global $wpdb;

        // delete all translations for the given post
        $wpdb->query("DELETE FROM $wpdb->postmeta WHERE post_id = $post_id AND meta_key LIKE 'translation_%';");
    }

    /**
     * create the translation key
     * @param  string $language_code the language code
     * @param  string $key           the key descriptor
     * @return string                the translation key in the appropriate format
     */
    protected function makeKey(string $source_language_code, string $target_language_code, string $key)
    {
        return implode('_', ['translation', $source_language_code, $target_language_code, $key]);
    }

    /**
     * attempts to fetch manually corrected translations
     * @param  string  $string the content to translate
     * @return mixed           the translated string or false
     */
    public function getCorrectedTranslation(string $string)
    {

        // fetch the google translate corrections array
        $translations = get_field('google_translate_correction', 'options');

        // if the translations don't exist return false
        if (! $translations) {
            return false;
        }

        // attempt to find and return the translated text
        foreach ($translations as $translation) {
            // compare the strings lower case and trimmed, some strings on the
            // front end are uppercase (css text-transform) and may be copied
            // with their case, this helps match them up

            if (strtolower(trim($translation['english'])) === strtolower(trim($string))) {
                return $translation['translated'];
            }
        }

        // return false so the caller knows to continue
        return false;
    }

    /**
     * return a cached translation if there is one, otherwise translate the string
     * @param  string $string the content to translate
     * @param  string $key    the cache content key
     * @return string         the translated content
     */
    public function translateString(
        string $string,
        string $key,
        string $source_language_code,
        string $target_language_code
    ) {
        // if the dtring is empty, don't attempt to translate
        if (empty($string)) {
            return $string;
        }

        // if the language is english, don't bother translating anything
        if ($this->getLanguageCode() === 'en') {
            return $string;
        }

        // don't translate this is we want to view the original
        if (isset($_GET['view_original'])) {
            return $string;
        }

        // check if there is a google translate correction before continuing
        if ($correction = $this->getCorrectedTranslation($string)) {
            return $correction;
        }

        // check for a cached translation to return
        if ($cached_translation = get_option($this->makeKey($source_language_code, $target_language_code, $key))) {
            return $cached_translation;
        }

        // throw an error if the Google API Key is not defined
        if (! defined('GOOGLE_TRANSLATE_API_KEY')) {
            throw new \Exception('Google Translate API key not valid.');
            exit;
        }

        // if GoogleTranslate throws an error, return the original untranslated content
        try {
            // output the transaction to the error log
            error_log('Translated ' . $key . ' (' . strlen($content) . ')', 0);

            $content = $this->googleTranslate($string, $source_language_code, $target_language_code);
        } catch (\Exception $e) {
            // output the failure to the error log
            error_log('Translation Failed: ' . $e->getMessage(), 0);

            return $content;
        }

        // store the translation in the meta
        update_option($this->makeKey($source_language_code, $target_language_code, $key), $content);

        return $content;
    }

    /**
     * return a cached translation if there is one, otherwise translate the content
     * @param  string $content the content to translate
     * @param  int    $post_id the wordpress post id
     * @param  string $key     the cache content key
     * @return string          the translated content
     */
    public function translateContent(
        string $content,
        int $post_id,
        string $key,
        string $source_language_code,
        string $target_language_code
    ) {

        // check if there is a google translate correction before continuing
        if ($correction = $this->getCorrectedTranslation($content)) {
            return $correction;
        }

        $cacheKey = $this->makeKey($source_language_code, $target_language_code, $key);

        // check for a cached translation to return
        if ($cached_translation = get_post_meta($post_id, $cacheKey, true)) {
            return $cached_translation;
        }

        // throw an error if the Google API Key is not defined
        if (! defined('GOOGLE_TRANSLATE_API_KEY')) {
            throw new \Exception('Google Translate API key not valid.');
            exit;
        }

        // if GoogleTranslate throws an error, return the original untranslated content
        try {
            // output the transaction to the error log
            error_log('Translated ' . $post_id . '_' . $key . ' (' . strlen($content) . ')', 0);

            $content = $this->googleTranslate($content, $source_language_code, $target_language_code);
        } catch (\Exception $e) {
            // output the failure to the error log
            error_log('Translation Failed: ' . $e->getMessage(), 0);

            return $content;
        }

        // store the translation in the meta
        update_post_meta($post_id, $this->makeKey($source_language_code, $target_language_code, $key), $content);

        return $content;
    }

    /**
     * Returns Google Translated content that has been translated from English into French
     * @param string $content the content to be translated
     * @return json $result
     */
    public function googleTranslate(string $content, string $source_language_code, string $target_language_code)
    {

        // the Google Translate endpoint
        $url = 'https://www.googleapis.com/language/translate/v2';

        // open connection
        $ch = curl_init($url);

        // set the post fields
        curl_setopt($ch, CURLOPT_POSTFIELDS, [
            'key' => GOOGLE_TRANSLATE_API_KEY,
            'source' => $source_language_code,
            'target' => $target_language_code,
            'format' => 'html',
            'q' => $content
        ]);

        // return the transfer and set the override to get
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['X-HTTP-Method-Override: GET']);

        // get the response and decode it from json
        $response = curl_exec($ch);
        $responseDecoded = json_decode($response, true);

        // close connection
        curl_close($ch);

        // throw an error if the response returns an error
        if (isset($responseDecoded['error'])) {
            throw new \Exception($responseDecoded['error']['message']);
            return $content;
        }

        // save the result into a friendlier variable
        $result = $responseDecoded['data']['translations'][0]['translatedText'];

        return $result;
    }

    /**
     * detect if the post is a duplicate of another
     * @param  int $post_id the post id to check
     * @return boolean
     */
    public function getDuplicateOfId($post_id = null)
    {

        if (! $post_id) {
            $post_id = get_the_ID();
        }

        if ($this->getPostLanguage($post_id) !== $this->getLanguageCode()) {
            return true;
        }

        if (! metadata_exists('post', $post_id, '_icl_lang_duplicate_of')) {
            return false;
        }

        return get_post_meta($post_id, '_icl_lang_duplicate_of', true);
    }

    /**
     * detect if the post is a duplicate of another
     * @param  int $post_id the post id to check
     * @return boolean
     */
    public function getPostLanguage($post_id = null)
    {
        if (! $post_id) {
            $post_id = get_the_ID();
        }

        $post_details = apply_filters('wpml_post_language_details', null, $post_id);

        if (is_wp_error($post_details)) {
            return 'en';
        }

        return $post_details['language_code'];
    }

    /**
     * fetch either the translation parent id, or itself
     * @param  int $post_id the post id to check
     * @return int
     */
    public function getTranslationParentId($post_id = null)
    {

        if (! $post_id) {
            $post_id = get_the_ID();
        }

        return apply_filters('wpml_object_id', $post_id, get_post_type($post_id), true, 'en');
    }

    /**
     * determine if the post should be automatically translated
     * @param  int $post_id the post id to check
     * @return boolean
     */
    public function isPostTranslatable($post_id = null)
    {
        if (! $post_id) {
            $post_id = get_the_ID();
        }

        return $this->getDuplicateOfId() !== false;
    }

    /**
     * returns a boolean if the given post type is translatable
     * @param string $language
     * @param int $post_id
     * @param string $post_type
     * @return int
     */
    public function isTranslatablePostType(string $post_type)
    {

        // fetch the custom post type sync option from wpml
        $wpml_post_types = apply_filters('wpml_setting', [], 'custom_posts_sync_option');

        // if the post type exists within the setting, and is "1", it is translatable
        return isset($wpml_post_types[$post_type])
            && ($wpml_post_types[$post_type] == 1
            || $wpml_post_types[$post_type] == 2);
    }

    /**
     * takes content from filters and runs them through the translation mechanism
     * @param  string   $content    the content to be translated
     * @param  mixed    $post_id    the id of the post content to be translated
     * @param  string   $key        the field type to be used as the cache key
     * @return string               the translated content
     */
    public function translateFilter(string $content, $post_id, string $key)
    {

        if ($post_id === null) {
            return $content;
        }

        // if the content is empty, don't attempt to translate
        if (empty($content)) {
            return $content;
        }

        if (! $this->isPostTranslatable($post_id)) {
            return $content;
        }

        if ($this->ignoreSpecficTranslation($post_id, $key)) {
            return $content;
        }

        // return the original title is we're not a translatable post type
        if (! $this->isTranslatablePostType(get_post_type($post_id))) {
            return $content;
        }

        // don't translate this is we want to view the original
        if (isset($_GET['view_original'])) {
            return $content;
        }

        // get original post id
        $original_id = $this->getDuplicateOfId($post_id);

        // get the original post language
        $original_language = $this->getPostLanguage($original_id);

        if (! $original_language) {
            return $content;
        }

        // return if the original and current languages are the same
        if ($original_language === $this->getLanguageCode()) {
            return $content;
        }

        return $this->translateContent($content, $post_id, $key, $original_language, $this->getLanguageCode());
    }

    /**
     * fetch the translatable custom fields from the wordpress filter
     * @param  int    $post_id the post id for the given object
     * @param  string $key     the translation key
     * @return array           an array of custom fields to translate
     */
    public function ignoreSpecficTranslation(int $post_id, string $key)
    {

        $ignored_posts = apply_filters('translation_ignore_specific', []);

        $parent_id = $this->getTranslationParentId($post_id);

        return array_search($parent_id . '_' . $key, $ignored_posts) !== false;
    }

    /**
     * fetch the translatable custom fields from the wordpress filter
     * @param  int    $post_id the post id for the given object
     * @return array           an array of custom fields to translate
     */
    public function getTranslatableFields(int $post_id)
    {
        return apply_filters('translatable_custom_fields', [], $post_id);
    }

    /**
     * determine if a particular field for a post id is translatable
     * @param  string  $field   the field name
     * @param  int     $post_id the post id for the field post
     * @return boolean          whether the field is translatable
     */
    public function isFieldTranslatable($field, int $post_id)
    {

        // get all translatable fields for this post id
        $translatable_fields = $this->getTranslatableFields($post_id);

        return in_array($field['key'], $translatable_fields) || in_array($field['__key'], $translatable_fields);
    }

    /**
     * Add "Translate field" option to ACF field, can then be used to translate
     * specific custom fields
     * @param array $field the field object
     */
    public function addFieldTranslationOption($field)
    {

        acf_render_field_setting($field, array(
           'label' => __('Automatically translate field?'),
           'instructions' => 'Can this field be automatically translated by Google?',
           'name' => 'translate_field',
           'type' => 'true_false',
           'ui' => 1
        ), true);
    }

    /**
     * Add a flag character to the label of fields tha can be automatically translated
     * @param array $field the field object
     */
    public function addTranslatableFieldLabel($field)
    {

        if (isset($field['translate_field']) && $field['translate_field'] == 1) {
            $field['label'] .= ' ⚑';
        }

        return $field;
    }
}
