<?php

namespace TheIdeaBureau\WordPress\Foundation;

/**
 * A WordPress specific class that bootstraps various useful features
 * for navigation functionality.
 */
class Navigation {

	private static $instance = null;

	private function __construct() {}

	public static function getInstance() {

		if ( self::$instance == null ) {
			self::$instance = new Navigation();
		}

		return self::$instance;

	}

	/**
	 * fetches the wordpress menu as a hierarchical array
	 * @param  mixed $name the menu id, slug, name or object
	 * @return array
	 */
	function getMenu($name) {

		// get the menu
		$menu = wp_get_nav_menu_items($name);

		// apply any classes to the menu items
		_wp_menu_item_classes_by_context($menu);

		// apply hierarchy
		$new_menu = array();

		foreach ( $menu as $menu_item ) {
			$new_menu[$menu_item->ID] = $menu_item;
			$new_menu[$menu_item->ID]->children = array();
		}

		$menu = $new_menu;

		// re-sort by menu order
		uasort($menu, function($a, $b) {

		    if ($a->menu_order == $b->menu_order) {
		        return 0;
		    }

		    return ($a->menu_order < $b->menu_order) ? -1 : 1;

		});

		// make the menu hierarchical
		$menu = $this->buildTree($menu);

		return $menu;

	}

	/**
	 * makes a linear wordpress menu hierarchical
	 * @param  array   $elements the current menu level
	 * @param  integer $parentId the id of the current parent
	 * @return array
	 */
	private function buildTree(array &$elements, $parentId = 0) {

		$branch = array();

		foreach ( $elements as $element ) {

			if ( $element->menu_item_parent == $parentId ) {

				$children = $this->buildTree($elements, $element->ID);

				if ( $children ) {
					$element->children = $children;
				}

				$branch[$element->ID] = $element;
				unset($elements[$element->ID]);

			}

		}

		return $branch;

	}

	/**
	 * get breadcrumbs for the current post
	 * @param  array  $options [description]
	 * @return array
	 */
	function getBreadcrumbs($options = array()) {

		if ( is_search() ) {
			return array();
		}

		// create the options object
		$options = (object) array_merge([
			'truncate_titles' => TRUE,
			'truncate_length' => 53,
			'home_label' => 'Home',
		], $options);

		global $post;

		// setup
		$post_type_object = get_post_type_object($post->post_type);
		$breadcrumbs = array();

		// post type archive
		if ( is_post_type_archive() ) {
			$breadcrumbs[] = array(get_post_type_archive_link($post_type_object->name) => $post_type_object->label);
		}

		// is single or page but not the front-page
		if ( is_single() || ( is_page() && ! is_front_page() ) ) {

			$title = get_the_title($post->ID);

			if ( $options->truncate_titles ) {

				if ( strlen($title) > $options->truncate_length ) {
					$title = trim(substr($title, 0, $options->truncate_length)) . '&hellip;';
				}

			}

			$breadcrumbs[] = array(get_permalink($post->ID) => $title);
		}

		// is blog or (specifically) a blog post
		if ( ( is_home() || ( is_single() && $post->post_type === 'post' ) ) && $posts_page_id = get_option('page_for_posts') ) {
			$breadcrumbs[] = array(get_permalink($posts_page_id) => get_the_title($posts_page_id));
		}

		// post parent hierarchy
		if ( $post->post_parent ) {

			$parent_id = $post->post_parent;

			while ($parent_id) {

				$page = get_page($parent_id);
				$breadcrumbs[] = array(get_permalink($page->ID) => get_the_title($page->ID));
				$parent_id = $page->post_parent;

			}

		}

		// if this post type has it's own archive, output that
		if ( ( is_single() || is_page() ) && $post_type_object->has_archive ) {
			$breadcrumbs[] = array(get_post_type_archive_link($post_type_object->name) => $post_type_object->label);
		}

		// add the top level home link
		$breadcrumbs[] = array('/' => $options->home_label);

		// remove the 'last' (it's first in the array) breadcrumb link
		$breadcrumbs[0] = array("" => current($breadcrumbs[0]));

		return array_reverse($breadcrumbs);

	}

}
