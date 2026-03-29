<?php
/**
 * Blog Refresher
 *
 * Wraps the AI Blog Writer plugin's generation logic into callable
 * step functions, mirroring the aipm_step_* pattern for products.
 * Uses the same OpenAI API but with blog-specific prompts from
 * the Blog Writer plugin.
 */

if (!defined('ABSPATH')) exit;

class SEOM_Blog_Refresher {

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

        return $content;
    }

    /**
     * Check if blog refresh is available (API key configured).
     */
    public static function is_available() {
        return !empty(get_option('ai_post_api_key'));
    }

    /**
     * Call OpenAI with given instruction and prompt.
     */
    private static function call_openai($instruction, $user_prompt = '', $model = 'gpt-4.1-nano', $temperature = 0.7) {
        $api_key = get_option('ai_post_api_key');
        if (!$api_key) return new WP_Error('no_key', 'Blog Writer API key not configured.');

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
     * Step 1: Generate new blog content from existing content.
     */
    public static function step_content($post_id) {
        $post = get_post($post_id);
        if (!$post) return new WP_Error('no_post', 'Post not found.');

        $title = $post->post_title;
        $raw_content = $post->post_content;

        // Only preserve practice_test shortcodes — strip all others (elementor, etc.)
        $preserved_shortcodes = [];
        if (preg_match_all('/\[practice_test[^\]]*\]/', $raw_content, $matches)) {
            $preserved_shortcodes = $matches[0];
        }

        // Strip ALL shortcodes from content before sending to AI
        $clean_content = preg_replace('/\[[^\]]+\]/', '', $raw_content);
        $existing = wp_strip_all_tags($clean_content);

        // Use existing content as the "outline" for rewriting
        $outline = mb_substr($existing, 0, 3000);

        $instruction = "You are a professional IT blog writer for ITU Online Training. Your tone is direct, knowledgeable, and practical. You never sound like a marketing bot or AI. You write for busy IT professionals who scan pages — not people who read every word.\n\n"
            . "Rewrite and improve the following blog post content to optimize for SEO, freshness, and scannability.\n\n"
            . "BANNED PHRASES — Do NOT use any of these openings or clichés:\n"
            . "- In today's rapidly evolving... / In an ever-changing landscape... / In the fast-paced world of...\n"
            . "- As technology continues to... / In today's digital age... / With the growing importance of...\n"
            . "- As organizations increasingly... / In the modern IT landscape...\n"
            . "Instead, open with something specific: a concrete problem, a real scenario, or a direct statement.\n\n"
            . "IMPORTANT: Do NOT invent or fabricate any certification names, exam codes, or credential titles.\n"
            . "IMPORTANT: Do NOT include any shortcodes (text in square brackets like [example]) in your output. They will be added separately.\n\n"
            . "DEPTH — Each major section (h2) must be 150-300 words. Go deep on the why and how, not just the what. Include specific examples, tool names, real scenarios, or step-by-step explanations.\n\n"
            . "SCANNABILITY — Use a MIX of these formats throughout (not just paragraphs and bullets):\n"
            . "- <p> tags — keep paragraphs SHORT (2-4 sentences max)\n"
            . "- <ul><li> for unordered lists / <ol><li> for ordered steps or ranked items\n"
            . "- <strong> to bold key terms and important phrases on first mention\n"
            . "- <blockquote> for notable quotes, industry insights, or compelling statements\n"
            . "- Callout boxes using: <div class=\"itu-callout itu-callout--tip\"><p><strong>Pro Tip</strong></p><p>Content.</p></div> — variants: --tip (green), --info (blue), --warning (amber), --key (purple)\n"
            . "- Use 1-3 callouts/blockquotes per post total\n"
            . "- <table> ONLY for simple 2-column comparisons — never 3+ columns (breaks on mobile). For multi-item comparisons use bullet lists with <strong>bold labels</strong> instead\n"
            . "- <h3> subheadings within long sections\n"
            . "- Every section must mix at least 2 different format types\n\n"
            . "OUTPUT FORMAT — CRITICAL:\n"
            . "- Return ONLY valid HTML. Do NOT use Markdown syntax anywhere\n"
            . "- Do NOT use # or ## or ### for headings — use <h2> and <h3> HTML tags\n"
            . "- Do NOT use **bold** markdown — use <strong> HTML tags\n"
            . "- Do NOT use - or * for lists — use <ul><li> or <ol><li> HTML tags\n\n"
            . "STRUCTURE:\n"
            . "- Minimum 1,500 words total\n"
            . "- Use <h2> for main sections, <h3> for subsections\n"
            . "- Do NOT include an <h1> tag\n"
            . "- Introduction: hook with a specific problem, preview key takeaways\n"
            . "- Conclusion: summarize + clear call to action\n"
            . "- Include LSI keywords and named entity 'ITU Online Training' naturally\n"
            . "- Write like a real person. Mix short punchy sentences with longer ones\n"
            . "- Return only the HTML content, no preamble";

        // Inject data-driven target keywords from GSC
        $kw_data = SEOM_Keyword_Researcher::get_target_keywords($post_id);
        if (!empty($kw_data['primary'])) {
            $instruction .= "\n\nTARGET SEO KEYWORDS (from actual Google search data):\n"
                . "Primary keyword (use in first paragraph + one H2 heading + 3-5 times naturally in body): " . $kw_data['primary'] . "\n";
            if (!empty($kw_data['secondary'])) {
                $instruction .= "LSI/Secondary keywords (use each 1-2 times naturally): " . implode(', ', $kw_data['secondary']) . "\n";
            }
            if (!empty($kw_data['rising'])) {
                $instruction .= "Trending/Rising keywords (incorporate these — they are gaining search volume): " . implode(', ', $kw_data['rising']) . "\n";
            }
            $instruction .= "These are real queries people use to find this page. Optimize for them.";
        }

        $prompt = "Blog Title: {$title}\n\nExisting Content:\n{$outline}";

        $result = self::call_openai($instruction, $prompt);
        if (is_wp_error($result)) return $result;

        // Safety net: convert any Markdown that slipped through to HTML
        $result = self::fix_markdown_in_html($result);

        // Prepend preserved shortcodes back to the top of the content
        if (!empty($preserved_shortcodes)) {
            $shortcode_block = implode("\n", $preserved_shortcodes) . "\n\n";
            $result = $shortcode_block . $result;
        }

        wp_update_post(['ID' => $post_id, 'post_content' => $result]);
        return $result;
    }

    /**
     * Step 2: Generate meta description (short description / excerpt).
     */
    public static function step_meta_description($post_id) {
        $title = get_the_title($post_id);
        $content = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $snippet = mb_substr($content, 0, 500);

        $instruction = "Write a meta description for this blog post. Rules: exactly 1-2 sentences, 140-155 characters total, start with an action verb (Learn, Discover, Master, Explore, Understand), mention what the reader will gain, do not use quotes or special characters. IMPORTANT: Do NOT invent or assume any certification name or exam code.";
        $prompt = "Title: {$title}\n\nContent:\n{$snippet}";

        $result = self::call_openai($instruction, $prompt, 'gpt-4.1-nano', 0.4);
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
    public static function step_faq_html($post_id) {
        $title = get_the_title($post_id);
        $content = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $snippet = mb_substr($content, 0, 500);

        $instruction = "Generate 5 unique, helpful FAQ entries for this blog post. Each FAQ must follow this exact HTML format:\n\n"
            . "<details><summary>Question here?</summary><div class=\"faq-content\">Answer here.</div></details>\n\n"
            . "Rules:\n"
            . "- Focus on topic-specific questions, best practices, definitions, misconceptions\n"
            . "- Do NOT ask generic site/access questions\n"
            . "- Each answer: 200+ words minimum\n"
            . "- Use focused and LSI keywords naturally\n"
            . "- Use <p> tags for paragraphs and <ul><li> for lists within answers\n"
            . "- IMPORTANT: Do NOT invent or fabricate any certification names or exam codes\n"
            . "- Do NOT number the FAQs or add text outside the <details> blocks";

        $prompt = "Title: {$title}\n\nContent:\n{$snippet}";

        $result = self::call_openai($instruction, $prompt);
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

        $instruction = "Convert the following HTML FAQ into a valid JSON-LD FAQPage schema block inside <script type=\"application/ld+json\"> tags. Only return the JSON-LD script tag. Pretty-print the JSON. Input HTML:\n\n" . $faq_html;

        $result = self::call_openai($instruction, null, 'gpt-4.1-nano', 0.3);
        if (is_wp_error($result)) return $result;

        // Strip markdown code fences
        $result = preg_replace('/^```[a-zA-Z]*\s*/m', '', $result);
        $result = preg_replace('/\s*```\s*$/m', '', $result);
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
        $prompt = "Title: {$title}\n\nContent:\n{$snippet}";

        $keyword = self::call_openai($instruction, $prompt, 'gpt-4.1-nano', 0.3);
        if (is_wp_error($keyword)) {
            $keyword = sanitize_text_field($title);
        } else {
            $keyword = sanitize_text_field(trim($keyword));
        }

        update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);

        return $keyword;
    }

    /**
     * Step 5b: Optimize SEO title for click-through rate.
     * Generates a compelling RankMath SEO title that drives clicks from search results.
     */
    public static function step_seo_title($post_id) {
        $title = get_the_title($post_id);
        $content = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $snippet = mb_substr($content, 0, 800);
        $keyword = get_post_meta($post_id, 'rank_math_focus_keyword', true) ?: '';

        $instruction = "You are an SEO expert who specializes in writing click-worthy search result titles. Given the blog post title, focus keyword, and content below, write an optimized SEO title.\n\n"
            . "RULES:\n"
            . "- Maximum 60 characters (Google truncates after this)\n"
            . "- Include the focus keyword near the beginning\n"
            . "- End with ' - ITU Online' (this counts toward the 60 characters)\n"
            . "- Make it compelling — use power words, numbers, or a clear benefit\n"
            . "- Do NOT use clickbait or misleading titles\n"
            . "- Do NOT invent certification names or exam codes\n"
            . "- Return ONLY the title, nothing else — no quotes, no explanation\n\n"
            . "GOOD EXAMPLES:\n"
            . "- 7 Essential ITAM Skills Every IT Manager Needs - ITU Online\n"
            . "- What Is Zero Trust Security? A Practical Guide - ITU Online\n"
            . "- CompTIA Security+ SY0-701: Complete Study Plan - ITU Online\n"
            . "- How to Build a Cloud Migration Strategy in 2025 - ITU Online\n\n"
            . "BAD EXAMPLES (don't do these):\n"
            . "- Understanding the Importance of IT Asset Management in Modern Organizations - ITU Online (too long, boring)\n"
            . "- IT Asset Management - ITU Online (too generic, no hook)\n"
            . "- You Won't Believe These ITAM Secrets! - ITU Online (clickbait)";

        $prompt = "Current Title: {$title}\nFocus Keyword: {$keyword}\n\nContent:\n{$snippet}";

        $result = self::call_openai($instruction, $prompt, 'gpt-4.1-nano', 0.5);
        if (is_wp_error($result)) return $result;

        $seo_title = sanitize_text_field(trim($result));

        // Only save if it looks reasonable
        if (!empty($seo_title) && mb_strlen($seo_title) <= 70) {
            update_post_meta($post_id, 'rank_math_title', $seo_title);
        }

        return $seo_title;
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
     * Run full refresh pipeline for a blog post.
     */
    public static function full_refresh($post_id) {
        $content = self::step_content($post_id);
        if (is_wp_error($content)) return $content;

        $meta = self::step_meta_description($post_id);
        if (is_wp_error($meta)) return $meta;

        $faq = self::step_faq_html($post_id);
        if (is_wp_error($faq)) return $faq;

        $json = self::step_faq_json($post_id, $faq);
        if (is_wp_error($json)) return $json;

        self::step_rankmath($post_id);
        self::step_seo_title($post_id);
        self::step_timestamp($post_id);

        return true;
    }

    /**
     * Run meta-only refresh for a blog post (CTR fix).
     * Optimizes title, meta description, and focus keyword — the things
     * that directly affect click-through rate in search results.
     */
    public static function meta_refresh($post_id) {
        $meta = self::step_meta_description($post_id);
        if (is_wp_error($meta)) return $meta;

        self::step_rankmath($post_id);
        self::step_seo_title($post_id);
        self::step_timestamp($post_id);

        return true;
    }
}
