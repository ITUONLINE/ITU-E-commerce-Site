<?php
/*
Plugin Name: AI Blog Writer & SEO Assistant (Posts Only)
Description: Write blog outlines & full blogs, generate FAQs, JSON-LD, and meta description for blog posts only.
Version: 1.0
Author: ITU Online
*/

if (!defined('ABSPATH')) exit;

// Register settings
add_action('admin_init', function() {
    add_option('ai_post_api_key', '');
    register_setting('ai_post_options_group', 'ai_post_api_key');
});
add_action('admin_menu', function() {
    add_options_page('AI Blog Writer Settings', 'AI Blog Writer', 'manage_options', 'ai-blog-writer', function() {
        ?>
        <div>
            <h2>AI Blog Writer Settings</h2>
            <form method="post" action="options.php">
                <?php settings_fields('ai_post_options_group'); ?>
                <label for="ai_post_api_key">OpenAI API Key:</label>
                <input type="text" name="ai_post_api_key" value="<?php echo esc_attr(get_option('ai_post_api_key')); ?>" size="50" />
                <?php submit_button(); ?>
            </form>
        </div>
        <?php
    });
});

// Add metabox for posts only
add_action('add_meta_boxes', function() {
    add_meta_box(
        'ai_blog_writer_metabox',
        'AI Blog Writer & SEO Assistant',
        'ai_blog_writer_metabox_render',
        'post',
        'normal',
        'high'
    );
});

function ai_blog_writer_metabox_render($post) {
    $title = get_the_title($post);
    $content = wp_strip_all_tags($post->post_content);
    $words = preg_split('/\s+/', $content);
    $first_300 = implode(' ', array_slice($words, 0, 300));
    $prompt = trim($title);
?>
<div id="ai-blog-writer-box">
	<p><strong>Status:</strong> <span id="ai_blog_writer_status">Idle</span></p>
    <p>
        <label><strong>Blog Topic / Title:</strong></label><br>
		<textarea id="ai_blog_title" rows="2" style="width:100%;"><?php echo esc_attr($title); ?></textarea>
    </p>
    <p>
        <button type="button" class="button" id="ai_generate_outline">Generate Blog Outline</button>
    </p>
    <p>
        <textarea id="ai_blog_outline" rows="3" style="width:100%;" placeholder="Outline will appear here..."></textarea>
    </p>
    <p>
        <button type="button" class="button" id="ai_generate_blog" disabled>Generate Blog (min 800 words, Gutenberg blocks)</button>
    </p>
    <p>
        <textarea id="ai_generated_blog" rows="3" style="width:100%;" placeholder="Generated blog will appear here..."></textarea>
    </p>
    <hr>
    <p>
        <button type="button" class="button" id="ai_generate_post_faqs">Generate SEO FAQs, JSON-LD, Meta Description</button>
    </p>

</div>
<script>
jQuery(document).ready(function($) {
    $('#ai_generate_outline').click(function() {
        const topic = $('#ai_blog_title').val();
        $('#ai_blog_writer_status').text('Generating outline...');
        $.post(ajaxurl, {
            action: 'ai_blog_generate_outline',
            topic: topic
        }, function(resp) {
            if(resp.success) {
                $('#ai_blog_outline').val(resp.data.outline);
                $('#ai_generate_blog').prop('disabled', false);
                $('#ai_blog_writer_status').text('Outline ready!');
            } else {
                $('#ai_blog_writer_status').text('❌ Failed to generate outline.');
            }
        });
    });

$('#ai_generate_blog').click(function() {
    const outline = $('#ai_blog_outline').val();
	const content = wp.data.select('core/editor').getEditedPostContent();

	if (content && content.trim().length > 0) {
	  $('#ai_blog_writer_status').text('Updating Current Blog (can take 30-60 seconds)...');
	} else {
	  $('#ai_blog_writer_status').text('Generating New Blog (can take 30-60 seconds)...');
	}

    $.ajax({
        url: ajaxurl,
        method: 'POST',
        data: {
            action: 'ai_blog_generate_full_blog',
            outline: outline,
			content: content,
			post_id: '<?php echo $post->ID; ?>'
        },
        timeout: 180000, // 30 seconds timeout
        success: function(resp) {
            if (resp.success) {
                $('#ai_generated_blog').val(resp.data.blog);
                // Overwrite current Gutenberg content
                if (window.wp && wp.data) {
                    const blocks = wp.blocks.rawHandler({ HTML: resp.data.blog });
                    wp.data.dispatch('core/editor').resetBlocks(blocks);
                    $('#ai_blog_writer_status').text('Blog inserted in editor!');
                } else {
                    $('#ai_blog_writer_status').text('Blog generated! Copy and paste into the editor.');
                }
            } else {
                $('#ai_blog_writer_status').text('❌ Failed to generate blog.');
            }
        },
        error: function(xhr, status, error) {
            if (status === 'timeout') {
                $('#ai_blog_writer_status').text('❌ Request timed out. Please try again.');
            } else {
                $('#ai_blog_writer_status').text('❌ An error occurred: ' + error);
            }
        }
    });
});


    $('#ai_generate_post_faqs').click(function() {
        const postId = '<?php echo $post->ID; ?>';
        const title = $('#ai_blog_title').val();
        const content = wp.data ? wp.data.select('core/editor').getEditedPostContent() : '';
        $('#ai_blog_writer_status').text('Generating SEO FAQs...');
        $.post(ajaxurl, {
            action: 'ai_blog_generate_faqs',
            post_id: postId,
            title: title,
            content: content
        }, function(faqResp) {
            if(!faqResp.success) {
                $('#ai_blog_writer_status').text('❌ Failed at Step 1: FAQ HTML.');
                return;
            }
            $('textarea[name="acf[field_6816a44480234]"]').val(faqResp.data.faq_html);

            $('#ai_blog_writer_status').text('Step 2 - Generating JSON-LD...');
            $.post(ajaxurl, {
                action: 'ai_blog_generate_faq_json',
                faq_html: faqResp.data.faq_html,
                post_id: postId
            }, function(jsonResp) {
                if (!jsonResp.success) {
                    $('#ai_blog_writer_status').text('❌ Failed at Step 2: JSON-LD.');
                    return;
                }
                $('textarea[name="acf[field_6816d54e3951d]"]').val(jsonResp.data.faq_json);

                $('#ai_blog_writer_status').text('Step 3 - Generating Meta Description...');
                $.post(ajaxurl, {
                    action: 'ai_blog_generate_meta_description',
                    post_id: postId,
                    title: title,
                    content: content
                }, function(metaResp) {
                    if (!metaResp.success) {
                        $('#ai_blog_writer_status').text('❌ Failed at Step 3: Meta Description.');
                        return;
                    }
                    $('#ai_blog_writer_status').text('✅ SEO FAQs, JSON-LD, and Meta Description all set!');
                });
            });
        });
    });
});
</script>
<?php
}

// ----------- AJAX: Generate Blog Outline ----------
add_action('wp_ajax_ai_blog_generate_outline', function() {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    $topic = sanitize_text_field($_POST['topic'] ?? '');
    if (!$api_key || !$topic) {
        wp_send_json_error('Missing API key or topic.');
    }
	
	$instruction = <<<PROMPT
Using the given topic or keword, create a compelling blog title using the keyword or words and display in title case.

Generate a very detailed outline for a long-form blog post (2,000-2,500 words when written). Include at least 6-8 main sections with 4-6 bullet points each.

Return the outline in plain text using only section headings and bulleted lists of key points or subtopics for each section. Do not use any numbers, Roman numerals, or lettered lists. Focus on depth and detail—each section should include multiple comprehensive bullet points that capture the full scope of ideas to be covered in the blog post.

Your output should follow this structure exactly:

TITLE OF BLOG

Main Heading

Comprehensive key point 1

Comprehensive key point 2

Additional detailed subtopics or supporting ideas as bullets

Next Main Heading

Comprehensive key point 1

Comprehensive key point 2

Additional detailed subtopics or supporting ideas as bullets

Continue this structure for all necessary sections of the blog. Ensure the outline covers all major angles, concepts, and relevant details that would contribute to a compelling and complete article.
PROMPT;
	
    $request = json_encode([
        'model' => function_exists('itu_ai_model') ? itu_ai_model('blog_content') : 'gpt-4.1-nano',
        'messages' => [
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => $topic]
        ],
        'temperature' => 0.7
    ]);
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body'    => $request,
        'timeout' => 60
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error('OpenAI Error: ' . $response->get_error_message());
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $outline = trim($data['choices'][0]['message']['content'] ?? '');
    wp_send_json_success(['outline' => $outline]);
});

// --------- AJAX: Generate Full Blog Post ----------
add_action('wp_ajax_ai_blog_generate_full_blog', function() {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    $outline = sanitize_textarea_field($_POST['outline'] ?? '');
    $content = sanitize_textarea_field($_POST['content'] ?? '');
    $post_id = intval($_POST['post_id'] ?? 0);
    if (!$api_key || !$outline) {
        wp_send_json_error('Missing API key or outline.');
    }

    // Extract practice_test shortcodes to preserve them
    $preserved_shortcodes = [];
    if ($post_id) {
        $raw_content = get_post_field('post_content', $post_id);
        if (preg_match_all('/\[practice_test[^\]]*\]/', $raw_content, $matches)) {
            $preserved_shortcodes = $matches[0];
        }
    }

    // Strip ALL shortcodes from content before sending to AI
    $content = preg_replace('/\[[^\]]+\]/', '', $content);

    $content_section = '';
    if (!empty(trim($content))) {
        $content_section = "\n\nHere is the current content to rewrite and improve:\n" . $content;
    }

$site_name = get_bloginfo('name');
$instruction = <<<PROMPT
You are a professional IT blog writer for {$site_name}. Your tone is direct, knowledgeable, and practical. You never sound like a marketing bot or AI. You write for busy IT professionals who scan pages — not people who read every word.

If current content is provided below, rewrite it to optimize for SEO, freshness, and scannability while preserving the core information. If no current content is provided, create a completely new blog from the outline.

BANNED PHRASES — Do NOT use any of these openings or clichés:
- In today's rapidly evolving...
- In an ever-changing landscape...
- In the fast-paced world of...
- As technology continues to...
- In today's digital age...
- With the growing importance of...
- As organizations increasingly...
- In the modern IT landscape...
- Any variation of these patterns
Instead, open with something specific: a concrete problem, a real scenario, or a direct statement about what the reader will learn.

IMPORTANT: Do NOT invent or fabricate any certification names, exam codes, or credential titles. Only mention certifications and exam codes that are explicitly referenced in the outline or existing content.

Write a detailed, long-form blog post using the outline below. This is for the WordPress Gutenberg block editor.

WORD COUNT — CRITICAL: The post MUST be at least 2,000 words. Each main section must be 200-350 words. Do NOT summarize or abbreviate — write every section in full detail with specific examples and actionable advice.

DEPTH REQUIREMENTS — This is critical:
- Each major section (h2) must contain 150-300 words minimum
- Do not write thin, surface-level summaries. Go deep. Explain the "why" and "how," not just the "what"
- Include specific examples, real-world scenarios, tool names, command examples, or step-by-step explanations where relevant
- When comparing options, actually compare them — don't just list them
- When explaining a concept, explain it fully enough that someone unfamiliar could understand it
- Do not say "we will include..." — actually write the content in full detail

SCANNABILITY AND FORMAT VARIETY — Readers scan, they don't read. You MUST use a variety of these formatting elements throughout the post (not just paragraphs and bullets):

1. <p> tags for paragraphs — keep paragraphs SHORT (2-4 sentences max). Long paragraphs kill readability
2. <ul><li> for unordered bullet lists — use for features, options, or items without priority
3. <ol><li> for numbered/ordered lists — use for steps, processes, or ranked items
4. <strong> for key terms and important phrases inline — bold the first mention of key concepts so scanners catch them
5. <blockquote> for notable quotes, industry insights, or a compelling statement — styled as a highlighted pull-quote
6. Callout boxes for tips, key takeaways, warnings, or important notes — use 1-3 per post total (mix of blockquotes and callouts). Use this exact HTML format for callouts:
   ONLY use these exact callout classes (do NOT combine or modify them):
   <div class="itu-callout itu-callout--tip"><p><strong>Pro Tip</strong></p><p>Content.</p></div>
   <div class="itu-callout itu-callout--info"><p><strong>Note</strong></p><p>Content.</p></div>
   <div class="itu-callout itu-callout--warning"><p><strong>Warning</strong></p><p>Content.</p></div>
   <div class="itu-callout itu-callout--key"><p><strong>Key Takeaway</strong></p><p>Content.</p></div>
6. <table> ONLY for simple 2-column comparisons (e.g., Feature vs Benefit, Option A vs Option B). Never use tables with 3+ columns — they break on mobile. For multi-item comparisons, use bullet lists with <strong>bold labels</strong> instead
7. <h3> subheadings within sections to break up long sections — use when a section covers multiple sub-topics

OUTPUT FORMAT — CRITICAL:
- Return ONLY valid HTML. Do NOT use Markdown syntax anywhere
- Do NOT use # or ## or ### for headings — use <h2> and <h3> HTML tags
- Do NOT use **bold** markdown — use <strong> HTML tags
- Do NOT use - or * for lists — use <ul><li> or <ol><li> HTML tags
- Do NOT use ``` for code blocks — use <code> or <pre> HTML tags

STRUCTURE:
- Use <h2> tags for all major sections
- Use <h3> for subpoints within sections
- Do NOT include an <h1> tag
- Do not use numbering or Roman numerals in headings
- The Introduction should hook the reader with a specific problem or scenario, then preview the key takeaways
- The Conclusion must summarize key points and include a clear call to action
- Every section must mix at least 2 different format types (e.g., paragraph + list, paragraph + table, paragraph + blockquote)

SEO AND GEO OPTIMIZATION:
- Use clear, factual language for accurate citation by generative AI
- Include relevant keywords and LSI keywords naturally throughout
- Phrase concepts to mirror common user questions (e.g., "What is...", "How does...", "Why is...")
- Use named entity "{$site_name}" where appropriate for attribution
- Write like a real person. Vary sentence length — mix short punchy sentences with longer explanatory ones

AUTHORITATIVE REFERENCES AND DATA — REQUIRED (critical for credibility):
Every blog post MUST include at least 3-5 distinct authoritative references from DIFFERENT sources. Do NOT rely on a single source.

REQUIRED reference types (include ALL that apply to the topic):
1. GOVERNING BODIES & CERT AUTHORITIES — MUST cite official source for relevant cert/vendor:
   CompTIA, Cisco, Microsoft (learn.microsoft.com), AWS, ISC2, ISACA, PMI, EC-Council,
   Axelos/PeopleCert, Google Cloud, Linux Foundation, Red Hat, VMware/Broadcom, Juniper, Palo Alto Networks
2. COMPLIANCE & REGULATORY FRAMEWORKS:
   NIST (CSF, SP 800), ISO 27001/27002/20000, PCI DSS (pcisecuritystandards.org),
   HIPAA/HHS, GDPR/EDPB, SOC 2/AICPA, FedRAMP, CMMC/DoD, CISA, SEC, FERPA, CCPA, COBIT, HITRUST
3. GOVERNMENT & WORKFORCE:
   BLS (bls.gov/ooh/), DoD Cyber Workforce, DHS, NSA, FTC, GAO, Dept of Labor, NSF
4. PROFESSIONAL ASSOCIATIONS & HR ORGANIZATIONS:
   SHRM (shrm.org), ISSA, IAPP, ACM, IEEE, ITSMF, HDI, Cloud Security Alliance,
   InfraGard, AICPA, World Economic Forum, NICE/NIST Workforce Framework,
   (ISC)² Workforce Study, CompTIA workforce reports
5. INDUSTRY RESEARCH & ANALYST FIRMS:
   Gartner, Forrester, IDC, McKinsey, Deloitte, PwC, KPMG, SANS Institute,
   Cybersecurity Ventures, Verizon DBIR, IBM Cost of a Data Breach, Ponemon Institute,
   CrowdStrike Threat Report, Mandiant/Google Threat Intel
6. TECHNICAL STANDARDS: Official vendor docs, IETF RFCs, OWASP, CIS Benchmarks,
   MITRE ATT&CK, W3C, FIRST (first.org)
7. SALARY — Use MULTIPLE sources: BLS, Glassdoor, PayScale, Robert Half, Indeed,
   LinkedIn, Dice, Global Knowledge Salary Report, SHRM compensation data

CITATION RULES:
- Format as HTML links: <a href="URL" target="_blank" rel="noopener">Source Name</a>
- Spread references throughout — each H2 section should have at least one
- VARY sources — do not cite same org more than twice per article
- For certs, always reference official cert page for exam details (domains, questions, passing score, cost)
- NEVER reference, link to, or mention competing IT training providers, online course platforms, bootcamps, or training companies. This includes: Coursera, Udemy, Pluralsight, CBT Nuggets, Cybrary, LinkedIn Learning, A Cloud Guru, INE, Infosec Institute, Training Camp, Global Knowledge, Skillsoft, Simplilearn, KnowledgeHut, edX, Codecademy, DataCamp, or ANY other entity that sells IT training. For learning resources, cite official vendor docs (Microsoft Learn, AWS Skill Builder, Cisco Learning Network) instead.
- Do NOT fabricate URLs — use main domain or well-known subpages you are confident exist
- Include concrete data: salary ranges, growth %, pass rates, market size, exam details
- Think like a human researcher: cross-reference claims from multiple sources

AI SEARCH OPTIMIZATION — Structure content so AI search engines (Google AI Overview, Perplexity, ChatGPT) can cite it:
- Lead sections with clear, factual thesis statements that directly answer common questions
- Use definition-style sentences for key concepts (e.g., "SIEM is a security solution that..." not "Let's talk about SIEM")
- Include comparison tables and structured lists that AI can extract as direct answers
- Write FAQ-style subheadings that match natural language queries (e.g., "How Long Does It Take to Get CompTIA A+ Certified?")
- Provide specific, quotable sentences with concrete numbers — AI search engines prefer citing exact claims over vague statements
- Use <strong> on key facts and definitions to help parsers identify core claims

Here is the outline:
$outline$content_section
PROMPT;

    $request = json_encode([
        'model' => function_exists('itu_ai_model') ? itu_ai_model('blog_content') : 'gpt-4.1-nano',
        'messages' => [
            ['role' => 'system', 'content' => $instruction]
        ],
        'temperature' => 0.7
    ]);
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body'    => $request,
        'timeout' => 240
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error('OpenAI Error: ' . $response->get_error_message());
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $blog = trim($data['choices'][0]['message']['content'] ?? '');

    // Safety net: convert any Markdown that slipped through to HTML
    $blog = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $blog);
    $blog = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $blog);
    $blog = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $blog);
    $blog = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $blog);
    $blog = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $blog);

    // Prepend preserved practice_test shortcodes back to the top
    if (!empty($preserved_shortcodes)) {
        $shortcode_block = implode("\n", $preserved_shortcodes) . "\n\n";
        $blog = $shortcode_block . $blog;
    }

    // Save content directly to the database
    if ($post_id) {
        wp_update_post(['ID' => $post_id, 'post_content' => $blog]);
    }

    wp_send_json_success(['blog' => $blog]);
});

// -------- AJAX: Generate SEO FAQs ----------
add_action('wp_ajax_ai_blog_generate_faqs', function() {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    $post_id = intval($_POST['post_id']);
    $title = sanitize_text_field($_POST['title'] ?? '');
    $content = wp_strip_all_tags($_POST['content'] ?? '');
    $words = preg_split('/\s+/', $content);
    $first_300 = implode(' ', array_slice($words, 0, 300));
    if (!$api_key || !$post_id || !$title) {
        wp_send_json_error('Missing API key, post ID, or title.');
    }
    $instruction = <<<INSTRUCTION
You are an expert blog writer and SEO specialist. Generate 5 unique, helpful FAQ entries that readers of the following blog post would be likely to ask.

RULES:
- Avoid generic site or access questions
- Focus on the blog topic, best practices, key definitions, misconceptions, and relevant insights
- Each answer should be 200 or more words
- Use focused keywords and LSI keywords naturally
- IMPORTANT: Do NOT invent or fabricate any certification names, exam codes, or credential titles. Only reference certifications that are explicitly mentioned in the blog content
- Do not number the FAQs or add any text outside the <details> blocks

FORMAT — Each FAQ must follow this exact HTML format:
<details><summary>Question here?</summary><div class="faq-content">
<p>First paragraph of the answer with key information.</p>
<p>Second paragraph with additional detail and examples.</p>
</div></details>

CRITICAL: Every answer MUST wrap ALL text in <p> tags. Do NOT put raw text directly inside the faq-content div without <p> tags. Break each answer into 2-4 separate paragraphs. Use <ul><li> for lists where appropriate.

Title: $title
Excerpt: $first_300
INSTRUCTION;
    $request = json_encode([
        'model' => function_exists('itu_ai_model') ? itu_ai_model('blog_content') : 'gpt-4.1-nano',
        'messages' => [
            ['role' => 'system', 'content' => $instruction]
        ],
        'temperature' => 0.7
    ]);
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body'    => $request,
        'timeout' => 90
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error('OpenAI Error: ' . $response->get_error_message());
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $faq_html = trim($data['choices'][0]['message']['content'] ?? '');

    // Fix FAQ answers missing <p> tags — wrap raw text in paragraphs
    $faq_html = preg_replace_callback(
        '/<div class="faq-content">(.*?)<\/div>/s',
        function ($match) {
            $content = trim($match[1]);
            if (stripos($content, '<p>') !== false) return $match[0];
            $sentences = preg_split('/(?<=[.!?])\s+/', $content);
            $chunks = []; $current = '';
            foreach ($sentences as $i => $s) {
                $current .= ($current ? ' ' : '') . $s;
                if (($i + 1) % 3 === 0 || $i === count($sentences) - 1) { $chunks[] = $current; $current = ''; }
            }
            $wrapped = '';
            foreach ($chunks as $c) {
                $c = trim($c);
                if (empty($c)) continue;
                $wrapped .= preg_match('/^<(p|ul|ol)/i', $c) ? $c . "\n" : '<p>' . $c . "</p>\n";
            }
            return '<div class="faq-content">' . "\n" . $wrapped . '</div>';
        },
        $faq_html
    );

    // Save FAQ HTML to ACF field server-side
    if ($post_id && function_exists('update_field')) {
        update_field('field_6816a44480234', $faq_html, $post_id);
    }

    wp_send_json_success(['faq_html' => $faq_html]);
});

// -------- AJAX: Generate JSON-LD from FAQ HTML ----------
add_action('wp_ajax_ai_blog_generate_faq_json', function() {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
	$faq_html = wp_kses_post(wp_unslash($_POST['faq_html'] ?? ''));
    $post_id = intval($_POST['post_id']);
    if (!$api_key || !$faq_html || !$post_id) {
        wp_send_json_error('Missing required data.');
    }
$instruction = <<<TEXT
You are an expert SEO and JSON-LD generator.

Convert the following HTML FAQ into a valid JSON-LD FAQPage schema.

Return only the raw JSON. Do not include <script> tags or any explanations.

Formatting instructions:

- Do NOT escape double quotes inside the "text" fields (unless required for valid JSON syntax).
- Do NOT escape apostrophes inside HTML.
- Preserve all HTML tags inside the "text" field, including <p>, <ul>, <li>, etc.
- Do NOT flatten, remove, or modify the HTML structure inside the "text" field.
- The JSON should be pretty-printed (not minified).

Input HTML:

$faq_html

EXPECTED FORMAT:

{
  "@context": "https://schema.org",
  "@type": "FAQPage",
  "mainEntity": [
    {
      "@type": "Question",
      "name": "What are the benefits of cybersecurity certifications?",
      "acceptedAnswer": {
        "@type": "Answer",
        "text": "<p>Cybersecurity certifications can help professionals in multiple ways:</p><ul><li>Validate existing skills</li><li>Improve job prospects</li><li>Provide access to better salaries</li></ul><p>They are also essential for roles in regulated industries.</p>"
      }
    }
  ]
}
TEXT;

    $request = json_encode([
        'model' => function_exists('itu_ai_model') ? itu_ai_model('blog_content') : 'gpt-4.1-nano',
        'messages' => [
            ['role' => 'system', 'content' => $instruction]
        ],
        'temperature' => 0.3
    ]);
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body'    => $request,
        'timeout' => 60
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error('OpenAI Error: ' . $response->get_error_message());
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $faq_json = trim($data['choices'][0]['message']['content'] ?? '');
    update_field('field_6816d54e3951d', $faq_json, $post_id); // Save to ACF
    wp_send_json_success(['faq_json' => $faq_json]);
});

// -------- AJAX: Generate meta description ----------
add_action('wp_ajax_ai_blog_generate_meta_description', function() {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    $post_id = intval($_POST['post_id']);
    $title = sanitize_text_field($_POST['title'] ?? '');
    $content = wp_strip_all_tags($_POST['content'] ?? '');
    $words = preg_split('/\s+/', $content);
    $first_300 = implode(' ', array_slice($words, 0, 300));
    if (!$api_key || !$post_id || !$title) {
        wp_send_json_error('Missing required data.');
    }

    // Generate meta description
    $instruction = <<<DESC
Write a meta description for this blog post. Rules: exactly 1-2 sentences, 140-155 characters total, start with an action verb (Learn, Discover, Master, Explore, Understand), mention what the reader will gain, do not use quotes or special characters. IMPORTANT: Do NOT invent or assume any certification name or exam code. Only reference certifications explicitly mentioned in the content.

Title: $title
Excerpt: $first_300
DESC;
    $request = json_encode([
        'model' => function_exists('itu_ai_model') ? itu_ai_model('blog_content') : 'gpt-4.1-nano',
        'messages' => [
            ['role' => 'system', 'content' => $instruction]
        ],
        'temperature' => 0.4
    ]);
    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body'    => $request,
        'timeout' => 60
    ]);
    if (is_wp_error($response)) {
        wp_send_json_error('OpenAI Error: ' . $response->get_error_message());
    }
    $data = json_decode(wp_remote_retrieve_body($response), true);
    $meta_desc = wp_strip_all_tags(trim($data['choices'][0]['message']['content'] ?? ''));
    update_post_meta($post_id, 'rank_math_description', $meta_desc);

    // Also generate and save a focus keyword
    $kw_instruction = "You are an SEO expert. Given the blog title and content below, return a single primary focus keyword (2-4 words) that best represents what this post is about. The keyword should be something people would actually search for. Return ONLY the keyword, nothing else — no quotes, no explanation.";
    $kw_prompt = "Title: {$title}\n\nContent:\n" . mb_substr($first_300, 0, 500);
    $kw_request = json_encode([
        'model' => function_exists('itu_ai_model') ? itu_ai_model('blog_content') : 'gpt-4.1-nano',
        'messages' => [
            ['role' => 'system', 'content' => $kw_instruction],
            ['role' => 'user', 'content' => $kw_prompt]
        ],
        'temperature' => 0.3
    ]);
    $kw_response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body'    => $kw_request,
        'timeout' => 30
    ]);
    $keyword = $title; // fallback
    if (!is_wp_error($kw_response)) {
        $kw_data = json_decode(wp_remote_retrieve_body($kw_response), true);
        $kw_result = trim($kw_data['choices'][0]['message']['content'] ?? '');
        if (!empty($kw_result)) $keyword = sanitize_text_field($kw_result);
    }
    update_post_meta($post_id, 'rank_math_focus_keyword', $keyword);

    wp_send_json_success([
        'meta_description' => $meta_desc,
        'keyword'          => $keyword,
    ]);
});
?>
