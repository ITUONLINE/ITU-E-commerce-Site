<?php
/*
Plugin Name: Blog Queue
Description: Queue blog topics for automated daily creation. Generates outlines, full blog posts, FAQs, and SEO metadata using AI.
Version: 1.0
Author: ITU Online
*/

if (!defined('ABSPATH')) exit;

// ─── Activation ──────────────────────────────────────────────────────────────

register_activation_hook(__FILE__, 'bq_activate');
function bq_activate() {
    bq_create_tables();

    if (!wp_next_scheduled('bq_daily_process')) {
        wp_schedule_event(strtotime('today 07:00'), 'daily', 'bq_daily_process');
    }
}

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bq_daily_process');
    wp_clear_scheduled_hook('bq_process_next');
});

// ─── Database ────────────────────────────────────────────────────────────────

function bq_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE {$wpdb->prefix}bq_queue (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(500) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        post_id bigint(20) unsigned DEFAULT NULL,
        added_at datetime NOT NULL,
        processed_at datetime DEFAULT NULL,
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY status (status)
    ) $charset;");

    dbDelta("CREATE TABLE {$wpdb->prefix}bq_history (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(500) NOT NULL,
        post_id bigint(20) unsigned DEFAULT NULL,
        status varchar(20) NOT NULL,
        processed_at datetime NOT NULL,
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY processed_at (processed_at)
    ) $charset;");
}

// ─── Settings ────────────────────────────────────────────────────────────────

function bq_get_settings() {
    return wp_parse_args(get_option('bq_settings', []), [
        'daily_limit'  => 5,
        'notify_email' => get_option('admin_email'),
        'enabled'      => false,
    ]);
}

// ─── Blog Creator ────────────────────────────────────────────────────────────

function bq_call_openai($instruction, $user_prompt = '', $model = '', $temperature = 0.7) {
    if (!$model) $model = function_exists('itu_ai_model') ? itu_ai_model('blog_queue') : 'gpt-4.1-nano';

    // Use unified provider router if available
    if (function_exists('itu_ai_call')) {
        return itu_ai_call($instruction, $user_prompt, $model, $temperature, ['key_name' => 'blog_writer', 'timeout' => 240]);
    }

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) return new WP_Error('no_key', 'Blog Writer API key not configured.');

    $messages = [['role' => 'system', 'content' => $instruction]];
    if ($user_prompt) $messages[] = ['role' => 'user', 'content' => $user_prompt];

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type'  => 'application/json',
        ],
        'body' => json_encode([
            'model'       => $model,
            'messages'    => $messages,
            'temperature' => $temperature,
        ]),
        'timeout' => 240,
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if (empty($content)) return new WP_Error('empty', 'No content returned from OpenAI.');

    return $content;
}

function bq_create_blog($title) {
    // Step 1: Generate outline
    $outline_instruction = <<<PROMPT
Using the given topic, create a compelling blog title in title case, then generate a very detailed outline for a long-form blog post that will be 2,000-2,500 words when fully written.

The outline must have at least 6-8 main sections (not counting Introduction and Conclusion). Each section must have 4-6 detailed bullet points covering specific concepts, examples, tools, or steps. The more detailed the outline, the longer and better the final blog post will be.

Return the outline in plain text using section headings and bulleted key points. Do not use numbers or Roman numerals.

Format:
BLOG TITLE

Main Heading
- Key point 1
- Key point 2
- Additional subtopics

Next Main Heading
- Key point 1
- Key point 2
PROMPT;

    $outline = bq_call_openai($outline_instruction, $title);
    if (is_wp_error($outline)) return $outline;

    // Extract the blog title from the outline (first line)
    $lines = explode("\n", trim($outline));
    $blog_title = trim($lines[0]);
    // Clean up title — remove "BLOG TITLE:" prefix if present
    $blog_title = preg_replace('/^(BLOG TITLE|Title)\s*[::\-]\s*/i', '', $blog_title);
    if (empty($blog_title) || strlen($blog_title) > 200) $blog_title = $title;

    // Step 2: Generate full blog content
    $blog_instruction = <<<PROMPT
You are a professional IT blog writer for ITU Online Training. Your tone is direct, knowledgeable, and practical. You write for busy IT professionals who scan pages.

BANNED PHRASES — Do NOT use any of these:
- In today's rapidly evolving... / In an ever-changing landscape... / In the fast-paced world of...
- As technology continues to... / In today's digital age... / With the growing importance of...
- As organizations increasingly... / In the modern IT landscape...

IMPORTANT: Do NOT invent certification names, exam codes, or credential titles not in the outline.

WORD COUNT REQUIREMENT — THIS IS CRITICAL:
The blog post MUST be at least 2,000 words. This is a hard minimum — do NOT write fewer than 2,000 words.
- Each of the 6-8 main sections must be 200-350 words
- The introduction must be at least 150 words
- The conclusion must be at least 150 words
- Do NOT summarize or abbreviate sections. Write each section in full detail with specific examples, explanations, and actionable advice
- If the outline has 8 sections at 250 words each + intro + conclusion, that's 2,300 words. Hit that target.

Write the full blog post from the outline below. Cover EVERY section in the outline thoroughly.

OUTPUT FORMAT — CRITICAL:
- Return ONLY valid HTML. No Markdown (no #, **, -, ```)
- Use <h2> for main sections, <h3> for subsections
- Do NOT include an <h1> tag
- Wrap paragraphs in <p> tags — keep them SHORT (2-4 sentences max)
- Use <ul><li> or <ol><li> for lists
- Use <strong> to bold key terms on first mention
- Use <blockquote> for 1-2 notable quotes or insights
- Use 1-3 callout boxes per post. ONLY use these exact classes (no variations):
  <div class="itu-callout itu-callout--tip"><p><strong>Pro Tip</strong></p><p>Content.</p></div>
  <div class="itu-callout itu-callout--info"><p><strong>Note</strong></p><p>Content.</p></div>
  <div class="itu-callout itu-callout--warning"><p><strong>Warning</strong></p><p>Content.</p></div>
  <div class="itu-callout itu-callout--key"><p><strong>Key Takeaway</strong></p><p>Content.</p></div>
- Use <table> ONLY for simple 2-column comparisons
- Every section must mix at least 2 format types (paragraphs + lists, paragraphs + callout, etc.)
- Include named entity "ITU Online Training" where appropriate

DEPTH REQUIREMENTS:
- Do NOT write surface-level summaries. Go deep with specifics, examples, tool names, commands, or step-by-step details
- Each section should teach the reader something concrete they can apply immediately
- Include real-world scenarios, comparisons between approaches, or common mistakes to avoid
- Introduction: hook with a specific problem or scenario, preview key takeaways
- Conclusion: summarize actionable points + include a call to action for ITU Online Training
- Write like a real person. Vary sentence length. Mix short punchy sentences with detailed explanations.
PROMPT;

    $blog_content = bq_call_openai($blog_instruction, "Outline:\n" . $outline);
    if (is_wp_error($blog_content)) return $blog_content;

    // Fix any markdown that slipped through
    $blog_content = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $blog_content);
    $blog_content = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $blog_content);
    $blog_content = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $blog_content);
    $blog_content = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $blog_content);
    $blog_content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $blog_content);

    // Fix invalid callout classes — normalize to valid variants
    $blog_content = preg_replace_callback('/itu-callout--([a-z\-]+)/', function ($m) {
        $valid = ['tip', 'info', 'warning', 'key'];
        $variant = $m[1];
        // Map common AI mistakes to valid classes
        if (strpos($variant, 'tip') !== false) return 'itu-callout--tip';
        if (strpos($variant, 'info') !== false || strpos($variant, 'note') !== false) return 'itu-callout--info';
        if (strpos($variant, 'warn') !== false || strpos($variant, 'caution') !== false) return 'itu-callout--warning';
        if (strpos($variant, 'key') !== false || strpos($variant, 'purple') !== false || strpos($variant, 'important') !== false) return 'itu-callout--key';
        if (in_array($variant, $valid)) return 'itu-callout--' . $variant;
        return 'itu-callout--tip'; // default fallback
    }, $blog_content);

    // Step 3: Create the WordPress post
    $post_id = wp_insert_post([
        'post_title'   => $blog_title,
        'post_content' => $blog_content,
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => 1,
    ]);

    if (is_wp_error($post_id)) return $post_id;

    // Assign to "Blogs" category
    $blog_cat = get_category_by_slug('blogs');
    if (!$blog_cat) $blog_cat = get_category_by_slug('blog');
    if ($blog_cat) {
        wp_set_post_categories($post_id, [$blog_cat->term_id]);
    }

    // Step 4: Generate meta description
    $snippet = mb_substr(wp_strip_all_tags($blog_content), 0, 500);
    $meta_instruction = "Write a meta description for this blog post. Rules: 1-2 sentences, 140-155 characters, start with an action verb, do not use quotes. Do NOT invent certifications.";
    $meta_desc = bq_call_openai($meta_instruction, "Title: {$blog_title}\n\nContent:\n{$snippet}", 'gpt-4.1-nano', 0.4);
    if (!is_wp_error($meta_desc)) {
        $meta_desc = wp_strip_all_tags(trim($meta_desc));
        wp_update_post(['ID' => $post_id, 'post_excerpt' => $meta_desc]);
        update_post_meta($post_id, 'rank_math_description', $meta_desc);
    }

    // Step 5: Generate focus keyword
    $kw_instruction = "Return a single primary focus keyword (2-4 words) for this blog post. Something people would search for. Return ONLY the keyword.";
    $keyword = bq_call_openai($kw_instruction, "Title: {$blog_title}\n\nContent:\n{$snippet}", 'gpt-4.1-nano', 0.3);
    if (!is_wp_error($keyword)) {
        update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field(trim($keyword)));
    }

    // Step 6: Generate FAQs
    $faq_instruction = "Generate 5 FAQ entries for this blog post. Format:\n<details><summary>Question?</summary><div class=\"faq-content\">\n<p>Paragraph 1.</p>\n<p>Paragraph 2.</p>\n</div></details>\n\nCRITICAL: Wrap ALL answer text in <p> tags. Each answer 200+ words, 2-4 paragraphs. Do NOT invent certifications.";
    $faq_html = bq_call_openai($faq_instruction, "Title: {$blog_title}\n\nContent:\n{$snippet}");
    if (!is_wp_error($faq_html) && function_exists('update_field')) {
        // Fix missing <p> tags
        $faq_html = preg_replace_callback('/<div class="faq-content">(.*?)<\/div>/s', function ($m) {
            $c = trim($m[1]);
            if (stripos($c, '<p>') !== false) return $m[0];
            $sents = preg_split('/(?<=[.!?])\s+/', $c);
            $chunks = []; $cur = '';
            foreach ($sents as $i => $s) {
                $cur .= ($cur ? ' ' : '') . $s;
                if (($i + 1) % 3 === 0 || $i === count($sents) - 1) { $chunks[] = $cur; $cur = ''; }
            }
            $w = '';
            foreach ($chunks as $ch) { $ch = trim($ch); if ($ch) $w .= '<p>' . $ch . "</p>\n"; }
            return '<div class="faq-content">' . "\n" . $w . '</div>';
        }, $faq_html);

        update_field('field_6816a44480234', $faq_html, $post_id);
    }

    // Step 7: Generate SEO title
    $seo_instruction = "Write an SEO title for this blog post. Max 60 characters. Include the focus keyword near the beginning. End with ' - ITU Online'. Return ONLY the title.";
    $seo_title = bq_call_openai($seo_instruction, "Title: {$blog_title}\nKeyword: {$keyword}", 'gpt-4.1-nano', 0.5);
    if (!is_wp_error($seo_title)) {
        $seo_title = sanitize_text_field(trim($seo_title));
        if (mb_strlen($seo_title) <= 70) {
            update_post_meta($post_id, 'rank_math_title', $seo_title);
        }
    }

    // Save timestamp
    update_post_meta($post_id, 'last_page_refresh', current_time('mysql'));

    return $post_id;
}

// ─── Queue Processor ─────────────────────────────────────────────────────────

function bq_process_queue($force = false) {
    $settings = bq_get_settings();
    if (!$force && !$settings['enabled']) return;

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $history = $wpdb->prefix . 'bq_history';
    $today_key = 'bq_daily_count_' . date('Y-m-d');
    $count = (int) get_option($today_key, 0);
    $limit = $settings['daily_limit'];

    if ($count >= $limit) return;

    // Pick random pending items up to daily limit
    $remaining = $limit - $count;
    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$table} WHERE status = 'pending' ORDER BY RAND() LIMIT %d",
        $remaining
    ));

    if (empty($items)) return;

    $created = [];
    $failed = [];

    foreach ($items as $item) {
        // Mark as processing
        $wpdb->update($table, ['status' => 'processing'], ['id' => $item->id]);

        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @set_time_limit(300);

        $result = bq_create_blog($item->title);

        if (is_wp_error($result)) {
            $error = $result->get_error_message();
            $wpdb->update($table, [
                'status'        => 'failed',
                'processed_at'  => current_time('mysql'),
                'error_message' => $error,
            ], ['id' => $item->id]);
            $wpdb->insert($history, [
                'title'        => $item->title,
                'status'       => 'failed',
                'processed_at' => current_time('mysql'),
                'error_message'=> $error,
            ]);

            // Send individual failure email
            if ($settings['notify_email']) {
                bq_send_notification($settings['notify_email'], $item->title, 'failed', 0, '', $error);
            }
        } else {
            $wpdb->update($table, [
                'status'       => 'completed',
                'post_id'      => $result,
                'processed_at' => current_time('mysql'),
            ], ['id' => $item->id]);
            $wpdb->insert($history, [
                'title'        => $item->title,
                'post_id'      => $result,
                'status'       => 'completed',
                'processed_at' => current_time('mysql'),
            ]);

            // Send individual success email
            if ($settings['notify_email']) {
                bq_send_notification($settings['notify_email'], $item->title, 'completed', $result, get_permalink($result));
            }
        }

        update_option($today_key, ++$count);
    }
}

// ─── Email Notification ──────────────────────────────────────────────────────

function bq_send_notification($to, $title, $status, $post_id = 0, $view_url = '', $error = '') {
    $is_success = $status === 'completed';
    $status_color = $is_success ? '#059669' : '#dc2626';
    $status_label = $is_success ? 'Published' : 'Failed';
    $status_icon  = $is_success ? '&#10003;' : '&#10007;';
    $subject = $is_success ? "[Blog Queue] Published: {$title}" : "[Blog Queue] Failed: {$title}";

    $edit_url = $post_id ? admin_url('post.php?action=edit&post=' . $post_id) : '';

    $error_row = $error ? '<tr style="background:#fef2f2;"><td style="padding:8px 16px;color:#94a3b8;font-size:13px;">Error</td><td style="padding:8px 16px;color:#dc2626;">' . esc_html($error) . '</td></tr>' : '';

    $buttons = '';
    if ($post_id) {
        $buttons = '<div style="margin-top:20px;">'
            . '<a href="' . esc_url($view_url) . '" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;margin-right:8px;">View Post</a>'
            . '<a href="' . esc_url($edit_url) . '" style="display:inline-block;padding:10px 20px;background:#f0f0f1;color:#1e293b;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;border:1px solid #c3c4c7;">Edit in WordPress</a>'
            . '</div>';
    }

    $word_count = '';
    if ($post_id) {
        $content = get_post_field('post_content', $post_id);
        $wc = str_word_count(wp_strip_all_tags($content));
        $read_time = max(1, round($wc / 250));
        $word_count = '<tr><td style="padding:8px 16px;color:#94a3b8;font-size:13px;">Word Count</td><td style="padding:8px 16px;">' . number_format($wc) . ' words (~' . $read_time . ' min read)</td></tr>';
    }

    $body = '
    <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">
        <div style="background:#1e293b;padding:20px 24px;border-radius:8px 8px 0 0;">
            <h1 style="margin:0;color:#fff;font-size:18px;">Blog Queue</h1>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
            <div style="display:inline-block;padding:6px 14px;border-radius:4px;background:' . $status_color . ';color:#fff;font-weight:600;font-size:14px;margin-bottom:16px;">
                ' . $status_icon . ' ' . $status_label . '
            </div>

            <h2 style="margin:0 0 16px;font-size:20px;color:#1e293b;">' . esc_html($title) . '</h2>

            <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                <tr><td style="padding:8px 16px;color:#94a3b8;font-size:13px;width:120px;">Type</td><td style="padding:8px 16px;">New Blog Post</td></tr>
                <tr style="background:#f9fafb;"><td style="padding:8px 16px;color:#94a3b8;font-size:13px;">Category</td><td style="padding:8px 16px;">Blogs</td></tr>
                ' . $word_count . '
                ' . $error_row . '
            </table>

            ' . $buttons . '

            <p style="margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px;">
                Sent by Blog Queue on ' . esc_html(get_bloginfo('name')) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '
            </p>
        </div>
    </div>';

    $set_html = function () { return 'text/html'; };
    add_filter('wp_mail_content_type', $set_html);
    wp_mail($to, $subject, $body);
    remove_filter('wp_mail_content_type', $set_html);
}

// ─── Cron Hooks ──────────────────────────────────────────────────────────────

add_action('bq_daily_process', 'bq_process_queue');

// ─── Admin Menu ──────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_menu_page('Blog Queue', 'Blog Queue', 'manage_options', 'blog-queue', 'bq_render_admin', 'dashicons-edit-large', 58);
});

// ─── AJAX Handlers ───────────────────────────────────────────────────────────

add_action('wp_ajax_bq_add_topics', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $topics = sanitize_textarea_field($_POST['topics'] ?? '');
    $lines = array_filter(array_map('trim', explode("\n", $topics)));

    if (empty($lines)) wp_send_json_error('No topics provided.');

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $now = current_time('mysql');
    $added = 0;

    foreach ($lines as $line) {
        if (mb_strlen($line) < 5) continue;
        $wpdb->insert($table, [
            'title'    => mb_substr($line, 0, 500),
            'status'   => 'pending',
            'added_at' => $now,
        ]);
        $added++;
    }

    wp_send_json_success(['added' => $added]);
});

add_action('wp_ajax_bq_get_queue', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bq_queue WHERE status = 'pending' ORDER BY added_at ASC LIMIT %d OFFSET %d",
        $per_page, $offset
    ));
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bq_queue WHERE status = 'pending'");
    $today_count = (int) get_option('bq_daily_count_' . date('Y-m-d'), 0);
    $bq_settings = bq_get_settings();

    wp_send_json_success([
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'pages'       => ceil($total / $per_page),
        'today_count' => $today_count,
        'daily_limit' => $bq_settings['daily_limit'],
    ]);
});

add_action('wp_ajax_bq_get_history', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bq_history ORDER BY processed_at DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ));
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bq_history");

    wp_send_json_success(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $per_page)]);
});

add_action('wp_ajax_bq_remove_item', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Missing ID.');
    $wpdb->delete($wpdb->prefix . 'bq_queue', ['id' => $id]);
    wp_send_json_success();
});

add_action('wp_ajax_bq_process_one', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(600);

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Missing ID.');

    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bq_queue WHERE id = %d", $id));
    if (!$item) wp_send_json_error('Item not found.');

    $wpdb->update($wpdb->prefix . 'bq_queue', ['status' => 'processing'], ['id' => $id]);

    $result = bq_create_blog($item->title);

    if (is_wp_error($result)) {
        $wpdb->update($wpdb->prefix . 'bq_queue', [
            'status' => 'failed', 'processed_at' => current_time('mysql'), 'error_message' => $result->get_error_message()
        ], ['id' => $id]);
        wp_send_json_error($result->get_error_message());
    }

    $wpdb->update($wpdb->prefix . 'bq_queue', [
        'status' => 'completed', 'post_id' => $result, 'processed_at' => current_time('mysql')
    ], ['id' => $id]);
    $wpdb->insert($wpdb->prefix . 'bq_history', [
        'title' => $item->title, 'post_id' => $result, 'status' => 'completed', 'processed_at' => current_time('mysql')
    ]);

    wp_send_json_success(['post_id' => $result, 'url' => get_permalink($result), 'edit_url' => admin_url('post.php?action=edit&post=' . $result)]);
});

add_action('wp_ajax_bq_save_settings', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $new = [
        'daily_limit'  => max(1, intval($_POST['daily_limit'] ?? 5)),
        'notify_email' => sanitize_email($_POST['notify_email'] ?? ''),
        'enabled'      => ($_POST['enabled'] ?? '0') === '1',
    ];
    update_option('bq_settings', $new);
    wp_send_json_success();
});

add_action('wp_ajax_bq_run_queue', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(1200);

    // Force = true bypasses the "enabled" check for manual triggers
    bq_process_queue(true);

    // Return what happened
    global $wpdb;
    $today_count = (int) get_option('bq_daily_count_' . date('Y-m-d'), 0);
    $recent = $wpdb->get_results("
        SELECT * FROM {$wpdb->prefix}bq_history
        WHERE DATE(processed_at) = CURDATE()
        ORDER BY processed_at DESC LIMIT 20
    ");

    wp_send_json_success([
        'processed'   => count($recent),
        'today_count' => $today_count,
        'results'     => $recent,
    ]);
});

add_action('wp_ajax_bq_generate_topics', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(120);

    $focus_area = sanitize_text_field($_POST['focus_area'] ?? '');
    $count = min(50, max(5, intval($_POST['count'] ?? 20)));

    global $wpdb;

    // Get existing post titles to avoid duplication
    $existing_titles = $wpdb->get_col("
        SELECT LOWER(post_title) FROM {$wpdb->posts}
        WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending')
        ORDER BY RAND() LIMIT 500
    ");
    $titles_sample = implode("\n", array_slice($existing_titles, 0, 200));

    // Get pending queue titles too
    $queued_titles = $wpdb->get_col("SELECT LOWER(title) FROM {$wpdb->prefix}bq_queue WHERE status = 'pending'");
    if (!empty($queued_titles)) {
        $titles_sample .= "\n" . implode("\n", $queued_titles);
    }

    // Get top GSC keywords for content gap ideas
    $keyword_context = '';
    $kw_table = $wpdb->prefix . 'seom_keywords';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$kw_table}'") === $kw_table) {
        // Content gaps — keywords with impressions but no dedicated page
        $gaps = $wpdb->get_col("
            SELECT keyword FROM {$kw_table}
            WHERE is_content_gap = 1 AND impressions >= 20
            ORDER BY opportunity_score DESC LIMIT 30
        ");
        if (!empty($gaps)) {
            $keyword_context = "\n\nCONTENT GAP KEYWORDS — These are real Google searches hitting our site with NO dedicated blog post. Prioritize creating content for these:\n" . implode(', ', $gaps);
        }

        // Rising keywords
        $rising = $wpdb->get_col("
            SELECT keyword FROM {$kw_table}
            WHERE trend_direction = 'rising' AND impressions >= 10
            ORDER BY trend_pct DESC LIMIT 20
        ");
        if (!empty($rising)) {
            $keyword_context .= "\n\nTRENDING/RISING KEYWORDS — These searches are growing in volume:\n" . implode(', ', $rising);
        }
    }

    // Get product titles for course-related topic ideas
    $products = $wpdb->get_col("
        SELECT post_title FROM {$wpdb->posts}
        WHERE post_type = 'product' AND post_status = 'publish'
        ORDER BY RAND() LIMIT 50
    ");
    $product_context = '';
    if (!empty($products)) {
        $product_context = "\n\nOUR IT TRAINING COURSES (for reference — create supporting blog content around these topics):\n" . implode(', ', array_slice($products, 0, 30));
    }

    $focus_instruction = '';
    if (!empty($focus_area)) {
        $focus_instruction = "\n\nFOCUS AREA: The user wants topics specifically about: {$focus_area}. Prioritize this area but still ensure variety.";
    }

    $instruction = <<<PROMPT
You are a content strategist for ITU Online Training, an IT training and certification company. Generate exactly {$count} unique blog post titles.

RULES:
- Each title must be specific, actionable, and SEO-friendly
- Target IT professionals, students, and career changers
- Mix these content types: how-to guides, comparison posts, career guides, technical deep-dives, certification prep, tool reviews, best practices, trend analysis
- Do NOT generate titles that are too similar to existing posts listed below
- Do NOT use generic/vague titles — be specific about the technology, tool, or concept
- Do NOT start titles with "The Ultimate Guide to..." or "Everything You Need to Know About..."
- Use title case
- Return ONLY the titles, one per line, no numbers, no bullets, no explanations
{$focus_instruction}
{$keyword_context}
{$product_context}

EXISTING BLOG TITLES (avoid duplicating or closely matching these):
{$titles_sample}
PROMPT;

    $result = bq_call_openai($instruction, "Generate {$count} unique IT blog post titles.", 'gpt-4.1-nano', 0.9);

    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

    // Parse titles from response
    $lines = array_filter(array_map('trim', explode("\n", $result)));
    // Clean up — remove numbering, bullets, quotes
    $titles = [];
    foreach ($lines as $line) {
        $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
        $line = preg_replace('/^[-*•]\s*/', '', $line);
        $line = trim($line, '"\'');
        $line = trim($line);
        if (mb_strlen($line) >= 10 && mb_strlen($line) <= 200) {
            $titles[] = $line;
        }
    }

    wp_send_json_success(['titles' => $titles, 'count' => count($titles)]);
});

// ─── Admin Page ──────────────────────────────────────────────────────────────

function bq_render_admin() {
    // Ensure tables exist
    global $wpdb;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}bq_queue'") !== $wpdb->prefix . 'bq_queue') {
        bq_create_tables();
    }

    $settings = bq_get_settings();
    $nonce = wp_create_nonce('bq_nonce');
    ?>
    <div class="wrap">
        <h1>Blog Queue</h1>

        <nav class="nav-tab-wrapper" style="border-bottom:2px solid #e2e8f0;margin-bottom:20px;">
            <a href="#" class="nav-tab nav-tab-active bq-tab" data-tab="add">Add Topics</a>
            <a href="#" class="nav-tab bq-tab" data-tab="queue">Queue <span id="bq-queue-count" style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;margin-left:4px;"></span></a>
            <a href="#" class="nav-tab bq-tab" data-tab="history">History</a>
            <a href="#" class="nav-tab bq-tab" data-tab="settings">Settings</a>
        </nav>

        <!-- Add Topics Tab -->
        <div class="bq-panel active" id="bq-panel-add">
            <div style="display:flex;gap:32px;flex-wrap:wrap;">
                <div style="flex:1;min-width:350px;">
                    <h2>Add Blog Topics</h2>
                    <p style="color:#64748b;">Enter one blog topic per line, or use the AI generator to suggest topics.</p>
                    <textarea id="bq-topics" rows="15" style="width:100%;font-size:14px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;" placeholder="Introduction to Cloud Computing for IT Professionals&#10;Top 10 Cybersecurity Certifications in 2026&#10;How to Build a Career in DevOps&#10;Understanding Zero Trust Security Architecture"></textarea>
                    <br>
                    <button type="button" class="button button-primary" id="bq-add-btn" style="margin-top:12px;">Add to Queue</button>
                    <span id="bq-add-status" style="margin-left:12px;"></span>
                </div>
                <div style="flex:1;min-width:350px;">
                    <h2>AI Topic Generator</h2>
                    <p style="color:#64748b;">Generate topic ideas based on your existing content, GSC keyword gaps, and trending searches. Avoids topics you've already covered.</p>
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:500;font-size:13px;display:block;margin-bottom:4px;">Focus area (optional):</label>
                        <input type="text" id="bq-focus-area" style="width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;" placeholder="e.g., cybersecurity, cloud computing, CompTIA, DevOps" />
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
                        <label style="font-weight:500;font-size:13px;">How many:</label>
                        <select id="bq-gen-count" style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="30">30</option>
                            <option value="50">50</option>
                        </select>
                        <button type="button" class="button button-primary" id="bq-generate-btn">Generate Topics</button>
                    </div>
                    <div id="bq-gen-status" style="font-size:13px;margin-bottom:8px;"></div>
                    <div id="bq-gen-results" style="display:none;max-height:400px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:4px;">
                        <div style="padding:8px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                            <label><input type="checkbox" id="bq-gen-check-all" checked /> <strong>Select All</strong></label>
                            <button type="button" class="button button-small" id="bq-gen-add-selected">Add Selected to Queue</button>
                        </div>
                        <div id="bq-gen-list"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Queue Tab -->
        <div class="bq-panel" id="bq-panel-queue">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;">
                <h2 style="margin:0;">Pending Queue</h2>
                <div>
                    <span id="bq-today-info" style="color:#64748b;font-size:13px;margin-right:12px;"></span>
                    <button type="button" class="button button-primary" id="bq-run-queue">Process Queue Now</button>
                </div>
            </div>
            <div id="bq-queue-loading">Loading...</div>
            <table class="wp-list-table widefat fixed striped" id="bq-queue-table" style="display:none;">
                <thead><tr><th>Title</th><th style="width:140px;">Added</th><th style="width:200px;">Actions</th></tr></thead>
                <tbody id="bq-queue-body"></tbody>
            </table>
            <div id="bq-queue-pagination" style="margin-top:12px;"></div>
            <div id="bq-queue-empty" style="display:none;color:#94a3b8;padding:20px;">Queue is empty. Add some topics.</div>
        </div>

        <!-- History Tab -->
        <div class="bq-panel" id="bq-panel-history">
            <h2>Creation History</h2>
            <div id="bq-history-loading">Loading...</div>
            <table class="wp-list-table widefat fixed striped" id="bq-history-table" style="display:none;">
                <thead><tr><th>Title</th><th style="width:100px;">Status</th><th style="width:140px;">Created</th><th style="width:200px;">Actions</th></tr></thead>
                <tbody id="bq-history-body"></tbody>
            </table>
            <div id="bq-history-pagination" style="margin-top:12px;"></div>
        </div>

        <!-- Settings Tab -->
        <div class="bq-panel" id="bq-panel-settings">
            <h2>Settings</h2>
            <table class="form-table" style="max-width:600px;">
                <tr><th>Enabled</th><td><label><input type="checkbox" id="bq-enabled" <?php checked($settings['enabled']); ?> /> Enable automated daily processing</label></td></tr>
                <tr><th>Daily Limit</th><td><input type="number" id="bq-daily-limit" value="<?php echo intval($settings['daily_limit']); ?>" min="1" max="50" style="width:80px;" /><p class="description">Max blogs to create per day. Randomly selected from queue.</p></td></tr>
                <tr><th>Notification Email</th><td><input type="email" id="bq-notify-email" value="<?php echo esc_attr($settings['notify_email']); ?>" style="width:300px;" /></td></tr>
            </table>
            <p><button type="button" class="button button-primary" id="bq-save-settings">Save Settings</button> <span id="bq-settings-status"></span></p>
        </div>
    </div>

    <style>
        .bq-panel { display: none; }
        .bq-panel.active { display: block; }
        .nav-tab-active { border-bottom-color: #2563eb !important; color: #2563eb !important; }
    </style>

    <script>
    jQuery(document).ready(function($) {
        var nonce = '<?php echo $nonce; ?>';

        // Tabs
        $('.bq-tab').click(function(e) {
            e.preventDefault();
            $('.bq-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.bq-panel').removeClass('active');
            $('#bq-panel-' + $(this).data('tab')).addClass('active');
            if ($(this).data('tab') === 'queue') loadQueue(1);
            if ($(this).data('tab') === 'history') loadHistory(1);
        });

        // Add topics
        $('#bq-add-btn').click(function() {
            var btn = $(this).prop('disabled', true).text('Adding...');
            $.post(ajaxurl, { action: 'bq_add_topics', nonce: nonce, topics: $('#bq-topics').val() }, function(resp) {
                btn.prop('disabled', false).text('Add to Queue');
                if (resp.success) {
                    $('#bq-add-status').html('<span style="color:#059669;">' + resp.data.added + ' topics added to queue.</span>');
                    $('#bq-topics').val('');
                    loadQueueCount();
                } else {
                    $('#bq-add-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                }
            });
        });

        // AI Topic Generator
        $('#bq-generate-btn').click(function() {
            var btn = $(this).prop('disabled', true).text('Generating...');
            $('#bq-gen-status').html('<span style="color:#d97706;">Analyzing your existing content, GSC keywords, and trends... (10-20 seconds)</span>');
            $('#bq-gen-results').hide();

            $.ajax({ url: ajaxurl, method: 'POST', timeout: 120000,
                data: {
                    action: 'bq_generate_topics', nonce: nonce,
                    focus_area: $('#bq-focus-area').val(),
                    count: $('#bq-gen-count').val()
                },
                success: function(resp) {
                    btn.prop('disabled', false).text('Generate Topics');
                    if (resp.success && resp.data.titles.length) {
                        $('#bq-gen-status').html('<span style="color:#059669;">' + resp.data.count + ' topics generated. Select the ones you want and add to queue.</span>');
                        var list = $('#bq-gen-list').empty();
                        resp.data.titles.forEach(function(t, i) {
                            list.append('<label style="display:block;padding:8px 12px;border-bottom:1px solid #f1f5f9;cursor:pointer;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'"><input type="checkbox" class="bq-gen-cb" checked data-title="' + t.replace(/"/g, '&quot;') + '" /> ' + t + '</label>');
                        });
                        $('#bq-gen-results').show();
                    } else {
                        $('#bq-gen-status').html('<span style="color:#dc2626;">Error: ' + (resp.data || 'No topics generated') + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Generate Topics');
                    $('#bq-gen-status').html('<span style="color:#dc2626;">Server error/timeout.</span>');
                }
            });
        });

        $('#bq-gen-check-all').change(function() {
            $('.bq-gen-cb').prop('checked', $(this).prop('checked'));
        });

        $('#bq-gen-add-selected').click(function() {
            var selected = [];
            $('.bq-gen-cb:checked').each(function() { selected.push($(this).data('title')); });
            if (!selected.length) { alert('Select at least one topic.'); return; }

            var topics = selected.join("\n");
            var btn = $(this).prop('disabled', true).text('Adding...');
            $.post(ajaxurl, { action: 'bq_add_topics', nonce: nonce, topics: topics }, function(resp) {
                btn.prop('disabled', false).text('Add Selected to Queue');
                if (resp.success) {
                    $('#bq-gen-status').html('<span style="color:#059669;">' + resp.data.added + ' topics added to queue!</span>');
                    $('#bq-gen-results').hide();
                    loadQueueCount();
                }
            });
        });

        // Queue
        function loadQueueCount() {
            $.post(ajaxurl, { action: 'bq_get_queue', nonce: nonce, page: 1 }, function(resp) {
                if (resp.success) $('#bq-queue-count').text(resp.data.total || '');
            });
        }

        function loadQueue(page) {
            $('#bq-queue-loading').show(); $('#bq-queue-table').hide(); $('#bq-queue-empty').hide();
            $.post(ajaxurl, { action: 'bq_get_queue', nonce: nonce, page: page || 1 }, function(resp) {
                $('#bq-queue-loading').hide();
                if (!resp.success || !resp.data.rows.length) { $('#bq-queue-empty').show(); return; }
                var d = resp.data;
                $('#bq-today-info').text('Created today: ' + d.today_count + ' / ' + d.daily_limit);
                var tbody = $('#bq-queue-body').empty();
                d.rows.forEach(function(r) {
                    tbody.append('<tr data-id="' + r.id + '"><td>' + r.title + '</td><td style="font-size:12px;color:#94a3b8;">' + (r.added_at || '').substring(0, 10) + '</td><td>'
                        + '<button class="button button-small bq-process-one" data-id="' + r.id + '">Create Now</button> '
                        + '<button class="button button-small bq-remove" data-id="' + r.id + '" style="color:#dc2626;">Remove</button>'
                        + '</td></tr>');
                });
                $('#bq-queue-table').show();
                var pag = $('#bq-queue-pagination').empty();
                if (d.pages > 1) for (var i = 1; i <= d.pages; i++) pag.append('<button class="' + (i === d.page ? 'button button-primary' : 'button') + ' bq-queue-page" data-page="' + i + '">' + i + '</button> ');
            });
        }

        $(document).on('click', '.bq-queue-page', function() { loadQueue($(this).data('page')); });
        $(document).on('click', '.bq-remove', function() {
            var row = $(this).closest('tr');
            $.post(ajaxurl, { action: 'bq_remove_item', nonce: nonce, id: $(this).data('id') }, function(resp) { if (resp.success) { row.fadeOut(); loadQueueCount(); } });
        });

        $(document).on('click', '.bq-process-one', function() {
            var btn = $(this).prop('disabled', true).text('Creating...');
            var row = btn.closest('tr');
            row.css('opacity', '0.7');
            $.ajax({ url: ajaxurl, method: 'POST', timeout: 600000,
                data: { action: 'bq_process_one', nonce: nonce, id: btn.data('id') },
                success: function(resp) {
                    if (resp.success) {
                        row.find('td:last').html('<span style="color:#059669;">Created!</span> <a href="' + resp.data.url + '" target="_blank">View</a> | <a href="' + resp.data.edit_url + '" target="_blank">Edit</a>');
                        row.css('opacity', '0.5');
                        loadQueueCount();
                    } else {
                        btn.prop('disabled', false).text('Retry'); row.css('opacity', '1');
                        alert('Error: ' + (resp.data || 'Unknown'));
                    }
                },
                error: function() { btn.prop('disabled', false).text('Retry'); row.css('opacity', '1'); }
            });
        });

        $('#bq-run-queue').click(function() {
            if (!confirm('Process the daily queue now? Blogs will be created one at a time.')) return;
            var btn = $(this).prop('disabled', true).text('Processing...');

            if (!$('#bq-run-status').length) {
                btn.after('<div style="margin-top:12px;padding:14px 18px;background:#fff;border:1px solid #e2e8f0;border-left:4px solid #2563eb;border-radius:0 8px 8px 0;" id="bq-run-status"></div>');
            }

            // Get pending queue items and process them one by one
            $.post(ajaxurl, { action: 'bq_get_queue', nonce: nonce, page: 1 }, function(resp) {
                if (!resp.success || !resp.data.rows.length) {
                    btn.prop('disabled', false).text('Process Queue Now');
                    $('#bq-run-status').html('<strong style="color:#d97706;">No pending topics in queue.</strong>').css('border-left-color', '#d97706');
                    return;
                }

                // Randomly shuffle and take up to daily limit
                var items = resp.data.rows.slice();
                var limit = resp.data.daily_limit - resp.data.today_count;
                if (limit <= 0) {
                    btn.prop('disabled', false).text('Process Queue Now');
                    $('#bq-run-status').html('<strong style="color:#d97706;">Daily limit reached (' + resp.data.daily_limit + '). Try again tomorrow.</strong>').css('border-left-color', '#d97706');
                    return;
                }

                // Shuffle for random selection
                for (var i = items.length - 1; i > 0; i--) {
                    var j = Math.floor(Math.random() * (i + 1));
                    var tmp = items[i]; items[i] = items[j]; items[j] = tmp;
                }
                items = items.slice(0, limit);

                var completed = 0, failed = 0, total = items.length;

                function processNext(idx) {
                    if (idx >= items.length) {
                        btn.prop('disabled', false).text('Process Queue Now');
                        $('#bq-run-status').html('<strong style="color:#059669;">' + completed + ' blog(s) created, ' + failed + ' failed.</strong> Check the History tab.').css('border-left-color', '#059669');
                        loadQueue(1); loadQueueCount();
                        return;
                    }

                    var item = items[idx];
                    $('#bq-run-status').html(
                        '<strong>Creating ' + (idx + 1) + ' of ' + total + '...</strong><br>' +
                        '<span style="color:#64748b;font-size:13px;">' + item.title + '</span><br>' +
                        '<span style="color:#94a3b8;font-size:12px;">' + completed + ' created, ' + failed + ' failed so far</span>'
                    ).css('border-left-color', '#2563eb');

                    $.ajax({ url: ajaxurl, method: 'POST', timeout: 600000,
                        data: { action: 'bq_process_one', nonce: nonce, id: item.id },
                        success: function(r) {
                            if (r.success) completed++; else failed++;
                            processNext(idx + 1);
                        },
                        error: function() { failed++; processNext(idx + 1); }
                    });
                }

                processNext(0);
            });
        });

        // History
        function loadHistory(page) {
            $('#bq-history-loading').show(); $('#bq-history-table').hide();
            $.post(ajaxurl, { action: 'bq_get_history', nonce: nonce, page: page || 1 }, function(resp) {
                $('#bq-history-loading').hide();
                if (!resp.success || !resp.data.rows.length) return;
                var tbody = $('#bq-history-body').empty();
                resp.data.rows.forEach(function(r) {
                    var status = r.status === 'completed' ? '<span style="color:#059669;">Published</span>' : '<span style="color:#dc2626;">Failed</span>';
                    var actions = r.post_id ? '<a href="/?p=' + r.post_id + '" target="_blank" class="button button-small">View</a> <a href="<?php echo admin_url('post.php?action=edit&post='); ?>' + r.post_id + '" target="_blank" class="button button-small">Edit</a>' : (r.error_message || '');
                    tbody.append('<tr><td>' + r.title + '</td><td>' + status + '</td><td style="font-size:12px;">' + (r.processed_at || '').substring(0, 16) + '</td><td>' + actions + '</td></tr>');
                });
                $('#bq-history-table').show();
                var pag = $('#bq-history-pagination').empty();
                if (resp.data.pages > 1) for (var i = 1; i <= resp.data.pages; i++) pag.append('<button class="' + (i === resp.data.page ? 'button button-primary' : 'button') + ' bq-hist-page" data-page="' + i + '">' + i + '</button> ');
            });
        }
        $(document).on('click', '.bq-hist-page', function() { loadHistory($(this).data('page')); });

        // Settings
        $('#bq-save-settings').click(function() {
            $.post(ajaxurl, {
                action: 'bq_save_settings', nonce: nonce,
                daily_limit: $('#bq-daily-limit').val(),
                notify_email: $('#bq-notify-email').val(),
                enabled: $('#bq-enabled').is(':checked') ? '1' : '0'
            }, function(resp) {
                $('#bq-settings-status').html(resp.success ? '<span style="color:#059669;">Saved!</span>' : '<span style="color:#dc2626;">Error</span>');
                setTimeout(function() { $('#bq-settings-status').text(''); }, 3000);
            });
        });

        loadQueueCount();
    });
    </script>
    <?php
}

// Also track this plugin in git
