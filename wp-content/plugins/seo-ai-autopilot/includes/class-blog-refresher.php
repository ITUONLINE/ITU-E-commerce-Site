<?php
/**
 * Content Refresher (legacy class name "Blog Refresher" — actually handles
 * blog posts, products, AND pages now since the AIPM merge).
 *
 * Single canonical source of truth for AI rewrite step functions used by
 * SEO AI AutoPilot. Each step is type-aware:
 *   - step_long_content() dispatches to step_content (blog) or
 *     step_product_description (product)
 *   - step_meta_description() handles both — writes post_excerpt + rank_math_description
 *   - step_faq_html() injects product-specific context (course outline, audience)
 *     when the post type is 'product'; otherwise emits the universal FAQ prompt
 *   - step_faq_json, step_rankmath, step_seo_title, step_timestamp are all
 *     content-type neutral
 *
 * Architecture (refactored 2026-04):
 *   SEO AI AutoPilot owns ALL the AI step functions (this class).
 *   AI Product Manager / Blog Queue are thin admin UIs that delegate here
 *   via legacy aipm_step_* / bq_* shims.
 *
 * The class name is kept as "SEOM_Blog_Refresher" for back-compat with
 * external callers that already reference it.
 */

if (!defined('ABSPATH')) exit;

class SEOM_Blog_Refresher {

    private static function featured_product_meta_key() {
        return defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id';
    }

    /**
     * Live list of published WooCommerce products that have a Udemy URL.
     *
     * These are the only products eligible for automatic featured-course
     * matching during blog post refreshes.
     */
    private static function get_priority_udemy_courses() {
        global $wpdb;

        $rows = $wpdb->get_results(
            "SELECT
                p.ID AS product_id,
                p.post_title AS product_title,
                p.post_excerpt AS short_description,
                udemy.meta_value AS udemy_link
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} udemy
                ON udemy.post_id = p.ID
                AND udemy.meta_key = 'udemy_url'
            WHERE p.post_type = 'product'
              AND p.post_status = 'publish'
              AND udemy.meta_value IS NOT NULL
              AND TRIM(udemy.meta_value) <> ''
            ORDER BY p.post_title ASC",
            ARRAY_A
        );

        if (empty($rows) || !is_array($rows)) return [];

        $courses = [];
        foreach ($rows as $row) {
            $id = (int) ($row['product_id'] ?? 0);
            if (!$id) continue;

            $courses[] = [
                'id'                => $id,
                'title'             => sanitize_text_field($row['product_title'] ?? ''),
                'short_description' => wp_trim_words(wp_strip_all_tags($row['short_description'] ?? ''), 28, ''),
                'udemy_link'        => esc_url_raw($row['udemy_link'] ?? ''),
            ];
        }

        return $courses;
    }

    private static function featured_course_tokens($text) {
        $text = strtolower(wp_strip_all_tags((string) $text));
        $text = preg_replace('/[^a-z0-9+#.]+/i', ' ', $text);
        $parts = preg_split('/\s+/', trim($text));
        if (empty($parts)) return [];

        $stop = array_flip([
            'the','and','for','with','from','that','this','your','you','are','can',
            'how','what','why','when','where','into','about','best','guide','learn',
            'course','training','certification','certified','exam','online','using',
            'skills','overview','complete','essential','introduction','basics',
        ]);

        $tokens = [];
        foreach ($parts as $part) {
            $part = trim($part);
            if ($part === '' || strlen($part) < 3 || isset($stop[$part])) continue;
            $tokens[$part] = true;
        }

        return array_keys($tokens);
    }

    private static function rank_featured_course_candidates($courses, $blog_title, $blog_context, $limit = 120) {
        if (empty($courses)) return [];

        $blog_title_tokens = array_flip(self::featured_course_tokens($blog_title));
        $blog_all_tokens   = array_flip(self::featured_course_tokens($blog_title . ' ' . $blog_context));

        foreach ($courses as &$course) {
            $course_tokens = self::featured_course_tokens(($course['title'] ?? '') . ' ' . ($course['short_description'] ?? ''));
            $score = 0;
            foreach ($course_tokens as $token) {
                if (isset($blog_title_tokens[$token])) $score += 4;
                elseif (isset($blog_all_tokens[$token])) $score += 1;
            }
            $course['_score'] = $score;
        }
        unset($course);

        usort($courses, function ($a, $b) {
            $score_cmp = ($b['_score'] ?? 0) <=> ($a['_score'] ?? 0);
            if ($score_cmp !== 0) return $score_cmp;
            return strcasecmp($a['title'] ?? '', $b['title'] ?? '');
        });

        $courses = array_slice($courses, 0, $limit);
        foreach ($courses as &$course) {
            unset($course['_score']);
        }
        unset($course);

        return $courses;
    }

    private static function get_featured_product_context($post_id) {
        if (get_post_type($post_id) !== 'post') return null;

        $product_id = (int) get_post_meta($post_id, self::featured_product_meta_key(), true);
        if (!$product_id || get_post_type($product_id) !== 'product' || get_post_status($product_id) !== 'publish') {
            return null;
        }

        return [
            'id'                => $product_id,
            'title'             => get_the_title($product_id),
            'short_description' => wp_trim_words(wp_strip_all_tags(get_post_field('post_excerpt', $product_id)), 45, ''),
        ];
    }

    /**
     * Pick the best Udemy-backed priority course for a blog title/context.
     *
     * Public so Blog Queue can use the exact same selector before a new post
     * exists. When $post_id is supplied, audit metadata is saved on the post.
     */
    public static function choose_priority_featured_product($blog_title, $blog_context = '', $post_id = 0) {
        $blog_title = sanitize_text_field((string) $blog_title);
        $blog_context = wp_trim_words(wp_strip_all_tags((string) $blog_context), 120, '');
        $post_id = (int) $post_id;

        if ($blog_title === '') return 0;

        $all_courses = self::get_priority_udemy_courses();
        if (empty($all_courses)) return 0;

        $candidates = self::rank_featured_course_candidates($all_courses, $blog_title, $blog_context);
        if (empty($candidates)) return 0;

        $candidate_map = [];
        $prompt_courses = [];
        foreach ($candidates as $course) {
            $id = (int) ($course['id'] ?? 0);
            if (!$id) continue;
            $candidate_map[$id] = $course;
            $prompt_courses[] = [
                'id' => $id,
                'title' => $course['title'] ?? '',
                'short_description' => $course['short_description'] ?? '',
            ];
        }

        $instruction = "You select a featured course for a blog post. Choose only when the blog title is naturally and closely related to one course. If no course is a close fit, choose product_id 0. Never invent a course. Return only JSON with keys product_id, confidence, and reason.";
        $prompt = "Blog post title: {$blog_title}\n\nBlog context:\n{$blog_context}\n\nEligible Udemy courses JSON:\n"
            . wp_json_encode($prompt_courses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
            . "\n\nRules:\n- product_id must be one of the eligible course IDs or 0.\n- Use 0 unless the relationship is direct enough that a reader would expect the course recommendation.\n- confidence must be 0 to 1.\n- Do not match only because both are broadly about IT or careers.";

        $raw = self::call_openai($instruction, $prompt, self::model_for('blog_seo'), 0.1);
        if (is_wp_error($raw)) {
            if ($post_id > 0) {
                update_post_meta($post_id, '_seom_featured_product_ai_error', $raw->get_error_message());
            }
            return 0;
        }

        $clean = trim((string) $raw);
        $clean = preg_replace('/^```(?:json)?\s*/i', '', $clean);
        $clean = preg_replace('/\s*```$/', '', $clean);
        if (preg_match('/\{.*\}/s', $clean, $m)) {
            $clean = $m[0];
        }
        $data = json_decode($clean, true);

        $selected_id = is_array($data) ? (int) ($data['product_id'] ?? 0) : 0;
        $confidence  = is_array($data) ? (float) ($data['confidence'] ?? 0) : 0.0;
        $reason      = is_array($data) ? sanitize_text_field($data['reason'] ?? '') : '';

        if ($post_id > 0) {
            update_post_meta($post_id, '_seom_featured_product_ai_checked_at', current_time('mysql'));
            update_post_meta($post_id, '_seom_featured_product_ai_reason', $reason);
        }

        if ($selected_id > 0 && isset($candidate_map[$selected_id]) && $confidence >= 0.75) {
            if ($post_id > 0) {
                delete_post_meta($post_id, '_seom_featured_product_ai_error');
            }
            return $selected_id;
        }

        return 0;
    }

    /**
     * Select a Udemy-backed course for a blog post before the rewrite.
     *
     * Manual featured-product selections are preserved. Auto selections are
     * re-evaluated each refresh so stale courses can be replaced or removed.
     */
    private static function maybe_update_auto_featured_product($post_id) {
        if (get_post_type($post_id) !== 'post') return 0;

        $meta_key = self::featured_product_meta_key();
        $current_id = (int) get_post_meta($post_id, $meta_key, true);
        $is_auto = get_post_meta($post_id, '_seom_featured_product_auto_selected', true) === '1';

        if ($current_id && !$is_auto) {
            return $current_id;
        }

        $post = get_post($post_id);
        if (!$post) return 0;

        $blog_context = wp_trim_words(wp_strip_all_tags($post->post_excerpt ?: $post->post_content), 120, '');
        $selected_id = self::choose_priority_featured_product($post->post_title, $blog_context, $post_id);
        if ($selected_id > 0) {
            update_post_meta($post_id, $meta_key, $selected_id);
            update_post_meta($post_id, '_seom_featured_product_auto_selected', '1');
            return $selected_id;
        }

        if ($is_auto) {
            delete_post_meta($post_id, $meta_key);
            delete_post_meta($post_id, '_seom_featured_product_auto_selected');
        }

        return 0;
    }

    /**
     * Get SEO performance context for a post from the refresh context transient.
     * Returns a formatted string for AI prompt injection, or empty string if no data.
     */
    public static function get_seo_context($post_id) {
        $ctx = get_transient('seom_refresh_context_' . $post_id);
        if (!$ctx) return '';

        $position = $ctx['position'] ?? 0;
        $clicks = $ctx['clicks'] ?? 0;
        $impressions = $ctx['impressions'] ?? 0;
        $ctr = $ctx['ctr'] ?? 0;
        $category = $ctx['category'] ?? '';
        $category_desc = $ctx['category_desc'] ?? '';
        $queries = $ctx['top_queries'] ?? [];

        // Build the performance summary
        $lines = [];
        $lines[] = "=== CURRENT SEO PERFORMANCE DATA (from Google Search Console, last 28 days) ===";
        $lines[] = "Category: {$category} — {$category_desc}";

        if ($position > 0) {
            $page_num = ceil($position / 10);
            $lines[] = "Current Average Position: {$position} (page {$page_num} of Google)";
        } else {
            $lines[] = "Current Average Position: Not ranking (no position data)";
        }

        $lines[] = "Impressions: {$impressions} (how many times Google showed this page in search results)";
        $lines[] = "Clicks: {$clicks} (how many people clicked through from search)";
        $lines[] = "CTR: " . round($ctr, 2) . "% (click-through rate)";

        // Top search queries
        if (!empty($queries)) {
            $lines[] = "";
            $lines[] = "TOP SEARCH QUERIES people use to find this page:";
            foreach (array_slice($queries, 0, 5) as $q) {
                $qname = $q['query'] ?? '';
                $qpos = round($q['position'] ?? 0, 1);
                $qimp = $q['impressions'] ?? 0;
                $qclicks = $q['clicks'] ?? 0;
                $qctr = round(($q['ctr'] ?? 0), 2);
                $lines[] = "  - \"{$qname}\" — position {$qpos}, {$qimp} impressions, {$qclicks} clicks, {$qctr}% CTR";
            }
        }

        // Category-specific optimization strategy
        $lines[] = "";
        $lines[] = "OPTIMIZATION STRATEGY based on this page's performance:";

        switch ($category) {
            case 'A':
                $lines[] = "- This is a GHOST PAGE — Google is not showing it at all. The content likely lacks topical authority or relevance signals.";
                $lines[] = "- PRIORITY: Comprehensive rewrite with strong keyword targeting, clear topical focus, and authoritative references to establish relevance.";
                $lines[] = "- Add clear, specific headings that match search intent. Use definition-style openings that tell Google exactly what this page is about.";
                $lines[] = "- Include structured data signals: FAQ section, comparison tables, and clear entity mentions.";
                break;
            case 'B':
                $lines[] = "- This is a CTR FIX — the page ranks well and gets impressions but people aren't clicking. The title and meta description are not compelling enough.";
                $lines[] = "- PRIORITY: Make the content opening extremely compelling. The first paragraph should hook readers immediately.";
                $lines[] = "- Write content that delivers on a strong promise — if we improve the title/meta, the content must back it up.";
                $lines[] = "- Focus on differentiating from competitors who rank nearby — what unique value does this page offer?";
                break;
            case 'C':
                $lines[] = "- This is a NEAR WIN — ranking on page 2, close to breaking into page 1. A small improvement could mean a big traffic increase.";
                $lines[] = "- PRIORITY: Deepen content authority and relevance. Add more comprehensive coverage of the topic than competing page-1 results.";
                $lines[] = "- Strengthen E-E-A-T signals: more authoritative citations, more specific data points, more expert-level detail.";
                $lines[] = "- Target the exact search queries listed above — make sure each query is thoroughly addressed in the content.";
                break;
            case 'D':
                $lines[] = "- This page is DECLINING — it used to get more traffic but clicks are dropping. The content may be outdated or competitors have improved.";
                $lines[] = "- PRIORITY: Update all outdated information, add current-year data and trends, refresh examples and tools mentioned.";
                $lines[] = "- Add new sections covering recent developments in this topic area.";
                $lines[] = "- Strengthen the content to be more comprehensive than what's currently ranking above it.";
                break;
            case 'E':
                $lines[] = "- This page is VISIBLE BUT IGNORED — lots of impressions but almost no clicks. People see it in search results but choose other results instead.";
                $lines[] = "- PRIORITY: The content itself needs to be rewritten to better match search intent. Users see the snippet and decide it's not what they need.";
                $lines[] = "- Restructure content to directly address what searchers are looking for based on the queries above.";
                $lines[] = "- Make the opening section immediately valuable — answer the core question in the first 100 words.";
                break;
            case 'F':
                $lines[] = "- This page has BURIED POTENTIAL — Google considers it relevant (it has impressions on page 3+) but doesn't rank it well.";
                $lines[] = "- PRIORITY: Major content upgrade needed. Significantly expand depth, add unique insights, and strengthen topical authority.";
                $lines[] = "- The page needs to be substantially better than what's currently on page 1 to climb from this position.";
                $lines[] = "- Focus heavily on the search queries above — build comprehensive coverage around each one.";
                break;
            default:
                $lines[] = "- General optimization: improve content quality, depth, and keyword relevance.";
        }

        // Append competitive research if available
        $research = $ctx['competitive_research'] ?? '';
        if (!empty($research)) {
            $research_date = $ctx['research_date'] ?? 'just collected';
            $lines[] = "";
            $lines[] = "=== COMPETITIVE INTELLIGENCE (web search of top-ranking pages, collected: {$research_date}) ===";
            $lines[] = $research;
            $lines[] = "";
            $lines[] = "HOW TO USE THIS COMPETITIVE RESEARCH:";
            $lines[] = "";
            $lines[] = "DO use the research to:";
            $lines[] = "- Cover TOPICS and CONCEPTS that top-ranking pages cover (content gaps identified above)";
            $lines[] = "- Answer the People Also Ask questions within your content body (NOT as a separate FAQ section — FAQs are generated in a separate step with proper schema)";
            $lines[] = "- Match or exceed the content depth (word count, number of sections) of top results";
            $lines[] = "- Incorporate unique angles that competitors are missing";
            $lines[] = "- Structure headings to target the same search intents competitors rank for";
            $lines[] = "";
            $lines[] = "DO NOT use the research to:";
            $lines[] = "- Fabricate course outlines, module lists, curriculum details, or pricing you don't have — only reference what exists in the provided content";
            $lines[] = "- Add images, videos, infographics, or interactive elements — you are generating text/HTML only";
            $lines[] = "- Invent instructor names, credentials, student reviews, or testimonials";
            $lines[] = "- Promise features, labs, practice tests, or tools unless they are mentioned in the existing content";
            $lines[] = "- Copy competitor content or structure directly — use the intelligence to INFORM, not imitate";
            $lines[] = "- Add a FAQ section to the content — FAQs are generated separately with proper JSON-LD schema in a dedicated pipeline step. Including inline FAQs creates duplicates";
            $lines[] = "";
            $lines[] = "If the research suggests including something you don't have data for (like a detailed syllabus or specific pricing), "
                     . "write about the TOPIC conceptually instead. For example, if research says 'include a course outline,' write about "
                     . "what skills and knowledge areas the certification covers — don't fabricate a module-by-module breakdown.";
        }

        return implode("\n", $lines);
    }

    /**
     * Convert any Markdown formatting that slipped through to proper HTML.
     */
    private static function fix_markdown_in_html($content) {
        // ### Heading -> <h3>Heading</h3>
        $content = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $content);
        $content = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $content);
        $content = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $content);
        $content = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $content);

        // **bold** -> <strong>bold</strong>
        $content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $content);

        // *italic* -> <em>italic</em> (but not inside HTML tags)
        $content = preg_replace('/(?<![<\/\w])\*([^*\n]+)\*(?![>])/', '<em>$1</em>', $content);

        // Fix invalid callout classes
        $content = preg_replace_callback('/itu-callout--([a-z\-]+)/', function ($m) {
            $v = $m[1];
            if (strpos($v, 'tip') !== false) return 'itu-callout--tip';
            if (strpos($v, 'info') !== false || strpos($v, 'note') !== false) return 'itu-callout--info';
            if (strpos($v, 'warn') !== false || strpos($v, 'caution') !== false) return 'itu-callout--warning';
            if (strpos($v, 'key') !== false || strpos($v, 'purple') !== false || strpos($v, 'important') !== false) return 'itu-callout--key';
            if (in_array($v, ['tip','info','warning','key'])) return 'itu-callout--' . $v;
            return 'itu-callout--tip';
        }, $content);

        return $content;
    }

    /**
     * Fix FAQ answers that have raw text without <p> tags inside faq-content divs.
     */
    private static function fix_faq_paragraphs($html) {
        // Find all faq-content div contents and ensure text is wrapped in <p> tags
        return preg_replace_callback(
            '/<div class="faq-content">(.*?)<\/div>/s',
            function ($match) {
                $content = trim($match[1]);

                // If already has <p> tags, leave it alone
                if (stripos($content, '<p>') !== false) return $match[0];

                // Split on double newlines or periods followed by spaces (sentence boundaries)
                // Then wrap each chunk in <p> tags
                $chunks = preg_split('/\n\s*\n/', $content);
                if (count($chunks) <= 1) {
                    // No double newlines — try splitting long text into ~3 sentence chunks
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
                }

                $wrapped = '';
                foreach ($chunks as $chunk) {
                    $chunk = trim($chunk);
                    if (empty($chunk)) continue;
                    // Don't wrap if it's already a block element
                    if (preg_match('/^<(p|ul|ol|table|blockquote|h[2-6]|div)/i', $chunk)) {
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

    /**
     * Check if blog refresh is available (API key configured).
     */
    public static function is_available() {
        if (function_exists('itu_ai_key')) {
            return !empty(itu_ai_key('blog_writer'));
        }
        return !empty(get_option('ai_post_api_key'));
    }

    /**
     * Detect if a model requires the Responses API (gpt-5.x, o-series reasoning models).
     * GPT-4.1 family uses Chat Completions; GPT-5+ uses Responses API.
     */
    private static function use_responses_api($model) {
        // gpt-5.x, gpt-5, o1, o3, o4 models require Responses API
        if (preg_match('/^(gpt-5|o[1-9])/', $model)) return true;
        return false;
    }

    /**
     * Call OpenAI with given instruction and prompt.
     * Automatically selects Chat Completions or Responses API based on model.
     * GPT-5.x models do not support temperature — uses reasoning effort instead.
     */
    /**
     * Resolve the model for a specific blog refresh step from centralized AI settings.
     */
    private static function model_for($process) {
        return function_exists('itu_ai_model') ? itu_ai_model($process) : '';
    }

    private static function call_openai($instruction, $user_prompt = '', $model = '', $temperature = 0.7) {
        if (!$model) $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

        // Use unified provider router if available
        if (function_exists('itu_ai_call')) {
            return itu_ai_call($instruction, $user_prompt, $model, $temperature, ['key_name' => 'blog_writer', 'timeout' => 240]);
        }

        $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
        if (!$api_key) return new WP_Error('no_key', 'Blog Writer API key not configured.');

        if (self::use_responses_api($model)) {
            return self::call_responses_api($api_key, $model, $instruction, $user_prompt);
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
            'body' => json_encode([
                'model'       => $model,
                'messages'    => $messages,
                'temperature' => $temperature,
            ]),
            'timeout' => 180,
        ]);

        if (is_wp_error($response)) return $response;

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $content = trim($data['choices'][0]['message']['content'] ?? '');

        if (empty($content)) return new WP_Error('empty', 'No content returned from OpenAI.');

        return $content;
    }

    /**
     * Call OpenAI Responses API (for gpt-5.x and reasoning models).
     * Uses 'input' instead of 'messages', 'instructions' for system prompt,
     * and does NOT send temperature (unsupported — uses default reasoning).
     */
    private static function call_responses_api($api_key, $model, $instruction, $user_prompt = '') {
        $input = [];
        if ($user_prompt) {
            $input = [
                ['role' => 'user', 'content' => $user_prompt],
            ];
        } else {
            // If no user prompt, send instruction as user input
            $input = $instruction;
            $instruction = '';
        }

        $body = [
            'model' => $model,
            'input' => $input,
        ];

        // System-level guidance goes in 'instructions' (not as a message role)
        if (!empty($instruction)) {
            $body['instructions'] = $instruction;
        }

        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body'    => json_encode($body),
            'timeout' => 240,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $err = $data['error']['message'] ?? "HTTP {$code}";
            return new WP_Error('responses_api_error', 'OpenAI Responses API error: ' . $err);
        }

        // Responses API returns output as an array of content blocks
        $output = $data['output'] ?? [];
        $content = '';
        foreach ($output as $block) {
            if (($block['type'] ?? '') === 'message') {
                foreach (($block['content'] ?? []) as $part) {
                    if (($part['type'] ?? '') === 'output_text') {
                        $content .= $part['text'] ?? '';
                    }
                }
            }
        }

        $content = trim($content);
        if (empty($content)) return new WP_Error('empty', 'No content returned from Responses API.');

        return $content;
    }

    /**
     * Check if a year in text is a date reference vs a product version number.
     * Returns true if the year appears to be a date (safe to update).
     */
    private static function is_date_year($text, $year, $match_pos) {
        // Words that precede product version years — do NOT update these
        $version_prefixes = [
            'server', 'windows', 'office', 'word', 'excel', 'outlook', 'powerpoint',
            'access', 'visio', 'project', 'exchange', 'sharepoint', 'sql',
            'visual studio', 'autocad', 'solidworks', 'revit', 'sketchup',
            'quickbooks', 'photoshop', 'illustrator', 'indesign', 'premiere',
            'r2', 'edition', 'version', 'v', 'release',
        ];

        // Get the ~40 chars before the year match
        $before = strtolower(substr($text, max(0, $match_pos - 40), min(40, $match_pos)));

        foreach ($version_prefixes as $prefix) {
            if (str_contains($before, $prefix)) return false;
        }

        // Check if the year is followed by version-like suffixes
        $after = strtolower(substr($text, $match_pos + 4, 20));
        $version_suffixes = [' r2', ' sp', ' edition', ' server', ' lts'];
        foreach ($version_suffixes as $suffix) {
            if (str_starts_with($after, $suffix)) return false;
        }

        return true;
    }

    /**
     * Replace stale years in a string, but only date references — not product versions.
     * "Job Trends for 2025" → "Job Trends for 2026"
     * "Windows Server 2016" → unchanged
     * "Word 2019" → unchanged
     */
    private static function replace_stale_years($text, $current_year) {
        return preg_replace_callback('/\b(20[2-3]\d)\b/', function($m) use ($current_year, $text) {
            $year = (int) $m[1];
            if ($year >= $current_year) return $m[0]; // not stale
            // Find the position of this match in the original text
            $pos = strpos($text, $m[0]);
            if ($pos !== false && !self::is_date_year($text, $year, $pos)) {
                return $m[0]; // product version — don't touch
            }
            return (string) $current_year;
        }, $text);
    }

    /**
     * Update stale year references in a post's title and SEO title to the current year.
     * Only updates date-context years — preserves product version numbers like
     * "Windows Server 2016", "Word 2019", "SQL Server 2022", etc.
     * Returns true if any updates were made.
     */
    public static function update_title_year($post_id) {
        $current_year = (int) date('Y');
        $post = get_post($post_id);
        if (!$post) return false;

        $updated = false;
        $original_slug = $post->post_name;

        // Update post title — preserve the original slug to avoid breaking the permalink
        $new_title = self::replace_stale_years($post->post_title, $current_year);
        if ($new_title !== $post->post_title) {
            wp_update_post([
                'ID'         => $post_id,
                'post_title' => $new_title,
                'post_name'  => $original_slug, // force WordPress to keep the original slug
            ]);
            $updated = true;
        }

        // Update RankMath SEO title if it contains a stale year
        $seo_title = get_post_meta($post_id, 'rank_math_title', true);
        if ($seo_title) {
            $new_seo = self::replace_stale_years($seo_title, $current_year);
            if ($new_seo !== $seo_title) {
                update_post_meta($post_id, 'rank_math_title', $new_seo);
                $updated = true;
            }
        }

        return $updated;
    }

    /**
     * Step 1: Generate new blog content from existing content.
     */
    /**
     * Long-content rewrite — type-aware entry point.
     *
     * Routes to step_content() for blog posts and step_product_description()
     * for products. Both are full content rewrites; the difference is only in
     * voice (article vs course-listing marketing copy) and a few product-only
     * context injections (course outline from SKU, trademark blocks).
     */
    public static function step_long_content($post_id) {
        $post_type = get_post_type($post_id);
        if ($post_type === 'product') {
            return self::step_product_description($post_id);
        }
        // Default: blog post / page rewrite
        return self::step_content($post_id);
    }

    /**
     * Generate the long product description (post_content) for a WooCommerce
     * course listing. Single canonical product description prompt — this is
     * where AIPM's iterated prompt was moved to.
     */
    public static function step_product_description($post_id) {
        // Update stale years in title before generating content
        self::update_title_year($post_id);

        $title   = get_the_title($post_id);
        $content = wp_strip_all_tags(get_post_field('post_content', $post_id));

        // Inject course outline when available — gives AI real module/lesson
        // structure to write from instead of paraphrasing the old description
        $outline_text = '';
        $sku = get_post_meta($post_id, '_sku', true);
        if ($sku && function_exists('get_course_outline_from_sku')) {
            $outline = get_course_outline_from_sku($sku);
            if (is_array($outline) && count($outline)) {
                $csv_lines = array_map(function ($row) {
                    return "{$row['module_title']},{$row['lesson_title']}";
                }, $outline);
                $outline_text = "Course Outline:\n" . implode("\n", $csv_lines);
            }
        }
        // Cap to first 50 modules to avoid token overflow
        if (!empty($outline_text) && substr_count($outline_text, "\n") > 50) {
            $ol_lines = explode("\n", $outline_text);
            $outline_text = implode("\n", array_slice($ol_lines, 0, 51)) . "\n... (additional modules omitted)";
        }

        // For refreshes prefer the outline; for new courses without an outline
        // fall back to existing content as context
        if (!empty($outline_text)) {
            $prompt_content = $outline_text;
        } elseif (!empty(trim($content))) {
            $prompt_content = mb_substr($content, 0, 3000);
        } else {
            $prompt_content = '(No existing content or outline available. Write based on the course title.)';
        }

        // Trademark reminders for vendor/cert names found in the title
        $tm_block = self::build_product_trademark_block($title);

        $prompt = trim($title . $tm_block . "\n\n" . $prompt_content);

        $instruction = self::get_product_description_instruction();

        // Inject data-driven target keywords from GSC
        if (class_exists('SEOM_Keyword_Researcher')) {
            $kw_data = SEOM_Keyword_Researcher::get_target_keywords($post_id);
            if (!empty($kw_data['primary'])) {
                $instruction .= "\n\nTARGET SEO KEYWORDS (from actual Google search data):\n"
                    . "Primary keyword (use in the opening paragraph + one H2 heading + 3-5 times naturally): " . $kw_data['primary'] . "\n";
                if (!empty($kw_data['secondary'])) {
                    $instruction .= "LSI/Secondary keywords (use each 1-2 times naturally): " . implode(', ', $kw_data['secondary']) . "\n";
                }
                if (!empty($kw_data['rising'])) {
                    $instruction .= "Trending/Rising keywords (incorporate these — they are gaining search volume): " . implode(', ', $kw_data['rising']) . "\n";
                }
                $instruction .= "These are real queries people use to find this course. Optimize for them.";
            }
        }

        // Inject SEO performance context into the user prompt
        $seo_context = self::get_seo_context($post_id);
        if ($seo_context) {
            $prompt .= "\n\n" . $seo_context;
        }

        $desc_model = function_exists('itu_ai_model') ? itu_ai_model('product_description') : self::model_for('blog_outline');
        $result = self::call_openai($instruction, $prompt, $desc_model);
        if (is_wp_error($result)) return $result;
        if (empty(trim($result))) {
            return new WP_Error('empty_result', 'AI returned empty product description.');
        }

        // Fix any markdown that slipped through — convert to HTML
        $result = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $result);
        $result = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $result);
        $result = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $result);
        $result = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $result);
        $result = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $result);
        $result = preg_replace('/(?<![<\/\w])\*([^*\n]+)\*(?![>])/', '<em>$1</em>', $result);

        // Clear old RankMath schema meta before replacing content
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
            $post_id, 'rank_math_schema_%'
        ));

        // Fully replace post_content (removes any old RankMath FAQ Gutenberg blocks)
        wp_update_post(['ID' => $post_id, 'post_content' => $result]);
        return $result;
    }

    /**
     * Canonical product description instruction. This is the single source of
     * truth for AI-generated product/course content across the entire system.
     */
    private static function get_product_description_instruction() {
        $site_name = get_bloginfo('name');
        $rules = [
            "You are an experienced IT instructor writing a course description for {$site_name}. Write as if YOU built this course and are personally explaining it to a prospective student sitting across from you. Your voice is that of a teacher who knows the subject deeply — direct, confident, specific, occasionally opinionated about what matters most. Every description you write should feel unique to THIS course, not like a template applied to different topics. Never sound like a marketing department or an AI.",
            '',
            '=== OUTPUT FORMAT — READ THIS FIRST ===',
            'Return ONLY valid HTML. ABSOLUTELY NO MARKDOWN.',
            '- Do NOT use **bold** — use <strong>text</strong>',
            '- Do NOT use ## or ### — use <h2> and <h3>',
            '- Do NOT use - or * for lists — use <ul><li>',
            '- Tags allowed: p, h2, h3, ul, li, ol, strong, em, blockquote. No classes. No h1.',
            '',
            '=== TRADEMARK & COPYRIGHT ===',
            'When you mention a vendor or certification name, use the proper symbol on FIRST mention only:',
            '- Vendor names: CompTIA&reg;, Cisco&reg;, Microsoft&reg;, AWS&reg;, EC-Council&reg;, ISC2&reg;, ISACA&reg;, PMI&reg;',
            '- Cert names: CEH&trade;, C|EH&trade;, CISSP&reg;, Security+&trade;, A+&trade;, CCNA&trade;, PMP&reg;',
            '- After first mention, symbols may be omitted.',
            '- ONLY include a trademark disclaimer if you actually mention trademarked vendor/cert names in the content.',
            '  If included, place at end: <p><em>[Vendor]&reg; and [Cert]&trade; are trademarks of [Owner]. This content is for educational purposes.</em></p>',
            '- Do NOT add a blanket disclaimer for vendors not mentioned in the content.',
            '- Do NOT invent exam codes not in the title or content.',
            '',
            '=== YOUR TASK ===',
            "Write a comprehensive product description for an on-demand IT training course sold on {$site_name}.",
            'This is an ON-DEMAND course — students purchase it and access self-paced video training immediately.',
            'Write completely fresh copy. Do NOT reuse or rephrase the existing content.',
            '',
            '=== CONTENT REQUIREMENTS ===',
            'TARGET: 1,500-2,500 words. Competing pages average 2,000-3,000 words with 8-15 sections.',
            'Do NOT stop at 500 words. Write a thorough, in-depth description that competes with top-ranking pages.',
            '',
            'You decide the structure. Choose sections and headings that make sense for THIS specific course.',
            'Cover topics like: what the course teaches, who benefits from it, what skills they gain,',
            'career impact, exam preparation (if certification-related), industry context, prerequisites.',
            'If a course outline is provided, use it to write about specific modules and skills in detail.',
            '',
            'DEPTH REQUIREMENTS:',
            '- Each h2 section: 150-300 words minimum. Go deep. Explain the "why" and "how."',
            '- Include specific details: exam domains, job titles, salary ranges, tools, frameworks, real scenarios.',
            '- Use format variety: paragraphs, bullet lists, numbered lists, blockquotes for key insights.',
            '',
            'CONTENT RULES:',
            '- BANNED OPENINGS — Do NOT start with sweeping generalizations about technology, the industry, or the modern world.',
            '  No "In today\'s rapidly evolving...", "As technology continues to...", "In the modern IT landscape...", etc.',
            '  Open with something SPECIFIC: a concrete problem this skill solves, a real workplace scenario, or a hard fact.',
            '- Do NOT include a FAQ section in the description. FAQs are generated separately with proper schema markup.',
            '- Do NOT mention course stats like video hours, lesson counts, or platform features.',
            '- Do NOT invent certifications, exam codes, or credentials not referenced in the course title or content.',
            '- Write in second person ("you"), active voice, varied sentence length.',
            '- Bold the primary keyword with <strong> once in the opening.',
        ];
        return implode("\n", $rules);
    }

    /**
     * Build a vendor/cert trademark reminder block based on keywords found
     * in the course title. Empty string if no recognized keywords.
     */
    private static function build_product_trademark_block($title) {
        $title_lower = strtolower($title);
        $tm_map = [
            'ceh'             => 'Use "EC-Council&reg; Certified Ethical Hacker (C|EH&trade;)" on first mention.',
            'ec-council'      => 'Use "EC-Council&reg;" with &reg; on first mention.',
            'ethical hacker'  => 'Use "EC-Council&reg; Certified Ethical Hacker (C|EH&trade;)" on first mention.',
            'comptia'         => 'Use "CompTIA&reg;" with &reg; on first mention.',
            'security+'       => 'Use "CompTIA&reg; Security+&trade;" on first mention.',
            'network+'        => 'Use "CompTIA&reg; Network+&trade;" on first mention.',
            'a+'              => 'Use "CompTIA&reg; A+&trade;" on first mention.',
            'cisco'           => 'Use "Cisco&reg;" with &reg; on first mention.',
            'ccna'            => 'Use "Cisco&reg; CCNA&trade;" on first mention.',
            'ccnp'            => 'Use "Cisco&reg; CCNP&trade;" on first mention.',
            'aws'             => 'Use "AWS&reg;" (Amazon Web Services) with &reg; on first mention.',
            'microsoft'       => 'Use "Microsoft&reg;" with &reg; on first mention.',
            'azure'           => 'Use "Microsoft&reg; Azure&trade;" on first mention.',
            'cissp'           => 'Use "ISC2&reg; CISSP&reg;" on first mention.',
            'pmp'             => 'Use "PMI&reg; PMP&reg;" on first mention.',
            'isaca'           => 'Use "ISACA&reg;" with &reg; on first mention.',
            'cisa'            => 'Use "ISACA&reg; CISA&reg;" on first mention.',
            'cism'            => 'Use "ISACA&reg; CISM&reg;" on first mention.',
        ];

        $reminders = [];
        foreach ($tm_map as $keyword => $reminder) {
            if (strpos($title_lower, $keyword) !== false) {
                $reminders[] = $reminder;
            }
        }
        $reminders = array_unique($reminders);
        if (empty($reminders)) return '';

        return "\n\nTRADEMARK REQUIREMENTS FOR THIS COURSE (legal — do not skip):\n- " . implode("\n- ", $reminders)
            . "\n- End with a disclaimer naming ONLY the trademarks listed above — not a generic blanket disclaimer.";
    }

    /**
     * Single source of truth for the long-form blog body writing instruction.
     *
     * Used by:
     *   - SEOM_Blog_Refresher::step_content() — refresh path (mode = 'rewrite')
     *   - Blog Queue bq_create_blog() and bq_process_step step 2 — new-blog
     *     paths (mode = 'new')
     *
     * Both paths get the same prompt rules (banned phrases, depth, scannability,
     * format variety, references, citations, trademarks, GEO/AI search). The
     * mode flag tweaks one sentence at the top so the AI knows whether it's
     * rewriting existing content or writing fresh from an outline. Optional
     * context blocks (target keywords, GSC perf data, featured product) are
     * appended only when supplied.
     *
     * @param string $site_name e.g. get_bloginfo('name')
     * @param array  $opts {
     *     @type string $mode               'rewrite' (default) or 'new'.
     *     @type array  $kw_data            From SEOM_Keyword_Researcher::get_target_keywords().
     *                                      Shape: ['primary' => string, 'secondary' => array, 'rising' => array].
     *     @type string $seo_context        Already-formatted SEO context block from
     *                                      SEOM_Blog_Refresher::get_seo_context($post_id).
     *     @type string $gap_keyword_block  User-supplied gap keyword text (Blog Queue uses this).
     *     @type string $target_keywords    Comma-separated specific target keywords (Blog Queue).
     *     @type array  $featured_product   ['title' => string, 'short_description' => string]
     *                                      — appended as a context note. The shortcode is
     *                                      injected outside this function.
     * }
     * @return string The instruction string ready to pass as the system prompt.
     */
    public static function build_blog_writer_instruction($site_name, $opts = []) {
        $mode = isset($opts['mode']) && $opts['mode'] === 'new' ? 'new' : 'rewrite';

        $opening = $mode === 'new'
            ? "Write a brand-new long-form blog post from the outline below. Cover EVERY section in the outline thoroughly."
            : "Rewrite the existing content below to optimize for SEO, freshness, and scannability while preserving the core information. Improve it significantly with more depth, examples, and actionable advice.";

        $instruction = "You are a professional IT blog writer for {$site_name}. Your tone is direct, knowledgeable, and practical. You never sound like a marketing bot or AI. You write for busy IT professionals who scan pages — not people who read every word.\n\n"
            . $opening . "\n\n"
            . "BANNED PHRASES — Do NOT use any of these openings or clichés:\n"
            . "- In today's rapidly evolving...\n"
            . "- In an ever-changing landscape...\n"
            . "- In the fast-paced world of...\n"
            . "- As technology continues to...\n"
            . "- In today's digital age...\n"
            . "- With the growing importance of...\n"
            . "- As organizations increasingly...\n"
            . "- In the modern IT landscape...\n"
            . "- Any variation of these patterns\n"
            . "Instead, open with something specific: a concrete problem, a real scenario, or a direct statement about what the reader will learn.\n\n"
            . "IMPORTANT: Do NOT invent or fabricate any certification names, exam codes, or credential titles. Only mention certifications and exam codes that are explicitly referenced in the outline or existing content.\n"
            . "IMPORTANT: Do NOT include any shortcodes (text in square brackets like [example]) in your output. They will be added separately.\n\n"
            . "WORD COUNT — CRITICAL: The post MUST be at least 2,000 words. Each main section must be 200-350 words. Do NOT summarize or abbreviate — write every section in full detail with specific examples and actionable advice.\n\n"
            . "DEPTH REQUIREMENTS — This is critical:\n"
            . "- Each major section (h2) must contain 150-300 words minimum\n"
            . "- Do not write thin, surface-level summaries. Go deep. Explain the \"why\" and \"how,\" not just the \"what\"\n"
            . "- Include specific examples, real-world scenarios, tool names, command examples, or step-by-step explanations where relevant\n"
            . "- When comparing options, actually compare them — don't just list them\n"
            . "- When explaining a concept, explain it fully enough that someone unfamiliar could understand it\n"
            . "- Do not say \"we will include...\" — actually write the content in full detail\n\n"
            . "SCANNABILITY AND FORMAT VARIETY — Readers scan, they don't read. You MUST use a variety of these formatting elements throughout the post (not just paragraphs and bullets):\n\n"
            . "1. <p> tags for paragraphs — keep paragraphs SHORT (2-4 sentences max). Long paragraphs kill readability\n"
            . "2. <ul><li> for unordered bullet lists — use for features, options, or items without priority\n"
            . "3. <ol><li> for numbered/ordered lists — use for steps, processes, or ranked items\n"
            . "4. <strong> for key terms and important phrases inline — bold the first mention of key concepts so scanners catch them\n"
            . "5. <blockquote> for notable quotes, industry insights, or a compelling statement — styled as a highlighted pull-quote\n"
            . "6. Callout boxes for tips, key takeaways, warnings, or important notes — use 1-3 per post total (mix of blockquotes and callouts). Use this exact HTML format for callouts:\n"
            . "   ONLY use these exact callout classes (do NOT combine or modify them):\n"
            . "   <div class=\"itu-callout itu-callout--tip\"><p><strong>Pro Tip</strong></p><p>Content.</p></div>\n"
            . "   <div class=\"itu-callout itu-callout--info\"><p><strong>Note</strong></p><p>Content.</p></div>\n"
            . "   <div class=\"itu-callout itu-callout--warning\"><p><strong>Warning</strong></p><p>Content.</p></div>\n"
            . "   <div class=\"itu-callout itu-callout--key\"><p><strong>Key Takeaway</strong></p><p>Content.</p></div>\n"
            . "7. <table> ONLY for simple 2-column comparisons (e.g., Feature vs Benefit, Option A vs Option B). Never use tables with 3+ columns — they break on mobile. For multi-item comparisons, use bullet lists with <strong>bold labels</strong> instead\n"
            . "8. <h3> subheadings within sections to break up long sections — use when a section covers multiple sub-topics\n\n"
            . "OUTPUT FORMAT — CRITICAL:\n"
            . "- Return ONLY valid HTML. Do NOT use Markdown syntax anywhere\n"
            . "- Do NOT use # or ## or ### for headings — use <h2> and <h3> HTML tags\n"
            . "- Do NOT use **bold** markdown — use <strong> HTML tags\n"
            . "- Do NOT use - or * for lists — use <ul><li> or <ol><li> HTML tags\n"
            . "- Do NOT use ``` for code blocks — use <code> or <pre> HTML tags\n\n"
            . "STRUCTURE:\n"
            . "- Use <h2> tags for all major sections\n"
            . "- Use <h3> for subpoints within sections\n"
            . "- Do NOT include an <h1> tag\n"
            . "- Do not use numbering or Roman numerals in headings\n"
            . "- The Introduction should hook the reader with a specific problem or scenario, then preview the key takeaways\n"
            . "- The Conclusion must summarize key points and include a clear call to action\n"
            . "- Every section must mix at least 2 different format types (e.g., paragraph + list, paragraph + table, paragraph + blockquote)\n"
            . "- Cover EVERY section in the outline thoroughly — do not skip any\n\n"
            . "SEO AND GEO OPTIMIZATION:\n"
            . "- Use clear, factual language for accurate citation by generative AI\n"
            . "- Include relevant keywords and LSI keywords naturally throughout\n"
            . "- Phrase concepts to mirror common user questions (e.g., \"What is...\", \"How does...\", \"Why is...\")\n"
            . "- Use named entity \"{$site_name}\" where appropriate for attribution\n"
            . "- Write like a real person. Vary sentence length — mix short punchy sentences with longer explanatory ones\n\n"
            . "AUTHORITATIVE REFERENCES AND DATA — REQUIRED (critical for credibility):\n"
            . "Every blog post MUST include at least 3-5 distinct authoritative references from DIFFERENT sources. Do NOT rely on a single source.\n\n"
            . "REQUIRED reference types (include ALL that apply to the topic):\n"
            . "1. GOVERNING BODIES & CERT AUTHORITIES — MUST cite official source for relevant cert/vendor:\n"
            . "   CompTIA, Cisco, Microsoft (learn.microsoft.com), AWS, ISC2, ISACA, PMI, EC-Council,\n"
            . "   Axelos/PeopleCert, Google Cloud, Linux Foundation, Red Hat, VMware/Broadcom, Juniper, Palo Alto Networks\n"
            . "2. COMPLIANCE & REGULATORY FRAMEWORKS:\n"
            . "   NIST (CSF, SP 800), ISO 27001/27002/20000, PCI DSS (pcisecuritystandards.org),\n"
            . "   HIPAA/HHS (hhs.gov), GDPR/EDPB, SOC 2/AICPA, FedRAMP, CMMC/DoD, CISA,\n"
            . "   SEC, FERPA, CCPA, COBIT, HITRUST\n"
            . "3. GOVERNMENT & WORKFORCE:\n"
            . "   BLS (bls.gov/ooh/), DoD Cyber Workforce (public.cyber.mil), DHS, NSA, FTC, GAO,\n"
            . "   Dept of Labor (dol.gov), NSF (nsf.gov)\n"
            . "4. PROFESSIONAL ASSOCIATIONS & HR ORGANIZATIONS:\n"
            . "   SHRM (shrm.org), ISSA, IAPP, ACM, IEEE, ITSMF, HDI, Cloud Security Alliance,\n"
            . "   InfraGard, AICPA, World Economic Forum, NICE/NIST Workforce Framework,\n"
            . "   (ISC)² Workforce Study, CompTIA workforce reports\n"
            . "5. INDUSTRY RESEARCH & ANALYST FIRMS:\n"
            . "   Gartner, Forrester, IDC, McKinsey, Deloitte, PwC, KPMG, SANS Institute,\n"
            . "   Cybersecurity Ventures, Verizon DBIR, IBM Cost of a Data Breach, Ponemon Institute,\n"
            . "   CrowdStrike Threat Report, Mandiant/Google Threat Intel\n"
            . "6. TECHNICAL STANDARDS: Official vendor docs, IETF RFCs, OWASP, CIS Benchmarks,\n"
            . "   MITRE ATT&CK, W3C, FIRST (first.org)\n"
            . "7. SALARY — Use MULTIPLE sources: BLS, Glassdoor, PayScale, Robert Half, Indeed,\n"
            . "   LinkedIn, Dice, Global Knowledge Salary Report, SHRM compensation data\n\n"
            . "CITATION RULES:\n"
            . "- Format as HTML links: <a href=\"URL\" target=\"_blank\" rel=\"noopener\">Source Name</a>\n"
            . "- Spread references throughout — each H2 section should have at least one\n"
            . "- VARY sources — do not cite same org more than twice per article\n"
            . "- For certs, always reference official cert page for exam details (domains, questions, passing score, cost)\n"
            . "- NEVER reference, link to, or mention competing IT training providers, online course platforms, bootcamps, or training companies. This includes: Coursera, Udemy, Pluralsight, CBT Nuggets, Cybrary, LinkedIn Learning, A Cloud Guru, INE, Infosec Institute, Training Camp, Global Knowledge, Skillsoft, Simplilearn, KnowledgeHut, edX, Codecademy, DataCamp, or ANY other entity that sells IT training. For learning resources, cite official vendor docs (Microsoft Learn, AWS Skill Builder, Cisco Learning Network) instead.\n"
            . "- Do NOT fabricate URLs — use main domain or well-known subpages you are confident exist\n"
            . "- Include concrete data: salary ranges, growth %, pass rates, market size, exam details\n"
            . "- Think like a human researcher: cross-reference claims from multiple sources\n\n"
            . "TRADEMARK & COPYRIGHT:\n"
            . "When you mention a vendor or certification by name, use the proper symbol on FIRST mention only:\n"
            . "- Vendor names get &reg; : CompTIA&reg;, Cisco&reg;, Microsoft&reg;, AWS&reg;, EC-Council&reg;, ISC2&reg;, ISACA&reg;, PMI&reg;\n"
            . "- Cert names get &trade; or &reg; : CEH&trade;, CISSP&reg;, Security+&trade;, A+&trade;, CCNA&trade;, PMP&reg;\n"
            . "- After first mention, symbols may be omitted.\n"
            . "- If you mention EC-Council or CEH, use 'EC-Council&reg; Certified Ethical Hacker (C|EH&trade;)' on first mention.\n"
            . "- ONLY include a trademark disclaimer at the end if you actually used trademarked names in the content.\n"
            . "  The disclaimer should name ONLY the specific trademarks you mentioned — not a blanket list of every vendor.\n"
            . "  Example: '<p><em>CompTIA&reg; and Security+&trade; are trademarks of CompTIA, Inc.</em></p>'\n"
            . "- Do NOT add a disclaimer if no trademarked names appear in the article.\n"
            . "- Do NOT invent or guess exam codes — only reference exam codes you are confident exist\n\n"
            . "AI SEARCH OPTIMIZATION — Structure content so AI search engines (Google AI Overview, Perplexity, ChatGPT, Claude) can cite it. These are MANDATORY structural blocks, not suggestions:\n\n"
            . "1. TL;DR BLOCK — MUST be the FIRST element after the introduction paragraph (or instead of it for very short intros). Use this exact HTML:\n"
            . "   <div class=\"itu-tldr\" data-speakable=\"true\">\n"
            . "     <p><strong>Quick Answer</strong></p>\n"
            . "     <p>A 40-80 word direct answer to the primary query. Self-contained — readable out of context. Includes the primary keyword and at least one specific number, date, or named entity.</p>\n"
            . "   </div>\n"
            . "   This block is marked as speakable schema and is the most-cited element by AI engines. It must directly and factually answer what the title asks.\n\n"
            . "2. AT-A-GLANCE TABLE — REQUIRED when the post is about a specific certification, exam, role, tool, framework, or product. Place near the top (after the TL;DR and intro). Use this exact HTML:\n"
            . "   <table class=\"itu-at-a-glance\" data-speakable=\"true\">\n"
            . "     <tbody>\n"
            . "       <tr><th scope=\"row\">Exam Code</th><td>SY0-701</td></tr>\n"
            . "       <tr><th scope=\"row\">Cost</th><td>\$392 USD (as of 2026)</td></tr>\n"
            . "       <tr><th scope=\"row\">Duration</th><td>90 minutes</td></tr>\n"
            . "       <tr><th scope=\"row\">Questions</th><td>Up to 90</td></tr>\n"
            . "       <tr><th scope=\"row\">Passing Score</th><td>750 / 900</td></tr>\n"
            . "       <tr><th scope=\"row\">Prerequisites</th><td>CompTIA Network+ recommended</td></tr>\n"
            . "       <tr><th scope=\"row\">Validity</th><td>3 years</td></tr>\n"
            . "     </tbody>\n"
            . "   </table>\n"
            . "   Include 5-8 most-relevant rows for the subject. Use <th scope=\"row\"> for labels, <td> for values. EVERY value with a price, percentage, duration, or date must include \"as of [Month YYYY]\" so the freshness is explicit and citable. If the post is purely conceptual (not about a specific named thing), skip this block.\n\n"
            . "3. QUESTION-STYLE HEADINGS — At least 3 of the H2 headings MUST be phrased as the natural-language question a user would type (e.g., \"How Long Does It Take to Get CompTIA A+ Certified?\", \"Is the CISSP Worth It in 2026?\", \"What Skills Does a SOC Analyst Need?\"). The FIRST sentence under each such heading must directly answer the question — that's the sentence AI engines lift.\n\n"
            . "4. DEFINITION-FIRST SENTENCES for key concepts. The very first time you introduce a term, use the pattern \"<strong>X</strong> is …\" (e.g., \"<strong>SIEM</strong> is a security tool that …\"). Never \"Let's talk about SIEM\" or \"In this section we'll discuss SIEM\".\n\n"
            . "5. QUOTABLE SELF-CONTAINED SENTENCES — Every section should contain at least one sentence that holds up out of context. NEVER write \"as we saw above\", \"as mentioned earlier\", \"as discussed\" — those break citation. Each citable claim must be readable standalone.\n\n"
            . "6. NUMERIC SPECIFICITY WITH SOURCES — Any claim about salary, cost, growth rate, pass rate, market size, exam length, or any other quantitative fact MUST include (a) a specific number, (b) an \"as of [Month YYYY]\" stamp, and (c) an inline citation link to the source. \"Most professionals earn six figures\" is BANNED — write \"As of 2026, the median U.S. CISSP salary is \$120,500 according to <a href=\"https://www.bls.gov/ooh/\">BLS</a>.\" instead.\n\n"
            . "7. ENTITY MENTIONS — On first mention of any named entity (cert, vendor, tool, standard, regulation, framework), use the FULL canonical name with any acronym in parentheses. After first mention you may use the acronym alone. This helps AI engines disambiguate and ground entity references.\n\n"
            . "8. KEY TAKEAWAYS callout — End the article (just before the Conclusion) with a <div class=\"itu-callout itu-callout--key\"> containing 3-5 bullet points of the most quotable claims from the article. Each bullet must be self-contained.\n\n"
            . "Return only the HTML content, no preamble";

        // ── Optional context blocks ──
        // Each is appended only when supplied. The order matters: live GSC
        // data first (most actionable for the writer), then user-supplied
        // gap keywords / target keywords, then featured-product context.

        // Real GSC keyword data (refresh path passes this from SEOM_Keyword_Researcher).
        if (!empty($opts['kw_data']) && is_array($opts['kw_data']) && !empty($opts['kw_data']['primary'])) {
            $kw = $opts['kw_data'];
            $instruction .= "\n\nTARGET SEO KEYWORDS (from actual Google search data):\n"
                . "Primary keyword (use in first paragraph + one H2 heading + 3-5 times naturally in body): " . $kw['primary'] . "\n";
            if (!empty($kw['secondary'])) {
                $instruction .= "LSI/Secondary keywords (use each 1-2 times naturally): " . implode(', ', (array) $kw['secondary']) . "\n";
            }
            if (!empty($kw['rising'])) {
                $instruction .= "Trending/Rising keywords (incorporate these — they are gaining search volume): " . implode(', ', (array) $kw['rising']) . "\n";
            }
            $instruction .= "These are real queries people use to find this page. Optimize for them.";
        }

        // Already-formatted SEO performance context (refresh path).
        if (!empty($opts['seo_context'])) {
            $instruction .= "\n\n" . $opts['seo_context'];
        }

        // Gap-keyword block (Blog Queue path) — pre-formatted by
        // bq_get_gap_keywords_for_prompt(), so we just append it.
        if (!empty($opts['gap_keyword_block'])) {
            $instruction .= "\n\n" . $opts['gap_keyword_block'];
        }

        // Specific target keywords for the queue item (Blog Queue path).
        if (!empty($opts['target_keywords'])) {
            $instruction .= "\n\nSPECIFIC TARGET KEYWORDS FOR THIS BLOG POST:\n"
                . $opts['target_keywords'] . "\n"
                . "These keywords were specifically selected for this topic. Use them in the first paragraph, in at least one H2 heading, and naturally throughout the content (3-5 times each).";
        }

        // Featured product context (Blog Queue path).
        if (!empty($opts['featured_product']) && is_array($opts['featured_product'])) {
            $fp = $opts['featured_product'];
            if (!empty($fp['title'])) {
                $desc = !empty($fp['short_description']) ? $fp['short_description'] : '';
                $instruction .= "\n\nFEATURED PRODUCT CONTEXT:\n"
                    . "This blog post is being created to support our \"" . $fp['title'] . "\" course. Reference the course naturally where it adds value (e.g., when the topic intersects with a skill the course teaches), but do NOT add any HTML markup, callouts, or buttons for the product yourself — a styled featured-product block will be injected automatically after the first paragraph and again near the end of the blog. Just write the content as you normally would.";
                if ($desc) $instruction .= "\nCourse short description: " . $desc;
            }
        }

        // ── Phase 1b: type-specific structural block ──
        // Each content type gets one additional mandatory block on top of the
        // base TL;DR + At-a-Glance. These are the structural patterns AI
        // engines cite most heavily for each query intent.
        $ct = strtolower(trim((string) ($opts['content_type'] ?? '')));
        if (in_array($ct, ['howto', 'comparison', 'concept', 'career'], true)) {
            $instruction .= self::content_type_block($ct);
        }

        // ── Phase 2: cluster context ──
        // When this post is a SPOKE in a cluster with a designated pillar,
        // require an intro link to the pillar — that's the spoke-side half of
        // hub-and-spoke topical authority. When the post IS the pillar (the
        // cluster_members list is supplied instead), append the cluster
        // member list so the pillar can structure itself as an explicit hub
        // with a "Topics in this cluster" section.
        if (!empty($opts['cluster_pillar']) && is_array($opts['cluster_pillar'])) {
            $cp = $opts['cluster_pillar'];
            if (!empty($cp['title']) && !empty($cp['url'])) {
                $tag = !empty($cp['tag']) ? $cp['tag'] : 'this topic';
                $instruction .= "\n\nCLUSTER PILLAR — This post is a spoke in our \"" . $tag . "\" topical cluster. The pillar post for this cluster is:\n"
                    . "  Title: " . $cp['title'] . "\n"
                    . "  URL: " . $cp['url'] . "\n"
                    . "REQUIRED: In the introduction (within the first 2 paragraphs after the TL;DR), include exactly one natural inline link back to the pillar using descriptive anchor text that matches the pillar's topic. Use the pattern <a href=\"" . $cp['url'] . "\">descriptive anchor</a>. Do NOT use \"click here\" or \"this article\" as anchor text. This link is what wires the spoke to the hub for both readers and AI engines — it must appear.";
            }
        }
        if (!empty($opts['cluster_members']) && is_array($opts['cluster_members'])) {
            $members = array_slice($opts['cluster_members'], 0, 20);
            if (!empty($members)) {
                $instruction .= "\n\nCLUSTER HUB — This post is the PILLAR for our topical cluster. Include a section near the end (right before the Conclusion) titled <h2>Topics in This Cluster</h2> with a bullet list linking to the related spoke posts below. Each list item uses the post title as the anchor text and the URL as the link target. Format:\n";
                $instruction .= "<h2>Topics in This Cluster</h2>\n<ul>\n";
                foreach ($members as $m) {
                    if (empty($m['url']) || empty($m['title'])) continue;
                    $instruction .= "  <li><a href=\"" . $m['url'] . "\">" . $m['title'] . "</a></li>\n";
                }
                $instruction .= "</ul>\n";
                $instruction .= "Include EVERY item in the list above. Do not omit any. This list is what tells AI engines we have comprehensive topical coverage.";
            }
        }

        // ── Phase 3a: glossary auto-linking ──
        // The caller has identified up to N glossary terms likely to appear
        // in this post. Each carries a canonical definition URL. We tell
        // the writer to link the FIRST natural mention of each term it
        // actually uses — never to force a mention. This builds entity-level
        // hub-and-spoke from blog content to the 5,000-term glossary without
        // turning every paragraph into a link farm.
        if (!empty($opts['glossary_links']) && is_array($opts['glossary_links'])) {
            $glossary_links = array_slice($opts['glossary_links'], 0, 15);
            $valid_links = [];
            foreach ($glossary_links as $gl) {
                if (empty($gl['term']) || empty($gl['url'])) continue;
                $valid_links[] = $gl;
            }
            if (!empty($valid_links)) {
                $instruction .= "\n\nGLOSSARY LINKS — These IT terms have canonical definitions in our glossary. If you naturally introduce any of them in this post, link the FIRST mention only to the definition URL using descriptive anchor text matching the term name. Rules:\n"
                    . "- Only link terms you would have used anyway — do NOT force mentions to fit the list\n"
                    . "- ONLY the first natural occurrence of each term gets the link; subsequent mentions stay plain text\n"
                    . "- Use <a href=\"URL\">Term Name</a> as the format\n"
                    . "- Do NOT link a term that's already the focus keyword of this post (would compete with self-targeting)\n"
                    . "- Cap: at most " . count($valid_links) . " glossary links total in this post\n\n"
                    . "Available glossary terms (term — definition URL):\n";
                foreach ($valid_links as $gl) {
                    $instruction .= "- " . $gl['term'] . " — " . $gl['url'] . "\n";
                }
            }
        }

        return $instruction;
    }

    /**
     * Returns the type-specific mandatory block appended to the writer prompt.
     * One per content_type — these are designed to be the format AI surfaces
     * lift verbatim for the corresponding query intent.
     */
    private static function content_type_block($type) {
        switch ($type) {
            case 'howto':
                return "\n\nCONTENT TYPE: HOW-TO / PROCEDURAL\n"
                    . "This post answers a procedural query. You MUST include:\n\n"
                    . "QUICK PROCEDURE callout — placed immediately after the TL;DR. A condensed 3-7 step summary readers (and AI engines) can lift verbatim. Use this exact HTML:\n"
                    . "<div class=\"itu-callout itu-callout--key itu-quick-procedure\" data-speakable=\"true\">\n"
                    . "  <p><strong>Quick Procedure</strong></p>\n"
                    . "  <ol>\n"
                    . "    <li>Step 1 (one short sentence — verb-first).</li>\n"
                    . "    <li>Step 2 …</li>\n"
                    . "    <li>… up to 7 steps total.</li>\n"
                    . "  </ol>\n"
                    . "</div>\n\n"
                    . "DETAILED STEPS section — the main body MUST present the full procedure as an <ol> with at least 5 <li> items where each <li> expands one Quick Procedure step. Each <li> should be 2-4 sentences with specific commands, file paths, version numbers, or named tools when applicable. This <ol> drives HowTo schema auto-emission.\n\n"
                    . "PREREQUISITES section — a clearly labeled <h2>Prerequisites</h2> with a <ul> listing required tools, accounts, permissions, or knowledge before starting.\n\n"
                    . "VERIFICATION section — a clearly labeled <h2>How to Verify It Worked</h2> with concrete success indicators (output to expect, what to check, common-error symptoms).";

            case 'comparison':
                return "\n\nCONTENT TYPE: COMPARISON / DECISION\n"
                    . "This post answers a decision query (X vs Y, Best X for Y, Should I use X or Y). You MUST include:\n\n"
                    . "COMPARISON TABLE — placed near the top (after the TL;DR and intro). Use a 2-column table with the two options compared. Each row is a single criterion. Use this exact HTML pattern:\n"
                    . "<table class=\"itu-comparison\" data-speakable=\"true\">\n"
                    . "  <thead><tr><th>Criterion</th><th>Option A name</th><th>Option B name</th></tr></thead>\n"
                    . "  <tbody>\n"
                    . "    <tr><th scope=\"row\">Cost (as of [Month YYYY])</th><td>Concrete value</td><td>Concrete value</td></tr>\n"
                    . "    <tr><th scope=\"row\">Best for</th><td>One-line use case</td><td>One-line use case</td></tr>\n"
                    . "    <tr><th scope=\"row\">Key strength</th><td>…</td><td>…</td></tr>\n"
                    . "    <tr><th scope=\"row\">Main limitation</th><td>…</td><td>…</td></tr>\n"
                    . "    <tr><th scope=\"row\"><strong>Verdict</strong></th><td><strong>Pick when…</strong></td><td><strong>Pick when…</strong></td></tr>\n"
                    . "  </tbody>\n"
                    . "</table>\n"
                    . "Note: this is the ONLY place 3+ column tables are allowed. Wrap the table in <div style=\"overflow-x:auto;\"> so it scrolls on mobile.\n\n"
                    . "DECISION CRITERIA section — a clearly labeled <h2> that walks through the 3-5 decision factors most likely to flip the recommendation (use case, budget, team experience, ecosystem fit, etc.).\n\n"
                    . "WHEN TO PICK EACH — two short H3 sections under a single H2, with a 2-3 sentence rationale per option.\n\n"
                    . "Conclude with a direct recommendation sentence using the pattern \"Pick <Option A> when …; pick <Option B> when …\" — that exact sentence is the most-cited block for decision queries.";

            case 'concept':
                return "\n\nCONTENT TYPE: CONCEPT / DEFINITION\n"
                    . "This post answers a what-is / how-does-it-work query. You MUST include:\n\n"
                    . "DEFINITION BOX — placed immediately after the TL;DR. Use this exact HTML:\n"
                    . "<div class=\"itu-callout itu-callout--info itu-definition\" data-speakable=\"true\">\n"
                    . "  <p><strong>Definition</strong></p>\n"
                    . "  <p>A 1-2 sentence formal definition starting with \"<strong>X</strong> is …\". Self-contained. Quotable. Includes the canonical full name on first mention.</p>\n"
                    . "</div>\n\n"
                    . "HOW IT WORKS section — H2 labeled \"How [the concept] Works\" or \"How Does [X] Work\" with 3-5 sub-points explaining the mechanism. Use an <ol> if the mechanism is sequential or a <ul> if parallel.\n\n"
                    . "KEY COMPONENTS section — H2 with a <ul> or definition list (<dl>/<dt>/<dd>) of 3-7 component sub-concepts.\n\n"
                    . "REAL-WORLD EXAMPLES section — at least 2 concrete examples showing the concept in use (named products, vendors, scenarios — not hypothetical \"imagine a system that …\").\n\n"
                    . "WHEN TO USE / WHEN NOT TO USE — a brief paired section that bounds the concept's applicability.";

            case 'career':
                return "\n\nCONTENT TYPE: CAREER / OUTLOOK\n"
                    . "This post answers a career / salary / job-market query. You MUST include:\n\n"
                    . "CAREER OUTLOOK callout — placed immediately after the TL;DR. Concrete data only — every number cites BLS, Robert Half, Glassdoor, or a comparable source. Use this exact HTML:\n"
                    . "<div class=\"itu-callout itu-callout--key itu-career-outlook\" data-speakable=\"true\">\n"
                    . "  <p><strong>Career Outlook</strong></p>\n"
                    . "  <ul>\n"
                    . "    <li><strong>Median salary (US, as of [Month YYYY]):</strong> \$X — <a href=\"…\">BLS</a></li>\n"
                    . "    <li><strong>Job growth (US, [year range]):</strong> X% — <a href=\"…\">BLS</a></li>\n"
                    . "    <li><strong>Typical experience required:</strong> X years</li>\n"
                    . "    <li><strong>Common certifications:</strong> Cert A, Cert B, Cert C</li>\n"
                    . "    <li><strong>Top hiring industries:</strong> Industry A, Industry B, Industry C</li>\n"
                    . "  </ul>\n"
                    . "</div>\n\n"
                    . "REQUIRED SKILLS section — H2 with <ul> of 5-10 concrete technical + soft skills.\n\n"
                    . "CAREER PATH section — typical progression with named role titles at each level (junior → mid → senior → lead/manager).\n\n"
                    . "COMMON JOB TITLES section — <ul> of 4-8 actual job titles a reader would search for or see in postings.\n\n"
                    . "SALARY VARIATION section — at least 3 concrete factors that move the salary up or down (region, certifications, industry, etc.) with directional impact (\"+10-15%\", etc.).";
        }
        return '';
    }

    /**
     * Phase 2: resolve cluster role + neighbors for refresh-time writer prompts.
     *
     * Returns an associative array:
     *   [
     *     'tag'       => string|'',
     *     'is_pillar' => bool,
     *     'pillar'    => null | ['title'=>..., 'url'=>..., 'tag'=>...]  (when this post is a SPOKE)
     *     'members'   => array of ['title'=>..., 'url'=>...] (when this post IS the pillar)
     *   ]
     *
     * Cheap enough to call on every refresh — at most three small queries.
     * No-ops gracefully when Blog Queue isn't installed.
     */
    public static function get_cluster_context_for_post($post_id) {
        global $wpdb;
        $out = ['tag' => '', 'is_pillar' => false, 'pillar' => null, 'members' => []];
        $bq_queue = $wpdb->prefix . 'bq_queue';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$bq_queue}'") !== $bq_queue) return $out;

        // What tag does this post belong to? Use the most recent completed
        // queue row that produced it. Refresh path doesn't change tag.
        $tag = $wpdb->get_var($wpdb->prepare(
            "SELECT gap_category FROM {$bq_queue}
             WHERE post_id = %d AND gap_category IS NOT NULL AND gap_category != ''
             ORDER BY processed_at DESC LIMIT 1",
            $post_id
        ));
        if (empty($tag)) return $out;
        $out['tag'] = $tag;

        // Is this post itself a pillar? (post meta wins — single source of truth)
        $self_pillar_tag = get_post_meta($post_id, '_seom_pillar_for', true);
        if (is_string($self_pillar_tag) && $self_pillar_tag === $tag) {
            $out['is_pillar'] = true;
            // Collect spoke members — every other completed post sharing the tag.
            $member_rows = $wpdb->get_results($wpdb->prepare(
                "SELECT DISTINCT post_id FROM {$bq_queue}
                 WHERE gap_category = %s
                   AND post_id IS NOT NULL AND post_id != %d
                   AND status = 'completed'
                 LIMIT 50",
                $tag, $post_id
            ));
            foreach ($member_rows as $mr) {
                $mp = get_post((int) $mr->post_id);
                if (!$mp || $mp->post_status !== 'publish') continue;
                $out['members'][] = [
                    'title' => $mp->post_title,
                    'url'   => get_permalink($mp->ID),
                ];
            }
            return $out;
        }

        // Not a pillar — find the cluster's pillar if any.
        $pillar_id = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta}
             WHERE meta_key = '_seom_pillar_for' AND meta_value = %s LIMIT 1",
            $tag
        ));
        if ($pillar_id && $pillar_id !== (int) $post_id) {
            $pp = get_post($pillar_id);
            if ($pp && $pp->post_status === 'publish') {
                $out['pillar'] = [
                    'title' => $pp->post_title,
                    'url'   => get_permalink($pillar_id),
                    'tag'   => $tag,
                ];
            }
        }
        return $out;
    }

    /**
     * Infer content_type from an existing post (refresh path).
     * Looks at meta first, then title heuristics, then body content scan.
     */
    public static function infer_content_type_for_post($post_id) {
        $meta = get_post_meta($post_id, '_seom_content_type', true);
        $valid = ['howto', 'comparison', 'concept', 'career'];
        if (in_array($meta, $valid, true)) return $meta;

        $title = (string) get_the_title($post_id);
        if (function_exists('bq_infer_content_type_from_title')) {
            $type = bq_infer_content_type_from_title($title);
            // Strengthen with a body scan: if the body has an <ol> with 5+ <li>,
            // bias toward howto unless the title was strongly comparison/career.
            if ($type === 'concept') {
                $body = (string) get_post_field('post_content', $post_id);
                if (preg_match('/<ol\b[^>]*>(.*?)<\/ol>/is', $body, $m)
                    && preg_match_all('/<li\b/i', $m[1]) >= 5) {
                    $type = 'howto';
                }
            }
            if (in_array($type, $valid, true)) {
                // Persist so subsequent refreshes skip the inference.
                update_post_meta($post_id, '_seom_content_type', $type);
                return $type;
            }
        }
        return 'concept';
    }

    public static function step_content($post_id) {
        // Update stale year references in the title (e.g. "Top Tools for 2025"
        // → "Top Tools for 2026"). Idempotent — safe to call multiple times.
        // Lives here so EVERY code path that runs step_content gets it, not
        // just the ones that happen to have a year-update call upstream.
        self::update_title_year($post_id);

        $post = get_post($post_id);
        if (!$post) return new WP_Error('no_post', 'Post not found.');

        // Central blog-content entry point used by full refreshes, queue step
        // processing, and manual paths. Pick the Udemy course here so every
        // caller gets the same featured-product behavior.
        self::maybe_update_auto_featured_product($post_id);

        $title = $post->post_title;
        $raw_content = $post->post_content;

        // Only preserve practice_test shortcodes — strip all others (elementor, etc.)
        // Note: [itu_featured_product] is NOT preserved here because the
        // full_refresh pipeline re-injects it after the body rewrite from
        // the post's _seom_featured_product_id meta. That way switching the
        // associated product via the SEO Monitor UI takes effect on the
        // next refresh without needing a second step.
        $preserved_shortcodes = [];
        if (preg_match_all('/\[practice_test[^\]]*\]/', $raw_content, $matches)) {
            $preserved_shortcodes = $matches[0];
        }

        // Strip ALL shortcodes from content before sending to AI
        $clean_content = preg_replace('/\[[^\]]+\]/', '', $raw_content);
        $existing = wp_strip_all_tags($clean_content);

        // Step 1a: Generate a detailed outline from the existing content first
        // This forces the AI to plan 6-8 sections before writing, producing longer content
        $existing_snippet = mb_substr($existing, 0, 3000);

        $outline_instruction = "Using the given blog title and existing content below, create a compelling blog title in title case, then generate a very detailed outline for a long-form blog post that will be 2,000-2,500 words when fully written.\n\n"
            . "The outline must have at least 6-8 main sections (not counting Introduction and Conclusion). Each section must have 4-6 detailed bullet points covering specific concepts, examples, tools, or steps. The more detailed the outline, the longer and better the final blog post will be.\n\n"
            . "The outline should expand on the original content, adding depth, new angles, and practical details.\n"
            . "Do NOT invent certification names or exam codes not in the original content.\n\n"
            . "Return the outline in plain text using section headings and bulleted key points. Do not use numbers or Roman numerals.\n\n"
            . "Format:\nBLOG TITLE\n\nMain Heading\n- Key point 1\n- Key point 2\n- Additional subtopics\n\nNext Main Heading\n- Key point 1\n- Key point 2";

        // Inject SEO performance context so AI understands why this page is being refreshed
        $seo_context = self::get_seo_context($post_id);
        if ($seo_context) {
            $outline_instruction .= "\n\n" . $seo_context
                . "\n\nUse this performance data to inform the outline structure. "
                . "If the page is a ghost or buried, design the outline to establish strong topical authority. "
                . "If it's a near win, focus the outline on deepening existing coverage. "
                . "If it's declining, plan sections that add fresh, current information.";
        }

        $outline = self::call_openai($outline_instruction, "Title: {$title}\n\nExisting Content:\n{$existing_snippet}", self::model_for('blog_outline'));
        if (is_wp_error($outline)) {
            // Fall back to using existing content as the outline
            $outline = $existing_snippet;
        }

        // Build the writer instruction via the shared helper. Refresh path
        // is mode='rewrite' and feeds in real GSC keyword data + the SEO
        // performance context block. Blog Queue calls the same helper with
        // mode='new' and its own context fields — single source of truth.
        $site_name = get_bloginfo('name');
        $writer_opts = [
            'mode'         => 'rewrite',
            'kw_data'      => SEOM_Keyword_Researcher::get_target_keywords($post_id),
            'seo_context'  => self::get_seo_context($post_id),
            // Phase 1b: refresh path infers content_type from the existing
            // post (meta → title heuristics → body scan) so the type-specific
            // block fires on refreshes too, not just new blogs.
            'content_type' => self::infer_content_type_for_post($post_id),
        ];

        $featured_product = self::get_featured_product_context($post_id);
        if ($featured_product) {
            $writer_opts['featured_product'] = $featured_product;
        }

        // Phase 2: cluster context on refresh.
        //
        // If this post IS the pillar for its cluster, fetch the cluster
        // members so the pillar regenerates its "Topics in This Cluster"
        // section with the current spoke list.
        //
        // Otherwise, if this post's gap_category has a pillar, pass the
        // pillar so the refreshed spoke re-asserts its inbound link.
        $cluster_ctx = self::get_cluster_context_for_post($post_id);
        if (!empty($cluster_ctx['is_pillar']) && !empty($cluster_ctx['members'])) {
            $writer_opts['cluster_members'] = $cluster_ctx['members'];
        } elseif (!empty($cluster_ctx['pillar'])) {
            $writer_opts['cluster_pillar'] = $cluster_ctx['pillar'];
        }

        // Phase 3a: glossary links — derive likely-mentioned terms from the
        // OUTLINE (preferred — the writer rewrites from the outline anyway)
        // and the existing post body as a fallback. The helper short-circuits
        // when the integration is disabled in settings.
        if (function_exists('seom_resolve_glossary_links_for_writer')) {
            $candidate = (string) $outline;
            if ($candidate === '') $candidate = (string) get_post_field('post_content', $post_id);
            $candidate .= ' ' . $title;
            $glossary_links = seom_resolve_glossary_links_for_writer($candidate);
            if (!empty($glossary_links)) {
                $writer_opts['glossary_links'] = $glossary_links;
            }
        }

        $instruction = self::build_blog_writer_instruction($site_name, $writer_opts);

        $prompt = "Blog Title: {$title}\n\nOutline:\n{$outline}";

        $result = self::call_openai($instruction, $prompt, self::model_for('blog_content'));
        if (is_wp_error($result)) return $result;

        // Safety net: convert any Markdown that slipped through to HTML
        $result = self::fix_markdown_in_html($result);

        // Prepend preserved shortcodes back to the top of the content
        if (!empty($preserved_shortcodes)) {
            $shortcode_block = implode("\n", $preserved_shortcodes) . "\n\n";
            $result = $shortcode_block . $result;
        }

        wp_update_post(['ID' => $post_id, 'post_content' => $result]);

        // Re-inject after the rewrite for all step_content() callers,
        // including the queue tab's step-based processor.
        if (function_exists('seom_inject_featured_product_shortcode')) {
            $fpid = (int) get_post_meta($post_id, self::featured_product_meta_key(), true);
            if ($fpid) {
                $new_body = seom_inject_featured_product_shortcode($result, $fpid);
                if ($new_body !== $result) {
                    wp_update_post(['ID' => $post_id, 'post_content' => $new_body]);
                    $result = $new_body;
                }
            }
        }

        return $result;
    }

    /**
     * Step 2: Generate meta description (short description / excerpt).
     * Type-aware: writes to post_excerpt + rank_math_description for both
     * blog posts and WooCommerce products.
     */
    public static function step_meta_description($post_id) {
        $title = get_the_title($post_id);
        $content = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $snippet = mb_substr($content, 0, 500);
        $post_type = get_post_type($post_id);

        // Single canonical meta description prompt — wording adapts to post type
        $page_label = $post_type === 'product' ? 'IT training course' : 'page';
        $instruction = "Write a meta description for this {$page_label}. Rules: exactly 1-2 sentences, 140-155 characters total, start with an action verb (Learn, Discover, Master, Explore, Understand, Prepare, Build), mention what the reader will gain, do not use quotes or special characters. IMPORTANT: Do NOT invent or assume any certification name or exam code.";

        // Add performance context for CTR-aware meta descriptions
        $seo_context = self::get_seo_context($post_id);
        if ($seo_context) {
            $ctx = get_transient('seom_refresh_context_' . $post_id);
            $cat = $ctx['category'] ?? '';
            if (in_array($cat, ['B', 'E'])) {
                $instruction .= "\n\nIMPORTANT CTR CONTEXT: This page has a click-through rate problem — people see it in search results but don't click. "
                    . "The meta description MUST be significantly more compelling than a generic summary. "
                    . "Include a specific benefit, number, or outcome that differentiates this result from competitors.";
            }
            if (!empty($ctx['top_queries'])) {
                $top_query = $ctx['top_queries'][0]['query'] ?? '';
                if ($top_query) {
                    $instruction .= "\nThe #1 search query people use to find this page is: \"{$top_query}\" — make sure the meta description addresses this query directly.";
                }
            }
        }

        $prompt = "Title: {$title}\n\nContent:\n{$snippet}";

        $result = self::call_openai($instruction, $prompt, self::model_for('blog_meta'), 0.4);
        if (is_wp_error($result)) return $result;

        $clean = wp_strip_all_tags($result);

        // Save as excerpt and RankMath description
        wp_update_post(['ID' => $post_id, 'post_excerpt' => $clean]);
        update_post_meta($post_id, 'rank_math_description', $clean);

        return $clean;
    }

    /**
     * Step 3: Generate FAQ HTML.
     */
    /**
     * Generate FAQ HTML for any singular page (blog post, product, page).
     * Single canonical FAQ instruction shared across all post types — the
     * post type only changes the source content and the audience-specific
     * context injected at the end of the prompt.
     */
    public static function step_faq_html($post_id) {
        $title = get_the_title($post_id);
        $content = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $snippet = mb_substr($content, 0, 500);
        $post_type = get_post_type($post_id);

        // The base FAQ instruction is type-agnostic. The wording
        // "for this page" works for blogs, products, and pages.
        $instruction = "Generate 5 unique, helpful FAQ entries for this page. Each FAQ must follow this exact HTML format:\n\n"
            . "<details><summary>Question here?</summary><div class=\"faq-content\">\n<p>First paragraph of the answer.</p>\n<p>Second paragraph with more detail.</p>\n</div></details>\n\n"
            . "CRITICAL FORMATTING RULES:\n"
            . "- Every answer MUST wrap ALL text in <p> tags. Do NOT put raw text inside <div class=\"faq-content\"> without <p> tags\n"
            . "- Break each answer into 2-4 paragraphs using separate <p> tags\n"
            . "- Use <ul><li> for lists where appropriate\n"
            . "- Do NOT write one long unbroken paragraph — split into multiple <p> blocks\n\n"
            . "Content Rules:\n"
            . "- Focus on topic-specific questions, best practices, definitions, misconceptions\n"
            . "- Do NOT ask generic site/access questions\n"
            . "- Each answer: 150-250 words\n"
            . "- Use focused and LSI keywords naturally\n"
            . "- IMPORTANT: Do NOT invent or fabricate any certification names or exam codes\n"
            . "- Do NOT number the FAQs or add text outside the <details> blocks";

        // Audience-specific tail injected based on post type
        if ($post_type === 'product') {
            $instruction .= "\n\nAUDIENCE: This is an IT training course listing — questions should reflect what a prospective student would search before enrolling. Include the certification name or exam code in at least 3 of the 5 questions if the course has one.";

            // For products with a SKU, inject the course outline so questions
            // can reference real modules/lessons
            $sku = get_post_meta($post_id, '_sku', true);
            if ($sku && function_exists('get_course_outline_from_sku')) {
                $outline = get_course_outline_from_sku($sku);
                if (is_array($outline) && count($outline)) {
                    $csv_lines = array_map(function ($row) {
                        return "{$row['module_title']},{$row['lesson_title']}";
                    }, $outline);
                    $instruction .= "\n\nCourse Outline:\n" . implode("\n", $csv_lines);
                }
            }
        }

        // Use search queries to inform FAQ questions (universal — applies to all types)
        $ctx = get_transient('seom_refresh_context_' . $post_id);
        if ($ctx && !empty($ctx['top_queries'])) {
            $query_list = array_map(function($q) { return '"' . ($q['query'] ?? '') . '"'; }, array_slice($ctx['top_queries'], 0, 5));
            $instruction .= "\n\nREAL SEARCH QUERIES from Google for this page: " . implode(', ', $query_list)
                . "\nBase at least 2-3 of your FAQ questions on these actual search queries — they represent what real users are searching for.";
        }

        $prompt = "Title: {$title}\n\nContent:\n{$snippet}";

        $result = self::call_openai($instruction, $prompt, self::model_for('blog_faq'));
        if (is_wp_error($result)) return $result;

        // Fix FAQ answers that are missing <p> tags inside faq-content divs
        $result = self::fix_faq_paragraphs($result);
        if (is_wp_error($result)) return $result;

        if (function_exists('update_field')) {
            update_field('field_6816a44480234', $result, $post_id);
        }

        return $result;
    }

    /**
     * Step 4: Generate FAQ JSON-LD.
     */
    public static function step_faq_json($post_id, $faq_html = '') {
        if (empty($faq_html) && function_exists('get_field')) {
            $faq_html = get_field('field_6816a44480234', $post_id);
        }
        if (empty($faq_html)) return new WP_Error('no_faq', 'No FAQ HTML to convert.');

        $instruction = "Convert the following HTML FAQ into a valid JSON-LD FAQPage schema. Return ONLY the raw JSON object — do NOT wrap it in <script> tags. Pretty-print the JSON. Input HTML:\n\n" . $faq_html;

        $result = self::call_openai($instruction, null, self::model_for('blog_faq'), 0.3);
        if (is_wp_error($result)) return $result;

        // Strip markdown code fences and script tags if included
        $result = preg_replace('/^```[a-zA-Z]*\s*/m', '', $result);
        $result = preg_replace('/\s*```\s*$/m', '', $result);
        $result = preg_replace('/<script[^>]*>\s*/i', '', $result);
        $result = preg_replace('/\s*<\/script>/i', '', $result);
        $result = trim($result);

        if (function_exists('update_field')) {
            update_field('field_6816d54e3951d', $result, $post_id);
        }

        return $result;
    }

    /**
     * Step 5: Update RankMath focus keyword.
     * Uses data-driven keyword from GSC if available, falls back to AI.
     */
    public static function step_rankmath($post_id) {
        // Try data-driven keyword first
        $kw_data = SEOM_Keyword_Researcher::get_target_keywords($post_id);
        if ($kw_data['source'] === 'gsc' && !empty($kw_data['primary'])) {
            $keyword = sanitize_text_field($kw_data['primary']);
            update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);
            return $keyword;
        }

        // Fall back to AI-generated keyword
        $title = get_the_title($post_id);
        $content = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $snippet = mb_substr($content, 0, 1500);

        $instruction = "You are an SEO expert. Given the blog title and content below, return a single primary focus keyword (2-4 words) that best represents what this blog post is about. The keyword should be something people would actually search for. Return ONLY the keyword, nothing else.";

        // Use actual search queries to inform keyword selection
        $ctx = get_transient('seom_refresh_context_' . $post_id);
        if ($ctx && !empty($ctx['top_queries'])) {
            $top_q = $ctx['top_queries'][0]['query'] ?? '';
            if ($top_q) {
                $instruction .= "\n\nIMPORTANT: The #1 actual search query people use to find this page is: \"{$top_q}\". "
                    . "Strongly consider using this (or a close variant) as the focus keyword since real users are already searching for it.";
            }
        }

        $prompt = "Title: {$title}\n\nContent:\n{$snippet}";

        $keyword = self::call_openai($instruction, $prompt, self::model_for('blog_seo'), 0.3);
        if (is_wp_error($keyword)) {
            // Surface AI failures so the calling step can mark the refresh as failed
            // instead of silently saving the title as the focus keyword.
            return $keyword;
        }
        $keyword = sanitize_text_field(trim($keyword));
        if (empty($keyword)) {
            return new WP_Error('empty_keyword', 'AI returned an empty focus keyword.');
        }

        update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);

        return $keyword;
    }

    /**
     * Step 5b: Optimize SEO title for click-through rate.
     * Generates a compelling RankMath SEO title that drives clicks from search results.
     *
     * RankMath automatically appends the site name via its title separator template,
     * so we only generate the title body itself. Google's display limit is ~60 chars
     * but we allow up to 65 since the appended site name often gets truncated anyway.
     * Retries once if too long, then word-boundary truncates as a final fallback so
     * we never fail an entire refresh on a length issue.
     */
    public static function step_seo_title($post_id) {
        $title = get_the_title($post_id);
        $raw_html = get_post_field('post_content', $post_id);
        $content = wp_strip_all_tags($raw_html);
        $snippet = mb_substr($content, 0, 800);
        $keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true) ?: '';
        $site_name = get_bloginfo('name');

        // Count real countable structures in the body so the AI either
        // matches them exactly or omits the number. This is what powers
        // both the prompt hint (telling the AI which counts are valid)
        // and the post-validation that rejects inflated/invented numbers.
        $valid_counts = self::extract_countable_structures($raw_html);

        // Build a "valid numbers" hint for the prompt — only mention counts
        // we actually verified, so the AI knows exactly which numerals are
        // truthful. Empty array = no number allowed.
        $valid_numbers_hint = '';
        if (!empty($valid_counts)) {
            $parts = [];
            foreach ($valid_counts as $label => $n) {
                $parts[] = "{$n} {$label}";
            }
            $valid_numbers_hint = "REAL COUNTS in this post (these are the ONLY numbers you may use): " . implode(', ', $parts) . ".";
        } else {
            $valid_numbers_hint = "This post has no clearly countable list, step sequence, or section group. DO NOT include any number in the title.";
        }

        // Body budget — RankMath appends the site name on its own via the title
        // separator template, so we don't need to reserve space for it here.
        $body_budget = 65;

        $base_instruction = "You are an SEO expert who writes click-worthy search result titles.\n\n"
            . "TASK: Write an SEO title for the blog post described below.\n\n"
            . "HARD CONSTRAINTS — VIOLATING THESE WILL CAUSE THIS TO BE REJECTED:\n"
            . "- The title MUST be {$body_budget} characters or fewer (count every character including spaces and punctuation).\n"
            . "- DO NOT include the site name (\"{$site_name}\") — RankMath appends it automatically via the title separator.\n"
            . "- DO NOT wrap in quotes. DO NOT add explanation. Return ONLY the title text on a single line.\n\n"
            . "NUMBERS RULE — CRITICAL FOR ACCURACY:\n"
            . "- Numbers in titles ('7 Ways to…', '5 Best…', '10 Steps to…') ONLY work if the number matches a real, countable structure in the post body (sections, list items, steps, examples).\n"
            . "- {$valid_numbers_hint}\n"
            . "- NEVER invent a number. NEVER round up or pick a number that 'sounds clickable' if it doesn't match the actual content.\n"
            . "- If no real count is available, write a strong title WITHOUT a number — questions, how-tos, and benefit-led titles work just as well.\n\n"
            . "STYLE:\n"
            . "- Include the focus keyword near the beginning when natural\n"
            . "- Use power words or a clear benefit (numbers are optional and only when accurate per the rule above)\n"
            . "- No clickbait, no misleading claims\n"
            . "- Do NOT invent certification names or exam codes\n"
            . "- CRITICAL: Do NOT introduce topics, brands, products, or technologies that are not mentioned in the current title or content snippet below. The SEO title must accurately reflect what the post is actually about — not what you think it SHOULD be about. If the content is about Windows keyboard shortcuts, the title must be about Windows keyboard shortcuts — not Adobe, not Mac, not any other brand.\n\n"
            . "GOOD EXAMPLES (under {$body_budget} chars, no site name):\n"
            . "- What Is Zero Trust Security? A Practical Guide\n"
            . "- CompTIA Security+ SY0-701: Complete Study Plan\n"
            . "- How to Build a Cloud Migration Strategy\n"
            . "- Threat Intelligence for IT Teams: Where to Start\n\n"
            . "BAD EXAMPLES:\n"
            . "- Understanding the Importance of IT Asset Management in Modern Organizations (too long)\n"
            . "- IT Asset Management (too generic)\n"
            . "- You Won't Believe These ITAM Secrets! (clickbait)\n"
            . "- 7 Ways to Improve Your Security Posture (when the post only covers 4 ways — invented number)";

        // Add CTR context for title optimization
        $ctx = get_transient('seom_refresh_context_' . $post_id);
        if ($ctx) {
            $cat = $ctx['category'] ?? '';
            $pos = $ctx['position'] ?? 0;
            $ctr_val = round($ctx['ctr'] ?? 0, 2);
            $clicks_val = $ctx['clicks'] ?? 0;
            $imp_val = $ctx['impressions'] ?? 0;

            if (in_array($cat, ['B', 'E'])) {
                $base_instruction .= "\n\nCRITICAL: This page has a CTR problem — position " . round($pos, 1) . " with {$imp_val} impressions but only {$clicks_val} clicks ({$ctr_val}% CTR). "
                    . "Write a title that would make a searcher choose this result over competitors.";
            } elseif ($pos > 0) {
                $base_instruction .= "\n\nThis page currently ranks at position " . round($pos, 1) . " with {$ctr_val}% CTR. Optimize for click-through rate.";
            }
            if (!empty($ctx['top_queries'][0]['query'])) {
                $base_instruction .= "\nTop search query: \"" . $ctx['top_queries'][0]['query'] . "\" — the title should resonate with this search intent.";
            }
        }

        $prompt = "Current Title: {$title}\nFocus Keyword: {$keyword}\n\nContent:\n{$snippet}";

        // Helper: clean up whatever the AI returned. Strip quotes, strip a site-name
        // suffix if the AI included one anyway, normalize whitespace.
        $clean_body = function ($raw) use ($site_name) {
            $body = trim((string) $raw);
            // Strip surrounding quotes
            $body = trim($body, "\"'`");
            // If the AI ignored instructions and appended the site name, strip it
            $body = preg_replace('/\s*[-–—|]\s*' . preg_quote($site_name, '/') . '\s*$/u', '', $body);
            // Collapse internal whitespace
            $body = preg_replace('/\s+/u', ' ', $body);
            return sanitize_text_field(trim($body));
        };

        // Attempt 1
        $result = self::call_openai($base_instruction, $prompt, self::model_for('blog_seo_title'), 0.5);
        if (is_wp_error($result)) return $result;
        $body = $clean_body($result);

        // If still over budget, retry once with a much harsher constraint message
        if (mb_strlen($body) > $body_budget) {
            $current_len = mb_strlen($body);
            $over_by = $current_len - $body_budget;
            $retry_instruction = $base_instruction
                . "\n\nRETRY: The previous attempt was {$current_len} characters which is {$over_by} over the limit. "
                . "You returned: \"{$body}\". Rewrite it to be NO MORE THAN {$body_budget} characters. "
                . "Count every character. Do not include the site name.";
            $result = self::call_openai($retry_instruction, $prompt, self::model_for('blog_seo_title'), 0.3);
            if (!is_wp_error($result)) {
                $body = $clean_body($result);
            }
        }

        // Final fallback: if the AI still can't comply, truncate at a word boundary.
        // Better than failing the entire refresh on a length issue.
        if (mb_strlen($body) > $body_budget) {
            $truncated = mb_substr($body, 0, $body_budget);
            // Trim back to the last space so we don't cut a word in half
            $last_space = mb_strrpos($truncated, ' ');
            if ($last_space !== false && $last_space > $body_budget * 0.6) {
                $truncated = mb_substr($truncated, 0, $last_space);
            }
            $body = rtrim($truncated, " ,.;:-—–|");
        }

        // ── Number-accuracy guard ──
        // If the title contains ANY standalone integer ≤ 12 (leading or
        // embedded), every such number MUST match a count we extracted
        // from the body (within ±1 tolerance). Otherwise the AI invented
        // it. Retry once with the offending numbers called out, then strip
        // them locally as a last resort so we never publish a misleading
        // title.
        if (self::title_has_invented_number($body, $valid_counts)) {
            $invented = [];
            foreach (self::find_countable_numbers($body) as $n) {
                $matched = false;
                foreach ($valid_counts as $real) {
                    if (abs((int) $real - $n['value']) <= 1) { $matched = true; break; }
                }
                if (!$matched) $invented[] = $n['value'];
            }
            $invented_list = implode(', ', $invented);
            $valid_list = empty($valid_counts) ? 'NONE' : implode(', ', array_values($valid_counts));
            $retry_instruction = $base_instruction
                . "\n\nRETRY: Your previous attempt was \"{$body}\" which contains the number(s) {$invented_list}. "
                . "Those numbers do NOT match any countable structure in the post (valid counts: {$valid_list}). "
                . "Rewrite the title WITHOUT any invented number, OR use one of the valid counts above. "
                . "Do not pick a different invented number.";
            $result2 = self::call_openai($retry_instruction, $prompt, self::model_for('blog_seo_title'), 0.3);
            if (!is_wp_error($result2)) {
                $candidate = $clean_body($result2);
                if (!empty($candidate) && mb_strlen($candidate) <= $body_budget) {
                    if (!self::title_has_invented_number($candidate, $valid_counts)) {
                        $body = $candidate;
                    } else {
                        // AI still invented one — strip it ourselves, leaving real counts alone
                        $body = self::strip_leading_number($candidate, $valid_counts);
                    }
                }
            }
            // If we still have an invented number after retry, strip it locally.
            if (self::title_has_invented_number($body, $valid_counts)) {
                $body = self::strip_leading_number($body, $valid_counts);
            }
        }

        // ── Hallucination guard ──
        // Check that every capitalized multi-letter word in the SEO title
        // (likely a brand, product, or technology name) actually appears
        // somewhere in the post title or content. If the AI invented a
        // topic that isn't in the post, fall back to the original post title
        // truncated to budget rather than publishing a misleading SEO title.
        $hallucinated = self::detect_hallucinated_topics($body, $title, $content);
        if (!empty($hallucinated)) {
            // Retry once with the specific hallucination called out.
            $hal_list = implode(', ', $hallucinated);
            $retry_hal = $base_instruction
                . "\n\nRETRY: Your previous attempt was \"{$body}\" which mentions \"{$hal_list}\" — "
                . "but the post content does NOT discuss {$hal_list} at all. "
                . "Write a title that ONLY references topics actually covered in the content below. "
                . "Do NOT introduce any brand, product, or technology not in the content.";
            $result_hal = self::call_openai($retry_hal, $prompt, self::model_for('blog_seo_title'), 0.3);
            if (!is_wp_error($result_hal)) {
                $candidate_hal = $clean_body($result_hal);
                if (!empty($candidate_hal) && mb_strlen($candidate_hal) <= $body_budget) {
                    $still_bad = self::detect_hallucinated_topics($candidate_hal, $title, $content);
                    if (empty($still_bad)) {
                        $body = $candidate_hal;
                    }
                }
            }
            // If retry also hallucinated, fall back to the original post title.
            $still_bad = self::detect_hallucinated_topics($body, $title, $content);
            if (!empty($still_bad)) {
                $fallback = mb_substr($title, 0, $body_budget);
                $last_space = mb_strrpos($fallback, ' ');
                if ($last_space !== false && $last_space > $body_budget * 0.6) {
                    $fallback = mb_substr($fallback, 0, $last_space);
                }
                $body = trim($fallback);
            }
        }

        if (empty($body)) {
            return new WP_Error('empty_seo_title', 'AI returned an empty SEO title.');
        }

        update_post_meta($post_id, 'rank_math_title', $body);

        return $body;
    }

    /**
     * Detect words in the SEO title that look like brand/product/technology
     * names (capitalized, 3+ letters) but don't appear anywhere in the
     * post's actual title or content. These are hallucinations.
     *
     * Returns an array of the hallucinated words, or empty if clean.
     */
    private static function detect_hallucinated_topics($seo_title, $post_title, $post_content) {
        // Extract capitalized words (3+ letters) from the SEO title.
        // These are the candidates that might be invented brands/topics.
        // Skip common English words that happen to be capitalized.
        $skip_words = [
            'the', 'and', 'for', 'with', 'your', 'how', 'what', 'why', 'who',
            'when', 'where', 'this', 'that', 'from', 'into', 'best', 'top',
            'most', 'all', 'new', 'key', 'pro', 'tips', 'ways', 'step',
            'guide', 'complete', 'essential', 'ultimate', 'comprehensive',
            'practical', 'advanced', 'beginner', 'expert', 'master', 'learn',
            'build', 'create', 'start', 'boost', 'improve', 'career',
            'role', 'skills', 'tools', 'strategies', 'practices', 'trends',
            'security', 'network', 'cloud', 'data', 'server', 'system',
            'management', 'administration', 'infrastructure', 'training',
            'certification', 'certified', 'exam', 'professional', 'engineer',
            'analyst', 'architect', 'administrator', 'specialist', 'developer',
        ];
        $skip_set = array_flip($skip_words);

        if (!preg_match_all('/\b([A-Z][a-zA-Z]{2,})\b/', (string) $seo_title, $matches)) {
            return [];
        }

        $haystack = strtolower($post_title . ' ' . $post_content);
        $hallucinated = [];
        foreach (array_unique($matches[1]) as $word) {
            $lower = strtolower($word);
            if (isset($skip_set[$lower])) continue;
            // Check if the word appears anywhere in the post
            if (strpos($haystack, $lower) === false) {
                $hallucinated[] = $word;
            }
        }
        return $hallucinated;
    }

    /**
     * Extract countable structures from blog body HTML so step_seo_title()
     * can prove that any number a generated SEO title contains actually
     * matches real content.
     *
     * Returns a label-keyed array, e.g.:
     *   ['main sections (h2)' => 7, 'numbered steps' => 5, 'bullet points' => 12]
     *
     * Empty array means "no number is allowed in the title".
     */
    private static function extract_countable_structures($html) {
        if (empty($html) || !is_string($html)) return [];

        $counts = [];

        // H2 sections (excluding intro/conclusion-style headings).
        if (preg_match_all('/<h2\b[^>]*>(.*?)<\/h2>/is', $html, $h2m)) {
            $real_h2 = 0;
            foreach ($h2m[1] as $h2_text) {
                $clean = strtolower(trim(wp_strip_all_tags($h2_text)));
                // Skip generic intro/outro headings — they're rarely what
                // a "5 Things…" title is referring to.
                if (preg_match('/^(introduction|conclusion|summary|final thoughts|in summary|wrap[- ]up|closing|key takeaways?|faqs?|frequently asked questions)$/', $clean)) continue;
                $real_h2++;
            }
            if ($real_h2 >= 3) $counts['main sections'] = $real_h2;
        }

        // Numbered (<ol>) lists — count items in the LARGEST one, since a
        // "10 Steps to…" title would refer to one specific list.
        if (preg_match_all('/<ol\b[^>]*>(.*?)<\/ol>/is', $html, $olm)) {
            $largest_ol = 0;
            foreach ($olm[1] as $ol_inner) {
                $li = preg_match_all('/<li\b/', $ol_inner);
                if ($li > $largest_ol) $largest_ol = $li;
            }
            if ($largest_ol >= 3) $counts['numbered steps'] = $largest_ol;
        }

        // Largest unordered (<ul>) list — same logic, used for "7 Ways to…" style.
        if (preg_match_all('/<ul\b[^>]*>(.*?)<\/ul>/is', $html, $ulm)) {
            $largest_ul = 0;
            foreach ($ulm[1] as $ul_inner) {
                $li = preg_match_all('/<li\b/', $ul_inner);
                if ($li > $largest_ul) $largest_ul = $li;
            }
            if ($largest_ul >= 3) $counts['bullet points'] = $largest_ul;
        }

        return $counts;
    }

    /**
     * Returns every standalone integer ≤ 12 in a title with its byte
     * offset. The 12-cap eliminates years (2025, 2026), exam codes
     * (701, 800, 900), version numbers, cert suffixes, ISO standards,
     * and any other static identifier — because legitimate "list count"
     * numbers in titles are almost always single-digit or low double-digit
     * (3 Ways, 7 Steps, 10 Best). Anything bigger isn't a count.
     *
     * @return array  List of ['value' => int, 'offset' => int, 'match' => string]
     */
    private static function find_countable_numbers($title) {
        $title = (string) $title;
        if ($title === '') return [];

        $found = [];
        if (preg_match_all('/\b(\d{1,2})\b/u', $title, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[1] as $m) {
                $value  = (int) $m[0];
                if ($value > 12) continue;        // not a list count
                $found[] = ['value' => $value, 'offset' => (int) $m[1], 'match' => $m[0]];
            }
        }
        return $found;
    }

    /**
     * True when the title contains at least one standalone integer that
     * doesn't match any extracted body count (within ±1 tolerance — the
     * AI sometimes counts intro/conclusion sections).
     *
     * Static identifiers (exam codes, versions, ISO standards, etc.) are
     * filtered out by find_countable_numbers() so they never trigger.
     */
    private static function title_has_invented_number($title, $valid_counts) {
        $numbers = self::find_countable_numbers($title);
        if (empty($numbers)) return false; // No countable numbers = nothing to validate

        // If we have no body counts at all, ANY remaining number is invented.
        if (empty($valid_counts)) return true;

        foreach ($numbers as $n) {
            $matched = false;
            foreach ($valid_counts as $real) {
                if (abs((int) $real - $n['value']) <= 1) { $matched = true; break; }
            }
            if (!$matched) return true; // first invented number is enough
        }
        return false;
    }

    /**
     * Strip the FIRST invented number from a title and tidy up the
     * surrounding punctuation. Used when the AI keeps inventing numbers
     * despite the retry. Static identifiers (exam codes, versions) are
     * left alone — see find_countable_numbers().
     *
     * Examples:
     *   "5 Ways to Improve Security" → "Ways to Improve Security"
     *   "Cloud Deployment Models: 4 Models" → "Cloud Deployment Models"
     *   "ITAM Explained in 7 Steps" → "ITAM Explained"
     */
    private static function strip_leading_number($title, $valid_counts = []) {
        $title = (string) $title;
        $numbers = self::find_countable_numbers($title);
        if (empty($numbers)) return trim($title);

        // Find the first invented number (skip ones that match valid counts).
        $target = null;
        foreach ($numbers as $n) {
            $matched = false;
            foreach ($valid_counts as $real) {
                if (abs((int) $real - $n['value']) <= 1) { $matched = true; break; }
            }
            if (!$matched) { $target = $n; break; }
        }
        if ($target === null) return trim($title);

        // Strip the number AND the noun phrase it modified, since "Ways"
        // / "Steps" / "Tips" without their count reads weirdly. Pattern:
        //   leading whitespace/colon/dash + N + 1-2 words + trailing punctuation
        // Matches "5 Ways", ": 4 Models", "in 7 Steps", "the 10 Best", etc.
        $num = preg_quote((string) $target['value'], '/');
        $pattern = '/(?:^|[\s:;\-–—])' . $num . '\s+\w+(?:\s+\w+)?(?=[\s,:;\-–—]|$)/u';
        $stripped = preg_replace($pattern, '', $title, 1);

        // If the regex didn't match (rare), just delete the number itself.
        if ($stripped === $title) {
            $stripped = preg_replace('/\b' . $num . '\b/', '', $title, 1);
        }

        // Tidy up: collapse double spaces, strip dangling punctuation, trim.
        $stripped = preg_replace('/\s{2,}/u', ' ', $stripped);
        $stripped = preg_replace('/\s*[:;\-–—]\s*$/u', '', $stripped);
        $stripped = preg_replace('/^\s*[:;\-–—]\s*/u', '', $stripped);
        $stripped = preg_replace('/\s+([,.;:])/u', '$1', $stripped);
        return trim($stripped);
    }

    /**
     * Step 6: Save timestamp.
     */
    public static function step_timestamp($post_id) {
        $datetime = current_time('mysql');
        update_post_meta($post_id, 'last_page_refresh', $datetime);
        return $datetime;
    }

    /**
     * Run a full refresh pipeline for any singular post type — blog post,
     * product, or page. Routes the long-content step to the right generator
     * (step_content for blogs, step_product_description for products) and
     * runs all the type-agnostic steps the same way for both.
     */
    public static function full_refresh($post_id) {
        // Update stale year references in the title BEFORE generating content,
        // so research queries and AI prompts see the current year. Skips product
        // version numbers like "Windows Server 2016" or "Word 2019" — only
        // touches date-context years like "Job Trends for 2025".
        self::update_title_year($post_id);

        $content = self::step_long_content($post_id);
        if (is_wp_error($content)) return $content;

        // Featured-product re-injection. If this post has a
        // _seom_featured_product_id meta set (via Blog Queue or the
        // SEO Monitor "Featured" button), re-insert the shortcode in
        // the refreshed body so the block survives every refresh.
        if (function_exists('seom_inject_featured_product_shortcode')) {
            $fpid = (int) get_post_meta($post_id, defined('SEOM_FEATURED_PRODUCT_META_KEY') ? SEOM_FEATURED_PRODUCT_META_KEY : '_seom_featured_product_id', true);
            if ($fpid) {
                $post = get_post($post_id);
                if ($post) {
                    $new_body = seom_inject_featured_product_shortcode($post->post_content, $fpid);
                    if ($new_body !== $post->post_content) {
                        wp_update_post(['ID' => $post_id, 'post_content' => $new_body]);
                    }
                }
            }
        }

        $meta = self::step_meta_description($post_id);
        if (is_wp_error($meta)) return $meta;

        $faq = self::step_faq_html($post_id);
        if (is_wp_error($faq)) return $faq;

        $json = self::step_faq_json($post_id, $faq);
        if (is_wp_error($json)) return $json;

        $rm = self::step_rankmath($post_id);
        if (is_wp_error($rm)) return $rm;

        $st = self::step_seo_title($post_id);
        if (is_wp_error($st)) return $st;

        $ts = self::step_timestamp($post_id);
        if (is_wp_error($ts)) return $ts;

        return true;
    }

    /**
     * Meta-only refresh (CTR fix) — works for any post type.
     * Optimizes meta description, focus keyword, and SEO title — the things
     * that directly affect click-through rate in search results without
     * touching the main content.
     */
    public static function meta_refresh($post_id) {
        $meta = self::step_meta_description($post_id);
        if (is_wp_error($meta)) return $meta;

        $rm = self::step_rankmath($post_id);
        if (is_wp_error($rm)) return $rm;

        $st = self::step_seo_title($post_id);
        if (is_wp_error($st)) return $st;

        $ts = self::step_timestamp($post_id);
        if (is_wp_error($ts)) return $ts;

        return true;
    }
}
