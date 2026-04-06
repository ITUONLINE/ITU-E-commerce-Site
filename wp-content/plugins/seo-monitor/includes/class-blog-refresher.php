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
    public static function step_content($post_id) {
        // Note: year update already runs in Step 1 (before research), no need to repeat here

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

        $site_name = get_bloginfo('name');
        $instruction = "You are a professional IT blog writer for {$site_name}. Your tone is direct, knowledgeable, and practical. You never sound like a marketing bot or AI. You write for busy IT professionals who scan pages — not people who read every word.\n\n"
            . "Rewrite the existing content below to optimize for SEO, freshness, and scannability while preserving the core information. Improve it significantly with more depth, examples, and actionable advice.\n\n"
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
            . "AI SEARCH OPTIMIZATION — Structure content so AI search engines (Google AI Overview, Perplexity, ChatGPT) can cite it:\n"
            . "- Lead sections with clear, factual thesis statements that directly answer common questions\n"
            . "- Use definition-style sentences for key concepts (e.g., \"SIEM is a security solution that...\" not \"Let's talk about SIEM\")\n"
            . "- Include comparison tables and structured lists that AI can extract as direct answers\n"
            . "- Write FAQ-style subheadings that match natural language queries (e.g., \"How Long Does It Take to Get CompTIA A+ Certified?\")\n"
            . "- Provide specific, quotable sentences with concrete numbers — AI search engines prefer citing exact claims over vague statements\n"
            . "- Use <strong> on key facts and definitions to help parsers identify core claims\n\n"
            . "Return only the HTML content, no preamble";

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

        // Inject SEO performance context
        $seo_context = self::get_seo_context($post_id);
        if ($seo_context) {
            $instruction .= "\n\n" . $seo_context;
        }

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
    public static function step_faq_html($post_id) {
        $title = get_the_title($post_id);
        $content = wp_strip_all_tags(get_post_field('post_content', $post_id));
        $snippet = mb_substr($content, 0, 500);

        $instruction = "Generate 5 unique, helpful FAQ entries for this blog post. Each FAQ must follow this exact HTML format:\n\n"
            . "<details><summary>Question here?</summary><div class=\"faq-content\">\n<p>First paragraph of the answer.</p>\n<p>Second paragraph with more detail.</p>\n</div></details>\n\n"
            . "CRITICAL FORMATTING RULES:\n"
            . "- Every answer MUST wrap ALL text in <p> tags. Do NOT put raw text inside <div class=\"faq-content\"> without <p> tags\n"
            . "- Break each answer into 2-4 paragraphs using separate <p> tags\n"
            . "- Use <ul><li> for lists where appropriate\n"
            . "- Do NOT write one long unbroken paragraph — split into multiple <p> blocks\n\n"
            . "Content Rules:\n"
            . "- Focus on topic-specific questions, best practices, definitions, misconceptions\n"
            . "- Do NOT ask generic site/access questions\n"
            . "- Each answer: 200+ words minimum\n"
            . "- Use focused and LSI keywords naturally\n"
            . "- IMPORTANT: Do NOT invent or fabricate any certification names or exam codes\n"
            . "- Do NOT number the FAQs or add text outside the <details> blocks";

        // Use search queries to inform FAQ questions
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
        $site_name = get_bloginfo('name');

        $instruction = "You are an SEO expert who specializes in writing click-worthy search result titles. Given the blog post title, focus keyword, and content below, write an optimized SEO title.\n\n"
            . "RULES:\n"
            . "- Maximum 60 characters (Google truncates after this)\n"
            . "- Include the focus keyword near the beginning\n"
            . "- End with ' - {$site_name}' (this counts toward the 60 characters)\n"
            . "- Make it compelling — use power words, numbers, or a clear benefit\n"
            . "- Do NOT use clickbait or misleading titles\n"
            . "- Do NOT invent certification names or exam codes\n"
            . "- Return ONLY the title, nothing else — no quotes, no explanation\n\n"
            . "GOOD EXAMPLES:\n"
            . "- 7 Essential ITAM Skills Every IT Manager Needs - {$site_name}\n"
            . "- What Is Zero Trust Security? A Practical Guide - {$site_name}\n"
            . "- CompTIA Security+ SY0-701: Complete Study Plan - {$site_name}\n"
            . "- How to Build a Cloud Migration Strategy in 2025 - {$site_name}\n\n"
            . "BAD EXAMPLES (don't do these):\n"
            . "- Understanding the Importance of IT Asset Management in Modern Organizations - {$site_name} (too long, boring)\n"
            . "- IT Asset Management - {$site_name} (too generic, no hook)\n"
            . "- You Won't Believe These ITAM Secrets! - {$site_name} (clickbait)";

        // Add CTR context for title optimization
        $ctx = get_transient('seom_refresh_context_' . $post_id);
        if ($ctx) {
            $cat = $ctx['category'] ?? '';
            $pos = $ctx['position'] ?? 0;
            $ctr_val = round($ctx['ctr'] ?? 0, 2);
            $clicks_val = $ctx['clicks'] ?? 0;
            $imp_val = $ctx['impressions'] ?? 0;

            if (in_array($cat, ['B', 'E'])) {
                $instruction .= "\n\nCRITICAL: This page has a CTR problem — position " . round($pos, 1) . " with {$imp_val} impressions but only {$clicks_val} clicks ({$ctr_val}% CTR). "
                    . "The current title is NOT compelling enough. Write a title that would make a searcher choose this result over competitors.";
            } elseif ($pos > 0) {
                $instruction .= "\n\nThis page currently ranks at position " . round($pos, 1) . " with {$ctr_val}% CTR. Optimize the title to improve click-through rate.";
            }
            if (!empty($ctx['top_queries'][0]['query'])) {
                $instruction .= "\nTop search query: \"" . $ctx['top_queries'][0]['query'] . "\" — the title should resonate with this search intent.";
            }
        }

        $prompt = "Current Title: {$title}\nFocus Keyword: {$keyword}\n\nContent:\n{$snippet}";

        $result = self::call_openai($instruction, $prompt, self::model_for('blog_seo_title'), 0.5);
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
