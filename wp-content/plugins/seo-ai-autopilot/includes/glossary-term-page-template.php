<?php
/**
 * Per-term page template. Loaded via template_include when the URL
 * resolves to a valid glossary slug. Structure mirrors the blog
 * post template (single.html) so the page sits inside the same
 * site-wide chrome readers are used to.
 *
 * The $wp_query global carries `seom_glossary_row` populated by the
 * template_include filter — we don't re-query here.
 */

if (!defined('ABSPATH')) exit;

global $wp_query;
$row = $wp_query->seom_glossary_row ?? null;
if (!$row) {
    // Defensive — template_include shouldn't route here without a row.
    status_header(404);
    return;
}

$slug         = !empty($row->slug) ? $row->slug : sanitize_title($row->term);
$term_url     = home_url('/it-glossary/' . $slug . '/');
$glossary_url = home_url('/it-glossary/');
$related      = seom_glossary_get_related_posts($slug, 5);
$has_related  = count($related) >= 2; // require at least 2 to bother with a "Related" header

// Parsed FAQ list (drives the visible accordion below).
$faqs = [];
if (!empty($row->faq_json)) {
    $decoded = json_decode((string) $row->faq_json, true);
    if (is_array($decoded)) {
        foreach ($decoded as $faq) {
            if (!is_array($faq)) continue;
            $q = trim((string) ($faq['question'] ?? ''));
            $a = trim((string) ($faq['answer']   ?? ''));
            if ($q === '' || $a === '') continue;
            $faqs[] = ['question' => $q, 'answer' => $a];
        }
    }
}

// Body content with auto-linked glossary cross-references. The helper
// from glossary.php finds other glossary terms in the text and replaces
// the first occurrence of each with a link to its deep-anchor. We pass
// a limit of 8 so a long body doesn't end up over-linked.
$expanded_body = (string) $row->definition_expanded;
if (function_exists('seom_find_glossary_terms_in_text') && $expanded_body !== '') {
    $links = seom_find_glossary_terms_in_text($expanded_body, 8);
    foreach ($links as $entry) {
        $term_word = $entry['term'];
        // Don't link the term to itself.
        if (strcasecmp($term_word, $row->term) === 0) continue;
        // First-occurrence replacement only, word-boundary aware. We
        // avoid replacing inside existing <a> tags by checking the
        // surrounding context with a negative lookbehind on `>`.
        $pattern = '/(?<![a-z0-9>])(' . preg_quote($term_word, '/') . ')(?![a-z0-9<])/iu';
        $replacement = '<a href="' . esc_url($entry['url']) . '" class="itu-glossary-inline-link">$1</a>';
        $expanded_body = preg_replace($pattern, $replacement, $expanded_body, 1);
    }
}

?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php wp_head(); ?>
</head>
<body <?php body_class('itu-glossary-term-page'); ?>>
<?php wp_body_open(); ?>

<?php
// Site header — use FSE header template part when available, fall
// back to a minimal wrapper for classic-theme sites. block_header_area()
// is the modern equivalent; block_template_part() works for explicit
// part loading by slug.
if (function_exists('block_template_part')) {
    block_template_part('header');
}
?>

<main class="wp-block-group itu-glossary-term-main">

    <?php
    // We render breadcrumbs inline rather than via [itu_breadcrumbs]:
    // the shortcode branches only on built-in contexts (product / page /
    // singular post / archive) — our virtual route falls through and the
    // shortcode emits an empty crumb. Hand-rolling the markup with the
    // same .itu-breadcrumbs* class names keeps the visual style aligned
    // with the rest of the site.
    $sep = '<span class="itu-breadcrumbs__sep">&raquo;</span>';
    ?>
    <nav class="itu-breadcrumbs" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="itu-breadcrumbs__link">Home</a>
        <?php echo $sep; ?>
        <a href="<?php echo esc_url($glossary_url); ?>" class="itu-breadcrumbs__link">IT Glossary</a>
        <?php echo $sep; ?>
        <span class="itu-breadcrumbs__current"><?php echo esc_html($row->term); ?></span>
    </nav>

    <article class="itu-section__inner itu-single-article itu-glossary-term-article">

        <div class="itu-post-hero itu-glossary-term-hero">
            <h1 class="itu-glossary-term-title"><?php echo esc_html($row->term); ?></h1>
            <?php if (!empty($row->primary_area)) : ?>
                <p class="itu-glossary-term-area">
                    Commonly used in <strong><?php echo esc_html($row->primary_area); ?></strong>
                </p>
            <?php endif; ?>
        </div>

        <?php echo do_shortcode('[itu_plans_bar]'); ?>

        <?php if (!empty($row->tldr)) : ?>
            <aside class="itu-glossary-tldr" data-speakable="true">
                <span class="itu-glossary-tldr-label">Quick Answer</span>
                <p class="itu-glossary-tldr-body"><?php echo esc_html($row->tldr); ?></p>
            </aside>
        <?php endif; ?>

        <?php if (!empty($row->definition)) : ?>
            <aside class="itu-glossary-quick-def">
                <h2 class="itu-glossary-quick-def-label">Short Definition</h2>
                <p class="itu-glossary-quick-def-body"><?php echo wp_kses_post($row->definition); ?></p>
            </aside>
        <?php endif; ?>

        <div class="itu-glossary-term-body">
            <?php
            // $expanded_body holds the wp_kses-sanitized HTML plus the
            // auto-linked glossary cross-references. We re-run wp_kses_post
            // here as defense-in-depth — the allowlist permits <a> tags
            // with href so our inline links survive.
            echo wp_kses_post($expanded_body);
            ?>
        </div>

        <?php if (!empty($faqs)) : ?>
            <div class="itu-faq-layout itu-glossary-faq" style="margin-top:60px;padding-bottom:20px;max-width:1200px;margin-left:auto;margin-right:auto">
                <div class="itu-faq-layout__intro">
                    <span class="itu-eyebrow">[ FAQ ]</span>
                    <h2>Frequently Asked Questions<em>.</em></h2>
                </div>
                <div class="itu-faq-layout__list">
                    <?php foreach ($faqs as $faq) : ?>
                        <details class="itu-faq-item">
                            <summary><?php echo esc_html($faq['question']); ?></summary>
                            <p><?php echo wp_kses_post($faq['answer']); ?></p>
                        </details>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($has_related) : ?>
            <section class="itu-glossary-related-blogs">
                <h2 class="itu-glossary-related-blogs-title">Related articles</h2>
                <p class="itu-glossary-related-blogs-intro">
                    Blogs that go deeper on <strong><?php echo esc_html($row->term); ?></strong> and adjacent topics.
                </p>
                <ul class="itu-glossary-related-blogs-list">
                    <?php foreach ($related as $post) : ?>
                        <li class="itu-glossary-related-blog">
                            <a href="<?php echo esc_url($post->permalink); ?>" class="itu-glossary-related-blog-link">
                                <span class="itu-glossary-related-blog-title"><?php echo esc_html($post->post_title); ?></span>
                                <?php if (!empty($post->post_excerpt)) : ?>
                                    <span class="itu-glossary-related-blog-excerpt"><?php echo esc_html($post->post_excerpt); ?></span>
                                <?php endif; ?>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>
        <?php endif; ?>

        <?php echo do_shortcode('[itu_plans_bar]'); ?>

        <nav class="itu-glossary-term-nav">
            <a href="<?php echo esc_url($glossary_url); ?>" class="itu-glossary-term-back">
                ← Back to the IT Glossary
            </a>
        </nav>

    </article>

</main>

<?php
if (function_exists('block_template_part')) {
    block_template_part('footer');
}
?>

<?php wp_footer(); ?>
</body>
</html>
