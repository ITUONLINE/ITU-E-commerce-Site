<?php
/**
 * Practice Test Study Guide — per-test permalink page that renders the
 * complete question bank with answers visibly + Education Q&A
 * structured data per Google's spec:
 *   https://developers.google.com/search/docs/appearance/structured-data/education-qa
 *
 * Why this exists: the interactive practice-test page (driven by the
 * practice-test-manager plugin's [practice_test test_id=N] shortcode)
 * displays a RANDOM subset of the question bank per session. Google
 * requires structured data to match what's visible on the page, so we
 * can't emit Quiz schema for the full bank from the interactive URL.
 * The study guide is a parallel, study-mode URL that renders every
 * question + correct answer on a single page so the schema 1:1
 * matches the DOM.
 *
 * URL structure:
 *   /study-guide/{post-slug}/   (post-slug = the practice-test post)
 *
 * Data source — the practice-test-manager plugin owns these tables
 * (all in the local WP DB, not the LMS DB):
 *
 *   wp_ptm_practice_tests:  id, title, test_post_id, exam_code,
 *                           governing_body, test_type, is_active
 *   wp_ptm_questions:       id, test_id, question_text
 *   wp_ptm_answers:         id, question_id, answer_text, is_correct,
 *                           explanation_correct, explanation_incorrect
 *
 * Lookup chain:
 *   1. URL slug → WP post (must be in `practice-tests` category)
 *   2. wp_ptm_practice_tests WHERE test_post_id = post.ID → test row
 *   3. Single JOIN query pulls every question + answer for the test
 *   4. Group rows by question_id, partition answers by is_correct
 *   5. Render the page + emit Quiz schema
 *
 * Each Question carries:
 *   - eduQuestionType: "Multiple choice"
 *   - learningResourceType: "Practice problem"
 *   - acceptedAnswer: the correct option text + its explanation
 *   - suggestedAnswer[]: each incorrect option text + its explanation
 *
 * Cache: rendered HTML + schema cached per slug (12h TTL); auto-busts
 * when the source post is saved.
 */

if (!defined('ABSPATH')) exit;

const SEOM_PT_QUERY_VAR        = 'seom_pt_study_guide_slug';
const SEOM_PT_STUDY_GUIDE_BASE = 'study-guide';
const SEOM_PT_CATEGORY_SLUG    = 'practice-tests';

// ── Rewrite rule + query var ────────────────────────────────────────────────

add_action('init', function () {
    add_rewrite_rule(
        '^' . SEOM_PT_STUDY_GUIDE_BASE . '/([a-z0-9\-]+)/?$',
        'index.php?' . SEOM_PT_QUERY_VAR . '=$matches[1]',
        'top'
    );
});

add_filter('query_vars', function ($vars) {
    $vars[] = SEOM_PT_QUERY_VAR;
    return $vars;
});

// Auto-flush rewrites once if our rule isn't installed yet.
add_action('admin_init', function () {
    $rules = get_option('rewrite_rules');
    if (!is_array($rules)) return;
    foreach (array_keys($rules) as $pattern) {
        if (strpos($pattern, '^' . SEOM_PT_STUDY_GUIDE_BASE . '/(') === 0) return;
    }
    flush_rewrite_rules(false);
});

// ── Data layer ──────────────────────────────────────────────────────────────

/**
 * Pull the test record + full question bank for a given WP post ID.
 * Returns:
 *   [
 *     'test'      => stdClass {id, title, exam_code, governing_body, test_type, ...},
 *     'questions' => [
 *        question_id => [
 *          'text'    => 'Question?',
 *          'correct' => [ ['text' => 'Correct', 'explanation' => '...'], ... ],
 *          'wrong'   => [ ['text' => 'Wrong', 'explanation' => '...'], ... ],
 *        ],
 *        ...
 *     ],
 *   ]
 *
 * Returns null when no matching test or no questions.
 */
function seom_pt_get_question_bank($post_id) {
    global $wpdb;
    $post_id = (int) $post_id;
    if ($post_id <= 0) return null;

    $test = $wpdb->get_row($wpdb->prepare(
        "SELECT id, title, exam_code, governing_body, test_type, is_active
         FROM {$wpdb->prefix}ptm_practice_tests
         WHERE test_post_id = %d AND is_active = 1
         LIMIT 1",
        $post_id
    ));
    if (!$test) return null;

    // Single JOIN — questions + answers in one round trip. Ordered so
    // we render questions in a stable order (id ASC) and answers
    // within each question in their natural creation order.
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT
            q.id   AS question_id,
            q.question_text,
            a.id   AS answer_id,
            a.answer_text,
            a.is_correct,
            a.explanation_correct,
            a.explanation_incorrect
         FROM {$wpdb->prefix}ptm_questions q
         INNER JOIN {$wpdb->prefix}ptm_answers a ON a.question_id = q.id
         WHERE q.test_id = %d
         ORDER BY q.id ASC, a.id ASC",
        (int) $test->id
    ));
    if (empty($rows)) return null;

    $questions = [];
    foreach ($rows as $r) {
        $qid = (int) $r->question_id;
        if ($qid <= 0) continue;
        if (!isset($questions[$qid])) {
            $questions[$qid] = [
                'text'    => (string) $r->question_text,
                'correct' => [],
                'wrong'   => [],
            ];
        }
        $answer = [
            'text'        => (string) $r->answer_text,
            'explanation' => (int) $r->is_correct === 1
                ? (string) $r->explanation_correct
                : (string) $r->explanation_incorrect,
        ];
        if ((int) $r->is_correct === 1) {
            $questions[$qid]['correct'][] = $answer;
        } else {
            $questions[$qid]['wrong'][] = $answer;
        }
    }

    // Drop questions with no correct answer (data integrity guard —
    // schema requires at least one).
    foreach ($questions as $qid => $q) {
        if (empty($q['correct'])) unset($questions[$qid]);
    }
    if (empty($questions)) return null;

    return [
        'test'      => $test,
        'questions' => $questions,
    ];
}

/**
 * Resolve the URL slug to a WP post in the practice-tests category.
 * Returns the post or null.
 */
function seom_pt_resolve_post($slug) {
    $slug = strtolower(trim((string) $slug));
    if ($slug === '' || !preg_match('/^[a-z0-9\-]+$/', $slug)) return null;
    $post = get_page_by_path($slug, OBJECT, 'post');
    if (!$post) return null;
    if (!has_category(SEOM_PT_CATEGORY_SLUG, $post->ID)) return null;
    return $post;
}

// ── Schema builder ──────────────────────────────────────────────────────────

/**
 * Build the Quiz JSON-LD body. Each Question carries the correct
 * answer in acceptedAnswer and the incorrect options in
 * suggestedAnswer[]. Explanations are appended to answer text since
 * Schema.org's Answer type has no dedicated explanation field.
 */
function seom_pt_build_quiz_schema($bank, $page_url) {
    $test_title = $bank['test']->title;
    $exam_code  = (string) ($bank['test']->exam_code ?? '');
    $body       = (string) ($bank['test']->governing_body ?? '');
    $has_part   = [];

    foreach ($bank['questions'] as $qid => $q) {
        // For the rare multi-correct case, join into one Answer block.
        if (count($q['correct']) === 1) {
            $accepted_text = $q['correct'][0]['text'];
            if (!empty($q['correct'][0]['explanation'])) {
                $accepted_text .= "\n\n" . $q['correct'][0]['explanation'];
            }
        } else {
            $parts = [];
            foreach ($q['correct'] as $c) {
                $parts[] = $c['text'] . (!empty($c['explanation']) ? ' — ' . $c['explanation'] : '');
            }
            $accepted_text = 'All of the following are correct: ' . implode('; ', $parts);
        }

        $suggested = [];
        foreach ($q['wrong'] as $w) {
            $text = $w['text'];
            if (!empty($w['explanation'])) {
                $text .= "\n\n" . $w['explanation'];
            }
            $suggested[] = ['@type' => 'Answer', 'text' => $text];
        }

        $has_part[] = [
            '@type'                => 'Question',
            'eduQuestionType'      => 'Multiple choice',
            'learningResourceType' => 'Practice problem',
            'name'                 => $q['text'],
            'text'                 => $q['text'],
            'acceptedAnswer'       => [
                '@type' => 'Answer',
                'text'  => $accepted_text,
            ],
            'suggestedAnswer'      => $suggested,
        ];
    }

    $schema = [
        '@context'             => 'https://schema.org',
        '@type'                => 'Quiz',
        '@id'                  => $page_url . '#quiz',
        'name'                 => $test_title . ' — Practice Questions',
        'url'                  => $page_url,
        'learningResourceType' => 'Practice problem',
        'numberOfQuestions'    => count($has_part),
        'about'                => [
            '@type' => 'Thing',
            'name'  => $test_title,
        ],
        'educationalAlignment' => [[
            '@type'         => 'AlignmentObject',
            'alignmentType' => 'educationalSubject',
            'targetName'    => $test_title,
        ]],
        'hasPart'              => $has_part,
    ];
    if ($exam_code !== '') $schema['about']['identifier'] = $exam_code;
    if ($body !== '')      $schema['about']['provider']   = ['@type' => 'Organization', 'name' => $body];

    return $schema;
}

// ── Rendering ───────────────────────────────────────────────────────────────

/**
 * Render the full study guide HTML page (including <html>, <head>,
 * <body>, schema, accordion, footer). Cached per slug.
 */
function seom_pt_render_study_guide($post, $bank) {
    $slug      = $post->post_name;
    $test_title = $bank['test']->title;
    $exam_code  = (string) ($bank['test']->exam_code ?? '');
    $page_url   = home_url('/' . SEOM_PT_STUDY_GUIDE_BASE . '/' . $slug . '/');
    $back_url   = get_permalink($post->ID);
    $schema     = seom_pt_build_quiz_schema($bank, $page_url);
    $count      = count($bank['questions']);

    $page_heading = $test_title . ' Practice Questions';

    ob_start();
    ?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
<meta charset="<?php bloginfo('charset'); ?>">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo esc_html($page_heading); ?> | <?php bloginfo('name'); ?></title>
<meta name="description" content="<?php echo esc_attr($page_heading . ': ' . $count . ' multiple choice questions with detailed answer explanations.'); ?>">
<link rel="canonical" href="<?php echo esc_url($page_url); ?>">
<script type="application/ld+json" data-source="seom-pt-study-guide">
<?php echo wp_json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE); ?>
</script>
<?php
$crumbs = [
    '@context'        => 'https://schema.org',
    '@type'           => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Home', 'item' => home_url('/')],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Practice Tests', 'item' => home_url('/category/' . SEOM_PT_CATEGORY_SLUG . '/')],
        ['@type' => 'ListItem', 'position' => 3, 'name' => $post->post_title, 'item' => $back_url],
        ['@type' => 'ListItem', 'position' => 4, 'name' => 'Study Guide', 'item' => $page_url],
    ],
];
?>
<script type="application/ld+json">
<?php echo wp_json_encode($crumbs, JSON_UNESCAPED_SLASHES); ?>
</script>
<?php wp_head(); ?>
</head>
<body <?php body_class('itu-pt-study-guide-page'); ?>>
<?php wp_body_open(); ?>

<?php if (function_exists('block_template_part')) block_template_part('header'); ?>

<main class="wp-block-group itu-pt-study-guide-main">
    <nav class="itu-breadcrumbs" aria-label="Breadcrumb">
        <a href="<?php echo esc_url(home_url('/')); ?>" class="itu-breadcrumbs__link">Home</a>
        <span class="itu-breadcrumbs__sep">&raquo;</span>
        <a href="<?php echo esc_url(home_url('/category/' . SEOM_PT_CATEGORY_SLUG . '/')); ?>" class="itu-breadcrumbs__link">Practice Tests</a>
        <span class="itu-breadcrumbs__sep">&raquo;</span>
        <a href="<?php echo esc_url($back_url); ?>" class="itu-breadcrumbs__link"><?php echo esc_html($post->post_title); ?></a>
        <span class="itu-breadcrumbs__sep">&raquo;</span>
        <span class="itu-breadcrumbs__current">Study Guide</span>
    </nav>

    <article class="itu-section__inner itu-single-article itu-pt-study-guide-article">
        <div class="itu-pt-study-guide-hero">
            <div class="itu-pt-study-guide-actions">
                <a href="<?php echo esc_url($back_url); ?>" class="itu-pt-study-guide-back-btn">
                    <span aria-hidden="true">&larr;</span>&nbsp;Back to interactive practice test
                </a>
            </div>
            <h1 class="itu-pt-study-guide-title"><?php echo esc_html($page_heading); ?></h1>
            <p class="itu-pt-study-guide-sub">
                <?php echo number_format($count); ?> multiple choice questions with detailed answer explanations.
            </p>
        </div>

        <?php echo do_shortcode('[itu_plans_bar]'); ?>

        <section class="itu-pt-study-guide-questions">
            <?php $position = 1; foreach ($bank['questions'] as $qid => $q) : ?>
                <details class="itu-pt-question" id="q-<?php echo (int) $qid; ?>">
                    <summary class="itu-pt-question-summary">
                        <span class="itu-pt-question-num">Q<?php echo $position++; ?>.</span>
                        <span class="itu-pt-question-text"><?php echo esc_html($q['text']); ?></span>
                    </summary>
                    <div class="itu-pt-question-body">
                        <h3 class="itu-pt-answer-heading">Correct answer<?php echo count($q['correct']) > 1 ? 's' : ''; ?>:</h3>
                        <ul class="itu-pt-answer-list itu-pt-answer-list--correct">
                            <?php foreach ($q['correct'] as $a) : ?>
                                <li>
                                    <span class="itu-pt-answer-text"><?php echo esc_html($a['text']); ?></span>
                                    <?php if (!empty($a['explanation'])) : ?>
                                        <p class="itu-pt-answer-explanation"><?php echo esc_html($a['explanation']); ?></p>
                                    <?php endif; ?>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                        <?php if (!empty($q['wrong'])) : ?>
                            <h3 class="itu-pt-answer-heading">Other options &mdash; why they're wrong:</h3>
                            <ul class="itu-pt-answer-list itu-pt-answer-list--wrong">
                                <?php foreach ($q['wrong'] as $a) : ?>
                                    <li>
                                        <span class="itu-pt-answer-text"><?php echo esc_html($a['text']); ?></span>
                                        <?php if (!empty($a['explanation'])) : ?>
                                            <p class="itu-pt-answer-explanation"><?php echo esc_html($a['explanation']); ?></p>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </div>
                </details>
            <?php endforeach; ?>
        </section>

        <?php echo do_shortcode('[itu_plans_bar]'); ?>

        <nav class="itu-pt-study-guide-nav">
            <a href="<?php echo esc_url($back_url); ?>" class="itu-pt-study-guide-back-link">Take the interactive practice test</a>
        </nav>
    </article>
</main>

<?php if (function_exists('block_template_part')) block_template_part('footer'); ?>

<?php wp_footer(); ?>
</body>
</html>
    <?php
    return ob_get_clean();
}

// ── Routing ─────────────────────────────────────────────────────────────────

add_filter('template_include', function ($template) {
    $slug = get_query_var(SEOM_PT_QUERY_VAR);
    if (empty($slug)) return $template;

    $post = seom_pt_resolve_post($slug);
    if (!$post) {
        global $wp_query;
        $wp_query->set_404();
        status_header(404);
        nocache_headers();
        return $template;
    }

    // Cache check first — avoid the join entirely on warm hits.
    $cache_key = 'seom_pt_sg_' . md5($slug);
    $html = get_transient($cache_key);
    if ($html === false) {
        $bank = seom_pt_get_question_bank($post->ID);
        if (!$bank) {
            global $wp_query;
            $wp_query->set_404();
            status_header(404);
            nocache_headers();
            return $template;
        }
        $html = seom_pt_render_study_guide($post, $bank);
        set_transient($cache_key, $html, 12 * HOUR_IN_SECONDS);
    }

    // Set up the query context like the glossary term page does so
    // wp_head emitters that gate on is_singular behave correctly.
    global $wp_query;
    $wp_query->is_singular   = true;
    $wp_query->is_404        = false;
    $wp_query->seom_is_practice_test_study_guide = true;
    status_header(200);

    echo $html;
    exit;
});

// Bust the cache when the source post is updated.
add_action('save_post_post', function ($post_id, $post) {
    if (!has_category(SEOM_PT_CATEGORY_SLUG, $post_id)) return;
    delete_transient('seom_pt_sg_' . md5($post->post_name));
}, 10, 2);

// Also bust whenever the underlying PTM data changes (e.g. an answer
// is updated via the cron-driven AI generator). We can't observe every
// individual update efficiently, but the ptm_cron_add_answers handler
// fires periodically and is a natural seam to drop the cache on.
add_action('ptm_cron_add_answers', function () {
    global $wpdb;
    // Invalidate caches for every active practice test that's linked
    // to a published post. Cheap — one DELETE per affected slug.
    $rows = $wpdb->get_results(
        "SELECT p.post_name
         FROM {$wpdb->prefix}ptm_practice_tests t
         INNER JOIN {$wpdb->posts} p ON p.ID = t.test_post_id
         WHERE t.is_active = 1 AND p.post_status = 'publish'"
    );
    foreach ($rows as $r) {
        if (!empty($r->post_name)) {
            delete_transient('seom_pt_sg_' . md5($r->post_name));
        }
    }
}, 99);

// ── Linking from the interactive practice test page ─────────────────────────

add_filter('the_content', function ($content) {
    if (is_admin() || !is_singular('post')) return $content;
    if (!has_category(SEOM_PT_CATEGORY_SLUG)) return $content;
    if (strpos($content, 'seom-pt-study-guide-cta') !== false) return $content;

    $post = get_post();
    if (!$post) return $content;

    // Only show CTA when a question bank actually exists.
    global $wpdb;
    $has_bank = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT 1
         FROM {$wpdb->prefix}ptm_practice_tests t
         INNER JOIN {$wpdb->prefix}ptm_questions q ON q.test_id = t.id
         WHERE t.test_post_id = %d AND t.is_active = 1
         LIMIT 1",
        $post->ID
    ));
    if (!$has_bank) return $content;

    $slug = $post->post_name;
    $url  = home_url('/' . SEOM_PT_STUDY_GUIDE_BASE . '/' . $slug . '/');

    $cta = '<aside class="seom-pt-study-guide-cta" style="margin:24px 0;padding:18px 22px;background:#eff6ff;border-left:4px solid #2563eb;border-radius:8px;">'
         . '<strong style="display:block;margin-bottom:4px;color:#1e3a8a;">Want to study every question with answer explanations?</strong>'
         . '<span style="color:#1e293b;">View the full <a href="' . esc_url($url) . '" style="color:#2563eb;font-weight:600;">study guide</a> for this practice test — every question, the correct answer, and why each option is right or wrong.</span>'
         . '</aside>';

    return $content . $cta;
});
