<?php

namespace TheIdeaBureau\WordPress\Foundation;

/**
 * An improved version of WP_Query
 *
 * Features
 * - Accepts the same arguments as WP_Query
 * - Can be used within a foreach
 * - Can be counted within count()
 * - Automatically calls have_posts() and the_post() on each iteration
 *
 * @param  array $args
 * @return array
 */
class QueryLoop implements \Iterator, \Countable
{
    public $post_ids = array();

    public function __construct($args = array())
    {

        // init WP_Query
        $this->query = new \WP_Query($args);

        // compile a list of post_ids
        foreach ($this->query->posts as $post) {
            $this->post_ids[] = $post->ID;
        }
    }

    public function count()
    {
        return count($this->query->posts);
    }

    public function rewind()
    {
        $this->query->rewind_posts();
    }

    public function current()
    {
        $this->query->the_post();
        return $this->query->post;
    }

    public function key()
    {
        return $this->query->post->ID;
    }

    public function next()
    {
        //$this->query->the_post();
    }

    public function valid()
    {
        if ($this->query->have_posts()) {
            return true;
        } else {
            wp_reset_postdata();
            return false;
        }
    }

    // deliberately not using PSR name standards to fit in with WP
    public function have_posts()
    {
        return $this->query->have_posts();
    }

    public function has_next_page()
    {

        $current_page = $this->query->query_vars['paged'] > 0 ? $this->query->query_vars['paged'] : 1;
        $max_pages = (int) $this->query->max_num_pages;

        return $current_page < $max_pages;
    }
}
