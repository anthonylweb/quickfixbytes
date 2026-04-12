<?php

/**
 * Plugin Name: Quick Fix Bytes
 * Description: AI -powered recipe creator..... and more!
 * Author: Anthony Laurence
 * Version: 2.0
 * 
 * 
 * Changlog:
 * 
 * - updated OpenAI call with now required params 06182025
 * 
 */

# Pull in Composer autoloading functionality
require 'vendor/autoload.php';

use OpenAI\Client;


class Chefbot
{

  public function __construct()
  {
    add_action('init', array($this, 'createCustomPostType'));
    add_action('wp_enqueue_scripts', array($this, 'enqueue_ajax_script'));


    add_action('wp_ajax_nosh_generate_recipe', [$this, 'process_form']);
    add_action('wp_ajax_nopriv_nosh_generate_recipe',  [$this, 'process_form']);
  }

  public function createCustomPostType()
  {

    $recipe_labels = array(
      'name'                  => _x('Recipes', 'Post Type General Name', 'text_domain'),
      'singular_name'         => _x('Recipe', 'Post Type Singular Name', 'text_domain'),
      'menu_name'             => __('Recipes', 'text_domain'),
      'name_admin_bar'        => __('Post Type', 'text_domain'),
      'archives'              => __('Recipe Archives', 'text_domain'),
      'attributes'            => __('Recipe Attributes', 'text_domain'),
      'parent_item_colon'     => __('Parent Recipe:', 'text_domain'),
      'all_items'             => __('All Recipes', 'text_domain'),
      'add_new_item'          => __('Add New Recipe', 'text_domain'),
      'add_new'               => __('Add Recipe', 'text_domain'),
      'new_item'              => __('New Recipe', 'text_domain'),
      'edit_item'             => __('Edit Recipe', 'text_domain'),
      'update_item'           => __('Update Recipe', 'text_domain'),
      'view_item'             => __('View Recipe', 'text_domain'),
      'view_items'            => __('View Recipes', 'text_domain'),
      'search_items'          => __('Search Recipe', 'text_domain'),
      'not_found'             => __('Not found', 'text_domain'),
      'not_found_in_trash'    => __('Not found in Trash', 'text_domain'),
      'featured_image'        => __('Featured Image', 'text_domain'),
      'set_featured_image'    => __('Set featured image', 'text_domain'),
      'remove_featured_image' => __('Remove featured image', 'text_domain'),
      'use_featured_image'    => __('Use as featured image', 'text_domain'),
      'insert_into_item'      => __('Insert into item', 'text_domain'),
      'uploaded_to_this_item' => __('Uploaded to this item', 'text_domain'),
      'items_list'            => __('Recipes list', 'text_domain'),
      'items_list_navigation' => __('Recipes list navigation', 'text_domain'),
      'filter_items_list'     => __('Filter items list', 'text_domain'),
    );
    $recipe_args = array(
      'label'                 => __('Recipe', 'text_domain'),
      'description'           => __('User created recipes.', 'text_domain'),
      'labels'                => $recipe_labels,
      'supports'              => array('title', 'editor', 'author', 'thumbnail', 'custom-fields'),
      'taxonomies'            => array('category', 'post_tag'),
      'hierarchical'          => false,
      'public'                => true,
      'show_ui'               => true,
      'show_in_menu'          => true,
      'menu_position'         => 5,
      'show_in_admin_bar'     => true,
      'show_in_nav_menus'     => true,
      'can_export'            => true,
      'has_archive'           => true,
      'exclude_from_search'   => false,
      'publicly_queryable'    => true,
      'show_in_rest' => true,      // expose to REST API
      'rest_base' => 'recipes',
      'rewrite' => false,
      'capability_type'       => 'page'
    );

    register_post_type('recipe', $recipe_args);
  }
  public function enqueue_ajax_script()
  {

    wp_enqueue_script(
      'custom-ajax-script',
      get_template_directory_uri() . '/scripts.js',
      ['jquery'],
      '1.0',
      true
    );

    wp_localize_script(
      'custom-ajax-script',
      'ajax_object',
      [
        'ajax_url' => admin_url('admin-ajax.php'),
        'nonce'    => wp_create_nonce('recipe_form_nonce'),
      ]
    );
  }

  public function get_title($str, $starting_word, $ending_word)
  {
    $subtring_start = strpos($str, $starting_word);
    // $_SESSION['subtring_start'] = $subtring_start;

    $subtring_start += strlen($starting_word);
    // $_SESSION['subtring_start'] = $subtring_start;

    $size = strpos($str, $ending_word, $subtring_start) - $subtring_start;
    //  $_SESSION['size'] = $size;

    $string = substr($str, $subtring_start, $size);
    //  $_SESSION['string'] = $string;

    return $string;
  }

  public function arrayToString($a)
  {
    $r = '';
    foreach ($a as $i) {
      $r .= $i . ", ";
    }
    return $r;
  }

  public function setupCategory($c)
  {
    foreach ($c as $i) {
      $check = intval(category_exists($i));
      $categories = [];

      if ($check === 0 || $check === null) {
        //category doesn't exist, let's create it
        $newCat = wp_insert_term(ucwords($i), 'category');

        $categories[] = intval($newCat['term_id']);
      } else {
        //category exists, just need the ID
        $categories[] = get_cat_ID(ucwords($i));
      }
    }

    return $categories;
  }

  public function nosh_strip_markdown_fences($text)
  {
    // Remove ```language
    $text = preg_replace('/```[\s\S]*?\n/', '', $text);
    // Remove closing ```
    $text = str_replace('```', '', $text);

    return trim($text);
  }

  public function nosh_normalize_text($text)
  {
    $replacements = [
      '“' => '"',
      '”' => '"',
      '‘' => "'",
      '’' => "'",
      '—' => '-',
      '–' => '-',
      '…' => '...',
      "\u{00A0}" => ' ', // non-breaking space
      "\u{200B}" => '',  // zero-width space
    ];

    return strtr($text, $replacements);
  }

  public function process_form()
  {
    $r = []; // used in debugging - returns via AJAX on form page when redirect turned off

    if (!empty($_POST['ingredient']) && isset($_POST['ingredient']) && check_ajax_referer('recipe_form_nonce', 'nonce')) {

      $ingr = isset($_POST['ingredient']) ? array_map('sanitize_text_field', (array) $_POST['ingredient']) : [];

      if (empty($ingr)) {
        wp_send_json_error('No ingredients provided');
      } else {
        $ingredients = $this->arrayToString($ingr);
      }

      // Optional / dashboard-only data
      $d  = isset($_POST['diet']) ? array_map('sanitize_text_field', (array) $_POST['diet']) : [];
      $e  = isset($_POST['exclude']) ? array_map('sanitize_text_field', (array) $_POST['exclude']) : [];
      $c  = isset($_POST['cuisine']) ? array_map('sanitize_text_field', (array) $_POST['cuisine']) : [];

      if (!empty($d)) {
        $diet = $this->arrayToString($d);
      }

      if (!empty($e)) {
        $exclude = $this->arrayToString($e);
      }

      if (!empty($c)) {
        $cuisine = $this->arrayToString($c);
      }

      // Are we in "dashboard / paid" mode?
      $has_extended_options = !empty($diet) || !empty($exclude) || !empty($cuisine);

      // Optional: enforce PMPro for extended features
      if ($has_extended_options && function_exists('pmpro_hasMembershipLevel')) {
        if (!pmpro_hasMembershipLevel()) {
          wp_send_json_error('Membership required for advanced preferences');
        }
      }



      //save our preferences to the user, if the user is using the Premium Form
      if (!empty($_POST['save_preferences']) && $_POST['save_preferences'] === 'yes') {
        update_user_meta(
          get_current_user_id(),
          'save_recipe_preferences',
          'yes'
        );

        // Optionally store preferences
        update_user_meta(get_current_user_id(), 'diet_preferences', $_POST['diet'] ?? []);
        update_user_meta(get_current_user_id(), 'excluded_ingredients', $_POST['exclude'] ?? []);
        update_user_meta(get_current_user_id(), 'preferred_cuisines', $_POST['cuisine'] ?? []);
      }



      $prompt = "Create a detailed recipe based on only some or all of the following ingredients: " . $ingredients . ".\n Additionally, assign a title to this dish, which can be used to create a photorealistic image of it. Make the response in HTML with the Recipe Title as an H2, the ingedients and an unordered list, and the instructions as paragraphs, but only use those HTML elements. Do not include other types of markdown, code fences, or formatting indicators.";


      if ($has_extended_options) {
        if ($diet) {
          $prompt .= "Use to following dietary preferences: " .  $diet . ".\n";
        }
        if ($exclude) {
          $prompt .= "MAke sure to exclude the following ingredients: " . $exclude . ".\n";
        }
        if ($cuisine) {
          $prompt .= "When considering the recipe, please only consider these preferred cuisines: " . $cuisine . ".\n";
        }
      }



      //connect to OpenAI
      $apiKey = defined('OPENAI_API_KEY') ? OPENAI_API_KEY : '';
      //error handling is not set
      if (empty($apiKey)) {
        wp_send_json_error([
          'message' => 'AI service is not configured.'
        ]);
      }
      $client = OpenAI::client($apiKey);
      try {

        $results = $client->chat()->create([
          'model'       => 'gpt-3.5-turbo',
          'messages'    => [
            ['role' => 'system', 'content' => 'You are a helpful AI chef.'],
            ['role' => 'user', 'content' => $prompt],
          ],
          'max_tokens'  => 500,
          'temperature' => 0.7
        ]);
      } catch (\Throwable $e) {

        error_log('OpenAI error: ' . $e->getMessage());

        wp_send_json_error([
          'message' => 'Our AI chefs are currently unavailable. Please try again later.'
        ]);
      }

      //uncomment to test raw resp from AI
      //error_log("RAW AI OUTPUT:\n" . $openai_response_text);

      if (!empty($results->choices[0]->message->content) && isset($results->choices[0]->message->content)) {
        //process the recipe 
        $title =  SELF::get_title($results->choices[0]->message->content, "<h2>", "</h2>");
        $recipe = str_replace("<h2>" . $title . "</h2>", '', str_replace("\n", '', $results->choices[0]->message->content));

        // sanitize the recipe 
        //1. Strip markdown
        $recipe = $this->nosh_strip_markdown_fences($recipe);

        // 2. Normalize unicode / quotes
        $recipe = $this->nosh_normalize_text($recipe);

        // 3. Sanitize for WP
        $recipe = wp_kses_post($recipe);

        //create a POST out of the recipe

        //add the ingredients, cuisine and diet (if set) as categories to the recipe POST
        $categories = [];
        $r['ingredients'] = $ingr;

        $categories = $this->setupCategory($ingr);

        if (isset($d) && !empty($d)) {
          $a = $this->setupCategory($d);

          array_push($categories, ...$a);
        }

        if (isset($c) && !empty($c)) {
          $aa = $this->setupCategory($c);
          array_push($categories, ...$aa);
        }

        $r['cats'] = $categories;

        //create the post

        $author_id = get_current_user_id();
        if (!$author_id) {
          $author_id = 1;
        }

        $wordpress_post = array(
          'post_title' =>  $title,
          'post_content' => $recipe,
          'post_status' => 'publish',
          'post_author' => $author_id,
          'post_type' => 'recipe',
          'post_category' => $categories
        );

        $recipe_ID = wp_insert_post($wordpress_post);

        if (is_wp_error($recipe_ID) || !$recipe_ID) {
          wp_send_json_error('Failed to create recipe');
        }

        //generate the image

        $response = $client->images()->create([

          'prompt' => $title . ', professional food photography, 15mm, studio lighting"',
          'n' => 1,
          'size' => '1024x1024',
          'response_format' => 'url',
        ]);
        $imageUrl = $response->data[0]['url'];

        //SAVE THE RECIPE/IMAGE AS A POST
        $wp_upload_dir = wp_upload_dir();
        $image_name = str_replace(" ", "-", $title) . ".png";
        $img_file = $wp_upload_dir['path'] . '/' . $image_name;

        // Function to write image into file 
        file_put_contents($img_file, file_get_contents($imageUrl));

        //associate the image and the recipe in WP
        // Check the type of file. We'll use this as the 'post_mime_type'.
        $filetype = wp_check_filetype(basename($image_name), null);
        $r['filetype'] = $filetype;

        // Prepare an array of post data for the attachment.
        $attachment = array(
          'guid'           => $wp_upload_dir['path'] . "/" . basename($image_name),
          'post_mime_type' => $filetype['type'],
          'post_title'     => preg_replace('/\.[^.]+$/', '', basename($image_name)) . "-image",
          'post_content'   => '',
          'post_status'    => 'inherit'
        );
        $r['attachment'] = $attachment;

        // Insert the attachment.
        $attach_id = wp_insert_attachment($attachment, $img_file, $recipe_ID);
        $r['attach id'] = $attach_id;

        // Make sure that this file is included, as  wp_generate_attachment_metadata() depends on it.
        require_once(ABSPATH . 'wp-admin/includes/image.php');

        // Generate the metadata for the attachment, and update the database record.
        $attach_data = wp_generate_attachment_metadata($attach_id, $img_file);
        $r['data'] = $attach_data;
        $r['update'] = wp_update_attachment_metadata($attach_id, $attach_data);

        set_post_thumbnail($recipe_ID, $attach_id);


        //redirect to newly created RECIPE page

        $r['link'] = get_permalink($recipe_ID);


        wp_send_json_success($r);
        wp_send_json_success([
          'link' => get_permalink($recipe_ID),
        ]);
        exit;
      } else {
        wp_send_json_error($r);
      }
    }
  }

  //FOR THE REST API 

  /*
// Block REST access to recipes unless owner or admin and a premium member
add_filter('rest_post_dispatch', function($result, $server, $request) {
    // only handle recipe post type responses coming from WP core posts endpoints
    $route = $request->get_route();
    // adjust if you use a custom namespace/route; this covers default /wp/v2/posts and /wp/v2/recipes
    if (strpos($route, '/wp/v2/posts') === false && strpos($route, '/wp/v2/recipes') === false) {
        return $result;
    }

    // If the response is already an error, let it pass.
    if (is_wp_error($result)) {
        return $result;
    }

    $current_user_id = get_current_user_id();

    // If single post response
    if (isset($result->data['id'])) {
        $post_id = (int) $result->data['id'];
        $post = get_post($post_id);
        if ($post->post_type === 'recipe') {
            if (!current_user_can('manage_options') && $post->post_author !== $current_user_id) {
                return new WP_Error('rest_forbidden', 'You are not allowed to view this recipe.', array('status' => 403));
            }
            if (!pmpro_hasMembershipLevel('premium', $current_user_id)) {
                return new WP_Error('rest_forbidden', 'Premium subscription required.', array('status' => 403));
            }
        }
    }

    // If collection (array of posts), filter out recipes not owned by user
    if (is_array($result->data)) {
        $filtered = array();
        foreach ($result->data as $item) {
            if (isset($item['type']) && $item['type'] === 'recipe') {
                $post = get_post($item['id']);
                if (current_user_can('manage_options') || ($post && $post->post_author === $current_user_id && pmpro_hasMembershipLevel('premium', $current_user_id))) {
                    $filtered[] = $item;
                } // else skip
            } else {
                $filtered[] = $item;
            }
        }
        $result->data = $filtered;
    }

    return $result;
}, 10, 3);


add_action('rest_api_init', function() {
    register_rest_route('myapp/v1', '/recipes', array(
        'methods' => 'GET',
        'callback' => 'myapp_get_recipes',
        'permission_callback' => function() {
            if (!is_user_logged_in()) {
                return new WP_Error('rest_not_logged_in', 'You must be logged in.', array('status'=>401));
            }
            $user_id = get_current_user_id();
            if (!pmpro_hasMembershipLevel('premium', $user_id)) {
                return new WP_Error('rest_forbidden', 'Premium subscription required.', array('status'=>403));
            }
            return true;
        }
    ));

    register_rest_route('myapp/v1', '/recipes', array(
        'methods' => 'POST',
        'callback' => 'myapp_create_recipe',
        'permission_callback' => function() {
            if (!is_user_logged_in()) return new WP_Error('rest_not_logged_in', 'You must be logged in.', array('status'=>401));
            $user_id = get_current_user_id();
            if (!pmpro_hasMembershipLevel('premium', $user_id)) return new WP_Error('rest_forbidden', 'Premium subscription required.', array('status'=>403));
            return true;
        }
    ));
});

function myapp_get_recipes($request) {
    $user_id = get_current_user_id();
    $args = array(
        'post_type' => 'recipe',
        'author' => $user_id,
        'post_status' => 'publish',
        'numberposts' => -1,
    );
    $posts = get_posts($args);
    $data = array();
    foreach ($posts as $p) {
        $data[] = array(
            'id' => $p->ID,
            'title' => $p->post_title,
            'content' => $p->post_content,
        );
    }
    return rest_ensure_response($data);
}

function myapp_create_recipe($request) {
    $user_id = get_current_user_id();
    $params = $request->get_json_params();
    $title = isset($params['title']) ? sanitize_text_field($params['title']) : 'Untitled Recipe';
    $content = isset($params['content']) ? wp_kses_post($params['content']) : '';

    $post_id = wp_insert_post(array(
        'post_title' => $title,
        'post_content' => $content,
        'post_type' => 'recipe',
        'post_status' => 'publish',
        'post_author' => $user_id,
    ));

    if (is_wp_error($post_id) || !$post_id) {
        return new WP_Error('create_failed', 'Could not create recipe.', array('status' => 500));
    }

    return rest_ensure_response(array('id' => $post_id));
}


  */
}
//instantiate
$ChefBot = new Chefbot();
