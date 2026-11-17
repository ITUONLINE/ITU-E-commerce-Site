<?php
/**
 * Competitive Researcher
 *
 * Performs web search-based competitive analysis before content refresh.
 * Supports two providers:
 *   - Perplexity Sonar (purpose-built search AI, best ROI)
 *   - OpenAI gpt-4.1-mini with Responses API web_search tool
 *
 * Returns a structured competitive intelligence brief that gets
 * injected into the blog refresher's AI prompts.
 */

if (!defined('ABSPATH')) exit;

class SEOM_Researcher {

    /**
     * Check if competitive research is enabled and configured.
     */
    public static function is_available() {
        $settings = seom_get_settings();
        if (empty($settings['research_enabled'])) return false;

        $provider = $settings['research_provider'] ?? 'openai';
        if ($provider === 'perplexity') {
            $pkey = function_exists('itu_ai_key') ? itu_ai_key('perplexity') : '';
            if (empty($pkey)) $pkey = $settings['perplexity_api_key'] ?? '';
            return !empty($pkey);
        }
        // OpenAI — uses existing blog writer key
        $key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
        return !empty($key);
    }

    /**
     * Run competitive research for a page about to be refreshed.
     *
     * @param int    $post_id    The post being refreshed
     * @param array  $seo_ctx    SEO context from seom_refresh_context transient
     * @return string|WP_Error   Competitive intelligence brief or error
     */
    public static function research($post_id, $seo_ctx = []) {
        if (!self::is_available()) return '';

        $settings = seom_get_settings();
        $provider = $settings['research_provider'] ?? 'openai';

        $title = get_the_title($post_id);
        $queries = $seo_ctx['top_queries'] ?? [];
        $position = $seo_ctx['position'] ?? 0;
        $category = $seo_ctx['category'] ?? '';
        $category_desc = $seo_ctx['category_desc'] ?? '';

        // Build the research query from the page's top search queries
        $primary_query = '';
        if (!empty($queries[0]['query'])) {
            $primary_query = $queries[0]['query'];
        } else {
            // Fall back to title-based query
            $primary_query = $title;
        }

        $query_list = [];
        foreach (array_slice($queries, 0, 3) as $q) {
            if (!empty($q['query'])) $query_list[] = '"' . $q['query'] . '"';
        }

        $post_type = get_post_type($post_id);
        $prompt = self::build_research_prompt($title, $primary_query, $query_list, $position, $category, $category_desc, $post_type);

        if ($provider === 'perplexity') {
            return self::call_perplexity($prompt, $settings);
        }
        return self::call_openai_search($prompt, $settings);
    }

    /**
     * Build the research prompt.
     */
    private static function build_research_prompt($title, $primary_query, $query_list, $position, $category, $category_desc, $post_type = 'post') {
        $queries_str = !empty($query_list) ? implode(', ', $query_list) : '"' . $title . '"';
        $site_name = get_bloginfo('name');

        // Context-specific framing based on content type
        if ($post_type === 'product') {
            $content_desc = "a product page for an on-demand IT training course titled \"{$title}\" on {$site_name}";
            $competitor_context = "competing IT training course pages (from providers like Udemy, Coursera, Pluralsight, official vendor training sites, and other IT training companies)";
            $focus_areas = "1. CONTENT GAPS — What specific topics do competing course pages cover? Look for: exam domain breakdowns, career path information, salary data, prerequisites, hands-on lab descriptions, comparison with related certifications, student outcomes, and industry context.\n\n"
                . "2. CONTENT DEPTH — How detailed are competing course pages? Word count range, number of sections, use of curriculum breakdowns, instructor info, or student testimonials.\n\n"
                . "3. UNIQUE SELLING POINTS — What could differentiate our course page? What are competitors doing well that we should match? What are they missing that we could add?\n\n"
                . "4. SEARCH INTENT — What are people actually looking for when they search for this course/certification? Are they comparing options, looking for exam prep, checking prerequisites, or evaluating career ROI?\n\n"
                . "5. KEY TAKEAWAY — In 2-3 sentences, what is the most important thing our course page needs to do better to outrank competing training providers?";
        } else {
            $content_desc = "a blog post titled \"{$title}\" on {$site_name} (an IT training and certification website)";
            $competitor_context = "competing blog posts and articles";
            $focus_areas = "1. CONTENT GAPS — What specific topics, sections, or angles do the top-ranking pages cover that are commonly included? List them as bullet points.\n\n"
                . "2. CONTENT DEPTH — How comprehensive are the top results? Approximate word count range, number of sections, use of tables/lists.\n\n"
                . "3. UNIQUE ANGLES — What could differentiate our content? Are there perspectives, data points, or practical examples the top results are missing?\n\n"
                . "4. FEATURED SNIPPETS & PEOPLE ALSO ASK — What questions appear in Google's People Also Ask for these queries? List them.\n\n"
                . "5. KEY TAKEAWAY — In 2-3 sentences, what is the single most important thing we should do differently to outrank the current top results?";
        }

        $prompt = "I'm optimizing {$content_desc} that currently ";

        if ($position > 0) {
            $page = ceil($position / 10);
            $prompt .= "ranks at position " . round($position, 1) . " (page {$page}) in Google.";
        } else {
            $prompt .= "has no Google ranking (ghost page — not indexed or not ranking).";
        }

        if ($category) {
            $prompt .= " Status: {$category_desc}.";
        }

        $prompt .= "\n\nSearch for {$queries_str} and analyze the TOP 5 CURRENTLY RANKING pages from {$competitor_context}. Tell me:\n\n"
            . $focus_areas . "\n\n"
            . "IMPORTANT: Do NOT recommend adding a FAQ section — we generate FAQs separately with proper JSON-LD schema. "
            . "Do NOT recommend adding images, videos, or interactive elements — we are optimizing text content only. "
            . "Focus your recommendations on topics, depth, structure, and information quality.\n\n"
            . "Be specific and actionable. Include actual topic names, section headings, and data points you find — not generic advice.";

        return $prompt;
    }

    /**
     * Call Perplexity Sonar API.
     */
    private static function call_perplexity($prompt, $settings) {
        // Check centralized AI settings first, then fall back to SEO Monitor setting
        $api_key = function_exists('itu_ai_key') ? itu_ai_key('perplexity') : '';
        if (empty($api_key)) $api_key = $settings['perplexity_api_key'] ?? '';
        if (empty($api_key)) return new WP_Error('no_key', 'Perplexity API key not configured.');

        // Check centralized AI settings first for model
        $model = function_exists('itu_ai_model') ? itu_ai_model('research') : '';
        if (empty($model)) $model = $settings['research_model'] ?: 'sonar';

        $response = wp_remote_post('https://api.perplexity.ai/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model'    => $model,
                'messages' => [
                    ['role' => 'system', 'content' => 'You are an SEO competitive analyst. Provide specific, data-driven analysis based on current search results. Be concise but thorough.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
            ]),
            'timeout' => 60,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $err = $body['error']['message'] ?? "HTTP {$code}";
            return new WP_Error('perplexity_error', 'Perplexity API error: ' . $err);
        }

        $content = trim($body['choices'][0]['message']['content'] ?? '');
        if (empty($content)) return new WP_Error('empty', 'Perplexity returned empty response.');

        // Append citations if available
        $citations = $body['citations'] ?? [];
        if (!empty($citations)) {
            $content .= "\n\nSources referenced: " . implode(', ', array_slice($citations, 0, 5));
        }

        return $content;
    }

    /**
     * Call OpenAI Responses API with web_search tool.
     * Uses gpt-4.1-mini by default (supports web search, good cost/quality).
     */
    private static function call_openai_search($prompt, $settings) {
        $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
        if (!$api_key) return new WP_Error('no_key', 'OpenAI API key not configured.');

        $model = $settings['research_model'] ?: 'gpt-4.1-mini';

        // Use the Responses API (not Chat Completions) for web search tool support
        $response = wp_remote_post('https://api.openai.com/v1/responses', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type'  => 'application/json',
            ],
            'body' => json_encode([
                'model' => $model,
                'tools' => [
                    ['type' => 'web_search'],
                ],
                'input' => [
                    ['role' => 'system', 'content' => 'You are an SEO competitive analyst. Provide specific, data-driven analysis based on current search results. Be concise but thorough.'],
                    ['role' => 'user', 'content' => $prompt],
                ],
                'temperature' => 0.3,
            ]),
            'timeout' => 90,
        ]);

        if (is_wp_error($response)) return $response;

        $code = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($code !== 200) {
            $err = $body['error']['message'] ?? "HTTP {$code}";
            return new WP_Error('openai_search_error', 'OpenAI web search error: ' . $err);
        }

        // Responses API returns output as an array of content blocks
        $output = $body['output'] ?? [];
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
        if (empty($content)) return new WP_Error('empty', 'OpenAI web search returned empty response.');

        return $content;
    }
}
