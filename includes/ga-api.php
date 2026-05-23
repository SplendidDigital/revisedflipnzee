<?php

// ================== EMPTY RESPONSE ==================

function flipnzee_empty_response() {

    return [
        'users'          => 0,
        'sessions'       => 0,
        'trend_percent'  => 0,
        'trend_label'    => '→',
        'user_diff'      => 0,
        'updated'        => time()
    ];
}



// ================== GET ACCESS TOKEN ==================

function flipnzee_get_access_token() {

    $token = get_option('flipnzee_ga_token');

    if (!$token || !is_array($token)) {
        return false;
    }

    if (empty($token['created'])) {

        $token['created'] = time();

        update_option(
            'flipnzee_ga_token',
            $token
        );
    }

    $access_token  = $token['access_token'] ?? '';
    $refresh_token = $token['refresh_token'] ?? '';
    $expires_in    = intval($token['expires_in'] ?? 0);
    $created       = intval($token['created'] ?? 0);


    // ================= REFRESH TOKEN =================

    if (
        time() > ($created + $expires_in - 60)
        && !empty($refresh_token)
    ) {

        $response = wp_remote_post(
            'https://oauth2.googleapis.com/token',
            [
                'body' => [
                    'client_id'     => get_option('flipnzee_client_id'),
                    'client_secret' => get_option('flipnzee_client_secret'),
                    'refresh_token' => $refresh_token,
                    'grant_type'    => 'refresh_token'
                ],

                'timeout' => 20
            ]
        );

        if (is_wp_error($response)) {

            error_log(
                'FLIPNZEE TOKEN ERROR: ' .
                $response->get_error_message()
            );

            return false;
        }

        $body = json_decode(
            wp_remote_retrieve_body($response),
            true
        );

        if (!empty($body['access_token'])) {

            $token['access_token'] = $body['access_token'];

            $token['expires_in'] = intval(
                $body['expires_in'] ?? 3600
            );

            $token['created'] = time();

            update_option(
                'flipnzee_ga_token',
                $token
            );

            return $body['access_token'];
        }

        error_log(
            'FLIPNZEE REFRESH FAILED: ' .
            print_r($body, true)
        );

        return false;
    }

    return $access_token;
}



// ================== GOOGLE POST ==================

function flipnzee_google_post($endpoint, $body) {
    file_put_contents(
    '/tmp/flipnzee-test.log',
    "GOOGLE POST FUNCTION CALLED\n",
    FILE_APPEND
);

    $access_token = flipnzee_get_access_token();

    if (!$access_token) {
        return false;
    }

    $response = wp_remote_post(
        $endpoint,
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ],

            'body' => wp_json_encode($body),

            'timeout' => 20
        ]
    );

    file_put_contents(
    '/tmp/flipnzee-test.log',
    "RAW API RESPONSE:\n" .
    print_r($response, true) .
    "\n",
    FILE_APPEND
);

    if (is_wp_error($response)) {

        error_log(
            'FLIPNZEE REQUEST ERROR: ' .
            $response->get_error_message()
        );

        return false;
    }

    $status = wp_remote_retrieve_response_code($response);

    if ($status !== 200) {

        error_log(
            'FLIPNZEE HTTP ERROR: ' .
            $status .
            ' | RESPONSE: ' .
            wp_remote_retrieve_body($response)
        );

        return false;
    }

    return json_decode(
        wp_remote_retrieve_body($response),
        true
    );
}



// ================== FETCH MAIN ==================

function flipnzee_fetch_and_store($property_id, $post_id) {

    $property_id = trim($property_id);
    $post_id     = intval($post_id);

    if (empty($property_id) || !$post_id) {
        return;
    }

    error_log(
        'FLIPNZEE FETCH MAIN | PROPERTY: ' .
        $property_id .
        ' | POST: ' .
        $post_id
    );

    $access_token = flipnzee_get_access_token();

    if (!$access_token) {

        set_transient(
            "flipnzee_main_{$post_id}",
            flipnzee_empty_response(),
            HOUR_IN_SECONDS
        );

        return;
    }

    $endpoint =
        "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";



    // ================= CURRENT PERIOD =================

    $data_current = flipnzee_google_post(
        $endpoint,
        [
            'dateRanges' => [
                [
                    'startDate' => '30daysAgo',
                    'endDate'   => 'today'
                ]
            ],

            'metrics' => [
                ['name' => 'activeUsers'],
                ['name' => 'sessions']
            ]
        ]
    );

    file_put_contents(
    '/tmp/flipnzee-test.log',
    "CURRENT DATA:\n" .
    print_r($data_current, true) .
    "\n",
    FILE_APPEND
);

    if (!$data_current) {

        set_transient(
            "flipnzee_main_{$post_id}",
            flipnzee_empty_response(),
            HOUR_IN_SECONDS
        );

        return;
    }

    error_log(
        'FLIPNZEE CURRENT RESPONSE: ' .
        print_r($data_current, true)
    );

    if (!empty($data_current['error'])) {

        error_log(
            'FLIPNZEE GA ERROR: ' .
            print_r($data_current['error'], true)
        );

        set_transient(
            "flipnzee_main_{$post_id}",
            flipnzee_empty_response(),
            HOUR_IN_SECONDS
        );

        return;
    }

    if (
        empty($data_current['rows']) ||
        !isset($data_current['rows'][0]['metricValues'][0]['value'])
    ) {

        error_log(
            'FLIPNZEE ERROR: Invalid GA response for property ID: ' .
            $property_id
        );

        set_transient(
            "flipnzee_main_{$post_id}",
            flipnzee_empty_response(),
            HOUR_IN_SECONDS
        );

        return;
    }

    $users = intval(
        $data_current['rows'][0]['metricValues'][0]['value'] ?? 0
    );

    $sessions = intval(
        $data_current['rows'][0]['metricValues'][1]['value'] ?? 0
    );



    // ================= PREVIOUS PERIOD =================

    $data_previous = flipnzee_google_post(
        $endpoint,
        [
            'dateRanges' => [
                [
                    'startDate' => '60daysAgo',
                    'endDate'   => '30daysAgo'
                ]
            ],

            'metrics' => [
                ['name' => 'activeUsers']
            ]
        ]
    );

  

    file_put_contents(
    '/tmp/flipnzee-test.log',
    "PREVIOUS DATA:\n" .
    print_r($data_previous, true) .
    "\n",
    FILE_APPEND
);

    if (!$data_previous) {

        error_log(
            'FLIPNZEE PREVIOUS REQUEST FAILED: ' .
            $property_id
        );

        $previous_users = 0;

    } elseif (!empty($data_previous['error'])) {

        error_log(
            'FLIPNZEE PREVIOUS ERROR: ' .
            print_r($data_previous['error'], true)
        );

        $previous_users = 0;

    } elseif (
        empty($data_previous['rows']) ||
        !isset($data_previous['rows'][0]['metricValues'][0]['value'])
    ) {

        error_log(
            'FLIPNZEE PREVIOUS DATA ERROR: ' .
            $property_id
        );

        $previous_users = 0;

    } else {

        $previous_users = intval(
            $data_previous['rows'][0]['metricValues'][0]['value']
        );
    }



    // ================= TREND =================

    $trend_percent = $previous_users > 0
        ? round(
            (
                ($users - $previous_users)
                / $previous_users
            ) * 100
        )
        : 0;

    $trend_label = $trend_percent > 0
        ? '↑'
        : ($trend_percent < 0 ? '↓' : '→');



    // ================= SAVE =================

    set_transient(
        "flipnzee_main_{$post_id}",
        [
            'users'         => $users,
            'sessions'      => $sessions,
            'trend_percent' => $trend_percent,
            'trend_label'   => $trend_label,
            'user_diff'     => $users - $previous_users,
            'updated'       => time()
        ],
        HOUR_IN_SECONDS
    );
}




// ================== FETCH INSIGHTS ==================

function flipnzee_fetch_insights($property_id, $post_id) {

    $property_id = trim($property_id);
    $post_id     = intval($post_id);

    if (empty($property_id) || !$post_id) {
        return;
    }

    error_log(
        'FLIPNZEE FETCH META | PROPERTY: ' .
        $property_id .
        ' | POST: ' .
        $post_id
    );

    $access_token = flipnzee_get_access_token();

    if (!$access_token) {
        return;
    }

    $endpoint =
        "https://analyticsdata.googleapis.com/v1beta/properties/{$property_id}:runReport";



    // ================= COUNTRIES =================

    $countries = [];

    $response = wp_remote_post(
        $endpoint,
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ],

            'body' => wp_json_encode([

                'dateRanges' => [
                    [
                        'startDate' => '30daysAgo',
                        'endDate'   => 'today'
                    ]
                ],

                'dimensions' => [
                    ['name' => 'country']
                ],

                'metrics' => [
                    ['name' => 'activeUsers']
                ],

                'limit' => 10
            ]),

            'timeout' => 20
        ]
    );

    if (!is_wp_error($response)) {

        $status = wp_remote_retrieve_response_code($response);

        if ($status === 200) {

            $data = json_decode(
                wp_remote_retrieve_body($response),
                true
            );

            if (empty($data['error'])) {

                $total = 0;
                $map   = [];

                foreach ($data['rows'] ?? [] as $row) {

                    $name = $row['dimensionValues'][0]['value'] ?? 'Unknown';

                    $value = intval(
                        $row['metricValues'][0]['value'] ?? 0
                    );

                    $map[$name] = ($map[$name] ?? 0) + $value;

                    $total += $value;
                }

                foreach ($map as $name => $value) {

                    $countries[] = [
                        'name'    => $name,
                        'percent' => $total > 0
                            ? round(($value / $total) * 100)
                            : 0
                    ];
                }
            }
        }
    }



    // ================= SOURCES =================

    $sources = [];

    $response = wp_remote_post(
        $endpoint,
        [
            'headers' => [
                'Authorization' => 'Bearer ' . $access_token,
                'Content-Type'  => 'application/json'
            ],

            'body' => wp_json_encode([

                'dateRanges' => [
                    [
                        'startDate' => '30daysAgo',
                        'endDate'   => 'today'
                    ]
                ],

                'dimensions' => [
                    ['name' => 'sessionDefaultChannelGroup']
                ],

                'metrics' => [
                    ['name' => 'sessions']
                ],

                'limit' => 5
            ]),

            'timeout' => 20
        ]
    );

    if (!is_wp_error($response)) {

        $status = wp_remote_retrieve_response_code($response);

        if ($status === 200) {

            $data = json_decode(
                wp_remote_retrieve_body($response),
                true
            );

            if (empty($data['error'])) {

                $total = 0;

                foreach ($data['rows'] ?? [] as $row) {

                    $value = intval(
                        $row['metricValues'][0]['value'] ?? 0
                    );

                    $sources[] = [
                        'name'  => $row['dimensionValues'][0]['value'] ?? '',
                        'value' => $value
                    ];

                    $total += $value;
                }

                foreach ($sources as &$s) {

                    $s['percent'] = $total > 0
                        ? round(($s['value'] / $total) * 100)
                        : 0;
                }
            }
        }
    }



    // ================= KEYWORDS =================

    $keywords = [];

    $site_url = get_post_meta(
        $post_id,
        '_ga_domain',
        true
    );

    if ($site_url) {

        $domain = preg_replace(
            '#^https?://#',
            '',
            trim($site_url)
        );

        $domain = rtrim($domain, '/');

        $variants = [
            'https://' . $domain . '/',
            'sc-domain:' . $domain,
            'http://' . $domain . '/'
        ];

        foreach ($variants as $site_for_api) {

            error_log(
                'FLIPNZEE SC TRY: ' .
                $site_for_api
            );

            $response = wp_remote_post(
                'https://searchconsole.googleapis.com/webmasters/v3/sites/' .
                urlencode($site_for_api) .
                '/searchAnalytics/query',

                [
                    'headers' => [
                        'Authorization' => 'Bearer ' . $access_token,
                        'Content-Type'  => 'application/json'
                    ],

                    'body' => wp_json_encode([

                        'startDate' => date(
                            'Y-m-d',
                            strtotime('-30 days')
                        ),

                        'endDate' => date('Y-m-d'),

                        'dimensions' => ['query'],

                        'rowLimit' => 50,

                        'searchType' => 'web'
                    ]),

                    'timeout' => 20
                ]
            );

            if (is_wp_error($response)) {
                continue;
            }

            $status = wp_remote_retrieve_response_code($response);

            if ($status !== 200) {

                error_log(
                    'FLIPNZEE SC HTTP ERROR: ' .
                    $status .
                    ' | RESPONSE: ' .
                    wp_remote_retrieve_body($response)
                );

                continue;
            }

            $raw = wp_remote_retrieve_body($response);

            $data = json_decode($raw, true);

            error_log(
                'FLIPNZEE SC RESPONSE: ' .
                $raw
            );

            if (!empty($data['rows'])) {

                foreach ($data['rows'] as $row) {

                    $keywords[] = [
                        'query' => $row['keys'][0] ?? '',

                        'clicks' => intval(
                            $row['clicks'] ?? 0
                        ),

                        'position' => round(
                            $row['position'] ?? 0,
                            1
                        )
                    ];
                }

                usort($keywords, function($a, $b) {

                    return ($a['position'] ?? 999)
                        <=> ($b['position'] ?? 999);

                });

                break;
            }
        }
    }



    // ================= SAVE META =================

    set_transient(
        "flipnzee_meta_{$post_id}",
        [
            'countries' => $countries,
            'sources'   => $sources,
            'keywords'  => $keywords
        ],
        6 * HOUR_IN_SECONDS
    );
}