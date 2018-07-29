<?php

namespace TheIdeaBureau\WordPress\Foundation;

/**
 * A WordPress specific class that bootstraps various useful features
 * for multilingual functionality.
 */
class Translation {

	private static $instance = null;

	private function __construct() {}

	public static function getInstance() {

		if ( self::$instance == null ) {
			self::$instance = new Translation();
		}

		return self::$instance;

	}

	public function initHooks() {

		add_action('save_post', [$this, 'deletePostTranslations']);
		add_action('save_post', [$this, 'duplicatePost'], 999, 1);

		// ACF integration
		add_action('acf/render_field_settings', [$this, 'addFieldTranslationOption']);
		add_action('acf/load_field', [$this, 'addTranslatableFieldLabel']);

		if ( ! is_admin() ) {

			add_filter('the_title', function(string $title, int $post_id = null) {
				return $this->translateFilter($title, $post_id, 'title');
			}, 999, 2);

			add_filter('the_content', function(string $content, int $post_id = null) {

				global $post;

				// we cannot rely on the ID passed into the filter callback, so fetch it ourselves
				return $this->translateFilter($content, $post->ID, 'content');

			}, 999, 2);

			add_filter('acf/format_value', function($value, $post_id, $field) {

				if ( $this->isFieldTranslatable($field['name'], (int) $post_id) && $this->isTranslatablePostType(get_post_type($post_id)) ) {
					$value = $this->translateFilter(apply_filters('acf_the_content', $value), $post_id, $field['name']);
				}

				return $value;

			}, 999, 3);

		}

	}

	/**
	 * returns the current language code
	 * @return string the language code
	 */
	public function getLanguageCode() {

		// the default will always be english
		$language = 'en';

		// if another has been set, use that instead
		if ( defined('ICL_LANGUAGE_CODE') && ! empty(ICL_LANGUAGE_CODE) ) {
			$language = ICL_LANGUAGE_CODE;
		}

		return $language;

	}

	/**
	 * returns an array of all languages
	 * @return array of languages
	 */
	public function getLanguages() {

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
	public function getLanguageUrl($language_code) {

		$languages = $this->getLanguages();

		if ( isset($languages[$language_code]) ) {
			return $languages[$language_code]['url'];
		}

	}

	/**
	 * attempts to return the id of a wpml object given the original post id and target language
	 * @param  int     $post_id                    the original post id
	 * @param  string  $language_code              the language to find the original post id in
	 * @param  bool    $return_original_if_missing whether it should return the original id if missing
	 * @return mixed                               the object post id, or null if missing
	 */
	public function getObjectId(int $post_id, string $language_code, bool $return_original_if_missing = TRUE) {
		return apply_filters('wpml_object_id', $post_id, get_post_type($post_id), $return_original_if_missing, $language_code);
	}

	/**
	 * get all duplicate post ids for a given post id
	 * @param  int    $post_id the original post id
	 * @return array           an array of duplicate post ids
	 */
	public function getDuplicatePostIds(int $post_id) {

		$duplicate_post_ids = array();

		// fetch all languages
		$languages = $this->getLanguages();

		// loop through the available languages…
		foreach ( $languages as $language_code => $language_details ) {

			// … fetch the translated post id
			$translated_post_id = $this->getObjectId($post_id, $language_code);

			if ( $this->isPostDuplicate($translated_post_id) ) {
				$duplicate_post_ids[$translated_post_id] = $language_code;
			}

		}

		return $duplicate_post_ids;

	}

	/**
	 * deletes the translations for a given post_id
	 * @param  int    $post_id the wordpress post id
	 * @return void
	 */
	public function deletePostTranslations(int $post_id) {

		// we don't want to delete translations if we're saving a revision
		if ( wp_is_post_revision($post_id) ) {
			return;
		}

		// only updating the original post should flush duplicate translations
		if ( $this->isPostDuplicate($post_id) ) {
			return;
		}

		error_log('Removing translations for ' . $post_id, 0);

		// get the duplicate post ids
		$duplicate_post_ids = $this->getDuplicatePostIds($post_id);

		// loop through the available languages…
		foreach ( $duplicate_post_ids as $duplicate_post_id => $language_code ) {

			// … get the transaltable fields for this post
			$translatable_fields = $this->getTranslatableFields($duplicate_post_id);

			// … delete the title and content meta
			delete_post_meta($duplicate_post_id, $this->makeKey($language_code, 'title'));
			delete_post_meta($duplicate_post_id, $this->makeKey($language_code, 'content'));

			// … and delete all field meta
			foreach ( $translatable_fields as $field ) {
				delete_post_meta($duplicate_post_id, $this->makeKey($language_code, $field));
			}

		}

	}

	/**
	 * create the translation key
	 * @param  string $language_code the language code
	 * @param  string $key           the key descriptor
	 * @return string                the translation key in the appropriate format
	 */
	protected function makeKey(string $language_code, string $key) {
		return 'translation_' . $language_code . '_' . $key;
	}

	/**
	 * return a cached translation if there is one, otherwise translate the content
	 * @param  string $content the content to translate
	 * @param  int    $post_id the wordpress post id
	 * @param  string $key     the cache content key
	 * @return string          the translated content
	 */
	function translateContent(string $content, int $post_id, string $key, string $language_code) {

		// check for a cached translation to return
		if ( $cached_translation = get_post_meta($post_id, $this->makeKey($language_code, $key), TRUE) ) {
			return $cached_translation;
		}

		// throw an error if the Google API Key is not defined
		if ( ! defined('GOOGLE_TRANSLATE_API_KEY') ) {
			throw new \Exception('Google Translate API key not valid.');
			exit;
		}

		// if GoogleTranslate throws an error, return the original untranslated content
		try {

			// output the transaction to the error log
			error_log('Translated ' . $post_id . '_' . $key . ' (' . strlen($content) . ')', 0);

		 	$content = $this->googleTranslate($content, $language_code);

		} catch (\Exception $e) {

			// output the failure to the error log
			error_log('Translation Failed: ' . $e->getMessage(), 0);

			return $content;

		}

		// store the translation in the meta
		update_post_meta($post_id, $this->makeKey($language_code, $key), $content);

		return $content;

	}

	/**
	 * Returns Google Translated content that has been translated from English into French
	 * @param string $content the content to be translated
	 * @return json $result
	 */
	function googleTranslate(string $content, string $language_code) {

		// the Google Translate endpoint
		$url = 'https://www.googleapis.com/language/translate/v2';

		// open connection
		$ch = curl_init($url);

		// set the post fields
		curl_setopt($ch, CURLOPT_POSTFIELDS, [
			'key' => GOOGLE_TRANSLATE_API_KEY,
			'source' => 'en',
			'target' => $language_code,
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
		if ( isset($responseDecoded['error']) ) {
			throw new \Exception($responseDecoded['error']['message']);
			return $content;
		}

		// save the result into a friendlier variable
		$result = $responseDecoded['data']['translations'][0]['translatedText'];

		return $result;

	}

	/**
	 * checks if the given post is a duplicate of another
	 * @param  int $post_id the post id to check
	 * @return boolean      the result
	 */
	function isPostDuplicate($post_id = NULL) {

		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		return ! empty(get_post_meta($post_id, '_icl_lang_duplicate_of', TRUE));

	}

	/**
	 * fetch either the translation parent id, or itself
	 * @param  int $post_id the post id to check
	 * @return int
	 */
	function getTranslationParentId($post_id = NULL) {

		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		$duplicate_of = get_post_meta($post_id, '_icl_lang_duplicate_of', TRUE);

		if ( ! empty($duplicate_of) ) {
			return $duplicate_of;
		}

		return $post_id;

	}

	/**
	 * checks if the post is a duplicate of another language
	 * @param int $post_id
	 * @return boolean
	 */
	function isGoogleTranslatible(int $post_id = NULL) {

		if ( ! $post_id ) {
			$post_id = get_the_ID();
		}

		return $this->isPostDuplicate($post_id);

	}

	/**
	 * returns a boolean if the given post type is translatable
	 * @param string $language
	 * @param int $post_id
	 * @param string $post_type
	 * @return int
	 */
	function isTranslatablePostType(string $post_type) {

		// fetch the custom post type sync option from wpml
		$wpml_post_types = apply_filters('wpml_setting', [], 'custom_posts_sync_option');

		// if the post type exists within the setting, and is "1", it is translatable
		return isset($wpml_post_types[$post_type]) && ($wpml_post_types[$post_type] == 1 || $wpml_post_types[$post_type] == 2);

	}

	/**
	 * upon saving of an default language post, create duplicates in all other languages
	 * @param int $post_id
	 * @return void
	 */
	function duplicatePost(int $post_id) {

		if ( defined('DOING_AUTOSAVE') && DOING_AUTOSAVE ) {
			return;
		}

		if ( defined('DOING_AJAX') && DOING_AJAX ) {
			return;
		}

		if ( wp_is_post_revision($post_id) !== false ) {
			return;
		}

		// check permissions
		if ( ! current_user_can('edit_post', $post_id) ) {
			return;
		}

		// don't duplicate if there is no current language, or if it's not english
		if ( ! defined('ICL_LANGUAGE_CODE') || ICL_LANGUAGE_CODE !== 'en' ) {
			return;
		}

	 	if ( apply_filters('wpml_post_language_details', NULL, $post_id)['language_code'] !== 'en' ) {
			return;
		}

		if ( ! $this->isTranslatablePostType(get_post_type($post_id)) ) {
			return;
		}

		// unhook this function so it doesn't loop infinitely
		remove_action('save_post', [$this, 'duplicatePost']);

		// ask wpml to duplicate the post
		do_action('wpml_make_post_duplicates', $post_id);

		// re-hook this function
		add_action('save_post', [$this, 'duplicatePost']);

	}

	/**
	 * takes content from filters and runs them through the translation mechanism
	 * @param  string 	$content 	the content to be translated
	 * @param  int 		$post_id 	the id of the post content to be translated
	 * @param  string 	$key 		the field type to be used as the cache key
	 * @return string 				the translated content
	 */
	function translateFilter(string $content, int $post_id, string $key) {

		// if the content is empty, don't attempt to translate
		if ( empty($content) ) {
			return $content;
		}

		// if the language is english, don't bother translating anything
		if ( $this->getLanguageCode() === 'en' ) {
			return $content;
		}

		if ( $this->ignoreSpecficTranslation($post_id, $key) ) {
			return $content;
		}

		// return the original title is we're not a translatable post type
		if ( ! $this->isTranslatablePostType(get_post_type($post_id)) ) {
			return $content;
		}

		// if the post is not a duplicate, return the original
		if ( ! $this->isGoogleTranslatible($post_id) ) {
			return $content;
		}

		// don't translate this is we want to view the original
		if ( isset($_GET['view_original']) ) {
			return $content;
		}

		return $this->translateContent($content, $post_id, $key, $this->getLanguageCode());

	}

	/**
	 * fetch the translatable custom fields from the wordpress filter
	 * @param  int    $post_id the post id for the given object
	 * @param  string $key     the translation key
	 * @return array           an array of custom fields to translate
	 */
	function ignoreSpecficTranslation(int $post_id, string $key) {

		$ignored_posts = apply_filters('translation_ignore_specific', []);

		$parent_id = $this->getTranslationParentId($post_id);

		return array_search($parent_id . '_' . $key, $ignored_posts) !== FALSE;

	}

	/**
	 * fetch the translatable custom fields from the wordpress filter
	 * @param  int    $post_id the post id for the given object
	 * @return array           an array of custom fields to translate
	 */
	function getTranslatableFields(int $post_id) {
		return apply_filters('translatable_custom_fields', [], $post_id);
	}

	/**
	 * determine if a particular field for a post id is translatable
	 * @param  string  $field   the field name
	 * @param  int     $post_id the post id for the field post
	 * @return boolean          whether the field is translatable
	 */
	function isFieldTranslatable(string $field, int $post_id) {

		// get all translatable fields for this post id
		$translatable_fields = $this->getTranslatableFields($post_id);

		return in_array($field, $translatable_fields);

	}

	/**
	 * Add "Translate field" option to ACF field, can then be used to translate
	 * specific custom fields
	 * @param array $field the field object
	 */
	 function addFieldTranslationOption($field) {

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
 	function addTranslatableFieldLabel($field) {

 		if ( isset($field['translate_field']) && $field['translate_field'] == 1 ) {
 			$field['label'] .= ' ⚑';
 		}

 	    return $field;

 	}

}
