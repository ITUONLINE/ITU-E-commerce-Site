<?php
/*
Plugin Name: AI Product Manager
Description: Bulk WooCommerce product content management with AI-powered generation (descriptions, short descriptions, FAQs, RankMath SEO).
Version: 1.0
Author: ITU Online
Requires Plugins: woocommerce
*/

if (!defined('ABSPATH')) exit;

// ─── Utilities ───────────────────────────────────────────────────────────────

/**
 * Release PHP session lock so other admin requests aren't blocked
 * while long-running AJAX calls (OpenAI, thumbnail generation) execute.
 */
function aipm_release_session_lock() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    // Prevent WP from holding any output buffer that blocks the connection
    if (function_exists('fastcgi_finish_request')) {
        // Available on Nginx + PHP-FPM — not relevant here but harmless
    }
}

function aipm_fix_faq_paragraphs($html) {
    return preg_replace_callback(
        '/<div class="faq-content">(.*?)<\/div>/s',
        function ($match) {
            $content = trim($match[1]);
            if (stripos($content, '<p>') !== false) return $match[0];

            $sentences = preg_split('/(?<=[.!?])\s+/', $content);
            $chunks = [];
            $current = '';
            foreach ($sentences as $i => $s) {
                $current .= ($current ? ' ' : '') . $s;
                if (($i + 1) % 3 === 0 || $i === count($sentences) - 1) {
                    $chunks[] = $current;
                    $current = '';
                }
            }

            $wrapped = '';
            foreach ($chunks as $chunk) {
                $chunk = trim($chunk);
                if (empty($chunk)) continue;
                if (preg_match('/^<(p|ul|ol|table|blockquote)/i', $chunk)) {
                    $wrapped .= $chunk . "\n";
                } else {
                    $wrapped .= '<p>' . $chunk . "</p>\n";
                }
            }

            return '<div class="faq-content">' . "\n" . $wrapped . '</div>';
        },
        $html
    );
}

function aipm_strip_quotes($text) {
    return preg_replace('/^(["\'])(.*)\1$/s', '$2', trim($text));
}

function aipm_call_openai($instruction, $user_prompt, $model = '', $temperature = 0.7) {
    if (!$model) $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

    // Use unified provider router if available
    if (function_exists('itu_ai_call')) {
        $result = itu_ai_call($instruction, $user_prompt, $model, $temperature, ['key_name' => 'openai']);
        if (is_wp_error($result)) return $result;
        return aipm_strip_quotes($result);
    }

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('openai') : get_option('aicg_api_key');
    if (!$api_key) return new WP_Error('no_key', 'OpenAI API key not configured.');

    // GPT-5.x and o-series models require Responses API (no temperature support)
    $use_responses = (bool) preg_match('/^(gpt-5|o[1-9])/', $model);

    if ($use_responses) {
        $body = ['model' => $model];
        if (!empty($instruction)) $body['instructions'] = $instruction;
        if (!empty($user_prompt)) {
            $body['input'] = [['role' => 'user', 'content' => $user_prompt]];
        } else {
            $body['input'] = $instruction;
            unset($body['instructions']);
        }

        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'headers' => ['Authorization' => 'Bearer ' . $api_key, 'Content-Type' => 'application/json'],
            'body'    => json_encode($body),
            'timeout' => 240,
        ]);

        if (is_wp_error($response)) return $response;
        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if ($code !== 200) return new WP_Error('api_error', 'Responses API: ' . ($data['error']['message'] ?? "HTTP {$code}"));

        $content = '';
        foreach (($data['output'] ?? []) as $block) {
            if (($block['type'] ?? '') === 'message') {
                foreach (($block['content'] ?? []) as $part) {
                    if (($part['type'] ?? '') === 'output_text') $content .= $part['text'] ?? '';
                }
            }
        }
        return !empty(trim($content)) ? aipm_strip_quotes(trim($content)) : new WP_Error('empty', 'No content returned from Responses API.');
    }

    // Chat Completions API (gpt-4.1 family)
    $messages = [['role' => 'system', 'content' => $instruction]];
    if ($user_prompt) {
        $messages[] = ['role' => 'user', 'content' => $user_prompt];
    }

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body'    => json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ]),
        'timeout' => 180,
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $content = $data['choices'][0]['message']['content'] ?? '';

    if (empty($content)) return new WP_Error('empty', 'No content returned from OpenAI.');

    return aipm_strip_quotes($content);
}

// ─── AI Step Shims ──────────────────────────────────────────────────────────
//
// REFACTORED 2026-04: All product AI rewrite logic now lives in the SEO AI
// AutoPilot plugin (SEOM_Blog_Refresher). The functions below are kept as
// thin back-compat shims that delegate to the canonical implementation, so
// any external caller using aipm_step_* (including this plugin's own admin
// page bulk processor) keeps working without changes.
//
// New code should call SEOM_Blog_Refresher::* directly instead.
//

if (!function_exists('aipm_step_description')) {
    function aipm_step_description($post_id) {
        if (!class_exists('SEOM_Blog_Refresher')) {
            return new WP_Error('missing_seom', 'SEO AI AutoPilot plugin is required for AI content generation.');
        }
        return SEOM_Blog_Refresher::step_long_content($post_id);
    }
}

if (!function_exists('aipm_step_short_description')) {
    function aipm_step_short_description($post_id, $description = '') {
        if (!class_exists('SEOM_Blog_Refresher')) {
            return new WP_Error('missing_seom', 'SEO AI AutoPilot plugin is required for AI content generation.');
        }
        // SEOM_Blog_Refresher::step_meta_description() ignores the second arg
        // (it always reads post_content); the optional $description param here
        // is preserved only for back-compat with old AIPM callers.
        return SEOM_Blog_Refresher::step_meta_description($post_id);
    }
}

if (!function_exists('aipm_step_faq_html')) {
    function aipm_step_faq_html($post_id) {
        if (!class_exists('SEOM_Blog_Refresher')) {
            return new WP_Error('missing_seom', 'SEO AI AutoPilot plugin is required for AI content generation.');
        }
        return SEOM_Blog_Refresher::step_faq_html($post_id);
    }
}

if (!function_exists('aipm_step_faq_json')) {
    function aipm_step_faq_json($post_id, $faq_html = '') {
        if (!class_exists('SEOM_Blog_Refresher')) {
            return new WP_Error('missing_seom', 'SEO AI AutoPilot plugin is required for AI content generation.');
        }
        return SEOM_Blog_Refresher::step_faq_json($post_id, $faq_html);
    }
}

if (!function_exists('aipm_step_rankmath')) {
    function aipm_step_rankmath($post_id) {
        if (!class_exists('SEOM_Blog_Refresher')) {
            return new WP_Error('missing_seom', 'SEO AI AutoPilot plugin is required for AI content generation.');
        }
        return SEOM_Blog_Refresher::step_rankmath($post_id);
    }
}

if (!function_exists('aipm_step_seo_title')) {
    function aipm_step_seo_title($post_id) {
        if (!class_exists('SEOM_Blog_Refresher')) {
            return new WP_Error('missing_seom', 'SEO AI AutoPilot plugin is required for AI content generation.');
        }
        return SEOM_Blog_Refresher::step_seo_title($post_id);
    }
}

if (!function_exists('aipm_step_timestamp')) {
    function aipm_step_timestamp($post_id) {
        if (!class_exists('SEOM_Blog_Refresher')) {
            // Fallback: still write the timestamp meta even without SEOM
            $datetime = current_time('mysql');
            update_post_meta($post_id, 'last_page_refresh', $datetime);
            return $datetime;
        }
        return SEOM_Blog_Refresher::step_timestamp($post_id);
    }
}


// ─── Featured Product Blog Search ────────────────────────────────────────────
//
// "Find Blogs" — search published blog posts by keyword that do NOT already
// have a featured product assigned. Returns matches so the user can pick
// which blogs to associate with the current product.

add_action('wp_ajax_aipm_search_blogs', function () {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $keyword    = sanitize_text_field(wp_unslash($_POST['keyword'] ?? ''));
    $product_id = (int) ($_POST['product_id'] ?? 0);

    if (empty($keyword) || !$product_id) {
        wp_send_json_error('Keyword and product ID are required.');
    }

    $meta_key = defined('SEOM_FEATURED_PRODUCT_META_KEY')
        ? SEOM_FEATURED_PRODUCT_META_KEY
        : '_seom_featured_product_id';

    $like = '%' . $wpdb->esc_like($keyword) . '%';

    // Return up to 100 matches — no pagination needed.
    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT DISTINCT p.ID, p.post_title, p.post_date, p.post_modified
        FROM {$wpdb->posts} p
        LEFT JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = %s
        WHERE p.post_type = 'post'
        AND p.post_status = 'publish'
        AND (pm.meta_value IS NULL OR pm.meta_value = '' OR pm.meta_value = '0')
        AND (p.post_title LIKE %s OR SUBSTRING(p.post_content, 1, 2000) LIKE %s)
        ORDER BY p.post_modified DESC
        LIMIT 100
    ", $meta_key, $like, $like));
    $total = count($rows);

    $results = [];
    foreach ($rows as $r) {
        $results[] = [
            'id'       => (int) $r->ID,
            'title'    => $r->post_title,
            'date'     => date('M j, Y', strtotime($r->post_modified)),
            'edit_url' => get_edit_post_link((int) $r->ID, ''),
            'view_url' => get_permalink((int) $r->ID),
        ];
    }

    wp_send_json_success([
        'results'    => $results,
        'total'      => $total,
        'keyword'    => $keyword,
        'product_id' => $product_id,
    ]);
});

// Bulk-assign a featured product to blog posts — processes a batch of
// post IDs per call and returns progress so the JS can loop until done.
add_action('wp_ajax_aipm_assign_featured_product', function () {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    $post_ids   = array_filter(array_map('intval', (array) ($_POST['post_ids'] ?? [])));

    if (!$product_id || empty($post_ids)) {
        wp_send_json_error('Product ID and at least one post ID are required.');
    }

    $product = get_post($product_id);
    if (!$product || $product->post_type !== 'product') {
        wp_send_json_error('Invalid product.');
    }

    $meta_key = defined('SEOM_FEATURED_PRODUCT_META_KEY')
        ? SEOM_FEATURED_PRODUCT_META_KEY
        : '_seom_featured_product_id';

    $assigned = 0;
    $failed   = 0;

    foreach ($post_ids as $pid) {
        $post = get_post($pid);
        if (!$post || $post->post_type !== 'post') { $failed++; continue; }

        update_post_meta($pid, $meta_key, $product_id);

        if (function_exists('seom_inject_featured_product_shortcode')) {
            $new_body = seom_inject_featured_product_shortcode($post->post_content, $product_id);
            if ($new_body !== $post->post_content) {
                // Preserve the original modified date — injecting a shortcode
                // is not a content edit and should not bump the post to the
                // top of date-sorted archives.
                $orig_modified     = $post->post_modified;
                $orig_modified_gmt = $post->post_modified_gmt;
                wp_update_post([
                    'ID'                => $pid,
                    'post_content'      => $new_body,
                    'post_modified'     => $orig_modified,
                    'post_modified_gmt' => $orig_modified_gmt,
                ]);
            }
        }

        $assigned++;
    }

    wp_send_json_success([
        'assigned'   => $assigned,
        'failed'     => $failed,
        'product_id' => $product_id,
    ]);
});

// List blogs currently assigned to a product as featured product.
add_action('wp_ajax_aipm_get_assigned_blogs', function () {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if (!$product_id) wp_send_json_error('Missing product ID.');

    $meta_key = defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id';

    $rows = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID, p.post_title, p.post_modified
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
        WHERE pm.meta_key = %s AND pm.meta_value = %s
        AND p.post_type = 'post' AND p.post_status = 'publish'
        ORDER BY p.post_modified DESC
    ", $meta_key, (string) $product_id));

    $blogs = [];
    foreach ($rows as $r) {
        $blogs[] = [
            'id'       => (int) $r->ID,
            'title'    => $r->post_title,
            'date'     => date('M j, Y', strtotime($r->post_modified)),
            'edit_url' => get_edit_post_link((int) $r->ID, ''),
            'view_url' => get_permalink((int) $r->ID),
        ];
    }

    wp_send_json_success(['blogs' => $blogs, 'product_id' => $product_id]);
});

// Unassign the featured product from a blog post — removes the meta AND
// strips the shortcode from the post body.
add_action('wp_ajax_aipm_unassign_featured_product', function () {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $post_ids   = array_filter(array_map('intval', (array) ($_POST['post_ids'] ?? [])));
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if (empty($post_ids)) wp_send_json_error('No posts selected.');

    $meta_key = defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id';
    $removed = 0;

    foreach ($post_ids as $pid) {
        $post = get_post($pid);
        if (!$post) continue;

        // Remove the meta
        delete_post_meta($pid, $meta_key);

        // Strip the shortcode from the body — preserve original modified date
        $new_body = preg_replace('/\[itu_featured_product[^\]]*\]\s*/', '', $post->post_content);
        if ($new_body !== $post->post_content) {
            wp_update_post([
                'ID'                => $pid,
                'post_content'      => $new_body,
                'post_modified'     => $post->post_modified,
                'post_modified_gmt' => $post->post_modified_gmt,
            ]);
        }
        $removed++;
    }

    wp_send_json_success(['removed' => $removed]);
});

// Reassign blogs from one product to another — updates the meta and swaps
// the shortcode ID in the post body.
add_action('wp_ajax_aipm_reassign_featured_product', function () {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $post_ids       = array_filter(array_map('intval', (array) ($_POST['post_ids'] ?? [])));
    $new_product_id = (int) ($_POST['new_product_id'] ?? 0);
    if (empty($post_ids) || !$new_product_id) wp_send_json_error('Missing required fields.');

    $new_product = get_post($new_product_id);
    if (!$new_product || $new_product->post_type !== 'product') wp_send_json_error('Invalid new product.');

    $meta_key = defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id';
    $reassigned = 0;

    foreach ($post_ids as $pid) {
        $post = get_post($pid);
        if (!$post) continue;

        // Update the meta
        update_post_meta($pid, $meta_key, $new_product_id);

        // Replace any existing shortcode with the new product ID — preserve modified date
        if (function_exists('seom_inject_featured_product_shortcode')) {
            $new_body = seom_inject_featured_product_shortcode($post->post_content, $new_product_id);
            if ($new_body !== $post->post_content) {
                wp_update_post([
                    'ID'                => $pid,
                    'post_content'      => $new_body,
                    'post_modified'     => $post->post_modified,
                    'post_modified_gmt' => $post->post_modified_gmt,
                ]);
            }
        }
        $reassigned++;
    }

    wp_send_json_success([
        'reassigned'     => $reassigned,
        'new_product_id' => $new_product_id,
        'new_product'    => $new_product->post_title,
    ]);
});

// ─── AJAX Handlers ───────────────────────────────────────────────────────────

add_action('wp_ajax_aipm_set_image', 'aipm_ajax_set_image');
function aipm_ajax_set_image() {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $post_id = intval($_POST['post_id'] ?? 0);
    $image_id = intval($_POST['image_id'] ?? 0);

    if (!$post_id) wp_send_json_error('Missing post ID.');

    if ($image_id) {
        set_post_thumbnail($post_id, $image_id);
        $thumb_url = wp_get_attachment_image_url($image_id, 'thumbnail');
    } else {
        delete_post_thumbnail($post_id);
        $thumb_url = '';
    }

    wp_send_json_success(['thumb_url' => $thumb_url]);
}

add_action('wp_ajax_aipm_save_title', 'aipm_ajax_save_title');
function aipm_ajax_save_title() {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied.');
    }

    $post_id = intval($_POST['post_id'] ?? 0);
    $title   = sanitize_text_field($_POST['title'] ?? '');

    if (!$post_id || empty($title)) {
        wp_send_json_error('Missing post ID or title.');
    }

    wp_update_post(['ID' => $post_id, 'post_title' => $title]);

    // Also update the slug to match the new title
    $new_slug = wp_unique_post_slug(sanitize_title($title), $post_id, 'publish', 'product', 0);
    wp_update_post(['ID' => $post_id, 'post_name' => $new_slug]);

    wp_send_json_success(['title' => $title]);
}

add_action('wp_ajax_aipm_process_step', 'aipm_ajax_process_step');
function aipm_ajax_process_step() {
    check_ajax_referer('aipm_nonce', 'nonce');

    if (!current_user_can('manage_woocommerce')) {
        wp_send_json_error('Permission denied.');
    }

    // Release session lock so other admin pages aren't blocked during OpenAI calls
    aipm_release_session_lock();

    $post_id = intval($_POST['post_id'] ?? 0);
    $step    = intval($_POST['step'] ?? 0);

    if (!$post_id || !$step) {
        wp_send_json_error('Missing post ID or step.');
    }

    switch ($step) {
        case 1:
            $result = aipm_step_description($post_id);
            if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
            wp_send_json_success(['step' => 1, 'description' => $result]);
            break;

        case 2:
            $desc = sanitize_textarea_field($_POST['description'] ?? '');
            $result = aipm_step_short_description($post_id, $desc);
            if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
            wp_send_json_success(['step' => 2, 'short_description' => $result]);
            break;

        case 3:
            $result = aipm_step_faq_html($post_id);
            if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
            wp_send_json_success(['step' => 3, 'faq_html' => $result]);
            break;

        case 4:
            $faq_html = $_POST['faq_html'] ?? '';
            $result = aipm_step_faq_json($post_id, $faq_html);
            if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
            wp_send_json_success(['step' => 4, 'faq_json' => $result]);
            break;

        case 5:
            $result = aipm_step_rankmath($post_id);
            if (is_wp_error($result)) wp_send_json_error($result->get_error_message());
            wp_send_json_success(['step' => 5, 'keyword' => $result['keyword'], 'meta_description' => $result['meta_description']]);
            break;

        case 6:
            $result = aipm_step_timestamp($post_id);

            // Return updated status flags so the UI can refresh checkmarks
            $faq = '';
            if (function_exists('get_field')) {
                $faq = get_field('field_6816a44480234', $post_id);
            }
            $status = [
                'has_description' => !empty(trim(strip_tags(get_post_field('post_content', $post_id)))),
                'has_image'       => has_post_thumbnail($post_id),
                'has_short_desc'  => !empty(trim(get_post_field('post_excerpt', $post_id))),
                'has_faq'         => !empty(trim(strip_tags($faq))),
                'last_edited'     => get_the_modified_date('Y-m-d H:i', $post_id),
            ];
            wp_send_json_success(['step' => 6, 'timestamp' => $result, 'status' => $status]);
            break;

        default:
            wp_send_json_error('Invalid step.');
    }
}

// ─── Product List Table ──────────────────────────────────────────────────────

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class AIPM_Product_List_Table extends WP_List_Table {

    public function __construct() {
        parent::__construct([
            'singular' => 'product',
            'plural'   => 'products',
            'ajax'     => false,
        ]);
    }

    public function get_columns() {
        return [
            'cb'             => '<input type="checkbox" />',
            'thumbnail'      => 'Image',
            'product_name'   => 'Product Name',
            'last_edited'    => 'Last Edited',
            'has_description'=> 'Description',
            'has_image'      => 'Image',
            'has_short_desc' => 'Short Desc',
            'has_faq'        => 'FAQ',
            'blog_count'     => 'Blogs',
            'actions'        => 'Actions',
        ];
    }

    public function get_sortable_columns() {
        return [
            'product_name' => ['title', false],
            'last_edited'  => ['modified', true],
        ];
    }

    public function get_bulk_actions() {
        return [
            'process_all' => 'Process All (AI Generate)',
        ];
    }

    public function column_cb($item) {
        return '<input type="checkbox" name="product_ids[]" value="' . esc_attr($item['ID']) . '" />';
    }

    public function prepare_items() {
        global $wpdb;
        $per_page     = 20;
        $current_page = $this->get_pagenum();
        $search       = isset($_REQUEST['s']) ? sanitize_text_field($_REQUEST['s']) : '';
        $orderby      = isset($_REQUEST['orderby']) ? sanitize_text_field($_REQUEST['orderby']) : 'modified';
        $order        = isset($_REQUEST['order']) ? sanitize_text_field($_REQUEST['order']) : 'DESC';

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $current_page,
            'orderby'        => $orderby,
            'order'          => $order,
        ];

        if ($search) {
            $args['s'] = $search;
        }

        $query = new WP_Query($args);
        $items = [];

        foreach ($query->posts as $post) {
            $faq = '';
            if (function_exists('get_field')) {
                $faq = get_field('field_6816a44480234', $post->ID);
            }

            $thumb_id  = get_post_thumbnail_id($post->ID);
            $thumb_url = $thumb_id ? wp_get_attachment_image_url($thumb_id, 'thumbnail') : '';

            // Count blogs featuring this product
            $fp_meta_key = defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id';
            $blog_count = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} pm
                 JOIN {$wpdb->posts} p ON pm.post_id = p.ID
                 WHERE pm.meta_key = %s AND pm.meta_value = %s
                 AND p.post_type = 'post' AND p.post_status = 'publish'",
                $fp_meta_key, (string) $post->ID
            ));

            $items[] = [
                'ID'              => $post->ID,
                'product_name'    => $post->post_title,
                'last_edited'     => get_the_modified_date('Y-m-d H:i', $post->ID),
                'has_description' => !empty(trim(strip_tags($post->post_content))),
                'has_image'       => has_post_thumbnail($post->ID),
                'has_short_desc'  => !empty(trim($post->post_excerpt)),
                'has_faq'         => !empty(trim(strip_tags($faq))),
                'thumb_url'       => $thumb_url,
                'thumb_id'        => $thumb_id,
                'blog_count'      => $blog_count,
            ];
        }

        $this->items = $items;

        $this->set_pagination_args([
            'total_items' => $query->found_posts,
            'per_page'    => $per_page,
            'total_pages' => ceil($query->found_posts / $per_page),
        ]);

        $this->_column_headers = [
            $this->get_columns(),
            [],
            $this->get_sortable_columns(),
        ];
    }

    private function check_icon($val) {
        return $val
            ? '<span style="color:#16a34a;font-size:18px;" title="Yes">&#10003;</span>'
            : '<span style="color:#dc2626;font-size:18px;" title="No">&#10007;</span>';
    }

    public function column_default($item, $column_name) {
        switch ($column_name) {
            case 'thumbnail':
                $img = $item['thumb_url']
                    ? '<img src="' . esc_url($item['thumb_url']) . '" alt="" class="aipm-thumb" />'
                    : '<span class="aipm-thumb aipm-thumb--empty">No image</span>';
                return '<div class="aipm-thumb-wrap aipm-change-image" data-id="' . esc_attr($item['ID']) . '" title="Click to change image" style="cursor:pointer;">' . $img . '</div>';
            case 'product_name':
                return '<span class="aipm-title-display" data-id="' . esc_attr($item['ID']) . '">'
                     . '<strong class="aipm-title-text">' . esc_html($item['product_name']) . '</strong>'
                     . '<button type="button" class="aipm-title-edit-btn" title="Edit title">&#9998;</button>'
                     . '</span>'
                     . '<span class="aipm-title-editor" data-id="' . esc_attr($item['ID']) . '" style="display:none;">'
                     . '<input type="text" class="aipm-title-input" value="' . esc_attr($item['product_name']) . '" />'
                     . '<button type="button" class="button button-small aipm-title-save">Save</button>'
                     . '<button type="button" class="button button-small aipm-title-cancel">Cancel</button>'
                     . '</span>'
                     . '<div class="aipm-row-status" data-id="' . esc_attr($item['ID']) . '"></div>';
            case 'last_edited':
                return esc_html($item['last_edited']);
            case 'has_description':
                return $this->check_icon($item['has_description']);
            case 'has_image':
                return $this->check_icon($item['has_image']);
            case 'has_short_desc':
                return $this->check_icon($item['has_short_desc']);
            case 'has_faq':
                return $this->check_icon($item['has_faq']);
            case 'blog_count':
                $count = (int) $item['blog_count'];
                if ($count === 0) return '<span style="color:#94a3b8;">0</span>';
                return '<a href="#" class="aipm-view-blogs-btn" data-id="' . esc_attr($item['ID']) . '" data-title="' . esc_attr($item['product_name']) . '" style="font-weight:600;color:#C026D3;text-decoration:none;">' . $count . '</a>';
            case 'actions':
                $edit_url = get_edit_post_link($item['ID']);
                $view_url = get_permalink($item['ID']);
                return '<button type="button" class="button aipm-process-btn" data-id="' . esc_attr($item['ID']) . '">Process All</button> '
                     . '<button type="button" class="button aipm-find-blogs-btn" data-id="' . esc_attr($item['ID']) . '" data-title="' . esc_attr($item['product_name']) . '" style="color:#C026D3;">Find Blogs</button> '
                     . '<a href="' . esc_url($edit_url) . '" class="button" target="_blank">Edit</a> '
                     . '<a href="' . esc_url($view_url) . '" class="button" target="_blank">View</a>';
            default:
                return '';
        }
    }
}

// ─── Admin Menu & Page ───────────────────────────────────────────────────────

add_action('admin_menu', function () {
    // Register as a submenu under SEO AI AutoPilot when that plugin is active.
    // Falls back to a top-level menu only if SEO AI AutoPilot isn't around.
    $parent_slug = (defined('SEOM_PATH')) ? 'seo-ai-autopilot' : null;

    if ($parent_slug) {
        add_submenu_page(
            $parent_slug,
            'AI Product Manager',
            'AI Products',
            'manage_woocommerce',
            'ai-product-manager',
            'aipm_render_admin_page'
        );
        add_submenu_page(
            $parent_slug,
            'Media Cleanup',
            'Media Cleanup',
            'manage_woocommerce',
            'aipm-media-cleanup',
            'aipm_render_media_page'
        );
    } else {
        // Standalone fallback — keep the old top-level menu so this plugin
        // still works if SEO AI AutoPilot is deactivated
        add_menu_page(
            'AI Product Manager',
            'AI Products',
            'manage_woocommerce',
            'ai-product-manager',
            'aipm_render_admin_page',
            'dashicons-products',
            56
        );
        add_submenu_page(
            'ai-product-manager',
            'Media Cleanup',
            'Media Cleanup',
            'manage_woocommerce',
            'aipm-media-cleanup',
            'aipm_render_media_page'
        );
    }
}, 11); // priority 11 so SEO AI AutoPilot's menu (default 10) is registered first

function aipm_render_admin_page() {
    wp_enqueue_media(); // Required for the media picker
    $api_key = get_option('aicg_api_key');
    $table   = new AIPM_Product_List_Table();
    $table->prepare_items();
    $nonce = wp_create_nonce('aipm_nonce');
    ?>
    <div class="wrap">
        <h1>AI Product Manager</h1>

        <?php if (empty($api_key)) : ?>
            <div class="notice notice-error"><p><strong>OpenAI API key not set.</strong> Configure it under <a href="<?php echo admin_url('options-general.php?page=aicg'); ?>">Settings &rarr; AI Content Generator</a>.</p></div>
        <?php endif; ?>

        <form method="get">
            <input type="hidden" name="page" value="ai-product-manager" />
            <?php
            $table->search_box('Search Products', 'aipm-search');
            $table->display();
            ?>
        </form>
    </div>

    <style>
        .aipm-row-status {
            font-size: 12px;
            color: #666;
            margin-top: 4px;
            min-height: 18px;
        }
        .aipm-row-status.pending    { color: #6b7280; font-style: italic; }
        .aipm-row-status.processing { color: #b45309; }
        .aipm-row-status.done       { color: #16a34a; }
        .aipm-row-status.error      { color: #dc2626; }
        .wp-list-table .column-cb           { width: 30px; }
        .wp-list-table .column-thumbnail    { width: 60px; }
        .aipm-thumb { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; display: block; }
        .aipm-thumb--empty {
            display: flex; align-items: center; justify-content: center;
            width: 50px; height: 50px; background: #f0f0f0; border: 1px dashed #ccc;
            border-radius: 4px; font-size: 10px; color: #999; text-align: center; line-height: 1.2;
        }
        .aipm-change-image { position: relative; }
        .aipm-change-image::after {
            content: '✎'; position: absolute; bottom: 0; right: 0;
            background: rgba(0,0,0,0.6); color: #fff; font-size: 11px;
            width: 18px; height: 18px; line-height: 18px; text-align: center;
            border-radius: 4px 0 4px 0; opacity: 0; transition: opacity 0.15s;
        }
        .aipm-change-image:hover::after { opacity: 1; }
        .aipm-change-image:hover .aipm-thumb { opacity: 0.8; }
        .aipm-change-image:hover .aipm-thumb--empty { border-color: #2271b1; color: #2271b1; }
        .wp-list-table .column-has_description,
        .wp-list-table .column-has_image,
        .wp-list-table .column-has_short_desc,
        .wp-list-table .column-has_faq      { width: 80px; text-align: center; }
        .wp-list-table .column-last_edited  { width: 140px; }
        .wp-list-table .column-blog_count   { width: 60px; text-align: center; }
        .wp-list-table .column-actions      { width: 340px; }
        .aipm-bulk-progress {
            margin: 16px 0;
            padding: 12px 16px;
            background: #fff;
            border: 1px solid #c3c4c7;
            border-left: 4px solid #2271b1;
            display: none;
        }
        .aipm-bulk-progress .progress-text { font-weight: 600; }
        .aipm-bulk-progress .progress-detail { color: #666; margin-top: 4px; font-size: 13px; }
        .aipm-title-display { display: inline-flex; align-items: center; gap: 6px; }
        .aipm-title-edit-btn {
            background: none; border: none; cursor: pointer; color: #999;
            font-size: 14px; padding: 0; line-height: 1; visibility: hidden;
        }
        tr:hover .aipm-title-edit-btn { visibility: visible; }
        .aipm-title-edit-btn:hover { color: #2271b1; }
        .aipm-title-editor { display: flex; align-items: center; gap: 4px; }
        .aipm-title-input {
            width: 100%; padding: 4px 8px; font-size: 13px; font-weight: 600;
        }
    </style>

    <script>
    jQuery(document).ready(function($) {
        var nonce = '<?php echo $nonce; ?>';
        var stepLabels = {
            1: 'Generating Description...',
            2: 'Generating Short Description...',
            3: 'Generating FAQ HTML...',
            4: 'Generating FAQ JSON-LD...',
            5: 'Updating RankMath SEO...',
            6: 'Saving timestamp...'
        };
        var isProcessing = false;

        var checkYes = '<span style="color:#16a34a;font-size:18px;" title="Yes">&#10003;</span>';
        var checkNo  = '<span style="color:#dc2626;font-size:18px;" title="No">&#10007;</span>';

        function setRowStatus(id, text, cls) {
            var el = $('.aipm-row-status[data-id="' + id + '"]');
            el.text(text).removeClass('pending processing done error').addClass(cls);
        }

        function updateRowChecks(id, status) {
            var row = $('input[name="product_ids[]"][value="' + id + '"]').closest('tr');
            row.find('.column-has_description').html(status.has_description ? checkYes : checkNo);
            row.find('.column-has_image').html(status.has_image ? checkYes : checkNo);
            row.find('.column-has_short_desc').html(status.has_short_desc ? checkYes : checkNo);
            row.find('.column-has_faq').html(status.has_faq ? checkYes : checkNo);
            row.find('.column-last_edited').text(status.last_edited);
        }

        function processProduct(productId) {
            return new Promise(function(resolve, reject) {
                var prevData = {};

                function runStep(step) {
                    if (step > 6) {
                        setRowStatus(productId, '&#10003; Complete', 'done');
                        // Use html() since we have an entity
                        $('.aipm-row-status[data-id="' + productId + '"]').html('&#10003; Complete').removeClass('processing error').addClass('done');
                        resolve();
                        return;
                    }

                    setRowStatus(productId, 'Step ' + step + '/6: ' + stepLabels[step], 'processing');

                    var postData = {
                        action: 'aipm_process_step',
                        nonce: nonce,
                        post_id: productId,
                        step: step
                    };

                    // Pass data from previous steps
                    if (step === 2 && prevData.description) postData.description = prevData.description;
                    if (step === 4 && prevData.faq_html) postData.faq_html = prevData.faq_html;

                    $.post(ajaxurl, postData, function(resp) {
                        if (!resp.success) {
                            setRowStatus(productId, '&#10007; Failed at step ' + step + ': ' + (resp.data || 'Unknown error'), 'error');
                            $('.aipm-row-status[data-id="' + productId + '"]').html('&#10007; Failed at step ' + step).removeClass('processing').addClass('error');
                            reject(resp.data);
                            return;
                        }

                        // Store output for dependent steps
                        if (step === 1) prevData.description = resp.data.description;
                        if (step === 3) prevData.faq_html = resp.data.faq_html;

                        // After final step, update the checkmark columns
                        if (step === 6 && resp.data.status) {
                            updateRowChecks(productId, resp.data.status);
                        }

                        runStep(step + 1);
                    }).fail(function(xhr) {
                        setRowStatus(productId, '&#10007; Server error at step ' + step, 'error');
                        $('.aipm-row-status[data-id="' + productId + '"]').html('&#10007; Server error at step ' + step).removeClass('processing').addClass('error');
                        reject('Server error');
                    });
                }

                runStep(1);
            });
        }

        // Change product image via media picker
        $(document).on('click', '.aipm-change-image', function() {
            var wrap = $(this);
            var postId = wrap.data('id');

            var frame = wp.media({
                title: 'Select Product Image',
                button: { text: 'Set as Product Image' },
                multiple: false,
                library: { type: 'image' }
            });

            frame.on('select', function() {
                var attachment = frame.state().get('selection').first().toJSON();
                $.post(ajaxurl, {
                    action: 'aipm_set_image',
                    nonce: nonce,
                    post_id: postId,
                    image_id: attachment.id
                }, function(resp) {
                    if (resp.success && resp.data.thumb_url) {
                        wrap.html('<img src="' + resp.data.thumb_url + '" alt="" class="aipm-thumb" />');
                        // Update the Image checkmark column
                        wrap.closest('tr').find('.column-has_image').html(checkYes);
                    }
                });
            });

            frame.open();
        });

        // Inline title editing
        $(document).on('click', '.aipm-title-edit-btn', function() {
            var display = $(this).closest('.aipm-title-display');
            var editor = display.next('.aipm-title-editor');
            display.hide();
            editor.show().find('.aipm-title-input').focus().select();
        });

        $(document).on('click', '.aipm-title-cancel', function() {
            var editor = $(this).closest('.aipm-title-editor');
            var display = editor.prev('.aipm-title-display');
            editor.hide();
            display.show();
        });

        $(document).on('keydown', '.aipm-title-input', function(e) {
            if (e.key === 'Enter') {
                $(this).closest('.aipm-title-editor').find('.aipm-title-save').click();
            } else if (e.key === 'Escape') {
                $(this).closest('.aipm-title-editor').find('.aipm-title-cancel').click();
            }
        });

        $(document).on('click', '.aipm-title-save', function() {
            var editor = $(this).closest('.aipm-title-editor');
            var id = editor.data('id');
            var newTitle = editor.find('.aipm-title-input').val().trim();
            var display = editor.prev('.aipm-title-display');

            if (!newTitle) return;

            var saveBtn = $(this);
            saveBtn.prop('disabled', true).text('Saving...');

            $.post(ajaxurl, {
                action: 'aipm_save_title',
                nonce: nonce,
                post_id: id,
                title: newTitle
            }, function(resp) {
                if (resp.success) {
                    display.find('.aipm-title-text').text(resp.data.title);
                    editor.hide();
                    display.show();
                } else {
                    alert('Failed to save: ' + (resp.data || 'Unknown error'));
                }
                saveBtn.prop('disabled', false).text('Save');
            }).fail(function() {
                alert('Server error saving title.');
                saveBtn.prop('disabled', false).text('Save');
            });
        });

        // Single row "Process All" button
        $(document).on('click', '.aipm-process-btn', function() {
            if (isProcessing) {
                alert('A process is already running. Please wait.');
                return;
            }
            var btn = $(this);
            var id = btn.data('id');
            btn.prop('disabled', true);
            isProcessing = true;

            processProduct(id).then(function() {
                btn.prop('disabled', false);
                isProcessing = false;
            }).catch(function() {
                btn.prop('disabled', false);
                isProcessing = false;
            });
        });

        // Bulk action: Process All checked products
        $('#doaction, #doaction2').on('click', function(e) {
            var sel = $(this).prev('select').val();
            if (sel !== 'process_all') return;

            e.preventDefault();

            if (isProcessing) {
                alert('A process is already running. Please wait.');
                return;
            }

            var ids = [];
            $('input[name="product_ids[]"]:checked').each(function() {
                ids.push(parseInt($(this).val()));
            });

            if (ids.length === 0) {
                alert('Please select at least one product.');
                return;
            }

            if (!confirm('Process ' + ids.length + ' product(s)? This will regenerate all AI content for each selected product.')) {
                return;
            }

            isProcessing = true;
            $('.aipm-process-btn').prop('disabled', true);

            // Mark all selected products as Pending
            ids.forEach(function(id) {
                setRowStatus(id, 'Pending...', 'pending');
            });

            // Show bulk progress bar
            var $progress = $('.aipm-bulk-progress');
            if ($progress.length === 0) {
                $progress = $('<div class="aipm-bulk-progress"><div class="progress-text"></div><div class="progress-detail"></div></div>');
                $('.wp-list-table').before($progress);
            }
            $progress.show();

            var completed = 0;
            var failed = 0;

            function processNext(index) {
                if (index >= ids.length) {
                    $progress.find('.progress-text').text('Bulk processing complete: ' + completed + ' succeeded, ' + failed + ' failed out of ' + ids.length + ' products.');
                    $progress.find('.progress-detail').text('');
                    isProcessing = false;
                    $('.aipm-process-btn').prop('disabled', false);
                    return;
                }

                var id = ids[index];
                var name = $('input[name="product_ids[]"][value="' + id + '"]').closest('tr').find('.column-product_name strong').text();
                $progress.find('.progress-text').text('Processing ' + (index + 1) + ' of ' + ids.length + '...');
                $progress.find('.progress-detail').text(name);

                processProduct(id).then(function() {
                    completed++;
                    processNext(index + 1);
                }).catch(function() {
                    failed++;
                    processNext(index + 1);
                });
            }

            processNext(0);
        });

        // ── View Assigned Blogs modal ──────────────────────────────────
        // Opens when user clicks the blog count number. Shows all blogs
        // assigned to this product with options to unassign or reassign
        // to a different product.

        $('body').append(
            '<div id="aipm-assigned-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;overflow:auto;">'
            + '<div style="max-width:800px;margin:60px auto;background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">'
            +   '<div style="padding:18px 24px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">'
            +     '<div>'
            +       '<h3 style="margin:0;font-size:16px;">Assigned Blogs</h3>'
            +       '<span style="font-size:12px;color:#64748b;" id="aipm-ab-product-name"></span>'
            +     '</div>'
            +     '<button type="button" id="aipm-ab-close" style="border:none;background:none;font-size:22px;cursor:pointer;color:#64748b;padding:0 4px;">&times;</button>'
            +   '</div>'
            +   '<div style="padding:20px 24px;">'
            +     '<div id="aipm-ab-loading" style="color:#64748b;">Loading…</div>'
            +     '<div id="aipm-ab-results" style="display:none;max-height:400px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;">'
            +       '<table class="wp-list-table widefat fixed striped" style="font-size:13px;">'
            +         '<thead><tr><th style="width:30px;"><input type="checkbox" id="aipm-ab-check-all" /></th><th>Blog Post</th><th style="width:120px;">Last Updated</th></tr></thead>'
            +         '<tbody id="aipm-ab-body"></tbody>'
            +       '</table>'
            +     '</div>'
            +     '<div id="aipm-ab-empty" style="display:none;color:#94a3b8;padding:20px;text-align:center;">No blogs assigned to this product.</div>'
            +     '<div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">'
            +       '<span id="aipm-ab-selected-count" style="font-size:12px;color:#64748b;">0 selected</span>'
            +       '<div style="display:flex;gap:8px;align-items:center;flex-wrap:wrap;">'
            +         '<button type="button" class="button" id="aipm-ab-unassign" disabled style="color:#dc2626;">Unassign Selected</button>'
            +         '<span style="color:#94a3b8;">or</span>'
            +         '<select id="aipm-ab-reassign-product" style="max-width:220px;padding:4px 8px;border:1px solid #c3c4c7;border-radius:4px;font-size:13px;"><option value="">Reassign to…</option></select>'
            +         '<button type="button" class="button button-primary" id="aipm-ab-reassign" disabled>Reassign</button>'
            +       '</div>'
            +     '</div>'
            +     '<div id="aipm-ab-status" style="margin-top:10px;font-size:12px;"></div>'
            +   '</div>'
            + '</div></div>'
        );

        var abModal = $('#aipm-assigned-modal');
        var abProductId = 0;

        // Open modal
        $(document).on('click', '.aipm-view-blogs-btn', function(e) {
            e.preventDefault();
            abProductId = $(this).data('id');
            $('#aipm-ab-product-name').text($(this).data('title'));
            loadAssignedBlogs();
            loadProductDropdown();
            abModal.fadeIn(200);
        });

        // Close
        $('#aipm-ab-close').click(function() { abModal.fadeOut(200); });
        abModal.click(function(e) { if (e.target === this) abModal.fadeOut(200); });

        function loadAssignedBlogs() {
            $('#aipm-ab-loading').show();
            $('#aipm-ab-results, #aipm-ab-empty').hide();
            $('#aipm-ab-status').empty();

            $.post(ajaxurl, { action: 'aipm_get_assigned_blogs', nonce: nonce, product_id: abProductId }, function(resp) {
                $('#aipm-ab-loading').hide();
                if (!resp.success) return;
                var blogs = resp.data.blogs;
                if (!blogs.length) { $('#aipm-ab-empty').show(); return; }

                var tb = $('#aipm-ab-body').empty();
                blogs.forEach(function(b) {
                    tb.append('<tr>'
                        + '<td><input type="checkbox" class="aipm-ab-cb" value="' + b.id + '" /></td>'
                        + '<td><a href="' + (b.edit_url || '#') + '" target="_blank">' + $('<span>').text(b.title).html() + '</a></td>'
                        + '<td style="color:#94a3b8;">' + b.date + '</td>'
                        + '</tr>');
                });
                $('#aipm-ab-results').show();
                $('#aipm-ab-check-all').prop('checked', false);
                updateAbCount();
            });
        }

        // Product dropdown for reassignment — loads all products except the current one
        var abProductsLoaded = false;
        function loadProductDropdown() {
            if (abProductsLoaded) {
                // Just remove the current product option
                $('#aipm-ab-reassign-product option[value="' + abProductId + '"]').remove();
                return;
            }
            $.post(ajaxurl, { action: 'seom_search_products', nonce: '<?php echo wp_create_nonce('seom_nonce'); ?>', term: '' }, function(resp) {
                if (!resp.success) return;
                var sel = $('#aipm-ab-reassign-product').empty().append('<option value="">Reassign to…</option>');
                resp.data.products.forEach(function(p) {
                    if (p.id !== abProductId) {
                        sel.append('<option value="' + p.id + '">' + $('<span>').text(p.title).html() + '</option>');
                    }
                });
                abProductsLoaded = true;
            });
        }

        // Select all / count
        $(document).on('change', '#aipm-ab-check-all', function() {
            $('.aipm-ab-cb').prop('checked', this.checked);
            updateAbCount();
        });
        $(document).on('change', '.aipm-ab-cb', function() { updateAbCount(); });

        function updateAbCount() {
            var n = $('.aipm-ab-cb:checked').length;
            $('#aipm-ab-selected-count').text(n + ' selected');
            $('#aipm-ab-unassign').prop('disabled', n === 0);
            $('#aipm-ab-reassign').prop('disabled', n === 0 || !$('#aipm-ab-reassign-product').val());
        }
        $(document).on('change', '#aipm-ab-reassign-product', updateAbCount);

        // Generic batched action runner — splits IDs into chunks of 5,
        // fires them sequentially, and shows live progress in #aipm-ab-status.
        function abBatchAction(actionName, allIds, extraData, verb, onDone) {
            var batchSize = 5;
            var batches = [];
            for (var i = 0; i < allIds.length; i += batchSize) {
                batches.push(allIds.slice(i, i + batchSize));
            }
            var totalDone = 0, totalFail = 0;

            function runNext(idx) {
                if (idx >= batches.length) {
                    onDone(totalDone, totalFail);
                    return;
                }
                var done = Math.min((idx + 1) * batchSize, allIds.length);
                $('#aipm-ab-status').html('<span style="color:#b45309;">' + verb + ' ' + done + ' / ' + allIds.length + '…</span>');

                var payload = $.extend({ action: actionName, nonce: nonce, 'post_ids[]': batches[idx] }, extraData);
                $.post(ajaxurl, payload, function(resp) {
                    if (resp.success) {
                        totalDone += (resp.data.removed || resp.data.reassigned || 0);
                    } else {
                        totalFail += batches[idx].length;
                    }
                    runNext(idx + 1);
                }).fail(function() {
                    totalFail += batches[idx].length;
                    runNext(idx + 1);
                });
            }
            runNext(0);
        }

        // Unassign — batched
        $('#aipm-ab-unassign').click(function() {
            var ids = $('.aipm-ab-cb:checked').map(function() { return parseInt(this.value, 10); }).get();
            if (!ids.length) return;
            if (!confirm('Unassign this product from ' + ids.length + ' blog(s)?\n\nThis removes the featured product meta and strips the shortcode from each post body.')) return;

            $(this).prop('disabled', true);
            abBatchAction('aipm_unassign_featured_product', ids, { product_id: abProductId }, 'Unassigning', function(done, failed) {
                $('#aipm-ab-unassign').prop('disabled', false);
                var msg = '<span style="color:#059669;">Removed from ' + done + ' blog(s).</span>';
                if (failed) msg += ' <span style="color:#dc2626;">' + failed + ' failed.</span>';
                $('#aipm-ab-status').html(msg);
                loadAssignedBlogs();
                var btn = $('.aipm-view-blogs-btn[data-id="' + abProductId + '"]');
                var newCount = Math.max(0, parseInt(btn.text(), 10) - done);
                if (newCount > 0) btn.text(newCount);
                else btn.replaceWith('<span style="color:#94a3b8;">0</span>');
            });
        });

        // Reassign — batched
        $('#aipm-ab-reassign').click(function() {
            var ids = $('.aipm-ab-cb:checked').map(function() { return parseInt(this.value, 10); }).get();
            var newPid = $('#aipm-ab-reassign-product').val();
            var newName = $('#aipm-ab-reassign-product option:selected').text();
            if (!ids.length || !newPid) return;
            if (!confirm('Reassign ' + ids.length + ' blog(s) from this product to "' + newName + '"?\n\nThis updates the featured product meta and swaps the shortcode in each post body.')) return;

            $(this).prop('disabled', true);
            abBatchAction('aipm_reassign_featured_product', ids, { new_product_id: newPid }, 'Reassigning', function(done, failed) {
                $('#aipm-ab-reassign').prop('disabled', false);
                var msg = '<span style="color:#059669;">Reassigned ' + done + ' blog(s) to "' + newName + '".</span>';
                if (failed) msg += ' <span style="color:#dc2626;">' + failed + ' failed.</span>';
                $('#aipm-ab-status').html(msg);
                loadAssignedBlogs();
                var btn = $('.aipm-view-blogs-btn[data-id="' + abProductId + '"]');
                var oldCount = Math.max(0, parseInt(btn.text(), 10) - done);
                if (oldCount > 0) btn.text(oldCount);
                else btn.replaceWith('<span style="color:#94a3b8;">0</span>');
            });
        });

        // ── Find Blog Opportunities modal ──────────────────────────────
        // Opens when user clicks "Find Blogs" on a product row. Searches
        // published blog posts by keyword that don't already have a
        // featured product assigned. Lets the user bulk-assign the
        // current product to selected blogs.

        // Inject the modal HTML into the page
        $('body').append(
            '<div id="aipm-find-blogs-modal" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:100000;overflow:auto;">'
            + '<div style="max-width:800px;margin:60px auto;background:#fff;border-radius:12px;box-shadow:0 20px 60px rgba(0,0,0,0.3);overflow:hidden;">'
            +   '<div style="padding:18px 24px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">'
            +     '<div>'
            +       '<h3 style="margin:0;font-size:16px;" id="aipm-fb-title">Find Blog Opportunities</h3>'
            +       '<span style="font-size:12px;color:#64748b;" id="aipm-fb-product-name"></span>'
            +     '</div>'
            +     '<button type="button" id="aipm-fb-close" style="border:none;background:none;font-size:22px;cursor:pointer;color:#64748b;padding:0 4px;">&times;</button>'
            +   '</div>'
            +   '<div style="padding:20px 24px;">'
            +     '<p style="margin:0 0 12px;font-size:13px;color:#64748b;">Search for blog posts by keyword. Only posts without a featured product are shown. Select the ones you want and assign this product to them.</p>'
            +     '<div style="display:flex;gap:8px;margin-bottom:16px;">'
            +       '<input type="text" id="aipm-fb-keyword" placeholder="Enter keyword (e.g. network+, security, azure)…" style="flex:1;padding:8px 12px;border:1px solid #c3c4c7;border-radius:6px;font-size:14px;" />'
            +       '<button type="button" class="button button-primary" id="aipm-fb-search">Search</button>'
            +     '</div>'
            +     '<input type="hidden" id="aipm-fb-product-id" value="" />'
            +     '<div id="aipm-fb-status" style="font-size:12px;margin-bottom:8px;"></div>'
            +     '<div id="aipm-fb-results" style="display:none;max-height:350px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:6px;">'
            +       '<table class="wp-list-table widefat fixed striped" style="font-size:13px;">'
            +         '<thead><tr><th style="width:30px;"><input type="checkbox" id="aipm-fb-check-all" /></th><th>Blog Post</th><th style="width:120px;">Last Updated</th></tr></thead>'
            +         '<tbody id="aipm-fb-body"></tbody>'
            +       '</table>'
            +     '</div>'
            +     '<div id="aipm-fb-pagination" style="margin-top:8px;text-align:center;"></div>'
            +     '<div style="margin-top:16px;display:flex;justify-content:space-between;align-items:center;">'
            +       '<span id="aipm-fb-selected-count" style="font-size:12px;color:#64748b;">0 selected</span>'
            +       '<div style="display:flex;gap:8px;">'
            +         '<button type="button" class="button" id="aipm-fb-cancel">Cancel</button>'
            +         '<button type="button" class="button button-primary" id="aipm-fb-assign" disabled>Assign Product to Selected</button>'
            +       '</div>'
            +     '</div>'
            +     '<div id="aipm-fb-assign-status" style="margin-top:10px;font-size:12px;"></div>'
            +   '</div>'
            + '</div></div>'
        );

        var fbModal = $('#aipm-find-blogs-modal');
        var fbProductId = 0;

        // Open modal
        $(document).on('click', '.aipm-find-blogs-btn', function() {
            fbProductId = $(this).data('id');
            var productTitle = $(this).data('title');
            $('#aipm-fb-product-id').val(fbProductId);
            $('#aipm-fb-product-name').text(productTitle);
            $('#aipm-fb-keyword').val('');
            $('#aipm-fb-body').empty();
            $('#aipm-fb-results').hide();
            $('#aipm-fb-pagination').empty();
            $('#aipm-fb-status').empty();
            $('#aipm-fb-assign-status').empty();
            $('#aipm-fb-assign').prop('disabled', true);
            $('#aipm-fb-selected-count').text('0 selected');
            fbModal.fadeIn(200);
            setTimeout(function() { $('#aipm-fb-keyword').focus(); }, 100);
        });

        // Close modal
        $('#aipm-fb-close, #aipm-fb-cancel').click(function() { fbModal.fadeOut(200); });
        fbModal.click(function(e) { if (e.target === this) fbModal.fadeOut(200); });

        // Search — returns up to 100 results, no pagination
        function fbSearch() {
            var keyword = $('#aipm-fb-keyword').val().trim();
            if (!keyword) { alert('Enter a keyword to search.'); return; }

            $('#aipm-fb-status').html('<span style="color:#b45309;">Searching…</span>');
            $('#aipm-fb-results').hide();
            $('#aipm-fb-pagination').empty();

            $.post(ajaxurl, {
                action: 'aipm_search_blogs', nonce: nonce,
                keyword: keyword, product_id: fbProductId
            }, function(resp) {
                if (!resp.success) {
                    $('#aipm-fb-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                    return;
                }
                var d = resp.data;
                var cap = d.total >= 100 ? ' (showing first 100)' : '';
                $('#aipm-fb-status').html('<span style="color:#059669;">' + d.total + ' blog(s) found without a featured product.' + cap + '</span>');

                var tb = $('#aipm-fb-body').empty();
                if (!d.results.length) {
                    tb.html('<tr><td colspan="3" style="color:#94a3b8;padding:12px;">No matching posts found.</td></tr>');
                    $('#aipm-fb-results').show();
                    return;
                }

                d.results.forEach(function(r) {
                    tb.append('<tr>'
                        + '<td><input type="checkbox" class="aipm-fb-cb" value="' + r.id + '" /></td>'
                        + '<td><a href="' + (r.edit_url || '#') + '" target="_blank">' + $('<span>').text(r.title).html() + '</a></td>'
                        + '<td style="color:#94a3b8;">' + r.date + '</td>'
                        + '</tr>');
                });

                $('#aipm-fb-results').show();
                $('#aipm-fb-check-all').prop('checked', false);
                updateFbCount();
            });
        }

        $('#aipm-fb-search').click(fbSearch);
        $('#aipm-fb-keyword').keydown(function(e) { if (e.key === 'Enter') { e.preventDefault(); fbSearch(); } });

        // Select all / count
        $(document).on('change', '#aipm-fb-check-all', function() {
            $('.aipm-fb-cb').prop('checked', this.checked);
            updateFbCount();
        });
        $(document).on('change', '.aipm-fb-cb', function() { updateFbCount(); });

        function updateFbCount() {
            var n = $('.aipm-fb-cb:checked').length;
            $('#aipm-fb-selected-count').text(n + ' selected');
            $('#aipm-fb-assign').prop('disabled', n === 0);
        }

        // Assign product to selected blogs — batched in chunks of 5 so
        // we don't timeout on large selections.
        $('#aipm-fb-assign').click(function() {
            var allIds = $('.aipm-fb-cb:checked').map(function() { return parseInt(this.value, 10); }).get();
            if (!allIds.length) return;
            if (!confirm('Assign this product as the Featured Product on ' + allIds.length + ' blog post(s)?\n\nThis will:\n• Save the product association\n• Inject the [itu_featured_product] shortcode into each post body')) return;

            var btn = $(this).prop('disabled', true);
            var totalAssigned = 0, totalFailed = 0;
            var batchSize = 5;
            var batches = [];
            for (var i = 0; i < allIds.length; i += batchSize) {
                batches.push(allIds.slice(i, i + batchSize));
            }

            function runBatch(index) {
                if (index >= batches.length) {
                    btn.prop('disabled', false).text('Assign Product to Selected');
                    $('#aipm-fb-assign-status').html(
                        '<span style="color:#059669;">✓ Assigned to ' + totalAssigned + ' post(s).'
                        + (totalFailed ? ' ' + totalFailed + ' failed.' : '') + '</span>'
                    );
                    setTimeout(fbSearch, 1000);
                    return;
                }
                var done = Math.min((index + 1) * batchSize, allIds.length);
                $('#aipm-fb-assign-status').html('<span style="color:#b45309;">Assigning ' + done + ' / ' + allIds.length + '…</span>');

                $.post(ajaxurl, {
                    action: 'aipm_assign_featured_product', nonce: nonce,
                    product_id: fbProductId, 'post_ids[]': batches[index]
                }, function(resp) {
                    if (resp.success) {
                        totalAssigned += resp.data.assigned;
                        totalFailed += resp.data.failed;
                    }
                    runBatch(index + 1);
                }).fail(function() {
                    totalFailed += batches[index].length;
                    runBatch(index + 1);
                });
            }
            runBatch(0);
        });
    });
    </script>
    <?php
}

// ─── Media Cleanup ───────────────────────────────────────────────────────────

add_action('wp_ajax_aipm_scan_media', 'aipm_ajax_scan_media');
function aipm_ajax_scan_media() {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    aipm_release_session_lock();

    $upload_dir = wp_upload_dir();
    $base_dir   = $upload_dir['basedir'];
    $base_url   = $upload_dir['baseurl'];
    $extensions  = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    // Get all registered attachment file paths from the DB
    global $wpdb;
    $registered = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file'");
    $registered_map = array_flip($registered);

    // Also collect all known generated thumbnail files
    $thumb_files = [];
    $meta_rows = $wpdb->get_col("SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attachment_metadata'");
    foreach ($meta_rows as $raw) {
        $meta = maybe_unserialize($raw);
        if (!is_array($meta) || empty($meta['sizes'])) continue;
        $dir = dirname($meta['file'] ?? '');
        foreach ($meta['sizes'] as $size) {
            if (!empty($size['file'])) {
                $thumb_files[$dir . '/' . $size['file']] = true;
            }
        }
    }

    // Scan only WordPress year/month upload directories (e.g., 2024/03)
    $unregistered = [];
    $base_dir_normalized = str_replace('\\', '/', $base_dir);
    $year_dirs = glob($base_dir . '/[0-9][0-9][0-9][0-9]', GLOB_ONLYDIR);

    foreach ($year_dirs as $year_dir) {
        $month_dirs = glob($year_dir . '/[0-9][0-9]', GLOB_ONLYDIR);
        foreach ($month_dirs as $month_dir) {
            $iterator = new DirectoryIterator($month_dir);
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                $ext = strtolower($file->getExtension());
                if (!in_array($ext, $extensions)) continue;

                $full_path = str_replace('\\', '/', $file->getPathname());
                $relative  = str_replace($base_dir_normalized . '/', '', $full_path);

                // Skip WP-generated thumbnails (pattern: filename-123x456.ext)
                if (preg_match('/-\d+x\d+\.\w+$/', $relative)) continue;

                // Skip if already registered or is a known thumbnail
                if (isset($registered_map[$relative])) continue;
                if (isset($thumb_files[$relative])) continue;

                $unregistered[] = [
                    'path'     => $relative,
                    'url'      => $base_url . '/' . $relative,
                    'filename' => basename($relative),
                    'size'     => $file->getSize(),
                ];
            }
        }
    }

    // Sort by filename
    usort($unregistered, function($a, $b) { return strcasecmp($a['filename'], $b['filename']); });

    wp_send_json_success([
        'total_registered'   => count($registered),
        'total_unregistered' => count($unregistered),
        'files'              => $unregistered,
    ]);
}

add_action('wp_ajax_aipm_register_image', 'aipm_ajax_register_image');
function aipm_ajax_register_image() {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    aipm_release_session_lock();

    $relative_path = sanitize_text_field($_POST['path'] ?? '');
    if (empty($relative_path)) wp_send_json_error('Missing file path.');

    $upload_dir = wp_upload_dir();
    $full_path  = $upload_dir['basedir'] . '/' . $relative_path;

    if (!file_exists($full_path)) wp_send_json_error('File not found: ' . $relative_path);

    $filetype = wp_check_filetype(basename($full_path));
    if (empty($filetype['type'])) wp_send_json_error('Invalid file type.');

    $title = preg_replace('/\.[^.]+$/', '', basename($full_path));
    $title = str_replace(['-', '_'], ' ', $title);
    $title = ucwords($title);

    $attachment = [
        'guid'           => $upload_dir['baseurl'] . '/' . $relative_path,
        'post_mime_type' => $filetype['type'],
        'post_title'     => $title,
        'post_content'   => '',
        'post_status'    => 'inherit',
    ];

    $attach_id = wp_insert_attachment($attachment, $full_path);
    if (is_wp_error($attach_id)) wp_send_json_error($attach_id->get_error_message());

    require_once ABSPATH . 'wp-admin/includes/image.php';
    $metadata = wp_generate_attachment_metadata($attach_id, $full_path);
    wp_update_attachment_metadata($attach_id, $metadata);

    $thumb_url = wp_get_attachment_image_url($attach_id, 'thumbnail');

    wp_send_json_success([
        'id'        => $attach_id,
        'title'     => $title,
        'thumb_url' => $thumb_url,
    ]);
}

add_action('wp_ajax_aipm_delete_image', 'aipm_ajax_delete_image');
function aipm_ajax_delete_image() {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $path = sanitize_text_field($_POST['path'] ?? '');
    if (empty($path)) wp_send_json_error('Missing file path.');

    $upload_dir = wp_upload_dir();
    $full_path  = $upload_dir['basedir'] . '/' . $path;

    if (!file_exists($full_path)) wp_send_json_error('File not found.');

    // Safety: only delete from uploads directory
    $real_base = realpath($upload_dir['basedir']);
    $real_file = realpath($full_path);
    if (strpos($real_file, $real_base) !== 0) wp_send_json_error('Invalid path.');

    unlink($full_path);
    wp_send_json_success();
}

// Get available years for the unused images year filter
add_action('wp_ajax_aipm_get_image_years', 'aipm_ajax_get_image_years');
function aipm_ajax_get_image_years() {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    global $wpdb;
    // Use the file path year (e.g., 2021/04/image.jpg → 2021) instead of WP upload date
    $years = $wpdb->get_col("
        SELECT DISTINCT SUBSTRING(pm.meta_value, 1, 4) as yr
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
        WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%'
        AND pm.meta_value REGEXP '^[0-9]{4}/[0-9]{2}/'
        ORDER BY yr ASC
    ");
    wp_send_json_success($years);
}

add_action('wp_ajax_aipm_scan_unused', 'aipm_ajax_scan_unused');
function aipm_ajax_scan_unused() {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');
    aipm_release_session_lock();

    global $wpdb;
    $upload_dir = wp_upload_dir();

    $page  = max(1, intval($_POST['page'] ?? 1));
    $year  = intval($_POST['year'] ?? 0);
    $limit = 500;
    $offset = ($page - 1) * $limit;

    // Filter by file path year (e.g., 2021/04/image.jpg) not WP upload date
    $year_sql = $year ? $wpdb->prepare(" AND SUBSTRING(pm.meta_value, 1, 4) = %s", strval($year)) : '';

    $total_images = (int) $wpdb->get_var("
        SELECT COUNT(*) FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
        WHERE p.post_type = 'attachment'
        AND p.post_mime_type LIKE 'image/%'
        AND pm.meta_value REGEXP '^[0-9]{4}/[0-9]{2}/'
        $year_sql
    ");

    $attachments = $wpdb->get_results($wpdb->prepare("
        SELECT p.ID, p.post_title, p.post_date, pm.meta_value AS file_path
        FROM {$wpdb->posts} p
        JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attached_file'
        WHERE p.post_type = 'attachment'
        AND p.post_mime_type LIKE 'image/%%'
        AND pm.meta_value REGEXP '^[0-9]{4}/[0-9]{2}/'
        $year_sql
        ORDER BY p.post_date ASC
        LIMIT %d OFFSET %d
    ", $limit, $offset));

    if (empty($attachments)) {
        wp_send_json_success([
            'total_images' => $total_images,
            'total_unused' => 0,
            'files'        => [],
            'page'         => $page,
            'has_more'     => false,
            'scanned'      => $offset,
        ]);
        return;
    }

    // Collect used attachment IDs (fast bulk queries)
    $used_as_thumbnail = $wpdb->get_col("
        SELECT DISTINCT meta_value FROM {$wpdb->postmeta}
        WHERE meta_key = '_thumbnail_id' AND meta_value > 0
    ");
    $gallery_ids = [];
    $galleries = $wpdb->get_col("
        SELECT meta_value FROM {$wpdb->postmeta}
        WHERE meta_key = '_product_image_gallery' AND meta_value != ''
    ");
    foreach ($galleries as $gallery) {
        foreach (explode(',', $gallery) as $gid) {
            $gid = intval(trim($gid));
            if ($gid > 0) $gallery_ids[] = $gid;
        }
    }
    $used_by_meta_map = array_flip(array_unique(array_merge(
        array_map('intval', $used_as_thumbnail),
        $gallery_ids
    )));

    // Build content blob (same proven approach as before)
    $all_content = $wpdb->get_var("
        SELECT GROUP_CONCAT(post_content SEPARATOR ' ')
        FROM {$wpdb->posts}
        WHERE post_type NOT IN ('attachment', 'revision')
        AND post_status IN ('publish', 'draft', 'private')
        AND post_content != ''
    ");
    if ($all_content === null) {
        $all_content = '';
        $content_offset = 0;
        do {
            $chunk = $wpdb->get_col($wpdb->prepare("
                SELECT post_content FROM {$wpdb->posts}
                WHERE post_type NOT IN ('attachment', 'revision')
                AND post_status IN ('publish', 'draft', 'private')
                AND post_content != ''
                LIMIT %d OFFSET %d
            ", 200, $content_offset));
            $all_content .= ' ' . implode(' ', $chunk);
            $content_offset += 200;
        } while (count($chunk) === 200);
    }

    // Build meta blob
    $all_meta = $wpdb->get_var("
        SELECT GROUP_CONCAT(meta_value SEPARATOR ' ')
        FROM {$wpdb->postmeta}
        WHERE meta_key NOT LIKE '\_%'
        AND meta_value != ''
        AND LENGTH(meta_value) < 500
    ");
    if ($all_meta === null) $all_meta = '';

    // Scan theme templates + functions + mu-plugins (small files, fast)
    $theme_dir = get_stylesheet_directory();
    $theme_files = array_merge(
        glob($theme_dir . '/*.php') ?: [],
        glob($theme_dir . '/parts/*.html') ?: [],
        glob($theme_dir . '/templates/*.html') ?: [],
        glob($theme_dir . '/assets/**/*') ?: []
    );
    if (is_dir(WPMU_PLUGIN_DIR)) {
        $theme_files = array_merge($theme_files, glob(WPMU_PLUGIN_DIR . '/*.php') ?: []);
    }
    foreach (array_unique($theme_files) as $tf) {
        if (file_exists($tf) && filesize($tf) < 500000) {
            $all_content .= ' ' . file_get_contents($tf);
        }
    }

    // Check each attachment
    $unused = [];
    foreach ($attachments as $att) {
        $id = intval($att->ID);
        if (isset($used_by_meta_map[$id])) continue;

        $filename = basename($att->file_path);
        $name_no_ext = preg_replace('/\.[^.]+$/', '', $filename);

        if (stripos($all_content, $filename) !== false) continue;
        if (stripos($all_content, $name_no_ext) !== false) continue;
        if (stripos($all_meta, $filename) !== false) continue;
        if (stripos($all_meta, strval($id)) !== false) continue;

        $thumb_url = wp_get_attachment_image_url($id, 'thumbnail');
        $full_url  = $upload_dir['baseurl'] . '/' . $att->file_path;
        $full_path = $upload_dir['basedir'] . '/' . $att->file_path;
        $file_size = file_exists($full_path) ? filesize($full_path) : 0;

        $unused[] = [
            'id'        => $id,
            'title'     => $att->post_title,
            'filename'  => $filename,
            'path'      => $att->file_path,
            'url'       => $full_url,
            'thumb_url' => $thumb_url ?: $full_url,
            'size'      => $file_size,
            'year'      => substr($att->file_path, 0, 4),
        ];
    }

    $scanned_so_far = $offset + count($attachments);

    wp_send_json_success([
        'total_images' => $total_images,
        'total_unused' => count($unused),
        'files'        => $unused,
        'page'         => $page,
        'has_more'     => $scanned_so_far < $total_images,
        'scanned'      => $scanned_so_far,
    ]);
}

add_action('wp_ajax_aipm_delete_attachment', 'aipm_ajax_delete_attachment');
function aipm_ajax_delete_attachment() {
    check_ajax_referer('aipm_nonce', 'nonce');
    if (!current_user_can('manage_woocommerce')) wp_send_json_error('Permission denied.');

    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Missing attachment ID.');

    $result = wp_delete_attachment($id, true); // true = force delete, skip trash
    if (!$result) wp_send_json_error('Failed to delete attachment.');

    wp_send_json_success();
}

function aipm_render_media_page() {
    $nonce = wp_create_nonce('aipm_nonce');
    ?>
    <div class="wrap">
        <h1>Media Cleanup</h1>

        <div class="aipm-media-tabs">
            <button type="button" class="aipm-tab active" data-tab="unregistered">Unregistered Files</button>
            <button type="button" class="aipm-tab" data-tab="unused">Unused Images</button>
        </div>

        <!-- ═══ Tab 1: Unregistered Files ═══ -->
        <div class="aipm-tab-panel active" id="aipm-panel-unregistered">
            <p>Scan your uploads folder for image files that aren't registered in the WordPress media library.</p>
            <p><button type="button" class="button button-primary" id="aipm-scan-btn">Scan for Unregistered Files</button></p>

            <div id="aipm-scan-status" class="aipm-status-line"></div>

            <div id="aipm-scan-actions" class="aipm-action-bar" style="display:none;">
                <button type="button" class="button button-primary" id="aipm-register-all-btn">Register All</button>
                <button type="button" class="button" id="aipm-register-selected-btn">Register Selected</button>
                <button type="button" class="button aipm-btn-danger" id="aipm-delete-selected-btn">Delete Selected Files</button>
            </div>

            <div id="aipm-media-progress" class="aipm-bulk-progress" style="display:none;">
                <div class="progress-text"></div>
                <div class="progress-detail"></div>
            </div>

            <table class="wp-list-table widefat fixed striped" id="aipm-media-table" style="display:none;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="aipm-media-check-all" /></th>
                        <th style="width:60px;">Preview</th>
                        <th>Filename</th>
                        <th style="width:100px;">Size</th>
                        <th style="width:120px;">Status</th>
                    </tr>
                </thead>
                <tbody id="aipm-media-body"></tbody>
            </table>
        </div>

        <!-- ═══ Tab 2: Unused Images ═══ -->
        <div class="aipm-tab-panel" id="aipm-panel-unused">
            <p>Find images registered in the media library but not used anywhere — not as a featured image, in post/page content, product galleries, theme templates, or custom fields.</p>

            <div style="display:flex; gap:8px; align-items:center; margin-bottom:12px; flex-wrap:wrap;">
                <strong>Year:</strong>
                <select id="aipm-unused-year" style="min-width:120px;">
                    <option value="0">All Years</option>
                </select>
                <button type="button" class="button button-primary" id="aipm-unused-scan-btn">Scan for Unused Images</button>
            </div>

            <div id="aipm-unused-status" class="aipm-status-line"></div>

            <div id="aipm-unused-actions" class="aipm-action-bar" style="display:none;">
                <button type="button" class="button aipm-btn-danger" id="aipm-unused-delete-btn">Delete Selected from Media Library</button>
                <span id="aipm-unused-size-total" style="margin-left:12px; color:#666;"></span>
            </div>

            <div id="aipm-unused-progress" class="aipm-bulk-progress" style="display:none;">
                <div class="progress-text"></div>
                <div class="progress-detail"></div>
            </div>

            <table class="wp-list-table widefat fixed striped" id="aipm-unused-table" style="display:none;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="aipm-unused-check-all" /></th>
                        <th style="width:60px;">Preview</th>
                        <th>Filename</th>
                        <th style="width:60px;">Year</th>
                        <th style="width:100px;">Size</th>
                        <th style="width:120px;">Status</th>
                    </tr>
                </thead>
                <tbody id="aipm-unused-body"></tbody>
            </table>
        </div>
    </div>

    <style>
        .aipm-media-tabs { display: flex; gap: 0; margin-bottom: 0; border-bottom: 1px solid #c3c4c7; }
        .aipm-tab {
            padding: 8px 16px; background: #f0f0f1; border: 1px solid #c3c4c7;
            border-bottom: none; cursor: pointer; font-size: 13px; font-weight: 600;
            margin-bottom: -1px; border-radius: 4px 4px 0 0;
        }
        .aipm-tab.active { background: #fff; border-bottom: 1px solid #fff; }
        .aipm-tab:hover:not(.active) { background: #e5e5e5; }
        .aipm-tab-panel { display: none; padding: 16px 0; }
        .aipm-tab-panel.active { display: block; }
        .aipm-status-line { margin: 12px 0; font-weight: 600; }
        .aipm-action-bar { display: flex; align-items: center; gap: 8px; margin: 12px 0; }
        .aipm-btn-danger { color: #dc2626 !important; }
        img.aipm-media-preview { width: 50px; height: 50px; object-fit: cover; border-radius: 4px; }
        .aipm-media-row.processed { opacity: 0.5; }
        .status-registered { color: #16a34a; font-weight: 600; }
        .status-deleted { color: #dc2626; font-weight: 600; }
        .aipm-bulk-progress { margin: 16px 0; padding: 12px 16px; background: #fff; border: 1px solid #c3c4c7; border-left: 4px solid #2271b1; }
        .aipm-bulk-progress .progress-text { font-weight: 600; }
        .aipm-bulk-progress .progress-detail { color: #666; margin-top: 4px; font-size: 13px; }
    </style>

    <script>
    jQuery(document).ready(function($) {
        var nonce = '<?php echo $nonce; ?>';

        // ─── Tab switching ───
        $('.aipm-tab').click(function() {
            $('.aipm-tab').removeClass('active');
            $('.aipm-tab-panel').removeClass('active');
            $(this).addClass('active');
            $('#aipm-panel-' + $(this).data('tab')).addClass('active');
        });

        function formatSize(bytes) {
            if (bytes < 1024) return bytes + ' B';
            if (bytes < 1048576) return (bytes / 1024).toFixed(1) + ' KB';
            return (bytes / 1048576).toFixed(1) + ' MB';
        }

        // ═══ Tab 1: Unregistered Files ═══

        var scannedFiles = [];

        $('#aipm-scan-btn').click(function() {
            var btn = $(this);
            btn.prop('disabled', true).text('Scanning...');
            $('#aipm-scan-status').text('Scanning uploads folder...');
            $('#aipm-media-table').hide();
            $('#aipm-scan-actions').hide();

            $.post(ajaxurl, { action: 'aipm_scan_media', nonce: nonce }, function(resp) {
                btn.prop('disabled', false).text('Scan for Unregistered Files');
                if (!resp.success) { $('#aipm-scan-status').text('Error: ' + (resp.data || 'Unknown')); return; }

                scannedFiles = resp.data.files;
                $('#aipm-scan-status').text(resp.data.total_registered + ' registered in media library, ' + resp.data.total_unregistered + ' unregistered file(s) found.');
                if (resp.data.total_unregistered === 0) return;

                var tbody = $('#aipm-media-body').empty();
                scannedFiles.forEach(function(file, i) {
                    tbody.append(
                        '<tr class="aipm-media-row" data-index="' + i + '" data-path="' + file.path + '">' +
                        '<td><input type="checkbox" class="aipm-media-cb" data-index="' + i + '" /></td>' +
                        '<td><img class="aipm-media-preview" src="' + file.url + '" loading="lazy" /></td>' +
                        '<td>' + file.filename + '<br><small style="color:#999;">' + file.path + '</small></td>' +
                        '<td>' + formatSize(file.size) + '</td>' +
                        '<td class="aipm-media-status">Unregistered</td>' +
                        '</tr>'
                    );
                });
                $('#aipm-media-table').show();
                $('#aipm-scan-actions').css('display', 'flex');
            }).fail(function() {
                btn.prop('disabled', false).text('Scan for Unregistered Files');
                $('#aipm-scan-status').text('Server error during scan.');
            });
        });

        $('#aipm-media-check-all').change(function() {
            $('.aipm-media-cb').not(':disabled').prop('checked', $(this).prop('checked'));
        });

        function getSelectedCbs(selector) {
            var indices = [];
            $(selector + ':checked').each(function() { indices.push(parseInt($(this).data('index'))); });
            return indices;
        }

        function bulkProcessFiles(indices, action) {
            var $progress = $('#aipm-media-progress').show();
            var completed = 0, failed = 0, total = indices.length;
            var label = action === 'register' ? 'Registering' : 'Deleting';

            function next(i) {
                if (i >= indices.length) {
                    $progress.find('.progress-text').text('Done: ' + completed + ' succeeded, ' + failed + ' failed out of ' + total + '.');
                    $progress.find('.progress-detail').text('');
                    $('#aipm-register-all-btn, #aipm-register-selected-btn, #aipm-delete-selected-btn').prop('disabled', false);
                    return;
                }
                var idx = indices[i], file = scannedFiles[idx];
                var row = $('.aipm-media-row[data-index="' + idx + '"]');
                $progress.find('.progress-text').text(label + ' ' + (i + 1) + ' of ' + total + '...');
                $progress.find('.progress-detail').text(file.filename);
                var ajaxAction = action === 'register' ? 'aipm_register_image' : 'aipm_delete_image';
                $.post(ajaxurl, { action: ajaxAction, nonce: nonce, path: file.path }, function(resp) {
                    if (resp.success) {
                        completed++;
                        if (action === 'register') {
                            row.addClass('processed').find('.aipm-media-status').html('<span class="status-registered">&#10003; Registered</span>');
                            if (resp.data.thumb_url) row.find('.aipm-media-preview').attr('src', resp.data.thumb_url);
                        } else {
                            row.addClass('processed').find('.aipm-media-status').html('<span class="status-deleted">Deleted</span>');
                        }
                        row.find('.aipm-media-cb').prop('checked', false).prop('disabled', true);
                    } else { failed++; row.find('.aipm-media-status').text('Failed: ' + (resp.data || 'Error')); }
                    next(i + 1);
                }).fail(function() { failed++; row.find('.aipm-media-status').text('Server error'); next(i + 1); });
            }
            $('#aipm-register-all-btn, #aipm-register-selected-btn, #aipm-delete-selected-btn').prop('disabled', true);
            next(0);
        }

        $('#aipm-register-all-btn').click(function() {
            var all = []; scannedFiles.forEach(function(f, i) { all.push(i); });
            if (confirm('Register all ' + all.length + ' images? This will generate thumbnails for each.')) bulkProcessFiles(all, 'register');
        });
        $('#aipm-register-selected-btn').click(function() {
            var sel = getSelectedCbs('.aipm-media-cb');
            if (!sel.length) { alert('Select at least one image.'); return; }
            bulkProcessFiles(sel, 'register');
        });
        $('#aipm-delete-selected-btn').click(function() {
            var sel = getSelectedCbs('.aipm-media-cb');
            if (!sel.length) { alert('Select at least one image.'); return; }
            if (confirm('Permanently delete ' + sel.length + ' file(s)? This cannot be undone.')) bulkProcessFiles(sel, 'delete');
        });

        // ═══ Tab 2: Unused Images ═══

        var unusedFiles = [];

        // Load available years into dropdown
        $.post(ajaxurl, { action: 'aipm_get_image_years', nonce: nonce }, function(resp) {
            if (resp.success && resp.data.length) {
                var sel = $('#aipm-unused-year');
                resp.data.forEach(function(yr) {
                    sel.append('<option value="' + yr + '">' + yr + '</option>');
                });
            }
        });

        $('#aipm-unused-scan-btn').click(function() {
            var btn = $(this);
            var selectedYear = parseInt($('#aipm-unused-year').val()) || 0;
            btn.prop('disabled', true).text('Scanning...');
            $('#aipm-unused-status').text('Scanning media library' + (selectedYear ? ' for ' + selectedYear : '') + '...');
            $('#aipm-unused-body').empty();
            $('#aipm-unused-table').hide();
            $('#aipm-unused-actions').hide();
            unusedFiles = [];

            var totalImages = 0;
            var totalSize = 0;
            var globalIndex = 0;

            function scanPage(page) {
                $.post(ajaxurl, { action: 'aipm_scan_unused', nonce: nonce, page: page, year: selectedYear }, function(resp) {
                    if (!resp.success) {
                        btn.prop('disabled', false).text('Scan for Unused Images');
                        $('#aipm-unused-status').text('Error on batch ' + page + ': ' + (resp.data || 'Unknown'));
                        return;
                    }

                    totalImages = resp.data.total_images;

                    var tbody = $('#aipm-unused-body');
                    resp.data.files.forEach(function(file) {
                        var i = globalIndex++;
                        unusedFiles.push(file);
                        totalSize += file.size;
                        tbody.append(
                            '<tr class="aipm-media-row" data-index="' + i + '">' +
                            '<td><input type="checkbox" class="aipm-unused-cb" data-index="' + i + '" /></td>' +
                            '<td><img class="aipm-media-preview" src="' + file.thumb_url + '" loading="lazy" /></td>' +
                            '<td>' + file.filename +
                                '<br><small style="color:#999;">' + file.path + '</small>' +
                                '<br><small style="color:#666;">ID: ' + file.id + ' &mdash; ' + file.title + '</small>' +
                            '</td>' +
                            '<td>' + (file.year || '-') + '</td>' +
                            '<td>' + formatSize(file.size) + '</td>' +
                            '<td class="aipm-media-status">Unused</td>' +
                            '</tr>'
                        );
                    });

                    if (unusedFiles.length > 0) {
                        $('#aipm-unused-table').show();
                        $('#aipm-unused-actions').css('display', 'flex');
                    }

                    var statusText = 'Scanned ' + resp.data.scanned + ' of ' + totalImages + ' images... '
                        + unusedFiles.length + ' unused found (' + formatSize(totalSize) + ')';

                    if (resp.data.has_more) {
                        $('#aipm-unused-status').text(statusText);
                        scanPage(page + 1);
                    } else {
                        $('#aipm-unused-status').text(
                            'Scan complete. ' + totalImages + ' total images' +
                            (selectedYear ? ' from ' + selectedYear : '') + ', ' +
                            unusedFiles.length + ' unused (' + formatSize(totalSize) + ').'
                        );
                        btn.prop('disabled', false).text('Scan for Unused Images');
                    }
                }).fail(function() {
                    btn.prop('disabled', false).text('Scan for Unused Images');
                    $('#aipm-unused-status').text(
                        'Error on batch ' + page + '. Found so far: ' +
                        unusedFiles.length + ' unused (' + formatSize(totalSize) + '). Try again.'
                    );
                });
            }

            scanPage(1);
        });

        $('#aipm-unused-check-all').change(function() {
            $('.aipm-unused-cb').not(':disabled').prop('checked', $(this).prop('checked'));
        });

        $('#aipm-unused-delete-btn').click(function() {
            var sel = [];
            $('.aipm-unused-cb:checked').each(function() { sel.push(parseInt($(this).data('index'))); });
            if (!sel.length) { alert('Select at least one image.'); return; }
            if (!confirm('Delete ' + sel.length + ' image(s) from the media library and disk? This cannot be undone.')) return;

            var $progress = $('#aipm-unused-progress').show();
            var completed = 0, failed = 0, total = sel.length;
            $('#aipm-unused-delete-btn').prop('disabled', true);

            function next(i) {
                if (i >= sel.length) {
                    $progress.find('.progress-text').text('Done: ' + completed + ' deleted, ' + failed + ' failed out of ' + total + '.');
                    $progress.find('.progress-detail').text('');
                    $('#aipm-unused-delete-btn').prop('disabled', false);
                    return;
                }
                var idx = sel[i], file = unusedFiles[idx];
                var row = $('#aipm-unused-body .aipm-media-row[data-index="' + idx + '"]');
                $progress.find('.progress-text').text('Deleting ' + (i + 1) + ' of ' + total + '...');
                $progress.find('.progress-detail').text(file.filename);

                $.post(ajaxurl, { action: 'aipm_delete_attachment', nonce: nonce, id: file.id }, function(resp) {
                    if (resp.success) {
                        completed++;
                        row.addClass('processed').find('.aipm-media-status').html('<span class="status-deleted">Deleted</span>');
                        row.find('.aipm-unused-cb').prop('checked', false).prop('disabled', true);
                    } else { failed++; row.find('.aipm-media-status').text('Failed'); }
                    next(i + 1);
                }).fail(function() { failed++; row.find('.aipm-media-status').text('Server error'); next(i + 1); });
            }
            next(0);
        });
    });
    </script>
    <?php
}
