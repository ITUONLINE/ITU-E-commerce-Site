/* ─── Inline Image Editor (admin-only front-end UI) ──────────────────────
 *
 * Bound to a logged-in admin viewing a single blog post. Three modes:
 *   - Idle: just the floating toggle button. Nothing else on the page.
 *   - Edit mode (toggle on): + buttons between blocks, hover controls on
 *     existing inline images.
 *   - Picking: WP media frame is open.
 *
 * Data flow:
 *   click + button → wp.media frame → user picks → AJAX insert →
 *   server returns rendered <figure> HTML → JS replaces the + button
 *   with the new <figure>.
 *
 * The block-index sent to the server is the ordinal position among
 * top-level children of the content container. The same indexing scheme
 * is what the PHP marker-insertion uses, so client and server agree.
 * ───────────────────────────────────────────────────────────────────────── */

(function ($) {
    'use strict';
    if (typeof seomInlineImage === 'undefined') return;

    const cfg = seomInlineImage;
    let active = false;
    let $container = null;

    /**
     * Find the first matching content container. Themes vary in their
     * post-body wrapper selector; we try a configured list in order and
     * use the first hit.
     */
    function locateContainer() {
        const selectors = cfg.contentSelectors || ['.entry-content'];
        for (let i = 0; i < selectors.length; i++) {
            const $c = $(selectors[i]).first();
            if ($c.length) return $c;
        }
        return null;
    }

    /**
     * Walk top-level children of the container and yield only blocks
     * that count as a "block boundary" for inserting an image between.
     * Skips text nodes, scripts, the byline div (admin-injected), and
     * existing insert buttons.
     */
    function blockSelector() {
        return 'p, h1, h2, h3, h4, h5, h6, ul, ol, table, figure, blockquote, pre, hr, aside, div:not(.itu-byline):not(.itu-image-controls):not(.itu-image-alt-editor)';
    }

    function getBlocks() {
        if (!$container) return $();
        return $container.children(blockSelector());
    }

    function showStatus(msg, kind) {
        let $s = $('.itu-editor-status');
        if (!$s.length) {
            $s = $('<div class="itu-editor-status"></div>').appendTo('body');
        }
        $s.removeClass('is-error is-success').text(msg);
        if (kind === 'error') $s.addClass('is-error');
        if (kind === 'success') $s.addClass('is-success');
        $s.addClass('is-visible');
        clearTimeout($s.data('hide-timer'));
        $s.data('hide-timer', setTimeout(function () { $s.removeClass('is-visible'); }, 2400));
    }

    /**
     * Insert + Add image buttons between every top-level block. Each
     * button's data-index = the ordinal position the new marker should
     * be inserted at server-side. Index 0 = before the first block;
     * index N = after the last block.
     */
    function renderInsertButtons() {
        // Clean any previous render
        $container.find('.itu-image-insert-btn').remove();

        const $blocks = getBlocks();
        $blocks.each(function (i) {
            $('<button type="button" class="itu-image-insert-btn">+ Add image here</button>')
                .attr('data-index', i)
                .insertBefore(this);
        });
        // Append a final + button at the end
        $('<button type="button" class="itu-image-insert-btn">+ Add image at end</button>')
            .attr('data-index', $blocks.length)
            .appendTo($container);
    }

    /**
     * Decorate every existing inline image with a hover-controls overlay.
     * Re-runs whenever edit mode is entered so new images added during
     * the session get controls too.
     */
    function renderImageControls() {
        $container.find('.itu-inline-image').each(function () {
            const $fig = $(this);
            if ($fig.find('.itu-image-controls').length) return;
            const uuid = $fig.attr('data-itu-image-uuid');
            if (!uuid) return;
            const $ctrls = $('<div class="itu-image-controls"></div>').appendTo($fig);
            $('<button type="button" class="itu-image-control-btn" data-action="replace">Replace</button>').appendTo($ctrls);
            $('<button type="button" class="itu-image-control-btn" data-action="alt">Alt + caption</button>').appendTo($ctrls);
            $('<button type="button" class="itu-image-control-btn is-danger" data-action="remove">Remove</button>').appendTo($ctrls);
        });
    }

    function enterEditMode() {
        if (active) return;
        $container = locateContainer();
        if (!$container) {
            showStatus('Could not find post content container', 'error');
            return;
        }
        active = true;
        $('body').addClass('itu-image-editor-active');
        renderInsertButtons();
        renderImageControls();
        $('.itu-editor-toggle').addClass('is-active').text('Done editing');
    }

    function exitEditMode() {
        if (!active) return;
        active = false;
        $('body').removeClass('itu-image-editor-active');
        if ($container) {
            $container.find('.itu-image-insert-btn').remove();
            $container.find('.itu-image-controls').remove();
            $container.find('.itu-image-alt-editor').remove();
        }
        $('.itu-editor-toggle').removeClass('is-active').text('Edit images');
    }

    /**
     * Open the WP media frame for either an insert or a replace.
     * Resolves with { id, url, alt }, or null if the user cancels.
     */
    function openMediaFrame(title) {
        return new Promise(function (resolve) {
            const frame = wp.media({
                title: title || 'Choose image',
                button: { text: 'Use this image' },
                library: { type: 'image' },
                multiple: false
            });
            frame.on('select', function () {
                const att = frame.state().get('selection').first().toJSON();
                resolve({
                    id: att.id,
                    url: att.url,
                    alt: att.alt || ''
                });
            });
            frame.on('close', function () {
                // If 'select' didn't fire, treat as cancel.
                setTimeout(function () { resolve(null); }, 50);
            });
            frame.open();
        });
    }

    /**
     * AJAX insert. Returns rendered HTML on success.
     */
    function ajaxInsert(position, mediaId, alt) {
        return $.post(cfg.ajaxUrl, {
            action:   'seom_inline_image_insert',
            nonce:    cfg.nonce,
            post_id:  cfg.postId,
            position: position,
            media_id: mediaId,
            alt:      alt || ''
        });
    }

    function ajaxRemove(uuid) {
        return $.post(cfg.ajaxUrl, {
            action:   'seom_inline_image_remove',
            nonce:    cfg.nonce,
            post_id:  cfg.postId,
            uuid:     uuid
        });
    }

    function ajaxUpdateAlt(uuid, alt, caption) {
        return $.post(cfg.ajaxUrl, {
            action:   'seom_inline_image_update_alt',
            nonce:    cfg.nonce,
            post_id:  cfg.postId,
            uuid:     uuid,
            alt:      alt || '',
            caption:  caption || ''
        });
    }

    // ─── Events ──

    // Floating toggle
    $(document).on('click', '.itu-editor-toggle', function () {
        if (active) exitEditMode();
        else enterEditMode();
    });

    // + Add image button — insert flow
    $(document).on('click', '.itu-image-insert-btn', function () {
        const $btn = $(this);
        const position = parseInt($btn.attr('data-index'), 10) || 0;
        openMediaFrame('Insert image here').then(function (chosen) {
            if (!chosen) return;
            showStatus('Inserting…');
            ajaxInsert(position, chosen.id, chosen.alt).done(function (resp) {
                if (!resp.success) {
                    showStatus(resp.data || 'Insert failed', 'error');
                    return;
                }
                // Replace the clicked button with the new figure.
                $btn.replaceWith(resp.data.html);
                showStatus('Image inserted', 'success');
                // Re-render insert buttons + controls so the new figure
                // gets hover controls and adjacent + buttons.
                renderInsertButtons();
                renderImageControls();
            }).fail(function () {
                showStatus('Network error', 'error');
            });
        });
    });

    // Replace existing image
    $(document).on('click', '.itu-image-control-btn[data-action="replace"]', function () {
        const $fig = $(this).closest('.itu-inline-image');
        const uuid = $fig.attr('data-itu-image-uuid');
        if (!uuid) return;
        openMediaFrame('Choose replacement image').then(function (chosen) {
            if (!chosen) return;
            // Replace = remove old + insert new at the same approximate
            // position. We compute position by counting the figure's
            // index among $container's children at the moment of click.
            const blocks = getBlocks().toArray();
            const figIdx = blocks.indexOf($fig[0]);
            showStatus('Replacing…');
            ajaxRemove(uuid).done(function () {
                ajaxInsert(figIdx, chosen.id, chosen.alt).done(function (resp) {
                    if (!resp.success) {
                        showStatus(resp.data || 'Replace failed', 'error');
                        return;
                    }
                    $fig.replaceWith(resp.data.html);
                    showStatus('Image replaced', 'success');
                    renderInsertButtons();
                    renderImageControls();
                });
            });
        });
    });

    // Alt + caption editor
    $(document).on('click', '.itu-image-control-btn[data-action="alt"]', function () {
        const $fig = $(this).closest('.itu-inline-image');
        const uuid = $fig.attr('data-itu-image-uuid');
        if (!uuid) return;
        // Toggle existing editor if open
        const $existing = $fig.find('.itu-image-alt-editor');
        if ($existing.length) {
            $existing.toggleClass('is-open');
            return;
        }
        const currentAlt = $fig.find('img').attr('alt') || '';
        const currentCap = $fig.find('figcaption').text() || '';
        const $editor = $(
            '<div class="itu-image-alt-editor is-open">' +
              '<label>Alt text (describe the image)</label>' +
              '<input type="text" class="itu-image-alt-input" />' +
              '<label>Caption (shown below the image, optional)</label>' +
              '<input type="text" class="itu-image-caption-input" />' +
              '<button type="button" class="itu-image-alt-save">Save</button>' +
              '<button type="button" class="itu-image-alt-cancel">Cancel</button>' +
            '</div>'
        );
        $editor.find('.itu-image-alt-input').val(currentAlt);
        $editor.find('.itu-image-caption-input').val(currentCap);
        $fig.append($editor);
    });

    $(document).on('click', '.itu-image-alt-save', function () {
        const $editor = $(this).closest('.itu-image-alt-editor');
        const $fig = $editor.closest('.itu-inline-image');
        const uuid = $fig.attr('data-itu-image-uuid');
        const alt = $editor.find('.itu-image-alt-input').val();
        const cap = $editor.find('.itu-image-caption-input').val();
        showStatus('Saving…');
        ajaxUpdateAlt(uuid, alt, cap).done(function (resp) {
            if (!resp.success) { showStatus(resp.data || 'Save failed', 'error'); return; }
            $fig.replaceWith(resp.data.html);
            showStatus('Saved', 'success');
            renderImageControls();
        });
    });

    $(document).on('click', '.itu-image-alt-cancel', function () {
        $(this).closest('.itu-image-alt-editor').removeClass('is-open').remove();
    });

    // Remove existing image
    $(document).on('click', '.itu-image-control-btn[data-action="remove"]', function () {
        const $fig = $(this).closest('.itu-inline-image');
        const uuid = $fig.attr('data-itu-image-uuid');
        if (!uuid) return;
        if (!confirm('Remove this image from the post?')) return;
        showStatus('Removing…');
        ajaxRemove(uuid).done(function (resp) {
            if (!resp.success) { showStatus(resp.data || 'Remove failed', 'error'); return; }
            $fig.remove();
            showStatus('Image removed', 'success');
            renderInsertButtons();
        });
    });

    // ─── Init ──
    $(function () {
        // Always inject the floating toggle for admins on single posts.
        if (!$('.itu-editor-toggle').length) {
            $('<button type="button" class="itu-editor-toggle">Edit images</button>')
                .appendTo('body');
        }
    });
})(jQuery);
