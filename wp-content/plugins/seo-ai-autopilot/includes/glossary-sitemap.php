<?php
/**
 * Glossary Sitemap — XML sitemap for the /it-glossary/{slug}/ pages,
 * served *through RankMath's sitemap pipeline* (not a standalone URL).
 *
 * Why through RankMath: RankMath registers a catch-all rewrite that
 * matches /([^/]+?)-sitemap.xml$ and routes every match through its
 * own Generator. Any custom rule we add for /glossary-sitemap.xml is
 * silently shadowed — the URL still hits RankMath's handler, which
 * doesn't recognize "glossary" as a sitemap type and 404s. Going with
 * the pipeline instead of around it means:
 *   - Our URL works (RankMath's rewrite already routes it)
 *   - We inherit RankMath's stylesheet + caching + content-type headers
 *   - The entry appears in /sitemap_index.xml automatically alongside
 *     post-sitemap, page-sitemap, etc.
 *
 * Two hooks do all the work:
 *
 *   1. `rank_math/sitemap/index` (filter)
 *      Appends our <sitemap> block to the index. URL is
 *      /glossary-sitemap.xml — same URL that RankMath's rewrite will
 *      route to us via hook #2.
 *
 *   2. `rank_math/sitemap/glossary/content` (filter)
 *      Returns the <urlset>...</urlset> body for our sitemap. RankMath
 *      wraps it with the <?xml ?> declaration + optional stylesheet
 *      and serves with the right headers.
 *
 * Cache: RankMath caches sitemap output on disk. When expansions
 * happen we invalidate via seom_glossary_changed → RankMath::Cache.
 */

if (!defined('ABSPATH')) exit;

const SEOM_GLOSSARY_SITEMAP_LM_OPT = 'seom_glossary_sitemap_lastmod';

/**
 * Build the <urlset> body listing every term that has expanded content.
 * Returns just the XML body (no <?xml ?> declaration) — RankMath's
 * Generator::get_output() prepends the declaration + stylesheet.
 */
function seom_glossary_sitemap_build_urlset() {
    global $wpdb;
    $rows = $wpdb->get_results(
        "SELECT term, slug, definition_expanded_at
         FROM ec_it_glossary
         WHERE definition_expanded IS NOT NULL AND definition_expanded != ''
           AND term IS NOT NULL AND term != ''
         ORDER BY term ASC"
    );
    if (!$rows) return '';

    $xml  = '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

    $max_lastmod = null;
    foreach ($rows as $r) {
        $slug = !empty($r->slug) ? $r->slug : sanitize_title($r->term);
        if ($slug === '') continue;
        $url     = home_url('/it-glossary/' . $slug . '/');
        $lastmod = $r->definition_expanded_at
            ? mysql2date('c', $r->definition_expanded_at, false)
            : mysql2date('c', current_time('mysql'), false);

        if (!$max_lastmod || $r->definition_expanded_at > $max_lastmod) {
            $max_lastmod = $r->definition_expanded_at;
        }

        $xml .= "\t<url>\n";
        $xml .= "\t\t<loc>" . htmlspecialchars($url) . "</loc>\n";
        $xml .= "\t\t<lastmod>" . htmlspecialchars($lastmod) . "</lastmod>\n";
        $xml .= "\t\t<changefreq>monthly</changefreq>\n";
        $xml .= "\t\t<priority>0.6</priority>\n";
        $xml .= "\t</url>\n";
    }

    $xml .= '</urlset>';

    if ($max_lastmod) {
        update_option(SEOM_GLOSSARY_SITEMAP_LM_OPT, (string) $max_lastmod, false);
    }

    return $xml;
}

/**
 * Hook #2: serve the body when RankMath asks for sitemap type=glossary.
 * RankMath's Generator::build_sitemap() loops registered providers; none
 * match "glossary" so it falls through to this filter:
 *   $this->do_filter("sitemap/{$type}/content", '');
 *
 * Returning empty string means "no sitemap" and RankMath 404s. We
 * return our <urlset> body whenever there's at least one expanded term.
 */
add_filter('rank_math/sitemap/glossary/content', function ($content) {
    $body = seom_glossary_sitemap_build_urlset();
    return $body !== '' ? $body : $content;
}, 10, 1);

/**
 * Hook #1: list the sitemap in the index. URL points at the route
 * RankMath's own rewrite already handles — /glossary-sitemap.xml.
 */
add_filter('rank_math/sitemap/index', function ($xml) {
    global $wpdb;
    $expanded = (int) $wpdb->get_var(
        "SELECT COUNT(*) FROM ec_it_glossary
         WHERE definition_expanded IS NOT NULL AND definition_expanded != ''"
    );
    if ($expanded < 1) return $xml;

    $url     = home_url('/glossary-sitemap.xml');
    $lastmod = get_option(SEOM_GLOSSARY_SITEMAP_LM_OPT, current_time('mysql'));
    $lastmod = mysql2date('c', $lastmod, false);

    $entry  = "\t<sitemap>\n";
    $entry .= "\t\t<loc>" . htmlspecialchars($url) . "</loc>\n";
    $entry .= "\t\t<lastmod>" . htmlspecialchars($lastmod) . "</lastmod>\n";
    $entry .= "\t</sitemap>\n";
    return $xml . $entry;
});

/**
 * Tell RankMath to nuke its sitemap cache when glossary content
 * changes — otherwise new expansions don't show up until the cache
 * naturally expires.
 */
add_action('seom_glossary_changed', function () {
    if (class_exists('\\RankMath\\Sitemap\\Cache')) {
        \RankMath\Sitemap\Cache::invalidate_storage();
    }
});
