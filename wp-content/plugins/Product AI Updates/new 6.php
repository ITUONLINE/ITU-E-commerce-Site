<?php
/*
Plugin Name: AI Content Generator
Description: Generates WooCommerce product descriptions, short descriptions, and FAQs using ChatGPT.
Version: 1.2
Author: ITU Online
*/

if (!defined('ABSPATH')) exit;

// Release PHP session lock so other admin requests aren't blocked during long API calls
function aicg_release_session_lock() {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
}

// remove quotes from content.
function aicg_strip_surrounding_quotes($text) {
    return preg_replace('/^(["\'])(.*)\1$/s', '$2', trim($text));
}


// Register settings
function aicg_register_settings() {
    add_option('aicg_api_key', '');
    register_setting('aicg_options_group', 'aicg_api_key');
}
add_action('admin_init', 'aicg_register_settings');

function aicg_register_options_page() {
    add_options_page('AI Content Generator', 'AI Content Generator', 'manage_options', 'aicg', 'aicg_options_page');
}
add_action('admin_menu', 'aicg_register_options_page');

function aicg_options_page() {
?>
<div>
    <h2>AI Content Generator Settings</h2>
    <form method="post" action="options.php">
        <?php settings_fields('aicg_options_group'); ?>
        <label for="aicg_api_key">OpenAI API Key:</label>
        <input type="text" name="aicg_api_key" value="<?php echo esc_attr(get_option('aicg_api_key')); ?>" size="50" />
        <?php submit_button(); ?>
    </form>
</div>
<?php
}

// Add metabox to product edit page
add_action('add_meta_boxes', 'aicg_add_meta_box');
function aicg_add_meta_box() {
    add_meta_box(
        'aicg_metabox',
        'AI Content Generator',
        'aicg_render_meta_box',
        'product',
        'normal',
        'high'
    );
}

function aicg_render_meta_box($post) {
    $title = get_the_title($post);
    $content = wp_strip_all_tags(get_post_field('post_content', $post));
    $default_prompt = trim($title . "\n\n" . $content);

    // For new/empty courses, enrich the prompt with the course outline if available
    if (empty(trim($content))) {
        $sku = get_post_meta($post->ID, '_sku', true);
        if ($sku && function_exists('get_course_outline_from_sku')) {
            $outline = get_course_outline_from_sku($sku);
            if (is_array($outline) && count($outline)) {
                $csv_lines = array_map(function($row) {
                    return "{$row['module_title']},{$row['lesson_title']}";
                }, $outline);
                $default_prompt = $title . "\n\nCourse Outline:\n" . implode("\n", $csv_lines);
            }
        }
    }
?>
<div id="aicg-box">
    <p>
        <label for="aicg_prompt"><strong>Prompt:</strong></label><br>
        <textarea id="aicg_prompt" rows="6" style="width:100%;"><?php echo esc_textarea($default_prompt); ?></textarea>
    </p>
    <p>
        <button type="button" class="button" id="aicg_generate_description">Generate Full Description</button>
        <button type="button" class="button" id="aicg_generate_short">Generate Short Description</button>
        <button type="button" class="button" id="aicg_generate_faq">Generate FAQ + JSON-LD</button>
		<button type="button" class="button" id="aicg_generate_objectives" style="display:none;">Populate Objectives</button>
		<button type="button" class="button" id="aicg_generate_audience" style="display:none;">Generate Audience</button>
		<button type="button" class="button" id="aicg_update_rankmath">Update RankMath SEO</button>
		<button type="button" class="button button-primary" id="aicg_process_all">Process All</button>
    </p>
    <p><strong>Status:</strong> <span id="aicg_status">Idle</span></p>
</div>

<script>
jQuery(document).ready(function($) {

// Helper: push keyword + description into RankMath's Gutenberg data store
function updateRankMathUI(keyword, description) {
    // Update via RankMath's data store (Gutenberg)
    if (window.wp && wp.data && wp.data.dispatch('rank-math')) {
        var rm = wp.data.dispatch('rank-math');
        if (keyword && rm.updateKeywords) {
            rm.updateKeywords(keyword);
        }
        if (description && rm.updateDescription) {
            rm.updateDescription(description);
        }
    }
    // Also update the visible input fields as fallback
    if (keyword) {
        // RankMath focus keyword input
        var kwInput = document.querySelector('.rank-math-focus-keyword input[type="text"]');
        if (kwInput) {
            var nativeSet = Object.getOwnPropertyDescriptor(window.HTMLInputElement.prototype, 'value').set;
            nativeSet.call(kwInput, keyword);
            kwInput.dispatchEvent(new Event('input', { bubbles: true }));
            kwInput.dispatchEvent(new Event('change', { bubbles: true }));
            // Simulate Enter key to add the tag
            kwInput.dispatchEvent(new KeyboardEvent('keydown', { key: 'Enter', keyCode: 13, bubbles: true }));
        }
    }
    if (description) {
        // RankMath description textarea
        var descEl = document.querySelector('#rank-math-editor-description, .rank-math-editor-description textarea');
        if (descEl) {
            var nativeSet = Object.getOwnPropertyDescriptor(window.HTMLTextAreaElement.prototype, 'value').set;
            nativeSet.call(descEl, description);
            descEl.dispatchEvent(new Event('input', { bubbles: true }));
            descEl.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
}

$('#aicg_process_all').click(function () {
    const postId = '<?php echo $post->ID; ?>';
    const prompt = $('#aicg_prompt').val();
    $('#aicg_status').text('Processing: Step 1 - Generating Description...');
    $('#aicg_process_all').prop('disabled', true);

    // Step 1: Generate Full Description
    $.post(ajaxurl, {
        action: 'aicg_generate_content',
        prompt: prompt,
        type: 'description',
        post_id: postId
    }, function (descResp) {
        if (!descResp.success) {
            $('#aicg_status').text('❌ Failed at Step 1: Description.');
            $('#aicg_process_all').prop('disabled', false);
            console.error(descResp.data);
            return;
        }

        const fullDesc = descResp.data.content;
        if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
            tinymce.get('content').setContent(fullDesc);
        } else {
            $('#content').val(fullDesc);
        }

        $('#aicg_status').text('Step 2 - Generating Short Description...');
        // Step 2: Generate Short Description using the current WP description
        var currentDesc = '';
        if (typeof tinymce !== 'undefined' && tinymce.get('content') && !tinymce.get('content').isHidden()) {
            currentDesc = tinymce.get('content').getContent({ format: 'text' });
        } else if ($('#content').length) {
            currentDesc = $('#content').val().replace(/<[^>]*>/g, '');
        }
        var shortPrompt = $('#aicg_prompt').val().split("\n")[0] + "\n\n" + currentDesc;
        $.post(ajaxurl, {
            action: 'aicg_generate_content',
            prompt: shortPrompt,
            type: 'short',
            post_id: postId
        }, function (shortResp) {
            if (!shortResp.success) {
                $('#aicg_status').text('❌ Failed at Step 2: Short Description.');
                $('#aicg_process_all').prop('disabled', false);
                console.error(shortResp.data);
                return;
            }

            const shortDesc = shortResp.data.content;
            if (typeof tinymce !== 'undefined' && tinymce.get('excerpt')) {
                tinymce.get('excerpt').setContent(shortDesc);
            } else {
                $('#excerpt').val(shortDesc);
            }

            $('#aicg_status').text('Step 3 - Generating FAQ HTML...');
            // Step 3: Generate FAQ HTML
            $.post(ajaxurl, {
                action: 'aicg_generate_faq_html',
                prompt: prompt,
                post_id: postId
            }, function (faqHtmlResp) {
                if (!faqHtmlResp.success) {
                    $('#aicg_status').text('❌ Failed at Step 3: FAQ HTML.');
                    $('#aicg_process_all').prop('disabled', false);
                    console.error(faqHtmlResp.data);
                    return;
                }

                const faqHtml = faqHtmlResp.data.faq_html;
                $('textarea[name="acf[field_6816a44480234]"]').val(faqHtml);

                $('#aicg_status').text('Step 4 - Generating FAQ JSON-LD...');
                // Step 4: Generate JSON-LD
                $.post(ajaxurl, {
                    action: 'aicg_generate_faq_json',
                    faq_html: faqHtml,
                    post_id: postId
                }, function (jsonResp) {
                    if (!jsonResp.success) {
                        $('#aicg_status').text('❌ Failed at Step 4: JSON-LD.');
                        $('#aicg_process_all').prop('disabled', false);
                        console.error(jsonResp.data);
                        return;
                    }

                    $('textarea[name="acf[field_6816d54e3951d]"]').val(jsonResp.data.faq_json);

                    $('#aicg_status').text('Step 5 - Updating RankMath SEO...');
                    // Step 5: Update RankMath SEO
                    $.post(ajaxurl, {
                        action: 'aicg_update_rankmath_seo',
                        post_id: postId
                    }, function (seoResp) {
                        if (!seoResp.success) {
                            $('#aicg_status').text('❌ Failed at Step 5: RankMath SEO.');
                            $('#aicg_process_all').prop('disabled', false);
                            console.error(seoResp.data);
                            return;
                        }

                        // Push keyword + description into RankMath's Gutenberg UI
                        updateRankMathUI(seoResp.data.keyword, seoResp.data.meta_description);

                        // Final step: save timestamp
                        $.post(ajaxurl, {
                            action: 'aicg_update_last_page_refresh',
                            post_id: postId
                        }, function (refreshResp) {
                            if (refreshResp.success) {
                                $('#aicg_status').text('✅ All steps completed successfully. Last refresh saved.');
                                console.log('Last Page Refresh:', refreshResp.data.datetime);
                                setTimeout(() => {
                                    document.querySelector('#publish')?.click();
                                }, 1500);
                            } else {
                                $('#aicg_status').text('✅ Steps completed, but failed to update refresh timestamp.');
                                console.error(refreshResp.data);
                            }
                            $('#aicg_process_all').prop('disabled', false);
                        });
                    });
                });
            });
        });
    });
});


	
$('#aicg_generate_audience').click(function () {
    $('#aicg_status').text('Generating Audience...');
    $.post(ajaxurl, {
        action: 'aicg_generate_audience',
        post_id: '<?php echo $post->ID; ?>'
    }, function (response) {
        if (response.success) {
			console.log(response);
            $('textarea[name="acf[field_6819065b6e1cc]"]').val(response.data.audience_html);
            $('#aicg_status').text('Audience Populated!');
        } else {
            $('#aicg_status').text('Failed to generate audience.');
            console.error(response.data);
        }
    });
});$

    function callAIContent(type) {
        var prompt = $('#aicg_prompt').val();

        // For short descriptions, always use the current WP description as context
        if (type === 'short') {
            var currentDesc = '';
            if (typeof tinymce !== 'undefined' && tinymce.get('content') && !tinymce.get('content').isHidden()) {
                currentDesc = tinymce.get('content').getContent({ format: 'text' });
            } else if ($('#content').length) {
                currentDesc = $('#content').val().replace(/<[^>]*>/g, '');
            }
            prompt = prompt.split("\n")[0] + "\n\n" + currentDesc;
        }

        $('#aicg_status').text('Generating ' + type + '...');
        $.ajax({
            url: ajaxurl,
            method: 'POST',
            data: {
                action: 'aicg_generate_content',
                prompt: prompt,
                type: type,
                post_id: '<?php echo $post->ID; ?>'
            },
            success: function(response) {
                console.log('Raw AJAX Response:', response);

                if (!response.success) {
                    $('#aicg_status').text('Error: ' + (response.data || 'Unknown error'));
                    return;
                }

                $('#aicg_status').text('Done!');

                let content = response?.data?.content || '';
                let faqJSON = response?.data?.faq_json || '';

                if (typeof content === 'object') content = JSON.stringify(content, null, 2);
                if (typeof faqJSON === 'object') faqJSON = JSON.stringify(faqJSON, null, 2);

                if (type === 'description') {
                    // Try Gutenberg first
                    if (window.wp && wp.data && wp.data.dispatch('core/editor')) {
                        try {
                            var blocks = wp.blocks.rawHandler({ HTML: content });
                            wp.data.dispatch('core/editor').resetBlocks(blocks);
                        } catch(e) {
                            console.log('Gutenberg insert failed, trying classic editor', e);
                        }
                    }
                    // Try TinyMCE (Classic Editor visual tab)
                    if (typeof tinymce !== 'undefined' && tinymce.get('content')) {
                        tinymce.get('content').setContent(content);
                    }
                    // Always update the raw textarea (Classic Editor text tab)
                    if ($('#content').length) {
                        $('#content').val(content);
                    }
                } else if (type === 'short') {
                    if (typeof tinymce !== 'undefined' && tinymce.get('excerpt')) {
                        tinymce.get('excerpt').setContent(content);
                    }
                    if ($('#excerpt').length) {
                        $('#excerpt').val(content);
                    }
                } else if (type === 'faq') {
                    $('textarea[name="acf[field_6816a44480234]"]').val(content);
                    $('textarea[name="acf[field_6816d54e3951d]"]').val(faqJSON);
                }
            },
            error: function(xhr, status, error) {
                console.error('AJAX Error:', status, error, xhr.responseText);
                var msg = 'Server error (' + xhr.status + ')';
                try {
                    var snippet = xhr.responseText.substring(0, 300);
                    msg += ': ' + snippet;
                } catch(e) {}
                $('#aicg_status').text(msg);
            }
        });
    }

    $('#aicg_generate_description').click(() => callAIContent('description'));
    $('#aicg_generate_short').click(() => callAIContent('short'));

			
$('#aicg_generate_faq').click(() => {
    const prompt = $('#aicg_prompt').val();
    $('#aicg_status').text('Generating FAQ HTML...');

    $.post(ajaxurl, {
        action: 'aicg_generate_faq_html',
        prompt: prompt,
        post_id: '<?php echo $post->ID; ?>'
    }, function(response) {
        if (response.success) {
            const faqHtml = response.data.faq_html;
            $('textarea[name="acf[field_6816a44480234]"]').val(faqHtml);

            $('#aicg_status').text('Generating JSON-LD schema...');

            $.post(ajaxurl, {
                action: 'aicg_generate_faq_json',
                faq_html: faqHtml,
                post_id: '<?php echo $post->ID; ?>'
            }, function(jsonResponse) {
                if (jsonResponse.success) {
                    $('textarea[name="acf[field_6816d54e3951d]"]').val(jsonResponse.data.faq_json);
                    $('#aicg_status').text('FAQ and JSON-LD schema generated successfully.');
                } else {
                    $('#aicg_status').text('FAQ HTML done, but JSON-LD generation failed.');
                    console.error(jsonResponse.data);
                }
            });
        } else {
            $('#aicg_status').text('Failed to generate FAQ HTML.');
            console.error(response.data);
        }
    });
});
			
$('#aicg_generate_objectives').click(function() {
    $('#aicg_status').text('Generating Objectives...');
    $.post(ajaxurl, {
        action: 'aicg_generate_objectives',
        post_id: '<?php echo $post->ID; ?>'
    }, function(response) {
        if (response.success && Array.isArray(response.data.objectives)) {
            const fields = [
                'field_681689611582c',
                'field_681689af1582d',
                'field_681689be1582e',
                'field_681689ca1582f',
                'field_681689d315830',
                'field_681689de15831',
                'field_681689e615832',
                'field_681689ed15833'
            ];
            fields.forEach((fieldKey, index) => {
                const value = response.data.objectives[index] || '';
                $(`input[name="acf[${fieldKey}]"]`).val(value);
            });
            $('#aicg_status').text('Objectives Populated!');
        } else {
            $('#aicg_status').text('Failed to generate objectives.');
            console.error(response.data);
        }
    });
});
	
	$('#aicg_update_rankmath').click(function () {
    $('#aicg_status').text('Updating RankMath SEO...');
    $.post(ajaxurl, {
        action: 'aicg_update_rankmath_seo',
        post_id: '<?php echo $post->ID; ?>'
    }, function (response) {
        if (response.success) {
			console.log(response);
            updateRankMathUI(response.data.keyword, response.data.meta_description);
            $('#aicg_status').text('RankMath SEO updated! Keyword: ' + response.data.keyword);
        } else {
            $('#aicg_status').text('Failed to update RankMath SEO.');
            console.error(response.data);
        }
    });
});

});
	
	
	
</script>
<?php
}


add_action('wp_ajax_aicg_update_rankmath_seo', 'aicg_update_rankmath_seo');
function aicg_update_rankmath_seo() {
    aicg_release_session_lock();
    $post_id = intval($_POST['post_id']);

    if (!$post_id) {
        wp_send_json_error('Missing post ID');
    }

    $api_key = get_option('aicg_api_key');
    $title = get_the_title($post_id);
    $content = get_post_field('post_content', $post_id);
    $excerpt = get_post_field('post_excerpt', $post_id);
    $plain_content = wp_strip_all_tags($content);

    // Use AI to extract the best primary keyword from the actual content
    $primary_keyword = '';
    if ($api_key && $plain_content) {
        $kw_instruction = "You are an SEO expert. Given the course title and description below, return a single primary focus keyword (2-4 words) that best represents what this course is about. The keyword should be something people would actually search for. Return ONLY the keyword, nothing else — no quotes, no explanation.";
        $kw_prompt = "Course Title: " . $title . "\n\nCourse Description:\n" . mb_substr($plain_content, 0, 1500);

        $kw_response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
            'headers' => [
                'Authorization' => 'Bearer ' . $api_key,
                'Content-Type' => 'application/json'
            ],
            'body' => json_encode([
                'model' => 'gpt-4',
                'messages' => [
                    ['role' => 'system', 'content' => $kw_instruction],
                    ['role' => 'user', 'content' => $kw_prompt]
                ],
                'temperature' => 0.3
            ]),
            'timeout' => 60
        ]);

        if (!is_wp_error($kw_response)) {
            $kw_data = json_decode(wp_remote_retrieve_body($kw_response), true);
            $primary_keyword = sanitize_text_field(trim($kw_data['choices'][0]['message']['content'] ?? ''));
        }
    }

    // Fallback: use the course title if AI didn't return a keyword
    if (empty($primary_keyword)) {
        $primary_keyword = sanitize_text_field($title);
    }

    // Use the short description as meta description (already AI-generated with proper rules)
    $meta_description = wp_strip_all_tags($excerpt);

    // Save to Rank Math custom fields
    update_post_meta($post_id, 'rank_math_focus_keyword', $primary_keyword);
    update_post_meta($post_id, 'rank_math_description', $meta_description);

    // Trigger post update for refresh
    $post = get_post($post_id);
    wp_update_post([
        'ID' => $post_id,
        'post_title' => $post->post_title
    ]);

    wp_send_json_success([
        'keyword' => $primary_keyword,
        'meta_description' => $meta_description
    ]);
}

// Handle AJAX call to OpenAI API
add_action('wp_ajax_aicg_generate_content', 'aicg_ajax_generate_content');
function aicg_log($msg) {
    $log_file = WP_CONTENT_DIR . '/aicg_debug.log';
    $timestamp = date('[Y-m-d H:i:s] ');
    file_put_contents($log_file, $timestamp . $msg . PHP_EOL, FILE_APPEND);
}

function aicg_ajax_generate_content() {
    aicg_release_session_lock();
    // Register custom error handler to catch fatals
    set_error_handler(function($errno, $errstr, $errfile, $errline) {
        aicg_log("PHP Error [$errno]: $errstr in $errfile on line $errline");
        return false;
    });
    register_shutdown_function(function() {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            aicg_log("FATAL: {$error['message']} in {$error['file']} on line {$error['line']}");
        }
    });

    aicg_log('generate_content called, type=' . ($_POST['type'] ?? 'none') . ', post_id=' . ($_POST['post_id'] ?? 'none'));

    $api_key = get_option('aicg_api_key');
    $prompt = sanitize_textarea_field($_POST['prompt'] ?? '');
    $type = sanitize_text_field($_POST['type'] ?? 'description');
    $post_id = intval($_POST['post_id']);

    aicg_log('type=' . $type . ', post_id=' . $post_id . ', prompt_length=' . strlen($prompt));

    switch ($type) {
        case 'faq':
            $instruction = "You are an IT certification training expert. Generate 5 FAQ entries for the course below. Each FAQ must follow this exact HTML format:\n\n<details><summary>Question here?</summary><div class=\"faq-content\">Answer here.</div></details>\n\nQUESTION RULES:\n- Ask questions a student would search before enrolling (e.g., \"What topics does the [cert] exam cover?\", \"What prerequisites do I need for [cert]?\", \"How does [cert] compare to [related cert]?\")\n- Include the certification name or exam code in at least 3 of the 5 questions\n- Do NOT ask generic questions about the website, pricing, or account access\n\nANSWER RULES:\n- Each answer: 150-250 words\n- Use <p> tags for paragraphs and <ul><li> for lists where appropriate\n- Include the certification name, exam code, vendor name, and related technologies naturally\n- Cover: exam scope, key domains/topics, career benefits, preparation strategies, and comparisons to related certifications\n- Write authoritatively — demonstrate subject matter expertise\n- Do NOT number the FAQs or add any text outside the <details> blocks";
            break;

        case 'short':
            $instruction = "Write a meta description for this IT training course. Rules: exactly 1-2 sentences, 140-155 characters total, start with an action verb (Master, Prepare, Learn, Build), mention the target audience or career outcome, do not use quotes or special characters. IMPORTANT: Only mention a certification name or exam code if one is explicitly stated in the course content provided. Do NOT invent or assume any certification or exam code. Many courses are general skill-building courses with no associated certification. This will be displayed in Google search results.";
            break;

case 'description':
default:
    aicg_log('entering description case');
    $desc_rules = array(
        'You are a senior course content writer at ' . get_bloginfo('name') . ', an IT training company. You write the way a knowledgeable instructor would describe their course to a prospective student. Your tone is direct, confident, and practical. You never sound like a marketing bot or AI.',
        '',
        'Write a product description for the course described in the user prompt. Write completely fresh copy. Do NOT reuse or paraphrase existing content.',
        '',
        'IMPORTANT: Not all courses lead to a certification exam. If the course title does not reference a specific certification or exam code, do NOT invent one. Instead, focus on the practical skills and career value the training provides. Only mention a certification and exam code if the course title clearly indicates one.',
        '',
        'BANNED PHRASES - Do NOT use any of these openings or cliches:',
        '- In today\'s rapidly evolving...',
        '- In an ever-changing landscape...',
        '- In the fast-paced world of...',
        '- As technology continues to...',
        '- In today\'s digital age...',
        '- With the growing importance of...',
        '- As organizations increasingly...',
        '- In the modern IT landscape...',
        '- Any variation of these patterns',
        'Instead, open with something specific: a concrete problem this skill solves, a real scenario a professional would face, or a direct statement about what this course delivers.',
        '',
        'STRUCTURE (use plain HTML tags only, no classes):',
        '',
        'SECTION 1 - Opening (2-3 paragraphs wrapped in p tags):',
        '- First paragraph: Lead with a specific, concrete statement. Example approaches: pose a real workplace scenario, state a hard fact about the technology, or describe what someone can do after completing this training. Do NOT open with sweeping trend statements.',
        '- Second paragraph: Describe what this course covers. If a certification exists, name it and its exam code here. Wrap the primary keyword in strong tags once only.',
        '- Third paragraph (optional): What sets this training apart. Be specific, not generic.',
        '',
        'SECTION 2 - h2 tag: What You Will Learn',
        '- Write 2-3 introductory sentences, then a ul list of 8-10 specific learning outcomes. Each list item should be a full sentence describing a concrete skill the student will walk away with, not just a topic name. Draw from the course outline if provided.',
        '',
        'SECTION 3 - h2 tag: Who This Course Is For',
        '- Write 2-3 sentences describing the ideal student. Name 4-5 specific job titles. State the experience level clearly. If prerequisites are apparent from the content, mention them.',
        '',
        'SECTION 4 - h2 tag: Why These Skills Matter',
        '- If the course leads to a certification: explain the career impact of that credential, employer demand, and what doors it opens.',
        '- If the course does NOT lead to a certification: explain why mastering these skills gives professionals a competitive edge, how they apply on the job, and what career outcomes they support.',
        '- Do NOT list course features like video hours, practice questions, or tools since those are shown elsewhere on the page.',
        '',
        'CONTENT RULES:',
        '- Total length: 500-700 words',
        '- Write in clear, short sentences. Vary sentence structure.',
        '- Use the course topic, vendor name, technologies, and related job titles naturally throughout',
        '- Use strong tags only once for the primary keyword in the opening',
        '- Use plain p, h2, ul, li tags only. No custom classes.',
        '- Do NOT include an h1 tag since the page already has one',
        '- Do NOT mention specific course stats like hours, video count, or question count',
        '- Write like a real person. Vary paragraph length. Mix short punchy sentences with longer explanatory ones.',
        '- Use active voice and second person throughout'
    );
    $instruction = implode("\n", $desc_rules);
    aicg_log('instruction set, length=' . strlen($instruction));
    break;
    }

    aicg_log('building request JSON');
    $request = json_encode([
        'model' => 'gpt-4',
        'messages' => [
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => $prompt]
        ],
        'temperature' => 0.7
    ]);
    aicg_log('request built, calling OpenAI, request_length=' . strlen($request));

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body'    => $request,
        'timeout' => 180
    ]);
    aicg_log('OpenAI response received');

    if (is_wp_error($response)) {
        aicg_log('OpenAI WP_Error: ' . $response->get_error_message());
        wp_send_json_error('OpenAI Error: ' . $response->get_error_message());
    }

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $full_faq = aicg_strip_surrounding_quotes($data['choices'][0]['message']['content'] ?? '');
    aicg_log('content extracted, length=' . strlen($full_faq));
    $faq_html = '';
    $faq_json = '';

    if ($type === 'faq') {
        // Extract the <script> block
        if (preg_match('/^(.*)<script[^>]*>(.*?)<\/script>/is', $full_faq, $matches)) {
            $faq_html = trim($matches[1]);
            $faq_json = trim($matches[2]);
        } else {
            $faq_html = $full_faq;
        }
    }

    // Save content directly to the database
    if ($post_id && $type === 'description') {
        // Clear old RankMath FAQ/schema meta before replacing content
        global $wpdb;
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key LIKE %s",
            $post_id, 'rank_math_schema_%'
        ));
        wp_update_post(['ID' => $post_id, 'post_content' => $full_faq]);
    }
    if ($post_id && $type === 'short') {
        wp_update_post(['ID' => $post_id, 'post_excerpt' => wp_strip_all_tags($full_faq)]);
    }

    // Return response
    wp_send_json_success([
        'content' => $type === 'faq' ? $faq_html : $full_faq,
        'faq_json' => $faq_json
    ]);
}

add_action('wp_ajax_aicg_generate_objectives', 'aicg_ajax_generate_objectives');
function aicg_ajax_generate_objectives() {
    $api_key = get_option('aicg_api_key');
    $post_id = intval($_POST['post_id']);
	
	error_log('[AICG] Objectives AJAX triggered. POST: ' . print_r($_POST, true));

    if (!$post_id || !$api_key) {
        error_log('Missing post ID or API key.');
        wp_send_json_error('Missing post ID or API key.');
    }

    $sku = get_post_meta($post_id, '_sku', true);
    if (!$sku) {
        error_log("SKU not found for post ID $post_id");
        wp_send_json_error('SKU not found.');
    }

    if (!function_exists('get_course_outline_from_sku')) {
        error_log('Function get_course_outline_from_sku not found.');
        wp_send_json_error('Required DB function is missing.');
    }

    $outline = get_course_outline_from_sku($sku);

    if (!is_array($outline) || empty($outline)) {
        error_log('No outline data returned for SKU ' . $sku);
        wp_send_json_error('No outline data returned.');
    }

    $csv_lines = array_map(function($row) {
        return "{$row['module_title']},{$row['lesson_title']}";
    }, $outline);
    $csv_data = implode("\n", $csv_lines);

    $instruction = <<<PROMPT
You are an instructional designer. Given this course outline in comma-separated format with module and lesson titles, generate 8 concise learning objectives (max 128 characters each) for the course. These objectives will be displayed to students before purchase. Keep each objective clear, outcome-focused, and beginner-friendly.

Course Outline:
$csv_data
PROMPT;

	
    $request = json_encode([
        'model' => 'gpt-4',
        'messages' => [
            ['role' => 'system', 'content' => $instruction]
        ],
        'temperature' => 0.6
    ]);

    $response = wp_remote_post('https://api.openai.com/v1/chat/completions', [
        'headers' => [
            'Authorization' => 'Bearer ' . $api_key,
            'Content-Type' => 'application/json'
        ],
        'body'    => $request,
        'timeout' => 60
    ]);
	
	error_log('[AICG] Final OpenAI request payload: ' . $request);
	
    if (is_wp_error($response)) {
        error_log('OpenAI Error: ' . $response->get_error_message());
        wp_send_json_error('OpenAI Error: ' . $response->get_error_message());
    }

    $body = wp_remote_retrieve_body($response);
    $data = json_decode($body, true);
    $content = $data['choices'][0]['message']['content'] ?? '';

    if (empty($content)) {
        error_log('No content in OpenAI response: ' . $body);
        wp_send_json_error('No objectives returned by AI.');
    }

    $lines = preg_split('/\r\n|\n|\r|^\d+\.\s*/m', trim($content));

    $objectives = array_values(array_filter(array_map(function($line) {
        return aicg_strip_surrounding_quotes(trim($line));
    }, $lines)));

    if (count($objectives) === 0) {
        error_log('Failed to parse objectives from: ' . $content);
        wp_send_json_error('No objectives parsed.');
    }

    wp_send_json_success([
        'objectives' => array_slice($objectives, 0, 8)
    ]);
}


add_action('wp_ajax_aicg_generate_faq_html', 'aicg_generate_faq_html');
function aicg_generate_faq_html() {
    aicg_release_session_lock();
    $api_key = get_option('aicg_api_key');
    $prompt = sanitize_text_field($_POST['prompt'] ?? '');
    $post_id = intval($_POST['post_id'] ?? 0);

    if (!$api_key || !$prompt) {
        wp_send_json_error('Missing API key or prompt.');
    }

   $instruction = "You are an IT certification training expert. Generate 5 FAQ entries for the course below. Each FAQ must follow this exact HTML format:\n\n<details><summary>Question here?</summary><div class=\"faq-content\">Answer here.</div></details>\n\nQUESTION RULES:\n- Ask questions a student would search before enrolling (e.g., \"What topics does the [cert] exam cover?\", \"What prerequisites do I need for [cert]?\", \"How does [cert] compare to [related cert]?\")\n- Include the certification name or exam code in at least 3 of the 5 questions\n- Do NOT ask generic questions about the website, pricing, or account access\n\nANSWER RULES:\n- Each answer: 150-250 words\n- Use <p> tags for paragraphs and <ul><li> for lists where appropriate\n- Include the certification name, exam code, vendor name, and related technologies naturally\n- Cover: exam scope, key domains/topics, career benefits, preparation strategies, and comparisons to related certifications\n- Write authoritatively — demonstrate subject matter expertise\n- Do NOT number the FAQs or add any text outside the <details> blocks";

    // Get SKU and fetch outline
    $sku = $post_id ? get_post_meta($post_id, '_sku', true) : '';
    if ($sku && function_exists('get_course_outline_from_sku')) {
        $outline = get_course_outline_from_sku($sku);

        if (is_array($outline) && count($outline)) {
            $csv_lines = array_map(function($row) {
                return "{$row['module_title']},{$row['lesson_title']}";
            }, $outline);
            $outline_text = implode("\n", $csv_lines);

            $instruction .= "\n\nCourse Outline:\n" . $outline_text;
        }
    }

	
    $request = json_encode([
        'model' => 'gpt-4o-mini',
        'messages' => [
            ['role' => 'system', 'content' => $instruction],
            ['role' => 'user', 'content' => $prompt]
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

    if (empty($faq_html)) {
        wp_send_json_error('No FAQ HTML returned.');
    }

    // Save FAQ HTML to ACF field
    if ($post_id && function_exists('update_field')) {
        update_field('field_6816a44480234', $faq_html, $post_id);
    }

    wp_send_json_success(['faq_html' => $faq_html]);
}

add_action('wp_ajax_aicg_generate_faq_json', 'aicg_generate_faq_json');
function aicg_generate_faq_json() {
    aicg_release_session_lock();
    $api_key = get_option('aicg_api_key');
    $faq_html = sanitize_textarea_field($_POST['faq_html'] ?? '');
    $post_id = intval($_POST['post_id'] ?? 0);

    if (!$api_key || empty($faq_html)) {
        wp_send_json_error('Missing API key or FAQ HTML content.');
    }

    $instruction = <<<TEXT
You are an SEO assistant. Convert the following HTML FAQ into a valid JSON-LD FAQPage schema. Return ONLY the raw JSON object — do NOT wrap it in <script> tags. Use \\n\\n and \\n formatting as needed. Input HTML:

$faq_html
TEXT;

    $request = json_encode([
        'model' => 'gpt-4o-mini',
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

    // Strip markdown code fences and script tags if included
    $faq_json = preg_replace('/^```[a-zA-Z]*\s*/m', '', $faq_json);
    $faq_json = preg_replace('/\s*```\s*$/m', '', $faq_json);
    $faq_json = preg_replace('/<script[^>]*>\s*/i', '', $faq_json);
    $faq_json = preg_replace('/\s*<\/script>/i', '', $faq_json);
    $faq_json = trim($faq_json);

    if (empty($faq_json)) {
        wp_send_json_error('No JSON-LD schema returned.');
    }

    // Save FAQ JSON-LD to ACF field
    if ($post_id && function_exists('update_field')) {
        update_field('field_6816d54e3951d', $faq_json, $post_id);
    }

    wp_send_json_success(['faq_json' => $faq_json]);
}


add_action('wp_ajax_aicg_generate_audience', 'aicg_ajax_generate_audience');
function aicg_ajax_generate_audience() {
    $post_id = intval($_POST['post_id']);
    $api_key = get_option('aicg_api_key');

    if (!$post_id || !$api_key) {
        wp_send_json_error('Missing post ID or API key.');
    }

    $title = get_the_title($post_id);
    $sku = get_post_meta($post_id, '_sku', true);
    $outline_text = '';

    if ($sku && function_exists('get_course_outline_from_sku')) {
        $outline = get_course_outline_from_sku($sku);
        if (is_array($outline) && count($outline)) {
            $csv_lines = array_map(function ($row) {
                return "{$row['module_title']},{$row['lesson_title']}";
            }, $outline);
            $outline_text = implode("\n", $csv_lines);
        }
    }

    $prompt = <<<PROMPT
Given the following course title and outline, generate an HTML ordered list (<ul><li>...</li></ul>) of the types of people who would benefit from this course. Use professional phrasing. Do not reference the course title directly in the list items. Only return the HTML formatted data code.

Course Title:
$title

Course Outline:
$outline_text
PROMPT;

    $request = json_encode([
        'model' => 'gpt-4',
        'messages' => [
            ['role' => 'system', 'content' => $prompt]
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
	$raw_output = trim($data['choices'][0]['message']['content'] ?? '');

	// Try to extract just the <ul>...</ul> part
	if (preg_match('/<ul>.*?<\/ul>/is', $raw_output, $matches)) {
		$audience_html = $matches[0];
	} else {
		// Fallback: return original content
		$audience_html = $raw_output;
	}
	
    if (empty($audience_html)) {
        wp_send_json_error('No audience data returned.');
    }

    wp_send_json_success(['audience_html' => $audience_html]);
}


add_action('wp_ajax_aicg_update_last_page_refresh', 'aicg_update_last_page_refresh');
function aicg_update_last_page_refresh() {
    $post_id = intval($_POST['post_id']);
    if (!$post_id) {
        wp_send_json_error('Missing post ID.');
    }

    $datetime = current_time('mysql'); // WordPress-formatted current datetime
    update_post_meta($post_id, 'last_page_refresh', $datetime);

    wp_send_json_success(['updated' => true, 'datetime' => $datetime]);
}
?>
