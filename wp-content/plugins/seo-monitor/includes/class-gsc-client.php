<?php
/**
 * Google Search Console API Client
 *
 * Handles authentication via service account and data retrieval.
 * Uses WordPress HTTP API instead of the Google PHP client library
 * to avoid dependency/namespace conflicts with other plugins.
 */

if (!defined('ABSPATH')) exit;

class SEOM_GSC_Client {

    private $credentials_json;
    private $property_url;
    private $access_token;
    private $token_expires;

    public function __construct($credentials_json = '', $property_url = '') {
        $this->credentials_json = $credentials_json;

        // Domain properties use "sc-domain:" prefix — don't add trailing slash
        if (strpos($property_url, 'sc-domain:') === 0) {
            $this->property_url = $property_url;
        } else {
            $this->property_url = rtrim($property_url, '/') . '/';
        }
    }

    /**
     * Get an OAuth2 access token using the service account JWT.
     */
    private function get_access_token() {
        if ($this->access_token && $this->token_expires > time()) {
            return $this->access_token;
        }

        // Check transient cache first
        $cached = get_transient('seom_gsc_token');
        if ($cached) {
            $this->access_token = $cached;
            $this->token_expires = time() + 1800;
            return $this->access_token;
        }

        if (empty($this->credentials_json)) {
            return new WP_Error('no_credentials', 'Service account JSON not configured. Paste it in Settings.');
        }

        $creds = json_decode($this->credentials_json, true);
        if (!$creds || empty($creds['client_email']) || empty($creds['private_key'])) {
            return new WP_Error('bad_credentials', 'Invalid service account JSON. Check the format in Settings.');
        }

        // Build JWT
        $header = base64_encode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $claims = base64_encode(json_encode([
            'iss'   => $creds['client_email'],
            'scope' => 'https://www.googleapis.com/auth/webmasters.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ]));

        $signing_input = $header . '.' . $claims;
        $signature = '';
        $key = openssl_pkey_get_private($creds['private_key']);
        if (!$key) {
            return new WP_Error('key_error', 'Failed to parse private key from service account JSON.');
        }
        openssl_sign($signing_input, $signature, $key, OPENSSL_ALGO_SHA256);
        $jwt = $signing_input . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        // Exchange JWT for access token
        $response = wp_remote_post('https://oauth2.googleapis.com/token', [
            'body' => [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) return $response;

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body['access_token'])) {
            $err = $body['error_description'] ?? $body['error'] ?? 'Unknown error';
            return new WP_Error('token_error', 'Failed to get access token: ' . $err);
        }

        $this->access_token = $body['access_token'];
        $this->token_expires = time() + ($body['expires_in'] ?? 3600) - 60;
        set_transient('seom_gsc_token', $this->access_token, $body['expires_in'] - 60);

        return $this->access_token;
    }

    /**
     * Make an authenticated request to the GSC API.
     */
    private function api_request($endpoint, $body = null) {
        $token = $this->get_access_token();
        if (is_wp_error($token)) return $token;

        $url = 'https://searchconsole.googleapis.com/webmasters/v3/' . $endpoint;
        $args = [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 60,
        ];

        if ($body !== null) {
            $args['body'] = json_encode($body);
            $response = wp_remote_post($url, $args);
        } else {
            $response = wp_remote_get($url, $args);
        }

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code >= 400) {
            $err = $data['error']['message'] ?? "HTTP $code";
            return new WP_Error('api_error', 'GSC API error: ' . $err);
        }

        return $data;
    }

    /**
     * Test the connection by listing all accessible sites, then matching the configured property.
     */
    public function test_connection() {
        // Step 1: Verify we can get an access token
        $token = $this->get_access_token();
        if (is_wp_error($token)) {
            return new WP_Error('auth_failed', 'Authentication failed: ' . $token->get_error_message());
        }

        $debug = [
            'step_1_auth' => 'OK — Access token obtained',
            'token_prefix' => substr($token, 0, 20) . '...',
        ];

        // Step 2: Call sites.list to get all properties
        $url = 'https://searchconsole.googleapis.com/webmasters/v3/sites';
        $response = wp_remote_get($url, [
            'headers' => [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
            ],
            'timeout' => 30,
        ]);

        if (is_wp_error($response)) {
            $debug['step_2_list'] = 'WP Error: ' . $response->get_error_message();
            return new WP_Error('list_failed', json_encode($debug, JSON_PRETTY_PRINT));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        $debug['step_2_http_code'] = $code;
        $debug['step_2_raw_response'] = $body_raw;

        if ($code >= 400) {
            return new WP_Error('api_error', json_encode($debug, JSON_PRETTY_PRINT));
        }

        // Step 3: Parse sites
        $available = [];
        $matched = null;
        foreach (($body['siteEntry'] ?? []) as $site) {
            $site_url = $site['siteUrl'] ?? '';
            $level = $site['permissionLevel'] ?? 'unknown';
            $available[] = $site_url . ' (' . $level . ')';

            // Normalize for comparison: domain properties match exactly,
            // URL-prefix properties match with trailing slash normalization
            $config_norm = rtrim($this->property_url, '/');
            $site_norm   = rtrim($site_url, '/');
            if ($site_norm === $config_norm || $site_url === $this->property_url) {
                $matched = $site;
            }
        }

        $debug['step_3_sites_found'] = count($available);
        $debug['step_3_sites'] = $available;
        $debug['configured_property'] = $this->property_url;

        if ($matched) {
            return [
                'property'  => $matched['siteUrl'],
                'level'     => $matched['permissionLevel'] ?? 'unknown',
                'status'    => 'Connected',
                'available' => $available,
                'debug'     => $debug,
            ];
        }

        return new WP_Error('no_match', json_encode($debug, JSON_PRETTY_PRINT));
    }

    /**
     * Fetch search analytics data from GSC.
     *
     * @param string $start_date  YYYY-MM-DD
     * @param string $end_date    YYYY-MM-DD
     * @param array  $dimensions  ['page'], ['page','query'], etc.
     * @param int    $row_limit   Max rows (default 5000, max 25000)
     * @param int    $start_row   For pagination
     * @return array|WP_Error
     */
    public function get_search_analytics($start_date, $end_date, $dimensions = ['page'], $row_limit = 5000, $start_row = 0) {
        $body = [
            'startDate'  => $start_date,
            'endDate'    => $end_date,
            'dimensions' => $dimensions,
            'rowLimit'   => $row_limit,
            'startRow'   => $start_row,
        ];

        $site_url = urlencode($this->property_url);
        return $this->api_request("sites/{$site_url}/searchAnalytics/query", $body);
    }

    /**
     * Fetch site-wide aggregate totals from GSC (no page dimension).
     * Returns the true site-wide clicks, impressions, CTR, and position.
     *
     * @param int $days Number of days to look back (default 28)
     * @return array|WP_Error ['clicks' => int, 'impressions' => int, 'ctr' => float, 'position' => float]
     */
    public function get_site_totals($days = 28) {
        $end_date   = date('Y-m-d', strtotime('-1 day'));
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        // Query with NO dimensions — GSC returns a single row with site-wide aggregates
        $result = $this->get_search_analytics($start_date, $end_date, [], 1, 0);
        if (is_wp_error($result)) return $result;

        $row = $result['rows'][0] ?? null;
        if (!$row) {
            return ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
        }

        return [
            'clicks'      => $row['clicks'] ?? 0,
            'impressions' => $row['impressions'] ?? 0,
            'ctr'         => round(($row['ctr'] ?? 0) * 100, 4),
            'position'    => round($row['position'] ?? 0, 1),
        ];
    }

    /**
     * Fetch page-level metrics for all pages.
     * Handles pagination automatically.
     *
     * @param int $days Number of days to look back (default 28)
     * @return array|WP_Error Array of [url => [clicks, impressions, ctr, position]]
     */
    public function get_all_page_metrics($days = 28) {
        $end_date   = date('Y-m-d', strtotime('-1 day')); // GSC returns whatever data is available
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $all_rows = [];
        $start_row = 0;
        $batch_size = 5000;

        do {
            $result = $this->get_search_analytics($start_date, $end_date, ['page'], $batch_size, $start_row);
            if (is_wp_error($result)) return $result;

            $rows = $result['rows'] ?? [];
            foreach ($rows as $row) {
                $url = $row['keys'][0];
                $all_rows[$url] = [
                    'clicks'      => $row['clicks'] ?? 0,
                    'impressions' => $row['impressions'] ?? 0,
                    'ctr'         => round(($row['ctr'] ?? 0) * 100, 4),
                    'position'    => round($row['position'] ?? 0, 1),
                ];
            }

            $start_row += $batch_size;
        } while (count($rows) === $batch_size);

        return $all_rows;
    }

    /**
     * Fetch top queries for ALL pages in a single bulk call.
     * Uses ['page', 'query'] dimensions and groups results by page URL.
     * Returns: [url => [ [query, clicks, impressions, ctr, position], ... ]]
     *
     * @param int $days Lookback period
     * @param int $max_queries_per_page Keep top N queries per page
     * @return array|WP_Error
     */
    public function get_all_page_queries($days = 28, $max_queries_per_page = 5) {
        $end_date   = date('Y-m-d', strtotime('-1 day'));
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $all_rows = [];
        $start_row = 0;
        $batch_size = 10000;

        do {
            $result = $this->get_search_analytics($start_date, $end_date, ['page', 'query'], $batch_size, $start_row);
            if (is_wp_error($result)) return $result;

            $rows = $result['rows'] ?? [];
            foreach ($rows as $row) {
                $url   = $row['keys'][0];
                $query = $row['keys'][1];

                if (!isset($all_rows[$url])) $all_rows[$url] = [];

                $all_rows[$url][] = [
                    'query'       => $query,
                    'clicks'      => $row['clicks'] ?? 0,
                    'impressions' => $row['impressions'] ?? 0,
                    'ctr'         => round(($row['ctr'] ?? 0) * 100, 4),
                    'position'    => round($row['position'] ?? 0, 1),
                ];
            }

            $start_row += $batch_size;
        } while (count($rows) === $batch_size);

        // Sort each page's queries by clicks descending and keep top N
        foreach ($all_rows as $url => &$queries) {
            usort($queries, function ($a, $b) { return $b['clicks'] <=> $a['clicks']; });
            $queries = array_slice($queries, 0, $max_queries_per_page);
        }

        return $all_rows;
    }

    /**
     * Fetch top queries for a specific page.
     *
     * @param string $page_url Full URL
     * @param int    $days     Lookback period
     * @param int    $limit    Max queries
     * @return array|WP_Error
     */
    public function get_page_queries($page_url, $days = 28, $limit = 10) {
        $end_date   = date('Y-m-d', strtotime('-1 day'));
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $body = [
            'startDate'        => $start_date,
            'endDate'          => $end_date,
            'dimensions'       => ['query'],
            'dimensionFilterGroups' => [[
                'filters' => [[
                    'dimension'  => 'page',
                    'expression' => $page_url,
                ]],
            ]],
            'rowLimit' => $limit,
        ];

        $site_url = urlencode($this->property_url);
        $result = $this->api_request("sites/{$site_url}/searchAnalytics/query", $body);

        if (is_wp_error($result)) return $result;

        $queries = [];
        foreach (($result['rows'] ?? []) as $row) {
            $queries[] = [
                'query'       => $row['keys'][0],
                'clicks'      => $row['clicks'] ?? 0,
                'impressions' => $row['impressions'] ?? 0,
                'ctr'         => round(($row['ctr'] ?? 0) * 100, 4),
                'position'    => round($row['position'] ?? 0, 1),
            ];
        }

        return $queries;
    }

    /**
     * Fetch ALL queries site-wide (not per-page).
     * Returns every query the site ranks for with aggregate metrics.
     *
     * @param int $days Lookback period
     * @return array|WP_Error  Array of [query => [clicks, impressions, ctr, position]]
     */
    public function get_all_queries_sitewide($days = 28) {
        $end_date   = date('Y-m-d', strtotime('-1 day'));
        $start_date = date('Y-m-d', strtotime("-{$days} days"));

        $all = [];
        $start_row = 0;
        $batch_size = 5000;

        do {
            $result = $this->get_search_analytics($start_date, $end_date, ['query'], $batch_size, $start_row);
            if (is_wp_error($result)) return $result;

            $rows = $result['rows'] ?? [];
            foreach ($rows as $row) {
                $all[$row['keys'][0]] = [
                    'clicks'      => $row['clicks'] ?? 0,
                    'impressions' => $row['impressions'] ?? 0,
                    'ctr'         => round(($row['ctr'] ?? 0) * 100, 4),
                    'position'    => round($row['position'] ?? 0, 1),
                ];
            }
            $start_row += $batch_size;
        } while (count($rows) === $batch_size);

        return $all;
    }

    /**
     * Fetch query trends by comparing two periods.
     * Returns queries with their current and previous metrics.
     *
     * @param int $days Period length (compares last N days vs prior N days)
     * @return array|WP_Error  [query => [current => {...}, previous => {...}, trend_pct => float]]
     */
    public function get_query_trends($days = 28) {
        $current = $this->get_all_queries_sitewide($days);
        if (is_wp_error($current)) return $current;

        $end_prev   = date('Y-m-d', strtotime('-' . ($days + 3) . ' days'));
        $start_prev = date('Y-m-d', strtotime('-' . ($days * 2 + 3) . ' days'));

        $prev_all = [];
        $start_row = 0;
        do {
            $result = $this->get_search_analytics($start_prev, $end_prev, ['query'], 5000, $start_row);
            if (is_wp_error($result)) return $result;

            $rows = $result['rows'] ?? [];
            foreach ($rows as $row) {
                $prev_all[$row['keys'][0]] = [
                    'clicks'      => $row['clicks'] ?? 0,
                    'impressions' => $row['impressions'] ?? 0,
                    'ctr'         => round(($row['ctr'] ?? 0) * 100, 4),
                    'position'    => round($row['position'] ?? 0, 1),
                ];
            }
            $start_row += 5000;
        } while (count($rows) === 5000);

        // Merge and compute trends
        $all_queries = array_unique(array_merge(array_keys($current), array_keys($prev_all)));
        $trends = [];

        foreach ($all_queries as $q) {
            $cur  = $current[$q] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];
            $prev = $prev_all[$q] ?? ['clicks' => 0, 'impressions' => 0, 'ctr' => 0, 'position' => 0];

            $imp_prev = max($prev['impressions'], 1);
            $trend_pct = round((($cur['impressions'] - $prev['impressions']) / $imp_prev) * 100, 1);

            $trends[$q] = [
                'current'    => $cur,
                'previous'   => $prev,
                'trend_pct'  => $trend_pct,
                'direction'  => $trend_pct > 10 ? 'rising' : ($trend_pct < -10 ? 'declining' : 'stable'),
            ];
        }

        return $trends;
    }

}
