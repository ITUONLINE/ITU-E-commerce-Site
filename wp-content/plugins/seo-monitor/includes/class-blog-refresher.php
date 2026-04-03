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
     * Call OpenAI with given instruction and prompt.
     */
    private static function call_openai($instruction, $user_prompt = '', $model = '', $temperature = 0.7) {
        if (!$model) $model = function_exists('itu_ai_model') ? itu_ai_model('default') : 'gpt-4.1-nano';

        // Use unified provider router if available
        if (function_exists('itu_ai_call')) {
            return itu_ai_call($instruction, $user_prompt, $model, $temperature, ['key_name' => 'blog_writer', 'timeout' => 240]);
        }

        $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
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

        // Step 1a: Generate a detailed outline from the existing content first
        // This forces the AI to plan 6-8 sections before writing, producing longer content
        $existing_snippet = mb_substr($existing, 0, 3000);

        $outline_instruction = "Using the given blog title and existing content below, create a compelling blog title in title case, then generate a very detailed outline for a long-form blog post that will be 2,000-2,500 words when fully written.\n\n"
            . "The outline must have at least 6-8 main sections (not counting Introduction and Conclusion). Each section must have 4-6 detailed bullet points covering specific concepts, examples, tools, or steps. The more detailed the outline, the longer and better the final blog post will be.\n\n"
            . "The outline should expand on the original content, adding depth, new angles, and practical details.\n"
            . "Do NOT invent certification names or exam codes not in the original content.\n\n"
            . "Return the outline in plain text using section headings and bulleted key points. Do not use numbers or Roman numerals.\n\n"
            . "Format:\nBLOG TITLE\n\nMain Heading\n- Key point 1\n- Key point 2\n- Additional subtopics\n\nNext Main Heading\n- Key point 1\n- Key point 2";

        $outline = self::call_openai($outline_instruction, "Title: {$title}\n\nExisting Content:\n{$existing_snippet}");
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

        $prompt = "Blog Title: {$title}\n\nOutline:\n{$outline}";

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

        $prompt = "Title: {$title}\n\nContent:\n{$snippet}";

        $result = self::call_openai($instruction, $prompt);
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

        $result = self::call_openai($instruction, null, 'gpt-4.1-nano', 0.3);
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
