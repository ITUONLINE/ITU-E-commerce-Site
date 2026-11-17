-- ═══════════════════════════════════════════════════════════════════════════
-- Elementor Data Cleanup Script
-- Run AFTER confirming a full database backup exists
--
-- This script:
--   1. Creates backup tables with the data to be deleted
--   2. Deletes Elementor data from production tables
--   3. Optimizes tables after cleanup
--
-- To RESTORE if needed:
--   INSERT INTO wp_postmeta SELECT * FROM wp_postmeta_elementor_backup;
--   INSERT INTO wp_options SELECT * FROM wp_options_elementor_backup;
--   INSERT INTO wp_postmeta SELECT * FROM wp_postmeta_elementor_css_backup;
-- ═══════════════════════════════════════════════════════════════════════════

-- ─── STEP 0: Check what we're about to delete ─────────────────────────────
-- Run these first to see the scope:

SELECT '=== Elementor PostMeta Summary ===' as info;
SELECT meta_key, COUNT(*) as rows_count,
       ROUND(SUM(LENGTH(meta_value)) / 1048576, 1) as size_mb
FROM wp_postmeta
WHERE meta_key LIKE '_elementor%'
GROUP BY meta_key
ORDER BY size_mb DESC;

SELECT '=== Elementor Options Summary ===' as info;
SELECT option_name, ROUND(LENGTH(option_value) / 1024, 1) as size_kb
FROM wp_options
WHERE option_name LIKE '%elementor%'
ORDER BY size_kb DESC;

SELECT '=== Elementor CSS in PostMeta ===' as info;
SELECT COUNT(*) as rows_count, ROUND(SUM(LENGTH(meta_value)) / 1048576, 1) as size_mb
FROM wp_postmeta
WHERE meta_key IN ('_elementor_css', '_elementor_inline_svg');

SELECT '=== TOTALS ===' as info;
SELECT
    (SELECT COUNT(*) FROM wp_postmeta WHERE meta_key LIKE '_elementor%') as postmeta_rows,
    (SELECT ROUND(SUM(LENGTH(meta_value)) / 1048576, 1) FROM wp_postmeta WHERE meta_key LIKE '_elementor%') as postmeta_mb,
    (SELECT COUNT(*) FROM wp_options WHERE option_name LIKE '%elementor%') as options_rows;


-- ─── STEP 1: Create backup tables ─────────────────────────────────────────

-- Backup Elementor postmeta (page builder data, settings, etc.)
DROP TABLE IF EXISTS wp_postmeta_elementor_backup;
CREATE TABLE wp_postmeta_elementor_backup LIKE wp_postmeta;
INSERT INTO wp_postmeta_elementor_backup
SELECT * FROM wp_postmeta WHERE meta_key LIKE '_elementor%';

-- Backup Elementor options (global settings, schemes, etc.)
DROP TABLE IF EXISTS wp_options_elementor_backup;
CREATE TABLE wp_options_elementor_backup LIKE wp_options;
INSERT INTO wp_options_elementor_backup
SELECT * FROM wp_options WHERE option_name LIKE '%elementor%';

-- Backup Elementor CSS cache in postmeta
DROP TABLE IF EXISTS wp_postmeta_elementor_css_backup;
CREATE TABLE wp_postmeta_elementor_css_backup LIKE wp_postmeta;
INSERT INTO wp_postmeta_elementor_css_backup
SELECT * FROM wp_postmeta WHERE meta_key IN ('_elementor_css', '_elementor_inline_svg');

-- Verify backups were created
SELECT '=== Backup Verification ===' as info;
SELECT 'wp_postmeta_elementor_backup' as backup_table, COUNT(*) as rows_backed_up FROM wp_postmeta_elementor_backup
UNION ALL
SELECT 'wp_options_elementor_backup', COUNT(*) FROM wp_options_elementor_backup
UNION ALL
SELECT 'wp_postmeta_elementor_css_backup', COUNT(*) FROM wp_postmeta_elementor_css_backup;


-- ─── STEP 2: Delete Elementor data ────────────────────────────────────────

-- Delete all Elementor post meta (_elementor_data, _elementor_edit_mode,
-- _elementor_template_type, _elementor_version, _elementor_pro_version, etc.)
DELETE FROM wp_postmeta WHERE meta_key LIKE '_elementor%';

-- Delete Elementor CSS cache
DELETE FROM wp_postmeta WHERE meta_key IN ('_elementor_css', '_elementor_inline_svg');

-- Delete Elementor options (settings, schemes, colors, fonts, etc.)
-- Preserves license keys in case Elementor Pro needs reactivation
DELETE FROM wp_options
WHERE option_name LIKE '%elementor%'
AND option_name NOT LIKE '%license%';

-- Delete Elementor transients
DELETE FROM wp_options WHERE option_name LIKE '_transient%elementor%';
DELETE FROM wp_options WHERE option_name LIKE '_site_transient%elementor%';


-- ─── STEP 3: Clean up Elementor post types (templates, etc.) ──────────────

-- Backup Elementor template posts first
DROP TABLE IF EXISTS wp_posts_elementor_backup;
CREATE TABLE wp_posts_elementor_backup LIKE wp_posts;
INSERT INTO wp_posts_elementor_backup
SELECT * FROM wp_posts WHERE post_type IN ('elementor_library', 'elementor_font', 'elementor_icons', 'e-landing-page');

-- Delete Elementor template posts
DELETE FROM wp_posts WHERE post_type IN ('elementor_library', 'elementor_font', 'elementor_icons', 'e-landing-page');

-- Clean up orphaned postmeta for deleted posts
DELETE pm FROM wp_postmeta pm
LEFT JOIN wp_posts p ON pm.post_id = p.ID
WHERE p.ID IS NULL;


-- ─── STEP 4: Optimize tables ──────────────────────────────────────────────

OPTIMIZE TABLE wp_postmeta;
OPTIMIZE TABLE wp_options;
OPTIMIZE TABLE wp_posts;


-- ─── STEP 5: Verify cleanup ──────────────────────────────────────────────

SELECT '=== Post-Cleanup Verification ===' as info;
SELECT 'Remaining Elementor postmeta' as check_item, COUNT(*) as remaining
FROM wp_postmeta WHERE meta_key LIKE '_elementor%'
UNION ALL
SELECT 'Remaining Elementor options', COUNT(*)
FROM wp_options WHERE option_name LIKE '%elementor%'
UNION ALL
SELECT 'Remaining Elementor posts', COUNT(*)
FROM wp_posts WHERE post_type IN ('elementor_library', 'elementor_font', 'elementor_icons', 'e-landing-page');

SELECT '=== Backup tables (keep until verified, then DROP to reclaim space) ===' as info;
SELECT 'wp_postmeta_elementor_backup' as backup_table, COUNT(*) as rows_saved FROM wp_postmeta_elementor_backup
UNION ALL
SELECT 'wp_options_elementor_backup', COUNT(*) FROM wp_options_elementor_backup
UNION ALL
SELECT 'wp_postmeta_elementor_css_backup', COUNT(*) FROM wp_postmeta_elementor_css_backup
UNION ALL
SELECT 'wp_posts_elementor_backup', COUNT(*) FROM wp_posts_elementor_backup;


-- ═══════════════════════════════════════════════════════════════════════════
-- TO RESTORE (if something breaks):
--
--   INSERT INTO wp_postmeta SELECT * FROM wp_postmeta_elementor_backup;
--   INSERT INTO wp_postmeta SELECT * FROM wp_postmeta_elementor_css_backup;
--   INSERT INTO wp_options SELECT * FROM wp_options_elementor_backup;
--   INSERT INTO wp_posts SELECT * FROM wp_posts_elementor_backup;
--
-- TO DROP BACKUPS (after confirming everything works):
--
--   DROP TABLE wp_postmeta_elementor_backup;
--   DROP TABLE wp_options_elementor_backup;
--   DROP TABLE wp_postmeta_elementor_css_backup;
--   DROP TABLE wp_posts_elementor_backup;
-- ═══════════════════════════════════════════════════════════════════════════
