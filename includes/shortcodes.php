<?php

file_put_contents(
    '/tmp/flipnzee-test.log',
    "SHORTCODES FILE LOADED\n",
    FILE_APPEND
);

// ================= HELPERS =================
function flipnzee_get_stats($post_id) {
file_put_contents(
    '/tmp/flipnzee-test.log',
    "GET_STATS FUNCTION CALLED\n",
    FILE_APPEND
);

    // Force integer safety
    file_put_contents(
    '/tmp/flipnzee-test.log',
    "RAW POST ID: " . print_r($post_id, true) . "\n",
    FILE_APPEND
);
    $post_id = intval($post_id);
    file_put_contents(
    '/tmp/flipnzee-test.log',
    "INT POST ID: {$post_id}\n",
    FILE_APPEND
);

    if (!$post_id) {
        return flipnzee_empty_response();
    }

 // Unique transient key
$cache_key = "flipnzee_main_{$post_id}";

// Force refresh temporarily
$stats = false;

    // ONLY fetch if transient truly missing
    // (not when users = 0)
    if ($stats === false) {
file_put_contents(
    '/tmp/flipnzee-test.log',
    "FETCH TRIGGERED FOR POST {$post_id}\n",
    FILE_APPEND
);
        $property_id = get_post_meta(
            $post_id,
            '_ga_property_id',
            true
        );

        $property_id = trim($property_id);

        if (!empty($property_id)) {
            file_put_contents(
    '/tmp/flipnzee-test.log',
    "PROPERTY FOUND: {$property_id}\n",
    FILE_APPEND
);

            // Fresh fetch
            flipnzee_fetch_and_store(
                $property_id,
                $post_id
            );

            // Reload transient after fetch
            $stats = get_transient($cache_key);
        }
    }

    // Final fallback
    if (!$stats || !is_array($stats)) {

        return [
            'users'         => 0,
            'sessions'      => 0,
            'trend_percent' => 0,
            'trend_label'   => '→',
            'user_diff'     => 0,
            'updated'       => time()
        ];
    }

    return $stats;
}



function flipnzee_get_meta($post_id) {

    // Force integer safety
    $post_id = intval($post_id);

    if (!$post_id) {
        return [];
    }

    // Unique transient key
    $cache_key = "flipnzee_meta_{$post_id}";

    $meta = get_transient($cache_key);

    // ONLY fetch if transient missing
    if ($meta === false) {

        $property_id = get_post_meta(
            $post_id,
            '_ga_property_id',
            true
        );

        $property_id = trim($property_id);

        if (!empty($property_id)) {

            flipnzee_fetch_insights(
                $property_id,
                $post_id
            );

            // Reload transient
            $meta = get_transient($cache_key);
        }
    }

    // Safety fallback
    if (!$meta || !is_array($meta)) {

        return [
            'countries' => [],
            'sources'   => [],
            'keywords'  => []
        ];
    }

    return $meta;
}



function flipnzee_clean_source_name($name) {

    $map = [
        'Organic Search' => 'SEO',
        'Direct'         => 'Direct',
        'Referral'       => 'Referrals',
        'Paid Search'    => 'Ads',
        'Organic Social' => 'Social',
        'Email'          => 'Email'
    ];

    return $map[$name] ?? $name;
}



// ================= VERIFIED DASHBOARD =================

add_shortcode('flipnzee_verified_badge', function () {

    file_put_contents(
    '/tmp/flipnzee-test.log',
    "SHORTCODE EXECUTED\n",
    FILE_APPEND
);
   if (is_admin() && defined('REST_REQUEST') && REST_REQUEST) {
    return '';
}

   
if (!is_singular('listing')) {
    return '';
}


    $post_id = get_the_ID();
    error_log('SHORTCODE RUNNING FOR POST: ' . $post_id);

error_log(
    'PROPERTY ID: ' .
    get_post_meta(
        $post_id,
        '_ga_property_id',
        true
    )
);

    $stats = flipnzee_get_stats($post_id);
    $meta  = flipnzee_get_meta($post_id);

    ob_start();

?>

<div class="flip-wrap">

    <div class="flip-header">

        <div class="flip-title-big">
            ✔ Google Verified Analytics
        </div>

        <div class="flip-rank-badge">
            <?php echo number_format($stats['users']); ?> Users
        </div>

    </div>


    <div class="flip-kpi-grid">

        <div class="flip-kpi-box">
            <div class="flip-kpi-value">
                <?php echo number_format($stats['users']); ?>
            </div>

            <div class="flip-kpi-label">
                Users
            </div>
        </div>


        <div class="flip-kpi-box">
            <div class="flip-kpi-value">
                <?php echo number_format($stats['sessions']); ?>
            </div>

            <div class="flip-kpi-label">
                Sessions
            </div>
        </div>


        <div class="flip-kpi-box">

            <div class="flip-kpi-value <?php echo ($stats['trend_percent'] >= 0 ? 'flip-trend-up' : 'flip-trend-down'); ?>">

                <?php
                echo $stats['trend_label'] . " " .
                abs($stats['trend_percent']);
                ?>%

            </div>

            <div class="flip-kpi-label">
                Growth
            </div>

        </div>

    </div>


    <div class="flip-kpi-label">

        Updated
        <?php
        echo human_time_diff(
            $stats['updated'],
            current_time('timestamp')
        );
        ?>
        ago

    </div>


    <!-- COUNTRIES -->

    <?php if (!empty($meta['countries'])) : ?>

        <div class="flip-section">

            <h4>Top Countries</h4>

            <?php foreach ($meta['countries'] as $c) : ?>

                <?php $percent = $c['percent'] ?? 0; ?>

                <div class="flip-row">

                    <div class="flip-keyword">

                        <span>
                            <?php echo esc_html($c['name'] ?? ''); ?>
                        </span>

                        <span>
                            <?php echo esc_html($percent); ?>%
                        </span>

                    </div>

                    <div class="flip-bar">

                        <div
                            class="flip-bar-fill"
                            style="width:<?php echo esc_attr($percent); ?>%"
                        ></div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <!-- SOURCES -->

    <?php if (!empty($meta['sources'])) : ?>

        <div class="flip-section">

            <h4>Traffic Sources</h4>

            <?php foreach ($meta['sources'] as $s) : ?>

                <?php $percent = $s['percent'] ?? 0; ?>

                <div class="flip-row">

                    <div class="flip-keyword">

                        <span>
                            <?php
                            echo esc_html(
                                flipnzee_clean_source_name(
                                    $s['name'] ?? ''
                                )
                            );
                            ?>
                        </span>

                        <span>
                            <?php echo esc_html($percent); ?>%
                        </span>

                    </div>

                    <div class="flip-bar">

                        <div
                            class="flip-bar-fill"
                            style="width:<?php echo esc_attr($percent); ?>%"
                        ></div>

                    </div>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>


    <!-- KEYWORDS -->

    <?php if (!empty($meta['keywords'])) : ?>

        <div class="flip-section">

            <h4>Top Keywords</h4>

            <?php foreach ($meta['keywords'] as $k) : ?>

                <div class="flip-keyword <?php echo (($k['position'] ?? 0) <= 3 ? 'flip-top-keyword' : ''); ?>">

                    <span>
                        <?php echo esc_html($k['query'] ?? ''); ?>
                    </span>

                    <span>
                        #<?php echo esc_html($k['position'] ?? 0); ?>
                    </span>

                </div>

            <?php endforeach; ?>

        </div>

    <?php endif; ?>

</div>

<?php

    return ob_get_clean();

});



// ================= ALL LISTINGS GRID =================

add_shortcode('flipnzee_all_listings', function () {

if (is_admin() && defined('REST_REQUEST') && REST_REQUEST) {
    return '';
}

    $posts = get_posts([
        'post_type'      => 'listing',
        'posts_per_page' => -1,
        'post_status'    => 'publish'
    ]);

    if (!$posts) {
        return "<p>No listings found.</p>";
    }

    $items = [];

    foreach ($posts as $post) {

        $stats = flipnzee_get_stats($post->ID);

        $property_id = get_post_meta(
            $post->ID,
            '_ga_property_id',
            true
        );

        if (!$property_id) {
            continue;
        }

        $post->flip_stats = $stats;

        $items[] = $post;
    }

    usort($items, function ($a, $b) {

        return ($b->flip_stats['users'] ?? 0)
            <=>
            ($a->flip_stats['users'] ?? 0);

    });

    ob_start();

?>

<div class="flip-grid">

<?php $rank = 1; ?>

<?php foreach ($items as $post) : ?>

    <?php $stats = $post->flip_stats; ?>

    <div class="flip-card-listing">

        <div class="flip-rank <?php echo ($rank <= 3 ? "top-$rank" : ""); ?>">
            #<?php echo $rank; ?>
        </div>

        <h3>
            <?php echo esc_html(get_the_title($post->ID)); ?>
        </h3>

        <?php if ($stats['trend_percent'] > 20) : ?>

            <div class="flip-trending">
                Fast Growing
            </div>

        <?php endif; ?>


        <div class="flip-users">

            <?php
            echo ($stats['users'] > 0)
                ? number_format($stats['users'])
                : '—';
            ?>

        </div>

        <div class="flip-sub">
            Users (30 days)
        </div>

        <?php if ($stats['users'] == 0) : ?>

            <div class="flip-sub">
                Fetching data...
            </div>

        <?php endif; ?>


        <div class="flip-sub">

            <?php
            echo $stats['trend_label'] .
            " " .
            abs($stats['trend_percent']);
            ?>%

        </div>


        <a href="<?php echo esc_url(get_permalink($post->ID)); ?>">
            View Details →
        </a>

    </div>

<?php $rank++; ?>

<?php endforeach; ?>

</div>

<?php

    return ob_get_clean();

});