<?php

/*
Plugin Name: Flipnzee Analytics
Description: GA Verified Traffic + Insights
Version: 2.0
Author: Flipnzee
*/

// Prevent direct access
if (!defined('ABSPATH')) {
    exit;
}


// ================== CONFIG ==================

define(
    'FLIPNZEE_REDIRECT_URI',
    home_url('/flipnzee-ga-callback')
);


// ================== LOAD FILES ==================

require_once plugin_dir_path(__FILE__) . 'includes/ga-api.php';
require_once plugin_dir_path(__FILE__) . 'includes/shortcodes.php';
require_once plugin_dir_path(__FILE__) . 'includes/admin-page.php';

// ================== FRONTEND ASSETS ==================

  // ================== FRONTEND ASSETS ==================

add_action('wp_enqueue_scripts', function () {

    wp_enqueue_style(
        'flipnzee-frontend',
        plugins_url(
            'assets/css/frontend.css',
            __FILE__
        ),
        [],
        time()
    );

}, 99);

// ================== CRON SETUP ==================

register_activation_hook(__FILE__, 'flipnzee_activate');

function flipnzee_activate() {

    if (!wp_next_scheduled('flipnzee_cron_fetch')) {

        wp_schedule_event(
            time(),
            'hourly',
            'flipnzee_cron_fetch'
        );
    }

    if (!wp_next_scheduled('flipnzee_cron_insights')) {

        wp_schedule_event(
            time(),
            'twicedaily',
            'flipnzee_cron_insights'
        );
    }
}


register_deactivation_hook(__FILE__, 'flipnzee_deactivate');

function flipnzee_deactivate() {

    wp_clear_scheduled_hook('flipnzee_cron_fetch');

    wp_clear_scheduled_hook('flipnzee_cron_insights');
}


// ================== FETCH CRON ==================

add_action('flipnzee_cron_fetch', function () {

    $args = [
        'post_type'      => 'listing',
        'posts_per_page' => -1,
        'post_status'    => 'publish'
    ];

    $listings = get_posts($args);

    foreach ($listings as $post) {

        $property_id = get_post_meta(
            $post->ID,
            '_ga_property_id',
            true
        );

        if (!$property_id) {
            continue;
        }

        flipnzee_fetch_and_store(
            $property_id,
            $post->ID
        );
    }
});


// ================== INSIGHTS CRON ==================

add_action('flipnzee_cron_insights', function () {

    $listings = get_posts([
        'post_type'      => 'listing',
        'posts_per_page' => -1,
        'post_status'    => 'publish'
    ]);

    foreach ($listings as $post) {

        $property_id = get_post_meta(
            $post->ID,
            '_ga_property_id',
            true
        );

        if (!$property_id) {
            continue;
        }

        flipnzee_fetch_insights(
            $property_id,
            $post->ID
        );
    }
});


// ================== CUSTOM POST TYPE ==================

add_action('init', function () {

    register_post_type('listing', [

        'labels' => [
            'name'          => 'Listings',
            'singular_name' => 'Listing'
        ],

        'public'       => true,
        'has_archive'  => true,

        'rewrite' => [
            'slug' => 'listings'
        ],

        'supports' => [
            'title',
            'editor',
            'thumbnail'
        ],

        'show_in_rest' => true

    ]);
});


// ================== OAUTH CALLBACK ==================

add_action('init', function () {

    if (
        strpos(
            $_SERVER['REQUEST_URI'],
            '/flipnzee-ga-callback'
        ) === false
    ) {
        return;
    }

    if (!isset($_GET['code'])) {

        echo "No authorization code received.";
        exit;
    }

    $code = sanitize_text_field($_GET['code']);

    $client_id = get_option('flipnzee_client_id');

    $client_secret = get_option('flipnzee_client_secret');

    if (empty($client_id) || empty($client_secret)) {

        echo "Client ID or Client Secret missing. Please save them in settings.";

        exit;
    }

    $response = wp_remote_post(
        'https://oauth2.googleapis.com/token',
        [
            'body' => [
                'code'          => $code,
                'client_id'     => $client_id,
                'client_secret' => $client_secret,
                'redirect_uri'  => FLIPNZEE_REDIRECT_URI,
                'grant_type'    => 'authorization_code'
            ]
        ]
    );

    $body = json_decode(
        wp_remote_retrieve_body($response),
        true
    );

    if (!empty($body['access_token'])) {

        // Save token creation time
        $body['created'] = time();

        update_option(
            'flipnzee_ga_token',
            $body
        );

        wp_redirect(
            admin_url(
                'admin.php?page=flipnzee-analytics&connected=1'
            )
        );

        exit;
    }

    echo "<h3>Token Exchange Failed</h3>";

    echo "<pre>";
    print_r($body);
    echo "</pre>";

    exit;
});


// ================== META BOX ==================

add_action('add_meta_boxes', function () {

    add_meta_box(
        'flipnzee_ga_meta',
        'Google Analytics Settings',
        'flipnzee_ga_meta_box_callback',
        'listing',
        'normal',
        'high'
    );
});


function flipnzee_ga_meta_box_callback($post) {

    $property_id = get_post_meta(
        $post->ID,
        '_ga_property_id',
        true
    );

    $domain = get_post_meta(
        $post->ID,
        '_ga_domain',
        true
    );

    wp_nonce_field(
        'flipnzee_save_meta',
        'flipnzee_meta_nonce'
    );

?>

<p>

    <label>
        <strong>Domain:</strong>
    </label>
    <br>

    <input
        type="text"
        name="ga_domain"
        value="<?php echo esc_attr($domain); ?>"
        style="width:100%;"
    >

</p>


<p>

    <label>
        <strong>GA Property ID:</strong>
    </label>
    <br>

    <input
        type="text"
        name="ga_property_id"
        value="<?php echo esc_attr($property_id); ?>"
        style="width:100%;"
    >

</p>

<?php
}


// ================== SAVE META ==================

// ================== SAVE META ==================

add_action('save_post', function ($post_id) {

    // Security checks
    if (!isset($_POST['flipnzee_meta_nonce'])) {
        return;
    }

    if (
        !wp_verify_nonce(
            $_POST['flipnzee_meta_nonce'],
            'flipnzee_save_meta'
        )
    ) {
        return;
    }

    if (
        defined('DOING_AUTOSAVE') &&
        DOING_AUTOSAVE
    ) {
        return;
    }

    if (!current_user_can('edit_post', $post_id)) {
        return;
    }

    // Only for listing post type
    if (get_post_type($post_id) !== 'listing') {
        return;
    }


    // ================= SAVE PROPERTY ID =================

    if (isset($_POST['ga_property_id'])) {

        $property_id = sanitize_text_field(
            $_POST['ga_property_id']
        );

        update_post_meta(
            $post_id,
            '_ga_property_id',
            $property_id
        );
    }


    // ================= SAVE DOMAIN =================

    if (isset($_POST['ga_domain'])) {

        $domain = sanitize_text_field(
            $_POST['ga_domain']
        );

        update_post_meta(
            $post_id,
            '_ga_domain',
            $domain
        );
    }


    // ================= CLEAR OLD TRANSIENTS =================

    delete_transient("flipnzee_main_{$post_id}");

    delete_transient("flipnzee_meta_{$post_id}");

});