<?php
/**
 * Inline Image Editor — admin-only front-end image insertion.
 *
 * Lets a logged-in admin enter "edit mode" on any single blog post and
 * insert / remove / re-pick images between any two top-level content
 * blocks. Three moving parts:
 *
 *   1. STORAGE
 *      - wp_posts.post_content gets HTML comment markers
 *          <!-- itu-img:UUID -->
 *        at the chosen positions. Comments are inert in browsers and
 *        invisible in the WP editor, so adding them doesn't disturb
 *        anything else.
 *      - wp_postmeta key `_itu_inline_images` holds a JSON map
 *          { uuid: { media_id, url, alt, caption, width, height, size } }
 *        Image metadata lives here so we don't have to query the media
 *        library on every render.
 *
 *   2. RENDER
 *      A the_content filter (priority 7 — runs BEFORE the byline filter
 *      at 8) scans for markers and replaces each with a <figure>+<img>
 *      block. Orphan markers (image deleted, attachment trashed, etc.)
 *      are silently stripped so the post never renders broken.
 *
 *   3. EDIT UI
 *      Admin scripts + styles are enqueued only when:
 *         - the visitor is logged in with edit_posts capability, AND
 *         - the request is a single 'post' front-end view
 *      The JS adds a floating "Edit images" toggle. Toggling on injects
 *      "+ Add image" buttons between top-level blocks, plus hover
 *      controls on existing inline images (replace, alt-text, remove).
 *
 * Four AJAX endpoints handle the CRUD: seom_inline_image_insert,
 * seom_inline_image_remove, seom_inline_image_update_alt, and
 * seom_inline_image_list (debug / diagnostics).
 */

if (!defined('ABSPATH')) exit;

const SEOM_INLINE_IMG_META = '_itu_inline_images';

// ─── Storage helpers ────────────────────────────────────────────────────────

/**
 * Return the inline-image map for a post: { uuid => { media_id, … } }.
 * Always returns an array, never null.
 */
function seom_inline_get_images($post_id) {
    $raw = get_post_meta((int) $post_id, SEOM_INLINE_IMG_META, true);
    if (is_array($raw)) return $raw;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return [];
}

/**
 * Persist the inline-image map. Stored as JSON string because some hosts
 * disable serialize() for postmeta and we'd rather have the JSON than
 * fight the host.
 */
function seom_inline_save_images($post_id, $images) {
    if (!is_array($images)) $images = [];
    update_post_meta((int) $post_id, SEOM_INLINE_IMG_META, wp_json_encode($images, JSON_UNESCAPED_SLASHES));
}

/**
 * Generate a short opaque ID for a new image slot. We don't need true
 * UUIDs — we just need something unlikely to collide within a post.
 */
function seom_inline_new_uuid() {
    return strtolower(wp_generate_password(10, false, false));
}

// ─── Render filter ──────────────────────────────────────────────────────────

/**
 * Replace inline-image markers in post_content with rendered <figure> tags.
 *
 * Priority 7 means this runs BEFORE the byline filter (priority 8) and
 * before most theme filters. Markers without matching metadata are
 * silently removed so an orphaned image never leaves a stray HTML
 * comment in the rendered output.
 */
add_filter('the_content', function ($content) {
    if (!is_singular('post') || is_admin() || !in_the_loop() || !is_main_query()) {
        return $content;
    }
    $post_id = get_the_ID();
    if (!$post_id) return $content;
    if (strpos($content, 'itu-img:') === false) return $content;

    $images = seom_inline_get_images($post_id);
    return preg_replace_callback(
        '/<!--\s*itu-img:([a-z0-9]+)\s*-->/i',
        function ($m) use ($images) {
            $uuid = strtolower($m[1]);
            if (!isset($images[$uuid])) return ''; // orphan — strip
            return seom_inline_render_figure($images[$uuid], $uuid);
        },
        $content
    );
}, 7);

/**
 * Render one image's <figure> wrapper. data-itu-image-uuid is on the
 * outer figure so the JS can target it for inline edit/remove without
 * relying on src matching.
 */
function seom_inline_render_figure($img, $uuid) {
    $url     = esc_url($img['url'] ?? '');
    $alt     = esc_attr($img['alt'] ?? '');
    $caption = isset($img['caption']) ? trim((string) $img['caption']) : '';
    $w       = isset($img['width'])  ? (int) $img['width']  : 0;
    $h       = isset($img['height']) ? (int) $img['height'] : 0;
    if ($url === '') return '';

    $dims = ($w > 0 && $h > 0) ? ' width="' . $w . '" height="' . $h . '"' : '';
    $caption_html = $caption !== ''
        ? '<figcaption>' . esc_html($caption) . '</figcaption>'
        : '';
    return '<figure class="itu-inline-image" data-itu-image-uuid="' . esc_attr($uuid) . '">'
        . '<img src="' . $url . '" alt="' . $alt . '"' . $dims . ' loading="lazy" />'
        . $caption_html
        . '</figure>';
}

// ─── Position resolution ────────────────────────────────────────────────────

/**
 * Insert a marker into post_content at a given block index.
 *
 * "Block index" = ordinal position among top-level elements of post_content
 * (paragraphs, headings, lists, tables, figures, divs, blockquotes, etc.).
 *   index = 0       → before the first block
 *   index = N       → before the Nth block (1-based across the API but we
 *                     pass 0-based internally; the JS sends 0-based too)
 *   index = count   → after the last block
 *
 * We deliberately don't try to insert inside a block (mid-paragraph).
 * Mid-paragraph insertion would split text awkwardly and is also much
 * harder to persist robustly across post edits.
 */
function seom_inline_insert_marker_at($content, $marker, $index) {
    $index = max(0, (int) $index);

    // Match top-level open tags. We keep this simple: split on the
    // boundary between a closing tag and the next opening tag.
    // For the rendered post body this is reliable since the writer prompt
    // emits well-structured top-level blocks.
    $blocks = preg_split('/(?=<(?:p|h[1-6]|ul|ol|table|figure|div|blockquote|pre|hr|aside)\b)/i', $content);
    // preg_split with PREG_SPLIT_DELIM_CAPTURE-like option isn't used here;
    // instead we accept that the first element may be a leading prelude
    // (theme insertions, etc.) and we treat it as "block 0 = anything
    // before the first real block."
    if (!is_array($blocks) || empty($blocks)) {
        // Fall back: prepend if index is 0, append otherwise.
        return $index === 0 ? $marker . "\n\n" . $content : $content . "\n\n" . $marker;
    }

    // The leading prelude (if any) is $blocks[0]; real block N corresponds
    // to $blocks[N]. Index 0 means "insert at the start" — before $blocks[0]'s
    // real content but after the prelude is fine; we put it as a new entry
    // at the front.
    if ($index >= count($blocks)) {
        return implode('', $blocks) . "\n\n" . $marker . "\n\n";
    }
    array_splice($blocks, $index, 0, [$marker . "\n\n"]);
    return implode('', $blocks);
}

/**
 * Remove a marker for the given uuid from post_content. No-op if not found.
 */
function seom_inline_remove_marker($content, $uuid) {
    return preg_replace(
        '/<!--\s*itu-img:' . preg_quote($uuid, '/') . '\s*-->\s*/i',
        '',
        $content
    );
}

// ─── AJAX endpoints ─────────────────────────────────────────────────────────

/**
 * Insert a new image at a block index. Body params:
 *   post_id, position (int), media_id (int), alt (string, optional),
 *   caption (string, optional)
 */
add_action('wp_ajax_seom_inline_image_insert', function () {
    check_ajax_referer('seom_inline_image', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

    $post_id  = intval($_POST['post_id'] ?? 0);
    $position = intval($_POST['position'] ?? 0);
    $media_id = intval($_POST['media_id'] ?? 0);
    $alt      = sanitize_text_field(wp_unslash($_POST['alt'] ?? ''));
    $caption  = sanitize_text_field(wp_unslash($_POST['caption'] ?? ''));

    $post = get_post($post_id);
    if (!$post || $post->post_type !== 'post') wp_send_json_error('Post not found.');
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error('Cannot edit this post.');

    $att = get_post($media_id);
    if (!$att || $att->post_type !== 'attachment') wp_send_json_error('Media not found.');

    $url = wp_get_attachment_url($media_id);
    if (!$url) wp_send_json_error('Could not resolve image URL.');

    // Pull dimensions from the largest available size so the figure can
    // emit width/height hints (helps CLS, helps lazy-loading).
    $meta = wp_get_attachment_metadata($media_id);
    $w = isset($meta['width'])  ? (int) $meta['width']  : 0;
    $h = isset($meta['height']) ? (int) $meta['height'] : 0;

    // If alt wasn't passed, fall back to the attachment's stored alt text.
    if ($alt === '') {
        $stored_alt = get_post_meta($media_id, '_wp_attachment_image_alt', true);
        if (is_string($stored_alt)) $alt = sanitize_text_field($stored_alt);
    }

    $images = seom_inline_get_images($post_id);
    $uuid   = seom_inline_new_uuid();
    while (isset($images[$uuid])) $uuid = seom_inline_new_uuid();
    $images[$uuid] = [
        'media_id' => $media_id,
        'url'      => $url,
        'alt'      => $alt,
        'caption'  => $caption,
        'width'    => $w,
        'height'   => $h,
        'added_at' => current_time('mysql'),
    ];

    $marker  = '<!-- itu-img:' . $uuid . ' -->';
    $new_content = seom_inline_insert_marker_at((string) $post->post_content, $marker, $position);

    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_content' => $new_content], ['ID' => $post_id]);
    seom_inline_save_images($post_id, $images);
    clean_post_cache($post_id);

    wp_send_json_success([
        'uuid'     => $uuid,
        'position' => $position,
        'html'     => seom_inline_render_figure($images[$uuid], $uuid),
    ]);
});

/**
 * Remove an existing image. Body: post_id, uuid.
 */
add_action('wp_ajax_seom_inline_image_remove', function () {
    check_ajax_referer('seom_inline_image', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

    $post_id = intval($_POST['post_id'] ?? 0);
    $uuid    = strtolower(sanitize_text_field(wp_unslash($_POST['uuid'] ?? '')));
    if ($post_id <= 0 || $uuid === '') wp_send_json_error('Missing post_id or uuid.');
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error('Cannot edit this post.');

    $post = get_post($post_id);
    if (!$post) wp_send_json_error('Post not found.');

    $images = seom_inline_get_images($post_id);
    unset($images[$uuid]);

    $new_content = seom_inline_remove_marker((string) $post->post_content, $uuid);

    global $wpdb;
    $wpdb->update($wpdb->posts, ['post_content' => $new_content], ['ID' => $post_id]);
    seom_inline_save_images($post_id, $images);
    clean_post_cache($post_id);

    wp_send_json_success(['removed' => $uuid]);
});

/**
 * Update alt and/or caption on an existing image. Body: post_id, uuid,
 * alt, caption.
 */
add_action('wp_ajax_seom_inline_image_update_alt', function () {
    check_ajax_referer('seom_inline_image', 'nonce');
    if (!current_user_can('edit_posts')) wp_send_json_error('Permission denied.');

    $post_id = intval($_POST['post_id'] ?? 0);
    $uuid    = strtolower(sanitize_text_field(wp_unslash($_POST['uuid'] ?? '')));
    $alt     = sanitize_text_field(wp_unslash($_POST['alt'] ?? ''));
    $caption = sanitize_text_field(wp_unslash($_POST['caption'] ?? ''));
    if ($post_id <= 0 || $uuid === '') wp_send_json_error('Missing post_id or uuid.');
    if (!current_user_can('edit_post', $post_id)) wp_send_json_error('Cannot edit this post.');

    $images = seom_inline_get_images($post_id);
    if (!isset($images[$uuid])) wp_send_json_error('Image not found.');

    $images[$uuid]['alt']     = $alt;
    $images[$uuid]['caption'] = $caption;
    seom_inline_save_images($post_id, $images);
    clean_post_cache($post_id);

    wp_send_json_success([
        'uuid' => $uuid,
        'html' => seom_inline_render_figure($images[$uuid], $uuid),
    ]);
});

// ─── Script + style enqueue (admins on single-post views only) ─────────────

add_action('wp_enqueue_scripts', function () {
    if (is_admin() || !is_singular('post') || !current_user_can('edit_posts')) return;

    // wp.media is the WP-bundled media library frame the post editor uses.
    wp_enqueue_media();

    $base = plugins_url('', dirname(__DIR__) . '/seo-ai-autopilot.php');
    // The file path resolution can be brittle on some installs — fall
    // back to plugin_dir_url() which is the canonical helper.
    if (defined('SEOM_PATH')) {
        $base = plugin_dir_url(SEOM_PATH . 'seo-ai-autopilot.php');
    }

    wp_enqueue_style(
        'seom-inline-images',
        $base . 'assets/css/inline-images.css',
        [],
        '1.0.0'
    );
    wp_enqueue_script(
        'seom-inline-images',
        $base . 'assets/js/inline-images.js',
        ['jquery'],
        '1.0.0',
        true
    );
    wp_localize_script('seom-inline-images', 'seomInlineImage', [
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'nonce'   => wp_create_nonce('seom_inline_image'),
        'postId'  => get_the_ID(),
        // Default content-container selectors the JS will try in order.
        // Themes can filter this via wp_localize_script + a window var
        // override if their main content wrapper differs.
        'contentSelectors' => [
            '.itu-blog-content',
            '.entry-content',
            'article .post-content',
            'main article',
        ],
    ]);
});
