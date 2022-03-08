<?php

namespace TheIdeaBureau\WordPress\Foundation;

use TheIdeaBureau\WordPress\Foundation\QueryLoop;

class VuePosts
{
    public function __construct($args = array())
    {
        $this->args = $args;
    }

    public function getPosts()
    {

        if (! isset($this->args['ignore'])) {
            $this->args['ignore'] = array();
        }

        $query = new QueryLoop($this->args['query']);
        $vue_posts = [];
        $post_ids = [];


        foreach ($query as $vue_post) {
            // track the post ID
            $post_ids[] = $vue_post->ID;

            $vue_posts[$vue_post->ID] = array(
                'taxonomies' => array(),
                'custom' => array(),
                'display' => true
            );

            if (! in_array('id', $this->args['ignore'])) {
                $vue_posts[$vue_post->ID]['id'] = get_the_ID();
            }

            if (! in_array('title', $this->args['ignore'])) {
                $vue_posts[$vue_post->ID]['title'] = get_the_title();
            }

            if (! in_array('slug', $this->args['ignore'])) {
                $vue_posts[$vue_post->ID]['slug'] = basename(get_the_permalink());
            }

            if (! in_array('post_type', $this->args['ignore'])) {
                $vue_posts[$vue_post->ID]['post_type'] = get_post_type();
            }

            if (! in_array('content', $this->args['ignore'])) {
                $vue_posts[$vue_post->ID]['content'] = wpautop(get_the_content());
            }

            if (! in_array('link', $this->args['ignore'])) {
                $vue_posts[$vue_post->ID]['link'] = get_the_permalink();
            }

            if (! in_array('date', $this->args['ignore'])) {
                // $vue_posts[$vue_post->ID]['date'] = OCP::get_the_date();
            }


            if (isset($this->args['taxonomies']) && ! empty($this->args['taxonomies'])) {
                // loop through taxonomies
                foreach ($this->args['taxonomies'] as $taxonomy) {
                    $vue_posts[$vue_post->ID]['taxonomies'][$taxonomy] = array();

                    if ($terms = get_the_terms($vue_post->ID, $taxonomy)) {
                        foreach ($terms as $term) {
                            $vue_posts[$vue_post->ID]['taxonomies'][$taxonomy][$term->slug] = $term->name;
                        }
                    }
                }
            }

            if (isset($this->args['fields'])) {
                $field_value = function ($field, $default_value) {

                    // attempt to return the ACF value
                    if ($value = get_field($field)) {
                        // check if the value is an array of WP_Post objects, and attempt to translate the post title

                        if (is_array($value)) {
                            foreach ($value as &$item) {
                                if ($item instanceof WP_Post) {
                                    $item->post_title = translateFilter($item->post_title, $item->ID, 'title');
                                }
                            }
                        }

                        return $value;
                    }

                    // if we're at this stage the ACF field returned false
                    // however that might have been a failed ACF field, if
                    // so return the default value

                    if (get_post_meta(get_the_ID(), '_' . $field, true)) {
                        return $default_value;
                    }

                    // attempt to return the base meta field value
                    if ($value = get_post_meta(get_the_ID(), $field, true)) {
                        return $value;
                    }

                    // return the default value as a last resort
                    return $default_value;
                };

                foreach ($this->args['fields'] as $field => $default_value) {
                    $vue_posts[$vue_post->ID]['fields'][$field] = $field_value($field, $default_value);
                };
            }

            if (isset($this->args['custom'])) {
                foreach ($this->args['custom'] as $custom => $callback) {
                    $vue_posts[$vue_post->ID]['custom'][$custom] = $callback($vue_posts[$vue_post->ID], $vue_post);
                }
            }

            if (isset($this->args['postFilter'])) {
                $vue_posts[$vue_post->ID] = $this->args['postFilter']($vue_posts[$vue_post->ID]);
            }
        }

        $vue_posts = array_values($vue_posts);

        return $vue_posts;
    }
}
