<?php
/**
 * Centralized AI Model & API Key Settings
 *
 * Provides a single admin page to manage AI models and API keys
 * used across all ITU custom plugins. Stores in wp_options.
 * Lives in mu-plugins so it's always loaded before other plugins.
 */

if (!defined('ABSPATH')) exit;

// ─── Helper Functions (available to all plugins) ─────────────────────────────

/**
 * Get an AI setting value.
 * @param string $key Setting key
 * @return string
 */
function itu_ai_setting($key) {
    static $settings = null;
    if ($settings === null) {
        $settings = get_option('itu_ai_settings', []);
    }
    $defaults = itu_ai_defaults();
    return $settings[$key] ?? $defaults[$key] ?? '';
}

/**
 * Get the model for a specific process.
 * @param string $process Process identifier
 * @return string Model name
 */
function itu_ai_model($process) {
    return itu_ai_setting('model_' . $process) ?: itu_ai_setting('model_default');
}

/**
 * Get the API key for a specific provider.
 * @param string $provider 'openai' or 'blog_writer'
 * @return string
 */
function itu_ai_key($provider = 'openai') {
    $key = itu_ai_setting('api_key_' . $provider);
    if ($key) return $key;

    // Fallback to legacy option keys for backward compatibility
    if ($provider === 'openai') return get_option('aicg_api_key', '');
    if ($provider === 'blog_writer') return get_option('ai_post_api_key', '');
    return '';
}

/**
 * Default settings.
 */
function itu_ai_defaults() {
    return [
        'api_key_openai'              => '',
        'api_key_blog_writer'         => '',
        'api_key_anthropic'           => '',
        'api_key_gemini'              => '',
        'model_default'               => 'gpt-4.1-nano',
        'model_product_description'   => 'gpt-4.1-nano',
        'model_product_short_desc'    => 'gpt-4.1-nano',
        'model_product_faq'           => 'gpt-4.1-nano',
        'model_product_faq_json'      => 'gpt-4.1-nano',
        'model_product_seo'           => 'gpt-4.1-nano',
        'model_product_seo_title'     => 'gpt-4.1-nano',
        'model_blog_content'          => 'gpt-4.1-nano',
        'model_blog_outline'          => 'gpt-4.1-nano',
        'model_blog_meta'             => 'gpt-4.1-nano',
        'model_blog_faq'              => 'gpt-4.1-nano',
        'model_blog_seo'              => 'gpt-4.1-nano',
        'model_blog_seo_title'        => 'gpt-4.1-nano',
        'model_blog_queue'            => 'gpt-4.1-nano',
        'model_keyword_research'      => 'gpt-4.1-nano',
        'model_topic_generation'      => 'gpt-4.1-nano',
        'model_practice_test'         => 'gpt-4o-mini',
        'model_research'              => '',
        'api_key_perplexity'          => '',
    ];
}

// ─── Admin Page ──────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_options_page('AI Settings', 'AI Settings', 'manage_options', 'itu-ai-settings', 'itu_ai_render_settings');
});

add_action('wp_ajax_itu_ai_save_settings', function () {
    check_ajax_referer('itu_ai_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $defaults = itu_ai_defaults();
    $new = [];

    foreach ($defaults as $key => $default) {
        if (isset($_POST[$key])) {
            $new[$key] = sanitize_text_field($_POST[$key]);
        }
    }

    update_option('itu_ai_settings', $new);

    // Save custom models separately
    if (isset($_POST['custom_models'])) {
        update_option('itu_ai_custom_models', sanitize_textarea_field($_POST['custom_models']));
    }

    // Also update legacy option keys so existing plugin code still works during migration
    if (!empty($new['api_key_openai'])) {
        update_option('aicg_api_key', $new['api_key_openai']);
    }
    if (!empty($new['api_key_blog_writer'])) {
        update_option('ai_post_api_key', $new['api_key_blog_writer']);
    }

    // Sync Perplexity key to SEO Monitor settings
    if (isset($new['api_key_perplexity'])) {
        $seom = get_option('seom_settings', []);
        $seom['perplexity_api_key'] = $new['api_key_perplexity'];
        update_option('seom_settings', $seom);
    }

    // Sync research model to SEO Monitor settings
    if (isset($new['model_research']) && !empty($new['model_research'])) {
        $seom = get_option('seom_settings', []);
        $seom['research_model'] = $new['model_research'];
        update_option('seom_settings', $seom);
    }

    wp_send_json_success('Settings saved.');
});

function itu_ai_render_settings() {
    $settings = get_option('itu_ai_settings', []);
    $defaults = itu_ai_defaults();
    $nonce = wp_create_nonce('itu_ai_nonce');

    // Pre-fill from legacy keys if not yet set
    if (empty($settings['api_key_openai'])) {
        $settings['api_key_openai'] = get_option('aicg_api_key', '');
    }
    if (empty($settings['api_key_blog_writer'])) {
        $settings['api_key_blog_writer'] = get_option('ai_post_api_key', '');
    }

    $s = wp_parse_args($settings, $defaults);

    // Built-in models + any custom models added by user
    $builtin_models = [
        // OpenAI — GPT-5.x Series
        'gpt-5.4'         => 'OpenAI — GPT-5.4 (Flagship, best quality)',
        'gpt-5.4-mini'    => 'OpenAI — GPT-5.4 Mini (Fast, efficient)',
        'gpt-5.4-nano'    => 'OpenAI — GPT-5.4 Nano (Cheapest, high volume)',
        // OpenAI — GPT-4.x Series
        'gpt-4.1'         => 'OpenAI — GPT-4.1 (Best 4.x quality)',
        'gpt-4.1-mini'    => 'OpenAI — GPT-4.1 Mini (Fast, good quality)',
        'gpt-4.1-nano'    => 'OpenAI — GPT-4.1 Nano (Fastest, cheapest 4.x)',
        'gpt-4o'          => 'OpenAI — GPT-4o (Multimodal)',
        'gpt-4o-mini'     => 'OpenAI — GPT-4o Mini (Fast, cheap)',
        // OpenAI — Reasoning Models
        'o3'              => 'OpenAI — o3 (Reasoning)',
        'o3-mini'         => 'OpenAI — o3 Mini (Reasoning, cheaper)',
        'o4-mini'         => 'OpenAI — o4 Mini (Reasoning)',
        // OpenAI — Legacy
        'gpt-4'           => 'OpenAI — GPT-4 (Legacy)',
        // Anthropic Claude
        'claude-sonnet-4-20250514'  => 'Claude — Sonnet 4 (Best balance)',
        'claude-haiku-4-5-20251001' => 'Claude — Haiku 4.5 (Fast, cheap)',
        'claude-opus-4-20250514'    => 'Claude — Opus 4 (Best quality)',
        // Google Gemini
        'gemini-2.5-pro'   => 'Gemini — 2.5 Pro (Best quality)',
        'gemini-2.5-flash' => 'Gemini — 2.5 Flash (Fast)',
        'gemini-2.0-flash' => 'Gemini — 2.0 Flash (Cheapest)',
    ];
    $custom_models_raw = get_option('itu_ai_custom_models', '');
    $custom_models = [];
    if (!empty($custom_models_raw)) {
        foreach (array_filter(array_map('trim', explode("\n", $custom_models_raw))) as $cm) {
            $custom_models[$cm] = $cm . ' (custom)';
        }
    }
    $models = array_merge($custom_models, $builtin_models);

    $process_groups = [
        'API Keys' => [
            'api_key_openai'      => ['label' => 'OpenAI API Key (Products)', 'type' => 'key'],
            'api_key_blog_writer' => ['label' => 'OpenAI API Key (Blog/SEO)', 'type' => 'key'],
            'api_key_anthropic'   => ['label' => 'Anthropic API Key (Claude)', 'type' => 'key', 'desc' => 'Get from console.anthropic.com'],
            'api_key_gemini'      => ['label' => 'Google Gemini API Key', 'type' => 'key', 'desc' => 'Get from aistudio.google.com'],
            'api_key_perplexity'  => ['label' => 'Perplexity API Key', 'type' => 'key', 'desc' => 'For competitive research. Get from perplexity.ai/account/api/keys'],
            'custom_models'       => ['label' => 'Custom Models', 'type' => 'models_textarea', 'desc' => 'Add custom model IDs, one per line (e.g., gpt-5, claude-5-sonnet). Prefix determines provider: claude- → Anthropic, gemini- → Google, others → OpenAI.'],
        ],
        'Default' => [
            'model_default' => ['label' => 'Default Model', 'desc' => 'Used when no specific model is set for a process'],
        ],
        'Product Content' => [
            'model_product_description' => ['label' => 'Product Description'],
            'model_product_short_desc'  => ['label' => 'Short Description / Meta'],
            'model_product_faq'         => ['label' => 'Product FAQ HTML'],
            'model_product_faq_json'    => ['label' => 'Product FAQ JSON-LD'],
            'model_product_seo'         => ['label' => 'Product Focus Keyword'],
            'model_product_seo_title'   => ['label' => 'Product SEO Title'],
        ],
        'Blog Content' => [
            'model_blog_outline'   => ['label' => 'Blog Outline'],
            'model_blog_content'   => ['label' => 'Blog Full Content'],
            'model_blog_meta'      => ['label' => 'Blog Meta Description'],
            'model_blog_faq'       => ['label' => 'Blog FAQ HTML'],
            'model_blog_seo'       => ['label' => 'Blog Focus Keyword'],
            'model_blog_seo_title' => ['label' => 'Blog SEO Title'],
        ],
        'Automation' => [
            'model_blog_queue'       => ['label' => 'Blog Queue (New Posts)'],
            'model_keyword_research' => ['label' => 'Keyword Research'],
            'model_topic_generation' => ['label' => 'Topic Generation'],
            'model_practice_test'    => ['label' => 'Practice Test Q&A'],
            'model_research'         => ['label' => 'Competitive Research', 'desc' => 'Web search for competitor analysis. Blank = use SEO Monitor setting. For Perplexity, use "sonar" or "sonar-pro".'],
        ],
    ];

    ?>
    <div class="wrap">
        <h1>AI Settings</h1>
        <p style="color:#64748b;">Centralized configuration for all AI models and API keys used across ITU plugins. Changes apply to all plugins immediately.</p>

        <form id="itu-ai-form" style="max-width:800px;">
            <?php foreach ($process_groups as $group_label => $fields) : ?>
                <h2 style="margin-top:24px; padding-bottom:8px; border-bottom:2px solid #e2e8f0;"><?php echo esc_html($group_label); ?></h2>
                <table class="form-table">
                    <?php foreach ($fields as $key => $field) : ?>
                        <tr>
                            <th style="width:220px;"><label for="<?php echo esc_attr($key); ?>"><?php echo esc_html($field['label']); ?></label></th>
                            <td>
                                <?php if (($field['type'] ?? '') === 'key') : ?>
                                    <input type="password" id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>"
                                        value="<?php echo esc_attr($s[$key] ?? ''); ?>"
                                        style="width:400px; padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px;" />
                                    <button type="button" class="button button-small" onclick="var f=document.getElementById('<?php echo esc_attr($key); ?>');f.type=f.type==='password'?'text':'password';">Show/Hide</button>
                                <?php elseif (($field['type'] ?? '') === 'models_textarea') : ?>
                                    <textarea id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" rows="4"
                                        style="width:400px; padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px; font-family:monospace; font-size:13px;"
                                        placeholder="gpt-5&#10;claude-sonnet-4-20250514&#10;claude-haiku-4-5-20251001"><?php echo esc_textarea($custom_models_raw); ?></textarea>
                                <?php else : ?>
                                    <select id="<?php echo esc_attr($key); ?>" name="<?php echo esc_attr($key); ?>" class="itu-model-select"
                                        style="width:300px; padding:6px 10px; border:1px solid #e2e8f0; border-radius:6px;">
                                        <?php foreach ($models as $model_id => $model_label) :
                                            $provider = 'openai';
                                            if (str_starts_with($model_id, 'claude-')) $provider = 'anthropic';
                                            elseif (str_starts_with($model_id, 'gemini-')) $provider = 'gemini';
                                            elseif (str_starts_with($model_id, 'sonar')) $provider = 'perplexity';
                                        ?>
                                            <option value="<?php echo esc_attr($model_id); ?>" data-provider="<?php echo $provider; ?>" <?php selected($s[$key], $model_id); ?>>
                                                <?php echo esc_html($model_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="itu-key-warning" style="display:none; color:#dc2626; font-size:12px; margin-left:8px;">&#9888; No API key for this provider</span>
                                <?php endif; ?>
                                <?php if (!empty($field['desc'])) : ?>
                                    <p class="description"><?php echo esc_html($field['desc']); ?></p>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            <?php endforeach; ?>

            <p style="margin-top:20px;">
                <button type="submit" class="button button-primary">Save All Settings</button>
                <button type="button" class="button" id="itu-ai-reset">Reset to Defaults</button>
                <span id="itu-ai-status" style="margin-left:12px;"></span>
            </p>
        </form>
    </div>

    <script>
    jQuery(document).ready(function($) {
        // Map providers to their API key field IDs
        var providerKeyMap = {
            openai: ['api_key_openai', 'api_key_blog_writer'],
            anthropic: ['api_key_anthropic'],
            gemini: ['api_key_gemini'],
            perplexity: ['api_key_perplexity']
        };

        function hasKeyForProvider(provider) {
            var keys = providerKeyMap[provider] || providerKeyMap['openai'];
            for (var i = 0; i < keys.length; i++) {
                if ($('#' + keys[i]).val().trim()) return true;
            }
            return false;
        }

        function checkModelSelect(select) {
            var $sel = $(select);
            var provider = $sel.find('option:selected').data('provider') || 'openai';
            var $warn = $sel.siblings('.itu-key-warning');
            if (!hasKeyForProvider(provider)) {
                $warn.show();
                $sel.css('border-color', '#dc2626');
            } else {
                $warn.hide();
                $sel.css('border-color', '#e2e8f0');
            }
        }

        // Check all selects on load and on change
        $('.itu-model-select').each(function() { checkModelSelect(this); });
        $(document).on('change', '.itu-model-select', function() { checkModelSelect(this); });
        // Re-check when API key fields change
        $('input[id^="api_key_"]').on('input', function() {
            $('.itu-model-select').each(function() { checkModelSelect(this); });
        });

        $('#itu-ai-form').submit(function(e) {
            e.preventDefault();

            // Block save if any model uses a provider without a key
            var blocked = [];
            $('.itu-model-select').each(function() {
                var provider = $(this).find('option:selected').data('provider') || 'openai';
                if (!hasKeyForProvider(provider)) {
                    var label = $(this).closest('tr').find('th label').text();
                    blocked.push(label + ' → ' + provider);
                }
            });
            if (blocked.length) {
                alert('Cannot save — the following processes use models without an API key:\n\n' + blocked.join('\n') + '\n\nAdd the API key or change the model.');
                return;
            }

            var data = $(this).serializeArray();
            data.push({ name: 'action', value: 'itu_ai_save_settings' });
            data.push({ name: 'nonce', value: '<?php echo $nonce; ?>' });
            $('#itu-ai-status').text('Saving...');
            $.post(ajaxurl, data, function(resp) {
                if (resp.success) {
                    $('#itu-ai-status').html('<span style="color:#059669;">Saved! Reloading...</span>');
                    setTimeout(function() { location.reload(); }, 500);
                } else {
                    $('#itu-ai-status').html('<span style="color:#dc2626;">Error</span>');
                }
            });
        });

        $('#itu-ai-reset').click(function() {
            if (!confirm('Reset all model selections to defaults? API keys will not be changed.')) return;
            var defaults = <?php echo json_encode(itu_ai_defaults()); ?>;
            for (var key in defaults) {
                if (key.indexOf('api_key') === -1) {
                    $('#' + key).val(defaults[key]);
                }
            }
        });
    });
    </script>
    <?php
}
