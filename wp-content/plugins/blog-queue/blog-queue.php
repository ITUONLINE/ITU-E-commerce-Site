<?php
/*
Plugin Name: Blog Queue
Description: Queue blog topics for automated daily creation. Generates outlines, full blog posts, FAQs, and SEO metadata using AI.
Version: 1.0
Author: ITU Online
*/

if (!defined('ABSPATH')) exit;

// ─── Activation ──────────────────────────────────────────────────────────────

register_activation_hook(__FILE__, 'bq_activate');
function bq_activate() {
    bq_create_tables();

    if (!wp_next_scheduled('bq_daily_process')) {
        wp_schedule_event(strtotime('today 07:00'), 'daily', 'bq_daily_process');
    }
}

register_deactivation_hook(__FILE__, function () {
    wp_clear_scheduled_hook('bq_daily_process');
    wp_clear_scheduled_hook('bq_process_next');
});

// ─── Database ────────────────────────────────────────────────────────────────

function bq_create_tables() {
    global $wpdb;
    $charset = $wpdb->get_charset_collate();
    require_once ABSPATH . 'wp-admin/includes/upgrade.php';

    dbDelta("CREATE TABLE {$wpdb->prefix}bq_queue (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(500) NOT NULL,
        status varchar(20) NOT NULL DEFAULT 'pending',
        gap_category varchar(100) DEFAULT NULL,
        target_keywords text DEFAULT NULL,
        post_id bigint(20) unsigned DEFAULT NULL,
        added_at datetime NOT NULL,
        processed_at datetime DEFAULT NULL,
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY status (status)
    ) $charset;");

    dbDelta("CREATE TABLE {$wpdb->prefix}bq_history (
        id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        title varchar(500) NOT NULL,
        post_id bigint(20) unsigned DEFAULT NULL,
        status varchar(20) NOT NULL,
        processed_at datetime NOT NULL,
        error_message text DEFAULT NULL,
        PRIMARY KEY (id),
        KEY processed_at (processed_at)
    ) $charset;");
}

// Auto-add new columns for existing installs
add_action('admin_init', function () {
    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $col = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'gap_category'");
    if (!$col) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN gap_category varchar(100) DEFAULT NULL AFTER status");
    }
    $col2 = $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'target_keywords'");
    if (!$col2) {
        $wpdb->query("ALTER TABLE {$table} ADD COLUMN target_keywords text DEFAULT NULL AFTER gap_category");
    }
}, 99);

// ─── Settings ────────────────────────────────────────────────────────────────

function bq_get_settings() {
    return wp_parse_args(get_option('bq_settings', []), [
        'daily_limit'  => 5,
        'notify_email' => get_option('admin_email'),
        'enabled'      => false,
    ]);
}

// ─── Blog Creator ────────────────────────────────────────────────────────────

function bq_call_openai($instruction, $user_prompt = '', $model = '', $temperature = 0.7) {
    if (!$model) $model = function_exists('itu_ai_model') ? itu_ai_model('blog_queue') : 'gpt-4.1-nano';

    // Use unified provider router if available
    if (function_exists('itu_ai_call')) {
        return itu_ai_call($instruction, $user_prompt, $model, $temperature, ['key_name' => 'blog_writer', 'timeout' => 240]);
    }

    $api_key = function_exists('itu_ai_key') ? itu_ai_key('blog_writer') : get_option('ai_post_api_key');
    if (!$api_key) return new WP_Error('no_key', 'Blog Writer API key not configured.');

    $messages = [['role' => 'system', 'content' => $instruction]];
    if ($user_prompt) $messages[] = ['role' => 'user', 'content' => $user_prompt];

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
        'timeout' => 240,
    ]);

    if (is_wp_error($response)) return $response;

    $data = json_decode(wp_remote_retrieve_body($response), true);
    $content = trim($data['choices'][0]['message']['content'] ?? '');
    if (empty($content)) return new WP_Error('empty', 'No content returned from OpenAI.');

    return $content;
}

/**
 * Round-robin pick: one item per category, then repeat, so consecutive
 * blogs are always from DIFFERENT categories.
 */
function bq_round_robin_pick($wpdb, $table, $limit) {
    // Get all pending items grouped by category
    $all = $wpdb->get_results("SELECT * FROM {$table} WHERE status = 'pending' ORDER BY RAND()");
    if (empty($all)) return [];

    // Group by gap_category (uncategorized goes to '')
    $buckets = [];
    foreach ($all as $item) {
        $cat = $item->gap_category ?: '__none__';
        $buckets[$cat][] = $item;
    }

    // Shuffle bucket order so the same category doesn't always go first
    $cat_keys = array_keys($buckets);
    shuffle($cat_keys);

    // Round-robin: pick one from each category in rotation
    $picked = [];
    while (count($picked) < $limit) {
        $picked_this_round = false;
        foreach ($cat_keys as $cat) {
            if (empty($buckets[$cat])) continue;
            $picked[] = array_shift($buckets[$cat]);
            $picked_this_round = true;
            if (count($picked) >= $limit) break;
        }
        if (!$picked_this_round) break; // all buckets empty
    }

    return $picked;
}

function bq_get_gap_keywords_for_prompt($gap_category) {
    if (empty($gap_category)) return '';

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) return '';

    // Get cooldown days from settings
    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    // Only pull keywords that haven't been used recently (or never used)
    $keywords = $wpdb->get_results($wpdb->prepare("
        SELECT id, keyword, search_volume, keyword_difficulty
        FROM {$table}
        WHERE tag = %s AND search_volume > 0
        AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY))
        ORDER BY search_volume DESC
        LIMIT 15
    ", $gap_category, $cooldown));

    if (empty($keywords)) return '';

    $lines = [];
    foreach ($keywords as $kw) {
        $lines[] = "- {$kw->keyword} (volume: {$kw->search_volume})";
    }

    return "\n\nTARGET SEO KEYWORDS (from competitive gap analysis — these are keywords competitors rank for but we don't):\n"
        . "Category: {$gap_category}\n"
        . implode("\n", $lines) . "\n"
        . "Work these keywords naturally into the content. Use the primary keywords in headings where appropriate. "
        . "Do NOT force keywords unnaturally — weave them into relevant sections.";
}

/**
 * Check if a keyword is on cooldown (used or reserved recently).
 * Works for both GSC and imported gap keywords.
 */
function bq_is_keyword_on_cooldown($keyword) {
    global $wpdb;
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") !== $usage_table) return false;

    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    $used = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$usage_table} WHERE keyword = %s AND used_at > DATE_SUB(CURDATE(), INTERVAL %d DAY)",
        strtolower(trim($keyword)), $cooldown
    ));

    return $used > 0;
}

/**
 * Reserve keywords when topics are added to the queue.
 * Prevents the same keywords from being offered to other topics.
 */
function bq_reserve_keywords($keywords, $queue_id = null, $source = 'gap') {
    if (empty($keywords)) return;

    global $wpdb;
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") !== $usage_table) return;

    // Check if status column exists
    $has_status = $wpdb->get_var("SHOW COLUMNS FROM {$usage_table} LIKE 'status'");

    foreach ((array) $keywords as $kw) {
        if (is_string($kw)) {
            $kw = strtolower(trim($kw));
        } else {
            continue;
        }
        if (empty($kw) || mb_strlen($kw) < 2) continue;

        $row = [
            'keyword' => mb_substr($kw, 0, 255),
            'source'  => $source,
            'used_at' => date('Y-m-d'),
        ];
        if ($has_status) $row['status'] = 'reserved';
        if ($queue_id) $row['queue_id'] = $queue_id;

        $wpdb->replace($usage_table, $row);
    }

    // Also stamp the gap table if applicable
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") === $gap_table) {
        foreach ((array) $keywords as $kw) {
            if (!is_string($kw)) continue;
            $kw = strtolower(trim($kw));
            if (empty($kw)) continue;
            $wpdb->query($wpdb->prepare(
                "UPDATE {$gap_table} SET last_used_at = CURDATE() WHERE LOWER(keyword) = %s AND last_used_at IS NULL",
                $kw
            ));
        }
    }
}

/**
 * Promote reserved keywords to used when blog is actually created.
 */
function bq_record_keywords_used($keywords, $post_id, $source = 'gap') {
    if (empty($keywords)) return;

    global $wpdb;
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") !== $usage_table) return;

    $has_status = $wpdb->get_var("SHOW COLUMNS FROM {$usage_table} LIKE 'status'");

    foreach ($keywords as $kw) {
        $kw = strtolower(trim($kw));
        if (empty($kw)) continue;

        $row = [
            'keyword' => mb_substr($kw, 0, 255),
            'source'  => $source,
            'used_at' => date('Y-m-d'),
            'post_id' => $post_id,
        ];
        if ($has_status) $row['status'] = 'used';

        $wpdb->replace($usage_table, $row);
    }
}

/**
 * Mark gap keywords as used after a blog is created targeting them.
 * Stamps both the seom_keyword_gaps table AND the unified usage table.
 */
function bq_stamp_gap_keywords_used($gap_category, $post_id) {
    if (empty($gap_category)) return;

    global $wpdb;
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") !== $gap_table) return;

    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    // Get the keywords that were used (same query as the prompt builder)
    $used_keywords = $wpdb->get_col($wpdb->prepare("
        SELECT keyword FROM {$gap_table}
        WHERE tag = %s AND search_volume > 0
        AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY))
        ORDER BY search_volume DESC
        LIMIT 15
    ", $gap_category, $cooldown));

    // Stamp the gap table
    $wpdb->query($wpdb->prepare("
        UPDATE {$gap_table}
        SET last_used_at = CURDATE(), used_in_post_id = %d
        WHERE tag = %s
        AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY))
        ORDER BY search_volume DESC
        LIMIT 15
    ", $post_id, $gap_category, $cooldown));

    // Also record in the unified usage table
    bq_record_keywords_used($used_keywords, $post_id, 'gap');
}

function bq_create_blog($title, $gap_category = '', $target_keywords = '') {
    // Step 1: Generate outline
    $outline_instruction = <<<PROMPT
Using the given topic, create a compelling blog title in title case, then generate a very detailed outline for a long-form blog post that will be 2,000-2,500 words when fully written.

The outline must have at least 6-8 main sections (not counting Introduction and Conclusion). Each section must have 4-6 detailed bullet points covering specific concepts, examples, tools, or steps. The more detailed the outline, the longer and better the final blog post will be.

TITLE RULES:
- Write the blog title in Title Case on the very first line — no label, no prefix, just the title itself
- Do NOT use ALL CAPS for the title or any heading
- Do NOT prefix the title with "BLOG TITLE:" or any label
- Do NOT include any year in the title

Return the outline in plain text using section headings and bulleted key points. Do not use numbers or Roman numerals.

Format:
Your Blog Title in Title Case Here

Main Heading
- Key point 1
- Key point 2
- Additional subtopics

Next Main Heading
- Key point 1
- Key point 2
PROMPT;

    $outline = bq_call_openai($outline_instruction, $title);
    if (is_wp_error($outline)) return $outline;

    // Extract the blog title from the outline (first non-empty line that isn't a label)
    $lines = explode("\n", trim($outline));
    $blog_title = '';
    foreach ($lines as $line) {
        $candidate = trim($line);
        if (empty($candidate)) continue;
        // Skip if it's just a label like "BLOG TITLE" or "BLOG TITLE:" with no actual title
        if (preg_match('/^(BLOG TITLE|Title|OUTLINE)\s*[::\-]?\s*$/i', $candidate)) continue;
        $blog_title = $candidate;
        break;
    }
    // Clean up title — remove "BLOG TITLE:" prefix, markdown bold, headings, and surrounding quotes
    $blog_title = preg_replace('/^(BLOG TITLE|Title)\s*[::\-]\s*/i', '', $blog_title);
    $blog_title = preg_replace('/^\*{1,2}|\*{1,2}$/', '', $blog_title);
    $blog_title = preg_replace('/^#{1,3}\s*/', '', $blog_title);
    $blog_title = trim($blog_title, " \t\n\r\0\x0B\"'");
    // Fix ALL CAPS titles — convert to title case
    if ($blog_title === mb_strtoupper($blog_title) && mb_strlen($blog_title) > 5) {
        $blog_title = mb_convert_case(mb_strtolower($blog_title), MB_CASE_TITLE);
    }
    if (empty($blog_title) || strlen($blog_title) > 200) $blog_title = $title;

    // Step 2: Generate full blog content
    $site_name = get_bloginfo('name');
    $blog_instruction = <<<PROMPT
You are a professional IT blog writer for {$site_name}. Your tone is direct, knowledgeable, and practical. You write for busy IT professionals who scan pages.

BANNED PHRASES — Do NOT use any of these:
- In today's rapidly evolving... / In an ever-changing landscape... / In the fast-paced world of...
- As technology continues to... / In today's digital age... / With the growing importance of...
- As organizations increasingly... / In the modern IT landscape...

IMPORTANT: Do NOT invent certification names, exam codes, or credential titles not in the outline.

WORD COUNT REQUIREMENT — THIS IS CRITICAL:
The blog post MUST be at least 2,000 words. This is a hard minimum — do NOT write fewer than 2,000 words.
- Each of the 6-8 main sections must be 200-350 words
- The introduction must be at least 150 words
- The conclusion must be at least 150 words
- Do NOT summarize or abbreviate sections. Write each section in full detail with specific examples, explanations, and actionable advice
- If the outline has 8 sections at 250 words each + intro + conclusion, that's 2,300 words. Hit that target.

Write the full blog post from the outline below. Cover EVERY section in the outline thoroughly.

OUTPUT FORMAT — CRITICAL:
- Return ONLY valid HTML. No Markdown (no #, **, -, ```)
- Use <h2> for main sections, <h3> for subsections
- Do NOT include an <h1> tag
- Wrap paragraphs in <p> tags — keep them SHORT (2-4 sentences max)
- Use <ul><li> or <ol><li> for lists
- Use <strong> to bold key terms on first mention
- Use <blockquote> for 1-2 notable quotes or insights
- Use 1-3 callout boxes per post. ONLY use these exact classes (no variations):
  <div class="itu-callout itu-callout--tip"><p><strong>Pro Tip</strong></p><p>Content.</p></div>
  <div class="itu-callout itu-callout--info"><p><strong>Note</strong></p><p>Content.</p></div>
  <div class="itu-callout itu-callout--warning"><p><strong>Warning</strong></p><p>Content.</p></div>
  <div class="itu-callout itu-callout--key"><p><strong>Key Takeaway</strong></p><p>Content.</p></div>
- Use <table> ONLY for simple 2-column comparisons
- Every section must mix at least 2 format types (paragraphs + lists, paragraphs + callout, etc.)
- Include named entity "{$site_name}" where appropriate

DEPTH REQUIREMENTS:
- Do NOT write surface-level summaries. Go deep with specifics, examples, tool names, commands, or step-by-step details
- Each section should teach the reader something concrete they can apply immediately
- Include real-world scenarios, comparisons between approaches, or common mistakes to avoid
- When comparing options, actually compare them — don't just list them
- When explaining a concept, explain it fully enough that someone unfamiliar could understand it
- Do not say "we will include..." — actually write the content in full detail
- Introduction: hook with a specific problem or scenario, preview key takeaways
- Conclusion: summarize actionable points + include a call to action for {$site_name}
- Write like a real person. Vary sentence length. Mix short punchy sentences with detailed explanations.

AUTHORITATIVE REFERENCES AND DATA — REQUIRED (critical for credibility):
Every blog post MUST include at least 3-5 distinct authoritative references from DIFFERENT sources. A knowledgeable human author would research multiple sources — so must you. Do NOT rely on a single source like BLS for everything.

REQUIRED reference types (include ALL that apply to the topic):

1. GOVERNING BODIES & CERTIFICATION AUTHORITIES — Most important. If the topic relates to a certification or vendor technology, you MUST cite that vendor's official documentation:
   - CompTIA (comptia.org) — A+, Network+, Security+, CySA+, CASP+, PenTest+, Cloud+, Linux+, Data+
   - Cisco (cisco.com) — CCNA, CCNP, CCIE, networking architecture, IOS
   - Microsoft (learn.microsoft.com) — Azure, Microsoft 365, Windows Server, AZ-900, MS-900, SC-900
   - AWS (aws.amazon.com/certification/) — Solutions Architect, SysOps, Developer, Cloud Practitioner
   - ISC2 (isc2.org) — CISSP, CCSP, SSCP, cybersecurity governance
   - ISACA (isaca.org) — CISM, CISA, CRISC, COBIT, IT audit/governance
   - PMI (pmi.org) — PMP, CAPM, PMI-ACP, project management
   - EC-Council (eccouncil.org) — CEH, ethical hacking, penetration testing
   - Axelos/PeopleCert — ITIL, PRINCE2
   - Google Cloud (cloud.google.com/certification) — GCP certifications
   - Linux Foundation (linuxfoundation.org) — CKA, CKAD, Kubernetes, open source
   - Red Hat (redhat.com) — RHCSA, RHCE, enterprise Linux
   - VMware/Broadcom — virtualization, VCP certifications
   - Juniper Networks (juniper.net) — JNCIA, JNCIS, networking
   - Palo Alto Networks (paloaltonetworks.com) — PCNSA, PCNSE, next-gen firewall
   Example: "According to <a href="https://www.comptia.org/certifications/security" target="_blank" rel="noopener">CompTIA</a>, the Security+ SY0-701 exam covers five domains including Threats, Vulnerabilities, and Mitigations"

2. COMPLIANCE, REGULATORY & LEGAL FRAMEWORKS — Critical for governance, risk, and security topics:
   - NIST (nist.gov) — Cybersecurity Framework (CSF), SP 800 series, Risk Management Framework
   - ISO/IEC (iso.org) — ISO 27001, ISO 27002, ISO 20000, ISO 9001
   - PCI Security Standards Council (pcisecuritystandards.org) — PCI DSS compliance
   - HIPAA / HHS (hhs.gov) — healthcare data privacy and security
   - GDPR / European Data Protection Board (edpb.europa.eu) — EU data privacy regulations
   - SOC 2 / AICPA (aicpa.org) — audit and compliance standards
   - FedRAMP (fedramp.gov) — federal cloud security authorization
   - CMMC / DoD (dodcio.defense.gov) — defense contractor cybersecurity maturity
   - CISA (cisa.gov) — cybersecurity advisories, vulnerability alerts, best practices
   - SEC (sec.gov) — cybersecurity disclosure requirements for public companies
   - FERPA / Dept of Education (ed.gov) — education data privacy
   - CCPA / California AG (oag.ca.gov) — California consumer privacy
   - COBIT (isaca.org/cobit) — IT governance framework
   - HITRUST (hitrustalliance.net) — healthcare information security framework
   Example: "Organizations handling payment card data must comply with <a href="https://www.pcisecuritystandards.org/" target="_blank" rel="noopener">PCI DSS</a> requirements, which mandate encryption, access controls, and regular vulnerability assessments"

3. GOVERNMENT & WORKFORCE:
   - Bureau of Labor Statistics (bls.gov/ooh/) — salary data, job outlook, growth projections
   - DoD Cyber Workforce (public.cyber.mil) — DoD 8570/8140 workforce requirements
   - Dept of Homeland Security (dhs.gov) — critical infrastructure, national cyber strategy
   - NSA (nsa.gov) — CAE program, cryptography standards
   - FTC (ftc.gov) — data protection enforcement, consumer privacy
   - GAO (gao.gov) — government IT spending reports, cybersecurity audits
   - Dept of Labor (dol.gov) — apprenticeship programs, workforce development
   - National Science Foundation (nsf.gov) — STEM workforce data, research grants
   Example: "The <a href="https://www.bls.gov/ooh/computer-and-information-technology/" target="_blank" rel="noopener">Bureau of Labor Statistics</a> projects 15% growth for information security analysts through 2032"

4. PROFESSIONAL ASSOCIATIONS & WORKFORCE ORGANIZATIONS:
   - SHRM (shrm.org) — Society for Human Resource Management, HR technology, workforce trends, hiring data
   - ISSA (issa.org) — Information Systems Security Association, security career development
   - IAPP (iapp.org) — International Association of Privacy Professionals, CIPP/CIPM certifications
   - ACM (acm.org) — Association for Computing Machinery, computing research
   - IEEE (ieee.org) — technical standards, professional development
   - ITSMF (itsmf.org) — IT Service Management Forum, ITSM best practices
   - HDI (hdi.com) — Help Desk Institute, service desk and support management
   - Cloud Security Alliance (cloudsecurityalliance.org) — CCSK, cloud security best practices
   - InfraGard (infragard.org) — FBI/private sector cybersecurity partnership
   - AICPA (aicpa.org) — accounting/audit professionals, SOC reporting
   - World Economic Forum (weforum.org) — global technology and workforce reports
   - CompTIA Research (connect.comptia.org) — State of IT workforce reports, hiring trends
   - (ISC)² Cybersecurity Workforce Study — annual workforce gap data
   - NICE / NIST Workforce Framework (nist.gov/nice) — cybersecurity career pathways
   Example: "According to <a href="https://www.shrm.org/" target="_blank" rel="noopener">SHRM</a>, 68% of HR professionals report difficulty filling cybersecurity positions, making certified candidates significantly more competitive"

5. INDUSTRY RESEARCH, ANALYST FIRMS & THREAT DATA:
   - Gartner — market forecasts, Magic Quadrant, Hype Cycle
   - Forrester — tech adoption, ROI studies, Wave reports
   - IDC — market sizing, IT spending forecasts
   - McKinsey & Company — digital transformation, workforce trends
   - Deloitte — technology trends, cyber survey reports
   - PwC — Global Digital Trust Insights, cyber risk surveys
   - KPMG — technology risk, governance surveys
   - SANS Institute (sans.org) — security surveys, training research
   - Cybersecurity Ventures — cybercrime cost projections, workforce gap data
   - Verizon DBIR — annual breach investigation data
   - IBM Cost of a Data Breach Report — breach cost statistics
   - Ponemon Institute — privacy and security research
   - MITRE ATT&CK (attack.mitre.org) — adversary tactics and techniques
   - CrowdStrike Global Threat Report — annual threat landscape
   - Mandiant/Google Threat Intelligence — incident response data
   Example: "According to <a href="https://www.ibm.com/reports/data-breach" target="_blank" rel="noopener">IBM's Cost of a Data Breach Report</a>, the average breach cost reached $4.45 million in 2023"

6. TECHNICAL STANDARDS & DOCUMENTATION:
   - Official vendor docs (docs.aws.amazon.com, learn.microsoft.com, cisco.com/c/en/us/support/, etc.)
   - IETF RFCs (ietf.org) — networking protocols, internet standards
   - OWASP (owasp.org) — web application security, Top 10
   - CIS Benchmarks (cisecurity.org) — system hardening guides
   - W3C (w3.org) — web standards, accessibility (WCAG)
   - DMTF (dmtf.org) — systems management standards
   - Open Networking Foundation (opennetworking.org) — SDN standards
   - FIRST (first.org) — incident response, CVSS scoring
   Example: "The <a href="https://owasp.org/www-project-top-ten/" target="_blank" rel="noopener">OWASP Top 10</a> identifies injection attacks as a critical web application vulnerability"

7. SALARY, JOB MARKET & CAREER DATA — Use MULTIPLE sources, not just BLS:
   - Bureau of Labor Statistics (bls.gov), Glassdoor, PayScale, Robert Half Technology Salary Guide
   - Indeed, LinkedIn Salary Insights, Dice Tech Salary Report, Burning Glass/Lightcast (labor market analytics)
   - Global Knowledge IT Skills and Salary Report — cert-specific salary premiums
   - SHRM compensation data, Mercer salary surveys
   - CompTIA workforce reports — IT hiring manager perspectives
   Example: "Network engineers earn a median of $89,050 per <a href="https://www.bls.gov/ooh/computer-and-information-technology/network-and-computer-systems-administrators.htm" target="_blank" rel="noopener">BLS data</a>, while <a href="https://www.payscale.com/" target="_blank" rel="noopener">PayScale</a> reports a range of $75,000-$110,000 depending on experience and location"

CITATION RULES:
- Format as HTML links: <a href="URL" target="_blank" rel="noopener">Source Name</a>
- Spread references throughout the entire article — not bunched in one section
- Each major section (H2) should ideally contain at least one reference
- For salary/stats, always name the source AND general timeframe
- Do NOT fabricate specific page URLs you are unsure about — use the organization's main domain or well-known subpages you are confident exist
- NEVER reference, link to, or mention competing IT training providers, online course platforms, bootcamps, or training companies by name. This includes but is not limited to: Coursera, Udemy, Pluralsight, CBT Nuggets, Cybrary, LinkedIn Learning, A Cloud Guru, INE, Infosec Institute, Training Camp, Global Knowledge, Skillsoft, Simplilearn, KnowledgeHut, edX, Codecademy, DataCamp, or ANY other entity that sells IT training courses or certification prep. If you need to reference learning resources, reference the official vendor documentation (e.g., Microsoft Learn, AWS Skill Builder, Cisco Learning Network) instead — these are free resources from the governing bodies themselves, not competing training companies.
- VARY your sources — do not cite the same organization more than twice per article
- Include concrete data: salary ranges, job growth %, pass rates, market size, adoption rates, exam details, number of questions, passing scores
- When discussing a certification, always reference the official cert page for exam details (domains, question count, passing score, cost)
- Think like a human researcher: cross-reference claims, cite the governing body AND an independent source for the same data point when possible

AI SEARCH OPTIMIZATION — Structure content so AI search engines (Google AI Overview, Perplexity, ChatGPT) can cite it:
- Lead sections with clear, factual thesis statements that directly answer common questions
- Use definition-style sentences for key concepts (e.g., "SIEM is a security solution that..." not "Let's talk about SIEM")
- Include comparison tables and structured lists that AI can extract as direct answers
- Write FAQ-style subheadings that match natural language queries (e.g., "How Long Does It Take to Get CompTIA A+ Certified?")
- Provide specific, quotable sentences with concrete numbers — AI search engines prefer citing exact claims over vague statements
- Use <strong> on key facts and definitions to help parsers identify core claims
PROMPT;

    // Inject target keywords — from gap category AND/OR specific queue item keywords
    $kw_prompt = bq_get_gap_keywords_for_prompt($gap_category);
    if (!empty($target_keywords)) {
        $kw_prompt .= "\n\nSPECIFIC TARGET KEYWORDS FOR THIS BLOG POST:\n"
            . $target_keywords . "\n"
            . "These keywords were specifically selected for this topic. Use them in the first paragraph, in at least one H2 heading, and naturally throughout the content (3-5 times each).";
    }
    $blog_content = bq_call_openai($blog_instruction . $kw_prompt, "Outline:\n" . $outline);
    if (is_wp_error($blog_content)) return $blog_content;

    // Fix any markdown that slipped through
    $blog_content = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $blog_content);
    $blog_content = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $blog_content);
    $blog_content = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $blog_content);
    $blog_content = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $blog_content);
    $blog_content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $blog_content);

    // Fix invalid callout classes — normalize to valid variants
    $blog_content = preg_replace_callback('/itu-callout--([a-z\-]+)/', function ($m) {
        $valid = ['tip', 'info', 'warning', 'key'];
        $variant = $m[1];
        // Map common AI mistakes to valid classes
        if (strpos($variant, 'tip') !== false) return 'itu-callout--tip';
        if (strpos($variant, 'info') !== false || strpos($variant, 'note') !== false) return 'itu-callout--info';
        if (strpos($variant, 'warn') !== false || strpos($variant, 'caution') !== false) return 'itu-callout--warning';
        if (strpos($variant, 'key') !== false || strpos($variant, 'purple') !== false || strpos($variant, 'important') !== false) return 'itu-callout--key';
        if (in_array($variant, $valid)) return 'itu-callout--' . $variant;
        return 'itu-callout--tip'; // default fallback
    }, $blog_content);

    // Step 3: Create the WordPress post
    $post_id = wp_insert_post([
        'post_title'   => $blog_title,
        'post_content' => $blog_content,
        'post_status'  => 'publish',
        'post_type'    => 'post',
        'post_author'  => 1,
    ]);

    if (is_wp_error($post_id)) return $post_id;

    // Assign to "Blogs" category
    $blog_cat = get_category_by_slug('blogs');
    if (!$blog_cat) $blog_cat = get_category_by_slug('blog');
    if ($blog_cat) {
        wp_set_post_categories($post_id, [$blog_cat->term_id]);
    }

    // Step 4: Generate meta description
    $snippet = mb_substr(wp_strip_all_tags($blog_content), 0, 500);
    $meta_instruction = "Write a meta description for this blog post. Rules: 1-2 sentences, 140-155 characters, start with an action verb, do not use quotes. Do NOT invent certifications.";
    $meta_desc = bq_call_openai($meta_instruction, "Title: {$blog_title}\n\nContent:\n{$snippet}", 'gpt-4.1-nano', 0.4);
    if (!is_wp_error($meta_desc)) {
        $meta_desc = wp_strip_all_tags(trim($meta_desc));
        wp_update_post(['ID' => $post_id, 'post_excerpt' => $meta_desc]);
        update_post_meta($post_id, 'rank_math_description', $meta_desc);
    }

    // Step 5: Generate focus keyword
    $kw_instruction = "Return a single primary focus keyword (2-4 words) for this blog post. Something people would search for. Return ONLY the keyword.";
    $keyword = bq_call_openai($kw_instruction, "Title: {$blog_title}\n\nContent:\n{$snippet}", 'gpt-4.1-nano', 0.3);
    if (!is_wp_error($keyword)) {
        update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field(trim($keyword)));
    }

    // Step 6: Generate FAQs
    $faq_instruction = "Generate 5 FAQ entries for this blog post. Format:\n<details><summary>Question?</summary><div class=\"faq-content\">\n<p>Paragraph 1.</p>\n<p>Paragraph 2.</p>\n</div></details>\n\nCRITICAL: Wrap ALL answer text in <p> tags. Each answer 200+ words, 2-4 paragraphs. Do NOT invent certifications.";
    $faq_html = bq_call_openai($faq_instruction, "Title: {$blog_title}\n\nContent:\n{$snippet}");
    if (!is_wp_error($faq_html) && function_exists('update_field')) {
        // Fix missing <p> tags
        $faq_html = preg_replace_callback('/<div class="faq-content">(.*?)<\/div>/s', function ($m) {
            $c = trim($m[1]);
            if (stripos($c, '<p>') !== false) return $m[0];
            $sents = preg_split('/(?<=[.!?])\s+/', $c);
            $chunks = []; $cur = '';
            foreach ($sents as $i => $s) {
                $cur .= ($cur ? ' ' : '') . $s;
                if (($i + 1) % 3 === 0 || $i === count($sents) - 1) { $chunks[] = $cur; $cur = ''; }
            }
            $w = '';
            foreach ($chunks as $ch) { $ch = trim($ch); if ($ch) $w .= '<p>' . $ch . "</p>\n"; }
            return '<div class="faq-content">' . "\n" . $w . '</div>';
        }, $faq_html);

        update_field('field_6816a44480234', $faq_html, $post_id);

        // Step 6b: Generate FAQ JSON-LD schema from the HTML
        $schema_instruction = "Convert the following HTML FAQ into a valid JSON-LD FAQPage schema. Return ONLY the raw JSON object — do NOT wrap it in <script> tags. Pretty-print the JSON. Input HTML:\n\n" . $faq_html;
        $faq_json = bq_call_openai($schema_instruction, null, 'gpt-4.1-nano', 0.3);
        if (!is_wp_error($faq_json)) {
            $faq_json = preg_replace('/^```[a-zA-Z]*\s*/m', '', $faq_json);
            $faq_json = preg_replace('/\s*```\s*$/m', '', $faq_json);
            $faq_json = preg_replace('/<script[^>]*>\s*/i', '', $faq_json);
            $faq_json = preg_replace('/\s*<\/script>/i', '', $faq_json);
            $faq_json = trim($faq_json);
            update_field('field_6816d54e3951d', $faq_json, $post_id);
        }
    }

    // Step 7: Generate SEO title
    $seo_instruction = "Write an SEO title for this blog post. Max 60 characters. Include the focus keyword near the beginning. End with ' - {$site_name}'. Return ONLY the title.";
    $seo_title = bq_call_openai($seo_instruction, "Title: {$blog_title}\nKeyword: {$keyword}", 'gpt-4.1-nano', 0.5);
    if (!is_wp_error($seo_title)) {
        $seo_title = sanitize_text_field(trim($seo_title));
        if (mb_strlen($seo_title) <= 70) {
            update_post_meta($post_id, 'rank_math_title', $seo_title);
        }
    }

    // Save timestamp
    update_post_meta($post_id, 'last_page_refresh', current_time('mysql'));

    // Stamp imported gap keywords as used so they enter cooldown
    bq_stamp_gap_keywords_used($gap_category, $post_id);

    // Also stamp the blog title and focus keyword in the unified usage table
    // so GSC content gaps that match this topic enter cooldown too
    $title_keywords = [];
    $title_keywords[] = strtolower($blog_title);
    $focus_kw = get_post_meta($post_id, 'rank_math_focus_keyword', true);
    if (!empty($focus_kw)) {
        $title_keywords[] = strtolower(trim($focus_kw));
    }
    bq_record_keywords_used($title_keywords, $post_id, 'gsc');

    return $post_id;
}

// ─── Queue Processor ─────────────────────────────────────────────────────────

function bq_process_queue($force = false) {
    $settings = bq_get_settings();
    if (!$force && !$settings['enabled']) return;

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $history = $wpdb->prefix . 'bq_history';

    // Reset stuck and failed items back to pending for retry
    $wpdb->query("UPDATE {$table} SET status = 'pending', error_message = NULL WHERE status IN ('processing', 'failed')");
    // Clean up completed items older than 24 hours
    $wpdb->query("DELETE FROM {$table} WHERE status = 'completed' AND processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

    $today_key = 'bq_daily_count_' . date('Y-m-d');
    $count = (int) get_option($today_key, 0);
    $limit = $settings['daily_limit'];

    if ($count >= $limit) return;

    // Pick pending items distributed across categories (round-robin)
    $remaining = $limit - $count;
    $items = bq_round_robin_pick($wpdb, $table, $remaining);

    if (empty($items)) return;

    $created = [];
    $failed = [];

    foreach ($items as $item) {
        // Mark as processing
        $wpdb->update($table, ['status' => 'processing'], ['id' => $item->id]);

        if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
        @set_time_limit(300);

        $result = bq_create_blog($item->title, $item->gap_category ?? '', $item->target_keywords ?? '');

        if (is_wp_error($result)) {
            $error = $result->get_error_message();
            $wpdb->update($table, [
                'status'        => 'failed',
                'processed_at'  => current_time('mysql'),
                'error_message' => $error,
            ], ['id' => $item->id]);
            $wpdb->insert($history, [
                'title'        => $item->title,
                'status'       => 'failed',
                'processed_at' => current_time('mysql'),
                'error_message'=> $error,
            ]);
            $failed[] = ['title' => $item->title, 'error' => $error];
        } else {
            $wpdb->update($table, [
                'status'       => 'completed',
                'post_id'      => $result,
                'processed_at' => current_time('mysql'),
            ], ['id' => $item->id]);
            $wpdb->insert($history, [
                'title'        => $item->title,
                'post_id'      => $result,
                'status'       => 'completed',
                'processed_at' => current_time('mysql'),
            ]);
            $created[] = ['title' => $item->title, 'post_id' => $result, 'url' => get_permalink($result)];
        }

        update_option($today_key, ++$count);
    }

    // Send summary email
    if ($settings['notify_email'] && (!empty($created) || !empty($failed))) {
        bq_send_summary_email($settings['notify_email'], $created, $failed);
    }
}

// ─── Email Notification ──────────────────────────────────────────────────────

function bq_send_notification($to, $title, $status, $post_id = 0, $view_url = '', $error = '') {
    $is_success = $status === 'completed';
    $status_color = $is_success ? '#059669' : '#dc2626';
    $status_label = $is_success ? 'Published' : 'Failed';
    $status_icon  = $is_success ? '&#10003;' : '&#10007;';
    $subject = $is_success ? "[Blog Queue] Published: {$title}" : "[Blog Queue] Failed: {$title}";

    $edit_url = $post_id ? admin_url('post.php?action=edit&post=' . $post_id) : '';

    $error_row = $error ? '<tr style="background:#fef2f2;"><td style="padding:8px 16px;color:#94a3b8;font-size:13px;">Error</td><td style="padding:8px 16px;color:#dc2626;">' . esc_html($error) . '</td></tr>' : '';

    $buttons = '';
    if ($post_id) {
        $buttons = '<div style="margin-top:20px;">'
            . '<a href="' . esc_url($view_url) . '" style="display:inline-block;padding:10px 20px;background:#2563eb;color:#fff;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;margin-right:8px;">View Post</a>'
            . '<a href="' . esc_url($edit_url) . '" style="display:inline-block;padding:10px 20px;background:#f0f0f1;color:#1e293b;text-decoration:none;border-radius:6px;font-weight:600;font-size:14px;border:1px solid #c3c4c7;">Edit in WordPress</a>'
            . '</div>';
    }

    $word_count = '';
    if ($post_id) {
        $content = get_post_field('post_content', $post_id);
        $wc = str_word_count(wp_strip_all_tags($content));
        $read_time = max(1, round($wc / 250));
        $word_count = '<tr><td style="padding:8px 16px;color:#94a3b8;font-size:13px;">Word Count</td><td style="padding:8px 16px;">' . number_format($wc) . ' words (~' . $read_time . ' min read)</td></tr>';
    }

    $body = '
    <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:600px;margin:0 auto;">
        <div style="background:#1e293b;padding:20px 24px;border-radius:8px 8px 0 0;">
            <h1 style="margin:0;color:#fff;font-size:18px;">Blog Queue</h1>
        </div>
        <div style="background:#fff;border:1px solid #e2e8f0;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
            <div style="display:inline-block;padding:6px 14px;border-radius:4px;background:' . $status_color . ';color:#fff;font-weight:600;font-size:14px;margin-bottom:16px;">
                ' . $status_icon . ' ' . $status_label . '
            </div>

            <h2 style="margin:0 0 16px;font-size:20px;color:#1e293b;">' . esc_html($title) . '</h2>

            <table style="width:100%;border-collapse:collapse;margin-bottom:20px;">
                <tr><td style="padding:8px 16px;color:#94a3b8;font-size:13px;width:120px;">Type</td><td style="padding:8px 16px;">New Blog Post</td></tr>
                <tr style="background:#f9fafb;"><td style="padding:8px 16px;color:#94a3b8;font-size:13px;">Category</td><td style="padding:8px 16px;">Blogs</td></tr>
                ' . $word_count . '
                ' . $error_row . '
            </table>

            ' . $buttons . '

            <p style="margin-top:24px;padding-top:16px;border-top:1px solid #e2e8f0;color:#94a3b8;font-size:12px;">
                Sent by Blog Queue on ' . esc_html(get_bloginfo('name')) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '
            </p>
        </div>
    </div>';

    $set_html = function () { return 'text/html'; };
    add_filter('wp_mail_content_type', $set_html);
    wp_mail($to, $subject, $body);
    remove_filter('wp_mail_content_type', $set_html);
}

function bq_send_summary_email($to, $created, $failed) {
    $site_name = get_bloginfo('name');
    $total = count($created) + count($failed);
    $subject = "[Blog Queue] Daily Summary: " . count($created) . " created, " . count($failed) . " failed";

    $rows = '';
    foreach ($created as $c) {
        $edit = admin_url('post.php?action=edit&post=' . $c['post_id']);
        $rows .= '<tr>'
            . '<td style="padding:8px 12px;"><a href="' . esc_url($c['url']) . '">' . esc_html($c['title']) . '</a></td>'
            . '<td style="padding:8px 12px;color:#059669;font-weight:600;">Published</td>'
            . '<td style="padding:8px 12px;"><a href="' . esc_url($edit) . '">Edit</a></td>'
            . '</tr>';
    }
    foreach ($failed as $f) {
        $rows .= '<tr style="background:#fef2f2;">'
            . '<td style="padding:8px 12px;">' . esc_html($f['title']) . '</td>'
            . '<td style="padding:8px 12px;color:#dc2626;font-weight:600;">Failed</td>'
            . '<td style="padding:8px 12px;font-size:12px;color:#dc2626;">' . esc_html($f['error']) . '</td>'
            . '</tr>';
    }

    $body = '
    <div style="font-family:-apple-system,BlinkMacSystemFont,Segoe UI,Roboto,sans-serif;max-width:700px;margin:0 auto;">
        <div style="background:#1d2327;padding:20px 24px;border-radius:8px 8px 0 0;">
            <h1 style="margin:0;color:#fff;font-size:18px;">Blog Queue — Daily Summary</h1>
        </div>
        <div style="background:#fff;border:1px solid #e5e7eb;border-top:none;border-radius:0 0 8px 8px;padding:24px;">
            <div style="display:flex;gap:24px;margin-bottom:20px;">
                <div><span style="font-size:32px;font-weight:700;color:#059669;">' . count($created) . '</span><br><span style="color:#6b7280;font-size:13px;">Created</span></div>
                <div><span style="font-size:32px;font-weight:700;color:#dc2626;">' . count($failed) . '</span><br><span style="color:#6b7280;font-size:13px;">Failed</span></div>
                <div><span style="font-size:32px;font-weight:700;color:#374151;">' . $total . '</span><br><span style="color:#6b7280;font-size:13px;">Total</span></div>
            </div>
            <table style="width:100%;border-collapse:collapse;border:1px solid #e5e7eb;">
                <thead><tr style="background:#f9fafb;"><th style="padding:8px 12px;text-align:left;">Title</th><th style="padding:8px 12px;text-align:left;">Status</th><th style="padding:8px 12px;text-align:left;">Details</th></tr></thead>
                <tbody>' . $rows . '</tbody>
            </table>
            <p style="margin-top:20px;color:#9ca3af;font-size:12px;">Sent by Blog Queue on ' . esc_html($site_name) . ' &middot; ' . esc_html(current_time('M j, Y g:i A')) . '</p>
        </div>
    </div>';

    $set_html = function () { return 'text/html'; };
    add_filter('wp_mail_content_type', $set_html);
    wp_mail($to, $subject, $body);
    remove_filter('wp_mail_content_type', $set_html);
}

// ─── Cron Hooks ──────────────────────────────────────────────────────────────

add_action('bq_daily_process', 'bq_process_queue');
add_action('bq_process_next', 'bq_process_one_from_queue');

/**
 * Process one item from the queue (for chained background processing).
 */
function bq_process_one_from_queue() {
    // Check stop flag
    if (get_option('bq_queue_stop', false)) {
        delete_option('bq_queue_stop');
        return;
    }

    $settings = bq_get_settings();
    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $history = $wpdb->prefix . 'bq_history';

    // Reset stuck and failed items back to pending for retry
    $wpdb->query("UPDATE {$table} SET status = 'pending', error_message = NULL WHERE status IN ('processing', 'failed')");

    // Clean up completed items older than 24 hours
    $wpdb->query("DELETE FROM {$table} WHERE status = 'completed' AND processed_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)");

    // Check daily limit
    $today_key = 'bq_daily_count_' . date('Y-m-d');
    $count = (int) get_option($today_key, 0);
    if ($count >= $settings['daily_limit']) return;

    // Pick next pending item
    $item = $wpdb->get_row("SELECT * FROM {$table} WHERE status = 'pending' ORDER BY RAND() LIMIT 1");
    if (!$item) return;

    $wpdb->update($table, ['status' => 'processing'], ['id' => $item->id]);

    @set_time_limit(300);
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

    $result = bq_create_blog($item->title, $item->gap_category ?? '', $item->target_keywords ?? '');

    $settings = bq_get_settings();
    if (is_wp_error($result)) {
        $error_msg = $result->get_error_message();
        $wpdb->update($table, ['status' => 'failed', 'processed_at' => current_time('mysql'), 'error_message' => $error_msg], ['id' => $item->id]);
        $wpdb->insert($history, ['title' => $item->title, 'status' => 'failed', 'processed_at' => current_time('mysql'), 'error_message' => $error_msg]);
        if ($settings['notify_email']) {
            bq_send_notification($settings['notify_email'], $item->title, 'failed', 0, '', $error_msg);
        }
    } else {
        $wpdb->update($table, ['status' => 'completed', 'post_id' => $result, 'processed_at' => current_time('mysql')], ['id' => $item->id]);
        $wpdb->insert($history, ['title' => $item->title, 'post_id' => $result, 'status' => 'completed', 'processed_at' => current_time('mysql')]);
        update_option($today_key, $count + 1);
        if ($settings['notify_email']) {
            bq_send_notification($settings['notify_email'], $item->title, 'created', $result, get_permalink($result));
        }
    }

    // Schedule next if more pending and under limit
    $remaining = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
    if ($remaining > 0 && ($count + 1) < $settings['daily_limit'] && !get_option('bq_queue_stop', false)) {
        wp_schedule_single_event(time() + 30, 'bq_process_next');
        spawn_cron();
        wp_remote_get(site_url('/wp-cron.php?doing_wp_cron=' . microtime(true)), [
            'timeout' => 0.01, 'blocking' => false, 'sslverify' => false,
        ]);
    } else {
        // Done or limit reached — clean up manual flag
        delete_option('bq_queue_manual');
    }
}

// Manual start/stop/status endpoints for background queue processing
add_action('wp_ajax_bq_start_queue_processing', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bq_queue WHERE status = 'pending'");
    if (!$pending) wp_send_json_error('No pending items.');

    delete_option('bq_queue_stop');
    update_option('bq_queue_manual', true);

    // Trigger cron chain — same mechanism as nightly processing
    wp_clear_scheduled_hook('bq_process_next');
    wp_schedule_single_event(time(), 'bq_process_next');
    spawn_cron();
    wp_remote_get(site_url('/wp-cron.php?doing_wp_cron=' . microtime(true)), [
        'timeout' => 0.01, 'blocking' => false, 'sslverify' => false,
    ]);

    wp_send_json_success(['pending' => $pending]);
});

add_action('wp_ajax_bq_queue_status', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $pending = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'pending'");
    $processing = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'processing'");
    $today_done = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'completed' AND DATE(processed_at) = CURDATE()");
    $today_failed = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status = 'failed' AND DATE(processed_at) = CURDATE()");
    $stopped = get_option('bq_queue_stop', false);
    $manual = get_option('bq_queue_manual', false);
    $next_scheduled = wp_next_scheduled('bq_process_next');

    $remaining = $pending + $processing;
    $is_running = ($processing > 0) || $next_scheduled || ($manual && !$stopped);

    // Clean up when done
    if ($manual && $pending === 0 && !$processing) {
        delete_option('bq_queue_manual');
        $is_running = false;
    }

    // Currently processing
    $current = $wpdb->get_row("SELECT id, title, gap_category FROM {$table} WHERE status = 'processing' LIMIT 1");

    $last = $wpdb->get_row("SELECT title, status, processed_at FROM {$table} WHERE status IN ('completed','failed') ORDER BY processed_at DESC LIMIT 1");

    $settings = bq_get_settings();
    wp_send_json_success([
        'remaining' => $remaining, 'pending' => $pending, 'processing' => $processing,
        'completed' => $today_done, 'failed' => $today_failed,
        'is_running' => $is_running && !$stopped, 'stopped' => (bool) $stopped,
        'current' => $current, 'last_done' => $last, 'daily_limit' => $settings['daily_limit'],
    ]);
});

add_action('wp_ajax_bq_stop_queue', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    update_option('bq_queue_stop', true);
    delete_option('bq_queue_manual');
    wp_clear_scheduled_hook('bq_process_next');
    wp_send_json_success('Stopped.');
});

// ─── Admin Menu ──────────────────────────────────────────────────────────────

add_action('admin_menu', function () {
    add_menu_page('Blog Queue', 'Blog Queue', 'manage_options', 'blog-queue', 'bq_render_admin', 'dashicons-edit-large', 58);
});

// "Documentation" link on the Plugins page for Blog Queue
add_filter('plugin_action_links_blog-queue/blog-queue.php', function ($links) {
    $url = admin_url('admin.php?page=seo-platform-docs');
    array_unshift($links, '<a href="' . esc_url($url) . '">Documentation</a>');
    return $links;
});

// ─── AJAX Handlers ───────────────────────────────────────────────────────────

add_action('wp_ajax_bq_add_topics', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $gap_category = sanitize_text_field($_POST['gap_category'] ?? '');

    // Accept either plain text (newline-separated titles) or JSON array of {title, keywords} objects
    $items_json = $_POST['items'] ?? '';
    $items = [];

    if (!empty($items_json) && is_string($items_json)) {
        $decoded = json_decode(wp_unslash($items_json), true);
        if (is_array($decoded)) $items = $decoded;
    }

    // Fallback: plain text topics
    if (empty($items)) {
        $topics = sanitize_textarea_field($_POST['topics'] ?? '');
        $lines = array_filter(array_map('trim', explode("\n", $topics)));
        foreach ($lines as $line) {
            $items[] = ['title' => $line, 'keywords' => ''];
        }
    }

    if (empty($items)) wp_send_json_error('No topics provided.');

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $now = current_time('mysql');
    $added = 0;

    foreach ($items as $item) {
        $title = sanitize_text_field(trim($item['title'] ?? ''));
        $keywords = sanitize_text_field(trim($item['keywords'] ?? ''));
        if (mb_strlen($title) < 5) continue;

        $row = [
            'title'    => mb_substr($title, 0, 500),
            'status'   => 'pending',
            'added_at' => $now,
        ];
        if (!empty($gap_category)) {
            $row['gap_category'] = $gap_category;
        }
        if (!empty($keywords)) {
            $row['target_keywords'] = $keywords;
        }
        $wpdb->insert($table, $row);
        $queue_id = $wpdb->insert_id;

        // Reserve the keywords so they don't get offered to other topics
        // and save LSI relationships
        if (!empty($keywords)) {
            $kw_list = array_map('trim', explode(',', $keywords));
            bq_reserve_keywords($kw_list, $queue_id, 'gap');

            // Save LSI keyword relationships — first keyword is primary, rest are LSI
            if (count($kw_list) > 1) {
                $primary = strtolower(trim($kw_list[0]));
                $lsi_table = $wpdb->prefix . 'seom_lsi_keywords';
                if ($wpdb->get_var("SHOW TABLES LIKE '{$lsi_table}'") === $lsi_table) {
                    $today = date('Y-m-d');
                    for ($k = 1; $k < count($kw_list); $k++) {
                        $lsi = strtolower(trim($kw_list[$k]));
                        if (empty($lsi) || $lsi === $primary) continue;
                        $wpdb->replace($lsi_table, [
                            'seed_keyword' => mb_substr($primary, 0, 255),
                            'lsi_keyword'  => mb_substr($lsi, 0, 255),
                            'source'       => 'ai',
                            'date_added'   => $today,
                        ]);
                    }
                }
            }
        }

        $added++;
    }

    wp_send_json_success(['added' => $added]);
});

add_action('wp_ajax_bq_get_queue', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bq_queue WHERE status = 'pending' ORDER BY added_at ASC LIMIT %d OFFSET %d",
        $per_page, $offset
    ));
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bq_queue WHERE status = 'pending'");
    $today_count = (int) get_option('bq_daily_count_' . date('Y-m-d'), 0);
    $bq_settings = bq_get_settings();

    wp_send_json_success([
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'pages'       => ceil($total / $per_page),
        'today_count' => $today_count,
        'daily_limit' => $bq_settings['daily_limit'],
    ]);
});

// Return ALL pending items with round-robin category distribution (for manual processing)
add_action('wp_ajax_bq_get_queue_distributed', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'bq_queue';
    $bq_settings = get_option('bq_settings', ['daily_limit' => 5]);
    $daily_limit = max(1, intval($bq_settings['daily_limit'] ?? 5));
    $limit = min(50, max(1, intval($_POST['limit'] ?? $daily_limit)));
    $all = $wpdb->get_results("SELECT id, title, gap_category, target_keywords FROM {$table} WHERE status = 'pending' ORDER BY RAND()");
    if (empty($all)) { wp_send_json_success(['rows' => []]); return; }

    // Group by category
    $buckets = [];
    foreach ($all as $item) {
        $cat = $item->gap_category ?: '__none__';
        $buckets[$cat][] = $item;
    }
    $cat_keys = array_keys($buckets);
    shuffle($cat_keys);

    // Round-robin pick across categories, capped at limit
    $distributed = [];
    $has_more = true;
    while ($has_more && count($distributed) < $limit) {
        $has_more = false;
        foreach ($cat_keys as $cat) {
            if (!empty($buckets[$cat])) {
                $distributed[] = array_shift($buckets[$cat]);
                $has_more = true;
                if (count($distributed) >= $limit) break;
            }
        }
    }

    wp_send_json_success(['rows' => $distributed, 'total' => count($distributed), 'daily_limit' => $daily_limit]);
});

add_action('wp_ajax_bq_get_history', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $page = max(1, intval($_POST['page'] ?? 1));
    $per_page = 25;
    $offset = ($page - 1) * $per_page;

    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}bq_history ORDER BY processed_at DESC LIMIT %d OFFSET %d",
        $per_page, $offset
    ));
    $total = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}bq_history");

    wp_send_json_success(['rows' => $rows, 'total' => $total, 'page' => $page, 'pages' => ceil($total / $per_page)]);
});

add_action('wp_ajax_bq_remove_item', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $id = intval($_POST['id'] ?? 0);
    if (!$id) wp_send_json_error('Missing ID.');

    // Release reserved keywords
    $item = $wpdb->get_row($wpdb->prepare("SELECT target_keywords FROM {$wpdb->prefix}bq_queue WHERE id = %d", $id));
    if ($item && !empty($item->target_keywords)) {
        $usage_table = $wpdb->prefix . 'seom_keyword_usage';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") === $usage_table) {
            $kw_list = array_map('trim', explode(',', $item->target_keywords));
            foreach ($kw_list as $kw) {
                $kw = strtolower(trim($kw));
                if (!empty($kw)) {
                    $wpdb->query($wpdb->prepare("DELETE FROM {$usage_table} WHERE keyword = %s AND status = 'reserved'", $kw));
                }
            }
        }
    }

    $wpdb->delete($wpdb->prefix . 'bq_queue', ['id' => $id]);
    wp_send_json_success();
});

add_action('wp_ajax_bq_bulk_delete', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $ids = array_map('intval', (array) ($_POST['ids'] ?? []));
    if (empty($ids)) wp_send_json_error('No items selected.');

    $placeholders = implode(',', array_fill(0, count($ids), '%d'));

    // Release reserved keywords before deleting
    $usage_table = $wpdb->prefix . 'seom_keyword_usage';
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    $has_usage = $wpdb->get_var("SHOW TABLES LIKE '{$usage_table}'") === $usage_table;
    $has_gaps = $wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") === $gap_table;

    $items = $wpdb->get_results($wpdb->prepare(
        "SELECT id, target_keywords FROM {$wpdb->prefix}bq_queue WHERE id IN ($placeholders) AND status = 'pending'",
        ...$ids
    ));
    foreach ($items as $item) {
        if (!empty($item->target_keywords)) {
            $kw_list = array_map('trim', explode(',', $item->target_keywords));
            foreach ($kw_list as $kw) {
                $kw = strtolower(trim($kw));
                if (empty($kw)) continue;
                // Remove reservation from usage table
                if ($has_usage) {
                    $wpdb->query($wpdb->prepare(
                        "DELETE FROM {$usage_table} WHERE keyword = %s AND status = 'reserved'", $kw
                    ));
                }
                // Clear last_used_at on gap table if it was only reserved (not used)
                if ($has_gaps) {
                    $wpdb->query($wpdb->prepare(
                        "UPDATE {$gap_table} SET last_used_at = NULL, used_in_post_id = NULL WHERE LOWER(keyword) = %s AND used_in_post_id IS NULL", $kw
                    ));
                }
            }
        }
    }

    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->prefix}bq_queue WHERE id IN ($placeholders) AND status = 'pending'",
        ...$ids
    ));

    wp_send_json_success(['deleted' => count($ids)]);
});

// Step-based blog creation — each step is a separate AJAX call to avoid 504 timeouts
add_action('wp_ajax_bq_process_step', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(300);
    ob_start();

    global $wpdb;
    $id   = intval($_POST['id'] ?? 0);
    $step = intval($_POST['step'] ?? 1);

    if (!$id) { ob_end_clean(); wp_send_json_error('Missing ID.'); }

    $item = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$wpdb->prefix}bq_queue WHERE id = %d", $id));
    if (!$item) { ob_end_clean(); wp_send_json_error('Item not found.'); }

    $progress_key = 'bq_progress_' . $id;
    $site_name = get_bloginfo('name');

    switch ($step) {
        case 1: // Generate outline
            $wpdb->update($wpdb->prefix . 'bq_queue', ['status' => 'processing'], ['id' => $id]);
            $outline_instruction = <<<PROMPT
Using the given topic, create a compelling blog title in title case, then generate a very detailed outline for a long-form blog post that will be 2,000-2,500 words when fully written.

The outline must have at least 6-8 main sections (not counting Introduction and Conclusion). Each section must have 4-6 detailed bullet points covering specific concepts, examples, tools, or steps. The more detailed the outline, the longer and better the final blog post will be.

TITLE RULES:
- Write the blog title in Title Case on the very first line — no label, no prefix, just the title itself
- Do NOT use ALL CAPS for the title or any heading
- Do NOT prefix the title with "BLOG TITLE:" or any label
- Do NOT include any year in the title

Return the outline in plain text using section headings and bulleted key points. Do not use numbers or Roman numerals.

Format:
Your Blog Title in Title Case Here

Main Heading
- Key point 1
- Key point 2
- Additional subtopics

Next Main Heading
- Key point 1
- Key point 2
PROMPT;

            $outline = bq_call_openai($outline_instruction, $item->title);
            if (is_wp_error($outline)) { ob_end_clean(); wp_send_json_error('Step 1 (Outline): ' . $outline->get_error_message()); }

            // Extract title
            $lines = explode("\n", trim($outline));
            $blog_title = '';
            foreach ($lines as $line) {
                $candidate = trim($line);
                if (empty($candidate)) continue;
                if (preg_match('/^(BLOG TITLE|Title|OUTLINE)\s*[::\-]?\s*$/i', $candidate)) continue;
                $blog_title = $candidate;
                break;
            }
            $blog_title = preg_replace('/^(BLOG TITLE|Title)\s*[::\-]\s*/i', '', $blog_title);
            $blog_title = preg_replace('/^\*{1,2}|\*{1,2}$/', '', $blog_title);
            $blog_title = preg_replace('/^#{1,3}\s*/', '', $blog_title);
            $blog_title = trim($blog_title, " \t\n\r\0\x0B\"'");
            if ($blog_title === mb_strtoupper($blog_title) && mb_strlen($blog_title) > 5) {
                $blog_title = mb_convert_case(mb_strtolower($blog_title), MB_CASE_TITLE);
            }
            if (empty($blog_title) || strlen($blog_title) > 200) $blog_title = $item->title;

            set_transient($progress_key, ['outline' => $outline, 'blog_title' => $blog_title, 'gap_category' => $item->gap_category ?? '', 'target_keywords' => $item->target_keywords ?? ''], 3600);
            ob_end_clean();
            wp_send_json_success(['step' => 1, 'blog_title' => $blog_title]);
            break;

        case 2: // Generate content
            $progress = get_transient($progress_key);
            if (!$progress) { ob_end_clean(); wp_send_json_error('Step 2: Lost progress data. Start over.'); }

            $blog_instruction = <<<PROMPT
You are a professional IT blog writer for {$site_name}. Your tone is direct, knowledgeable, and practical. You write for busy IT professionals who scan pages.

BANNED PHRASES — Do NOT use any of these:
- In today's rapidly evolving... / In an ever-changing landscape... / In the fast-paced world of...
- As technology continues to... / In today's digital age... / With the growing importance of...
- As organizations increasingly... / In the modern IT landscape...

IMPORTANT: Do NOT invent certification names, exam codes, or credential titles not in the outline.

WORD COUNT REQUIREMENT — THIS IS CRITICAL:
The blog post MUST be at least 2,000 words. This is a hard minimum — do NOT write fewer than 2,000 words.
- Each of the 6-8 main sections must be 200-350 words
- The introduction must be at least 150 words
- The conclusion must be at least 150 words
- Do NOT summarize or abbreviate sections. Write each section in full detail with specific examples, explanations, and actionable advice
- If the outline has 8 sections at 250 words each + intro + conclusion, that's 2,300 words. Hit that target.

Write the full blog post from the outline below. Cover EVERY section in the outline thoroughly.

OUTPUT FORMAT — CRITICAL:
- Return ONLY valid HTML. No Markdown (no #, **, -, ```)
- Use <h2> for main sections, <h3> for subsections
- Do NOT include an <h1> tag
- Wrap paragraphs in <p> tags — keep them SHORT (2-4 sentences max)
- Use <ul><li> or <ol><li> for lists
- Use <strong> to bold key terms on first mention
- Use <blockquote> for 1-2 notable quotes or insights
- Use 1-3 callout boxes per post. ONLY use these exact classes (no variations):
  <div class="itu-callout itu-callout--tip"><p><strong>Pro Tip</strong></p><p>Content.</p></div>
  <div class="itu-callout itu-callout--info"><p><strong>Note</strong></p><p>Content.</p></div>
  <div class="itu-callout itu-callout--warning"><p><strong>Warning</strong></p><p>Content.</p></div>
  <div class="itu-callout itu-callout--key"><p><strong>Key Takeaway</strong></p><p>Content.</p></div>
- Use <table> ONLY for simple 2-column comparisons
- Every section must mix at least 2 format types (paragraphs + lists, paragraphs + callout, etc.)
- Include named entity "{$site_name}" where appropriate

DEPTH REQUIREMENTS:
- Do NOT write surface-level summaries. Go deep with specifics, examples, tool names, commands, or step-by-step details
- Each section should teach the reader something concrete they can apply immediately
- Include real-world scenarios, comparisons between approaches, or common mistakes to avoid
- When comparing options, actually compare them — don't just list them
- When explaining a concept, explain it fully enough that someone unfamiliar could understand it
- Do not say "we will include..." — actually write the content in full detail
- Introduction: hook with a specific problem or scenario, preview key takeaways
- Conclusion: summarize actionable points + include a call to action for {$site_name}
- Write like a real person. Vary sentence length. Mix short punchy sentences with detailed explanations.

AUTHORITATIVE REFERENCES AND DATA — REQUIRED (critical for credibility):
Every blog post MUST include at least 3-5 distinct authoritative references from DIFFERENT sources. A knowledgeable human author would research multiple sources — so must you. Do NOT rely on a single source like BLS for everything.

REQUIRED reference types (include ALL that apply to the topic):

1. GOVERNING BODIES & CERTIFICATION AUTHORITIES — Most important. If the topic relates to a certification or vendor technology, you MUST cite that vendor's official documentation:
   - CompTIA (comptia.org) — A+, Network+, Security+, CySA+, CASP+, PenTest+, Cloud+, Linux+, Data+
   - Cisco (cisco.com) — CCNA, CCNP, CCIE, networking architecture, IOS
   - Microsoft (learn.microsoft.com) — Azure, Microsoft 365, Windows Server, AZ-900, MS-900, SC-900
   - AWS (aws.amazon.com/certification/) — Solutions Architect, SysOps, Developer, Cloud Practitioner
   - ISC2 (isc2.org) — CISSP, CCSP, SSCP, cybersecurity governance
   - ISACA (isaca.org) — CISM, CISA, CRISC, COBIT, IT audit/governance
   - PMI (pmi.org) — PMP, CAPM, PMI-ACP, project management
   - EC-Council (eccouncil.org) — CEH, ethical hacking, penetration testing
   - Axelos/PeopleCert — ITIL, PRINCE2
   - Google Cloud (cloud.google.com/certification) — GCP certifications
   - Linux Foundation (linuxfoundation.org) — CKA, CKAD, Kubernetes, open source
   - Red Hat (redhat.com) — RHCSA, RHCE, enterprise Linux
   - VMware/Broadcom — virtualization, VCP certifications
   - Juniper Networks (juniper.net) — JNCIA, JNCIS, networking
   - Palo Alto Networks (paloaltonetworks.com) — PCNSA, PCNSE, next-gen firewall
   Example: "According to <a href="https://www.comptia.org/certifications/security" target="_blank" rel="noopener">CompTIA</a>, the Security+ SY0-701 exam covers five domains including Threats, Vulnerabilities, and Mitigations"

2. COMPLIANCE, REGULATORY & LEGAL FRAMEWORKS — Critical for governance, risk, and security topics:
   - NIST (nist.gov) — Cybersecurity Framework (CSF), SP 800 series, Risk Management Framework
   - ISO/IEC (iso.org) — ISO 27001, ISO 27002, ISO 20000, ISO 9001
   - PCI Security Standards Council (pcisecuritystandards.org) — PCI DSS compliance
   - HIPAA / HHS (hhs.gov) — healthcare data privacy and security
   - GDPR / European Data Protection Board (edpb.europa.eu) — EU data privacy regulations
   - SOC 2 / AICPA (aicpa.org) — audit and compliance standards
   - FedRAMP (fedramp.gov) — federal cloud security authorization
   - CMMC / DoD (dodcio.defense.gov) — defense contractor cybersecurity maturity
   - CISA (cisa.gov) — cybersecurity advisories, vulnerability alerts, best practices
   - SEC (sec.gov) — cybersecurity disclosure requirements for public companies
   - FERPA / Dept of Education (ed.gov) — education data privacy
   - CCPA / California AG (oag.ca.gov) — California consumer privacy
   - COBIT (isaca.org/cobit) — IT governance framework
   - HITRUST (hitrustalliance.net) — healthcare information security framework
   Example: "Organizations handling payment card data must comply with <a href="https://www.pcisecuritystandards.org/" target="_blank" rel="noopener">PCI DSS</a> requirements, which mandate encryption, access controls, and regular vulnerability assessments"

3. GOVERNMENT & WORKFORCE:
   - Bureau of Labor Statistics (bls.gov/ooh/) — salary data, job outlook, growth projections
   - DoD Cyber Workforce (public.cyber.mil) — DoD 8570/8140 workforce requirements
   - Dept of Homeland Security (dhs.gov) — critical infrastructure, national cyber strategy
   - NSA (nsa.gov) — CAE program, cryptography standards
   - FTC (ftc.gov) — data protection enforcement, consumer privacy
   - GAO (gao.gov) — government IT spending reports, cybersecurity audits
   - Dept of Labor (dol.gov) — apprenticeship programs, workforce development
   - National Science Foundation (nsf.gov) — STEM workforce data, research grants
   Example: "The <a href="https://www.bls.gov/ooh/computer-and-information-technology/" target="_blank" rel="noopener">Bureau of Labor Statistics</a> projects 15% growth for information security analysts through 2032"

4. PROFESSIONAL ASSOCIATIONS & WORKFORCE ORGANIZATIONS:
   - SHRM (shrm.org) — Society for Human Resource Management, HR technology, workforce trends, hiring data
   - ISSA (issa.org) — Information Systems Security Association, security career development
   - IAPP (iapp.org) — International Association of Privacy Professionals, CIPP/CIPM certifications
   - ACM (acm.org) — Association for Computing Machinery, computing research
   - IEEE (ieee.org) — technical standards, professional development
   - ITSMF (itsmf.org) — IT Service Management Forum, ITSM best practices
   - HDI (hdi.com) — Help Desk Institute, service desk and support management
   - Cloud Security Alliance (cloudsecurityalliance.org) — CCSK, cloud security best practices
   - InfraGard (infragard.org) — FBI/private sector cybersecurity partnership
   - AICPA (aicpa.org) — accounting/audit professionals, SOC reporting
   - World Economic Forum (weforum.org) — global technology and workforce reports
   - CompTIA Research (connect.comptia.org) — State of IT workforce reports, hiring trends
   - (ISC)² Cybersecurity Workforce Study — annual workforce gap data
   - NICE / NIST Workforce Framework (nist.gov/nice) — cybersecurity career pathways
   Example: "According to <a href="https://www.shrm.org/" target="_blank" rel="noopener">SHRM</a>, 68% of HR professionals report difficulty filling cybersecurity positions, making certified candidates significantly more competitive"

5. INDUSTRY RESEARCH, ANALYST FIRMS & THREAT DATA:
   - Gartner — market forecasts, Magic Quadrant, Hype Cycle
   - Forrester — tech adoption, ROI studies, Wave reports
   - IDC — market sizing, IT spending forecasts
   - McKinsey & Company — digital transformation, workforce trends
   - Deloitte — technology trends, cyber survey reports
   - PwC — Global Digital Trust Insights, cyber risk surveys
   - KPMG — technology risk, governance surveys
   - SANS Institute (sans.org) — security surveys, training research
   - Cybersecurity Ventures — cybercrime cost projections, workforce gap data
   - Verizon DBIR — annual breach investigation data
   - IBM Cost of a Data Breach Report — breach cost statistics
   - Ponemon Institute — privacy and security research
   - MITRE ATT&CK (attack.mitre.org) — adversary tactics and techniques
   - CrowdStrike Global Threat Report — annual threat landscape
   - Mandiant/Google Threat Intelligence — incident response data
   Example: "According to <a href="https://www.ibm.com/reports/data-breach" target="_blank" rel="noopener">IBM's Cost of a Data Breach Report</a>, the average breach cost reached $4.45 million in 2023"

6. TECHNICAL STANDARDS & DOCUMENTATION:
   - Official vendor docs (docs.aws.amazon.com, learn.microsoft.com, cisco.com/c/en/us/support/, etc.)
   - IETF RFCs (ietf.org) — networking protocols, internet standards
   - OWASP (owasp.org) — web application security, Top 10
   - CIS Benchmarks (cisecurity.org) — system hardening guides
   - W3C (w3.org) — web standards, accessibility (WCAG)
   - DMTF (dmtf.org) — systems management standards
   - Open Networking Foundation (opennetworking.org) — SDN standards
   - FIRST (first.org) — incident response, CVSS scoring
   Example: "The <a href="https://owasp.org/www-project-top-ten/" target="_blank" rel="noopener">OWASP Top 10</a> identifies injection attacks as a critical web application vulnerability"

7. SALARY, JOB MARKET & CAREER DATA — Use MULTIPLE sources, not just BLS:
   - Bureau of Labor Statistics (bls.gov), Glassdoor, PayScale, Robert Half Technology Salary Guide
   - Indeed, LinkedIn Salary Insights, Dice Tech Salary Report, Burning Glass/Lightcast (labor market analytics)
   - Global Knowledge IT Skills and Salary Report — cert-specific salary premiums
   - SHRM compensation data, Mercer salary surveys
   - CompTIA workforce reports — IT hiring manager perspectives
   Example: "Network engineers earn a median of $89,050 per <a href="https://www.bls.gov/ooh/computer-and-information-technology/network-and-computer-systems-administrators.htm" target="_blank" rel="noopener">BLS data</a>, while <a href="https://www.payscale.com/" target="_blank" rel="noopener">PayScale</a> reports a range of $75,000-$110,000 depending on experience and location"

CITATION RULES:
- Format as HTML links: <a href="URL" target="_blank" rel="noopener">Source Name</a>
- Spread references throughout the entire article — not bunched in one section
- Each major section (H2) should ideally contain at least one reference
- For salary/stats, always name the source AND general timeframe
- Do NOT fabricate specific page URLs you are unsure about — use the organization's main domain or well-known subpages you are confident exist
- NEVER reference, link to, or mention competing IT training providers, online course platforms, bootcamps, or training companies by name. This includes but is not limited to: Coursera, Udemy, Pluralsight, CBT Nuggets, Cybrary, LinkedIn Learning, A Cloud Guru, INE, Infosec Institute, Training Camp, Global Knowledge, Skillsoft, Simplilearn, KnowledgeHut, edX, Codecademy, DataCamp, or ANY other entity that sells IT training courses or certification prep. If you need to reference learning resources, reference the official vendor documentation (e.g., Microsoft Learn, AWS Skill Builder, Cisco Learning Network) instead — these are free resources from the governing bodies themselves, not competing training companies.
- VARY your sources — do not cite the same organization more than twice per article
- Include concrete data: salary ranges, job growth %, pass rates, market size, adoption rates, exam details, number of questions, passing scores
- When discussing a certification, always reference the official cert page for exam details (domains, question count, passing score, cost)
- Think like a human researcher: cross-reference claims, cite the governing body AND an independent source for the same data point when possible

AI SEARCH OPTIMIZATION — Structure content so AI search engines (Google AI Overview, Perplexity, ChatGPT) can cite it:
- Lead sections with clear, factual thesis statements that directly answer common questions
- Use definition-style sentences for key concepts (e.g., "SIEM is a security solution that..." not "Let's talk about SIEM")
- Include comparison tables and structured lists that AI can extract as direct answers
- Write FAQ-style subheadings that match natural language queries (e.g., "How Long Does It Take to Get CompTIA A+ Certified?")
- Provide specific, quotable sentences with concrete numbers — AI search engines prefer citing exact claims over vague statements
- Use <strong> on key facts and definitions to help parsers identify core claims
PROMPT;

            $kw_prompt = bq_get_gap_keywords_for_prompt($progress['gap_category'] ?? '');
            $target_kw = $progress['target_keywords'] ?? '';
            if (!empty($target_kw)) {
                $kw_prompt .= "\n\nSPECIFIC TARGET KEYWORDS FOR THIS BLOG POST:\n"
                    . $target_kw . "\n"
                    . "These keywords were specifically selected for this topic. Use them in the first paragraph, in at least one H2 heading, and naturally throughout the content (3-5 times each).";
            }
            $blog_content = bq_call_openai($blog_instruction . $kw_prompt, "Outline:\n" . $progress['outline']);
            if (is_wp_error($blog_content)) { ob_end_clean(); wp_send_json_error('Step 2 (Content): ' . $blog_content->get_error_message()); }

            // Fix markdown/callout issues
            $blog_content = preg_replace('/^####\s+(.+)$/m', '<h4>$1</h4>', $blog_content);
            $blog_content = preg_replace('/^###\s+(.+)$/m', '<h3>$1</h3>', $blog_content);
            $blog_content = preg_replace('/^##\s+(.+)$/m', '<h2>$1</h2>', $blog_content);
            $blog_content = preg_replace('/^#\s+(.+)$/m', '<h1>$1</h1>', $blog_content);
            $blog_content = preg_replace('/\*\*(.+?)\*\*/', '<strong>$1</strong>', $blog_content);
            $blog_content = preg_replace_callback('/itu-callout--([a-z\-]+)/', function ($m) {
                $valid = ['tip', 'info', 'warning', 'key'];
                $variant = $m[1];
                if (strpos($variant, 'tip') !== false) return 'itu-callout--tip';
                if (strpos($variant, 'info') !== false || strpos($variant, 'note') !== false) return 'itu-callout--info';
                if (strpos($variant, 'warn') !== false || strpos($variant, 'caution') !== false) return 'itu-callout--warning';
                if (strpos($variant, 'key') !== false || strpos($variant, 'purple') !== false || strpos($variant, 'important') !== false) return 'itu-callout--key';
                if (in_array($variant, $valid)) return 'itu-callout--' . $variant;
                return 'itu-callout--tip';
            }, $blog_content);

            // Create the WP post
            $post_id = wp_insert_post([
                'post_title'   => $progress['blog_title'],
                'post_content' => $blog_content,
                'post_status'  => 'publish',
                'post_type'    => 'post',
                'post_author'  => 1,
            ]);
            if (is_wp_error($post_id)) { ob_end_clean(); wp_send_json_error('Step 2 (Insert): ' . $post_id->get_error_message()); }

            $blog_cat = get_category_by_slug('blogs');
            if (!$blog_cat) $blog_cat = get_category_by_slug('blog');
            if ($blog_cat) wp_set_post_categories($post_id, [$blog_cat->term_id]);

            $progress['post_id'] = $post_id;
            $progress['blog_content'] = $blog_content;
            set_transient($progress_key, $progress, 3600);
            ob_end_clean();
            wp_send_json_success(['step' => 2, 'post_id' => $post_id]);
            break;

        case 3: // Meta, keyword, SEO title
            $progress = get_transient($progress_key);
            if (!$progress || empty($progress['post_id'])) { ob_end_clean(); wp_send_json_error('Step 3: Lost progress data.'); }

            $post_id = $progress['post_id'];
            $blog_title = $progress['blog_title'];
            $snippet = mb_substr(wp_strip_all_tags($progress['blog_content']), 0, 500);

            $meta_instruction = "Write a meta description for this blog post. Rules: 1-2 sentences, 140-155 characters, start with an action verb, do not use quotes. Do NOT invent certifications.";
            $meta_desc = bq_call_openai($meta_instruction, "Title: {$blog_title}\n\nContent:\n{$snippet}", 'gpt-4.1-nano', 0.4);
            if (!is_wp_error($meta_desc)) {
                $meta_desc = wp_strip_all_tags(trim($meta_desc));
                wp_update_post(['ID' => $post_id, 'post_excerpt' => $meta_desc]);
                update_post_meta($post_id, 'rank_math_description', $meta_desc);
            }

            $kw_instruction = "Return a single primary focus keyword (2-4 words) for this blog post. Something people would search for. Return ONLY the keyword.";
            $keyword = bq_call_openai($kw_instruction, "Title: {$blog_title}\n\nContent:\n{$snippet}", 'gpt-4.1-nano', 0.3);
            if (!is_wp_error($keyword)) {
                update_post_meta($post_id, 'rank_math_focus_keyword', sanitize_text_field(trim($keyword)));
            }

            $seo_instruction = "Write an SEO title for this blog post. Max 60 characters. Include the focus keyword near the beginning. End with ' - {$site_name}'. Return ONLY the title.";
            $seo_title = bq_call_openai($seo_instruction, "Title: {$blog_title}\nKeyword: {$keyword}", 'gpt-4.1-nano', 0.5);
            if (!is_wp_error($seo_title)) {
                $seo_title = sanitize_text_field(trim($seo_title));
                if (mb_strlen($seo_title) <= 70) {
                    update_post_meta($post_id, 'rank_math_title', $seo_title);
                }
            }

            set_transient($progress_key, $progress, 3600);
            ob_end_clean();
            wp_send_json_success(['step' => 3]);
            break;

        case 4: // FAQ HTML + JSON-LD schema + finalize
            $progress = get_transient($progress_key);
            if (!$progress || empty($progress['post_id'])) { ob_end_clean(); wp_send_json_error('Step 4: Lost progress data.'); }

            $post_id = $progress['post_id'];
            $blog_title = $progress['blog_title'];
            $snippet = mb_substr(wp_strip_all_tags($progress['blog_content']), 0, 500);

            $faq_instruction = "Generate 5 FAQ entries for this blog post. Format:\n<details><summary>Question?</summary><div class=\"faq-content\">\n<p>Paragraph 1.</p>\n<p>Paragraph 2.</p>\n</div></details>\n\nCRITICAL: Wrap ALL answer text in <p> tags. Each answer 200+ words, 2-4 paragraphs. Do NOT invent certifications.";
            $faq_html = bq_call_openai($faq_instruction, "Title: {$blog_title}\n\nContent:\n{$snippet}");
            if (!is_wp_error($faq_html) && function_exists('update_field')) {
                $faq_html = preg_replace_callback('/<div class="faq-content">(.*?)<\/div>/s', function ($m) {
                    $c = trim($m[1]);
                    if (stripos($c, '<p>') !== false) return $m[0];
                    $sents = preg_split('/(?<=[.!?])\s+/', $c);
                    $chunks = []; $cur = '';
                    foreach ($sents as $i => $s) {
                        $cur .= ($cur ? ' ' : '') . $s;
                        if (($i + 1) % 3 === 0 || $i === count($sents) - 1) { $chunks[] = $cur; $cur = ''; }
                    }
                    $w = '';
                    foreach ($chunks as $ch) { $ch = trim($ch); if ($ch) $w .= '<p>' . $ch . "</p>\n"; }
                    return '<div class="faq-content">' . "\n" . $w . '</div>';
                }, $faq_html);

                update_field('field_6816a44480234', $faq_html, $post_id);

                $schema_instruction = "Convert the following HTML FAQ into a valid JSON-LD FAQPage schema. Return ONLY the raw JSON object — do NOT wrap it in <script> tags. Pretty-print the JSON. Input HTML:\n\n" . $faq_html;
                $faq_json = bq_call_openai($schema_instruction, null, 'gpt-4.1-nano', 0.3);
                if (!is_wp_error($faq_json)) {
                    $faq_json = preg_replace('/^```[a-zA-Z]*\s*/m', '', $faq_json);
                    $faq_json = preg_replace('/\s*```\s*$/m', '', $faq_json);
                    $faq_json = preg_replace('/<script[^>]*>\s*/i', '', $faq_json);
                    $faq_json = preg_replace('/\s*<\/script>/i', '', $faq_json);
                    $faq_json = trim($faq_json);
                    update_field('field_6816d54e3951d', $faq_json, $post_id);
                }
            }

            update_post_meta($post_id, 'last_page_refresh', current_time('mysql'));

            // Stamp gap keywords as used
            bq_stamp_gap_keywords_used($progress['gap_category'] ?? '', $post_id);

            // Mark queue item complete
            $wpdb->update($wpdb->prefix . 'bq_queue', [
                'status' => 'completed', 'post_id' => $post_id, 'processed_at' => current_time('mysql')
            ], ['id' => $id]);
            $wpdb->insert($wpdb->prefix . 'bq_history', [
                'title' => $item->title, 'post_id' => $post_id, 'status' => 'completed', 'processed_at' => current_time('mysql')
            ]);

            // Send notification email
            $bq_settings = bq_get_settings();
            if ($bq_settings['notify_email']) {
                bq_send_notification($bq_settings['notify_email'], $progress['blog_title'], 'completed', $post_id, get_permalink($post_id));
            }

            delete_transient($progress_key);
            ob_end_clean();
            wp_send_json_success([
                'step'     => 4,
                'complete' => true,
                'post_id'  => $post_id,
                'url'      => get_permalink($post_id),
                'edit_url' => admin_url('post.php?action=edit&post=' . $post_id),
            ]);
            break;

        default:
            ob_end_clean();
            wp_send_json_error('Invalid step.');
    }
});

add_action('wp_ajax_bq_save_settings', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $new = [
        'daily_limit'  => max(1, intval($_POST['daily_limit'] ?? 5)),
        'notify_email' => sanitize_email($_POST['notify_email'] ?? ''),
        'enabled'      => ($_POST['enabled'] ?? '0') === '1',
    ];
    update_option('bq_settings', $new);
    wp_send_json_success();
});

add_action('wp_ajax_bq_run_queue', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(1200);

    // Force = true bypasses the "enabled" check for manual triggers
    bq_process_queue(true);

    // Return what happened
    global $wpdb;
    $today_count = (int) get_option('bq_daily_count_' . date('Y-m-d'), 0);
    $recent = $wpdb->get_results("
        SELECT * FROM {$wpdb->prefix}bq_history
        WHERE DATE(processed_at) = CURDATE()
        ORDER BY processed_at DESC LIMIT 20
    ");

    wp_send_json_success([
        'processed'   => count($recent),
        'today_count' => $today_count,
        'results'     => $recent,
    ]);
});

// Get keyword gap categories for the topic generator dropdown
add_action('wp_ajax_bq_get_gap_categories', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    global $wpdb;
    $table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$table}'") !== $table) {
        wp_send_json_success([]);
        return;
    }

    $cooldown = 90;
    if (function_exists('seom_get_settings')) {
        $settings = seom_get_settings();
        $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
    }

    $gap_kw_filter = sanitize_text_field($_POST['gap_kw_filter'] ?? 'all');
    $filter_clause = '';
    switch ($gap_kw_filter) {
        case 'high_volume':    $filter_clause = ' AND search_volume >= 1000'; break;
        case 'low_difficulty': $filter_clause = ' AND keyword_difficulty <= 30'; break;
        case 'quick_wins':     $filter_clause = ' AND search_volume >= 500 AND keyword_difficulty <= 40'; break;
    }

    $tags = $wpdb->get_results($wpdb->prepare("
        SELECT tag,
            COUNT(*) as cnt,
            SUM(CASE WHEN (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL %d DAY)){$filter_clause} THEN 1 ELSE 0 END) as available
        FROM {$table}
        WHERE tag IS NOT NULL AND tag != ''
        GROUP BY tag
        ORDER BY tag ASC
    ", $cooldown));

    wp_send_json_success($tags ?: []);
});

add_action('wp_ajax_bq_generate_topics', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(120);

    $focus_area    = sanitize_text_field($_POST['focus_area'] ?? '');
    $gap_category  = sanitize_text_field($_POST['gap_category'] ?? '');
    $gap_kw_filter = sanitize_text_field($_POST['gap_kw_filter'] ?? 'all');
    $count = min(50, max(5, intval($_POST['count'] ?? 20)));

    global $wpdb;

    // Get existing post titles to avoid duplication
    $existing_titles = $wpdb->get_col("
        SELECT LOWER(post_title) FROM {$wpdb->posts}
        WHERE post_type = 'post' AND post_status IN ('publish', 'draft', 'pending')
        ORDER BY RAND() LIMIT 500
    ");
    $titles_sample = implode("\n", array_slice($existing_titles, 0, 200));

    // Get pending queue titles too
    $queued_titles = $wpdb->get_col("SELECT LOWER(title) FROM {$wpdb->prefix}bq_queue WHERE status = 'pending'");
    if (!empty($queued_titles)) {
        $titles_sample .= "\n" . implode("\n", $queued_titles);
    }

    // Get top GSC keywords for content gap ideas
    $keyword_context = '';
    $kw_table = $wpdb->prefix . 'seom_keywords';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$kw_table}'") === $kw_table) {
        // Content gaps — keywords with impressions but no dedicated page
        // Use higher threshold and cross-check against existing titles to reduce false positives
        $gap_rows = $wpdb->get_results("
            SELECT keyword, impressions, avg_position FROM {$kw_table}
            WHERE is_content_gap = 1 AND impressions >= 30
            ORDER BY opportunity_score DESC LIMIT 50
        ");

        // Filter out gaps that are on cooldown or too similar to existing post titles
        $filtered_gaps = [];
        $existing_lower = array_map('strtolower', $existing_titles);
        foreach ($gap_rows as $g) {
            // Skip if this keyword was recently used in content
            if (bq_is_keyword_on_cooldown($g->keyword)) continue;

            // Skip if too similar to an existing post title (word overlap check)
            $gw = array_filter(explode(' ', strtolower($g->keyword)), function($w) { return strlen($w) > 2; });
            $dominated = false;
            foreach ($existing_lower as $et) {
                $tw = array_filter(explode(' ', $et), function($w) { return strlen($w) > 2; });
                $overlap = count(array_intersect($gw, $tw));
                if (!empty($gw) && $overlap >= ceil(count($gw) * 0.6)) {
                    $dominated = true;
                    break;
                }
            }
            if (!$dominated) {
                $filtered_gaps[] = $g->keyword . ' (' . $g->impressions . ' searches, pos ' . round($g->avg_position) . ')';
            }
            if (count($filtered_gaps) >= 20) break;
        }

        if (!empty($filtered_gaps)) {
            $keyword_context = "\n\nCONTENT GAP KEYWORDS — These are real Google searches where we appear but have NO dedicated blog post. Use these as inspiration for new angles, NOT direct title copies:\n" . implode("\n", $filtered_gaps);
        }

        // Rising keywords that ARE mapped to existing content (for supportive/adjacent content)
        $rising = $wpdb->get_col("
            SELECT keyword FROM {$kw_table}
            WHERE trend_direction = 'rising' AND impressions >= 10
            ORDER BY trend_pct DESC LIMIT 20
        ");
        if (!empty($rising)) {
            $keyword_context .= "\n\nTRENDING/RISING KEYWORDS — These searches are growing. Create content that targets these from a UNIQUE angle not already covered:\n" . implode(', ', $rising);
        }
    }

    // Keyword gap data — imported from SEMrush/Ahrefs competitive analysis
    $gap_context = '';
    $gap_table = $wpdb->prefix . 'seom_keyword_gaps';
    if ($wpdb->get_var("SHOW TABLES LIKE '{$gap_table}'") === $gap_table && !empty($gap_category)) {
        $cooldown = 90;
        if (function_exists('seom_get_settings')) {
            $settings = seom_get_settings();
            $cooldown = intval($settings['gap_keyword_cooldown'] ?? 90);
        }

        // Build filter clause
        $cooldown_clause = " AND (last_used_at IS NULL OR last_used_at <= DATE_SUB(CURDATE(), INTERVAL {$cooldown} DAY))";
        $filter_clause = '';
        switch ($gap_kw_filter) {
            case 'high_volume':    $filter_clause = ' AND search_volume >= 1000'; break;
            case 'low_difficulty': $filter_clause = ' AND keyword_difficulty <= 30'; break;
            case 'quick_wins':     $filter_clause = ' AND search_volume >= 500 AND keyword_difficulty <= 40'; break;
            case 'available':      $filter_clause = ''; break; // cooldown_clause already handles this
        }

        $gap_keywords = $wpdb->get_results($wpdb->prepare("
            SELECT keyword, search_volume, keyword_difficulty
            FROM {$gap_table}
            WHERE tag = %s AND your_position = 0
            {$cooldown_clause} {$filter_clause}
            ORDER BY search_volume DESC
            LIMIT 30
        ", $gap_category));

        // No silent fallback — if filter is strict and returns nothing, tell the AI exactly that
        $filter_label = '';
        switch ($gap_kw_filter) {
            case 'high_volume':    $filter_label = 'High Volume (1000+)'; break;
            case 'low_difficulty': $filter_label = 'Low Difficulty (≤30)'; break;
            case 'quick_wins':    $filter_label = 'Quick Wins (vol≥500, KD≤40)'; break;
        }

        // Pull any saved LSI keywords for the gap keywords in this category
        $lsi_context = '';
        $lsi_table = $wpdb->prefix . 'seom_lsi_keywords';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$lsi_table}'") === $lsi_table && !empty($gap_keywords)) {
            $seed_list = [];
            foreach ($gap_keywords as $gk) {
                $seed_list[] = strtolower($gk->keyword);
            }
            if (!empty($seed_list)) {
                $seed_placeholders = implode(',', array_fill(0, count($seed_list), '%s'));
                $lsi_rows = $wpdb->get_results($wpdb->prepare(
                    "SELECT seed_keyword, lsi_keyword FROM {$lsi_table} WHERE seed_keyword IN ({$seed_placeholders}) ORDER BY seed_keyword",
                    ...$seed_list
                ));
                if (!empty($lsi_rows)) {
                    $lsi_grouped = [];
                    foreach ($lsi_rows as $lr) {
                        $lsi_grouped[$lr->seed_keyword][] = $lr->lsi_keyword;
                    }
                    $lsi_lines = [];
                    foreach ($lsi_grouped as $seed => $lsis) {
                        $lsi_lines[] = "  {$seed} → " . implode(', ', $lsis);
                    }
                    $lsi_context = "\n\nPREVIOUSLY DISCOVERED LSI KEYWORDS (use these to create unique topics — each topic should target different LSI variants):\n" . implode("\n", $lsi_lines);
                }
            }
        }

        $kw_count = count($gap_keywords);

        // Build context — always enforce category
        $gap_context = "\n\nCATEGORY FOCUS: {$gap_category}\n"
            . "ALL blog topics MUST be directly relevant to \"{$gap_category}\" — do NOT generate topics outside this category.\n"
            . "Every title must pass the test: 'Would someone searching for \"{$gap_category}\" topics find this relevant?'\n";

        if ($kw_count > 0) {
            $gap_list = [];
            foreach ($gap_keywords as $gk) {
                $gap_list[] = $gk->keyword . ' (vol: ' . $gk->search_volume . ', KD: ' . $gk->keyword_difficulty . ')';
            }

            $gap_context .= "\nAVAILABLE TARGET KEYWORDS ({$kw_count}):\n"
                . implode("\n", $gap_list) . "\n"
                . $lsi_context . "\n\n"
                . "TOPIC GENERATION STRATEGY:\n"
                . "- You have {$kw_count} primary keyword(s) and the user wants up to {$count} topics\n"
                . "- EACH topic MUST target a UNIQUE primary keyword or unique LSI/long-tail variation — no two topics should compete for the same search query\n"
                . "- For each primary keyword, you can create MULTIPLE topics using different angles (how-to, comparison, career guide, deep-dive, best practices) BUT each must target a different long-tail keyword\n"
                . "- Example: primary keyword 'cissp' → Topic 1 targets 'cissp exam requirements', Topic 2 targets 'cissp vs cism comparison', Topic 3 targets 'cissp study plan'\n"
                . "- Generate LSI and long-tail keywords for each primary keyword to differentiate topics\n"
                . "- Do NOT create topics with overlapping keyword targets — each blog should rank for its own unique search queries\n"
                . "- Return UP TO {$count} topics, but ONLY if each has a genuinely unique keyword target. If you can only find " . max($kw_count, min($count, $kw_count * 4)) . " unique angles, return that many\n"
                . "- Do NOT pad the list with generic or off-category topics just to reach {$count}";
        } else {
            $filter_msg = $filter_label ? " with filter \"{$filter_label}\"" : '';
            $gap_context .= "\nNo keywords matched{$filter_msg} in category \"{$gap_category}\".\n"
                . "Generate up to {$count} topics based on the category name \"{$gap_category}\" using your knowledge.\n"
                . "For each topic, find relevant long-tail keywords that someone in this field would search for.\n"
                . "Each topic must target different keywords — no overlap.";
        }
    }

    // Get product titles for course-related topic ideas
    $products = $wpdb->get_col("
        SELECT post_title FROM {$wpdb->posts}
        WHERE post_type = 'product' AND post_status = 'publish'
        ORDER BY RAND() LIMIT 50
    ");
    $product_context = '';
    if (!empty($products)) {
        $product_context = "\n\nOUR IT TRAINING COURSES (for reference — create supporting blog content around these topics):\n" . implode(', ', array_slice($products, 0, 30));
    }

    $focus_instruction = '';
    if (!empty($focus_area)) {
        $focus_instruction = "\n\nFOCUS AREA: The user wants topics specifically about: {$focus_area}. Prioritize this area but still ensure variety.";
    }

    $site_name = get_bloginfo('name');
    $instruction = <<<PROMPT
You are a content strategist for {$site_name}, an IT training and certification company. Generate exactly {$count} unique blog post titles.

RULES:
- Each title must be specific, actionable, and SEO-friendly
- Target IT professionals, students, and career changers
- Mix these content types: how-to guides, comparison posts, career guides, technical deep-dives, certification prep, tool reviews, best practices, trend analysis
- CRITICAL: Do NOT generate titles that cover the same topic as any existing post below, even from a different angle. If a topic is already covered, skip it entirely and find a genuinely NEW topic
- Content gap keywords are suggestions, NOT mandatory titles. Use them for inspiration but ensure the blog would cover something meaningfully different from existing content
- Do NOT use generic/vague titles — be specific about the technology, tool, or concept
- Do NOT start titles with "The Ultimate Guide to..." or "Everything You Need to Know About..."
- Do NOT include any year in blog titles (no 2024, 2025, 2026, etc.) — years immediately date content and hurt long-term SEO value
- Use title case
- Return each title on its own line in this format: Title Here | primary keyword, lsi keyword 1, lsi keyword 2, long-tail keyword
- After the pipe, include 3-5 keywords: the primary target keyword from the gap list PLUS 2-4 LSI/long-tail keywords that are semantically related and would strengthen the blog's ranking
- LSI keywords should be terms someone searching for the primary keyword might also search for (e.g., primary "cissp certification" → LSI: "cissp exam cost", "cissp salary", "cissp study guide", "information security certification")
- Prefer long-tail keywords (3-5 words) as they are easier to rank for
- Each blog topic should target DIFFERENT primary keywords — do NOT reuse the same primary keyword across multiple titles
- If no specific keywords apply, just return the title with no pipe
{$focus_instruction}
{$gap_context}
{$keyword_context}
{$product_context}

EXISTING BLOG TITLES (avoid duplicating or closely matching these):
{$titles_sample}
PROMPT;

    $result = bq_call_openai($instruction, "Generate {$count} unique IT blog post titles.", 'gpt-4.1-nano', 0.9);

    if (is_wp_error($result)) wp_send_json_error($result->get_error_message());

    // Parse titles and keywords from response
    $lines = array_filter(array_map('trim', explode("\n", $result)));
    $titles = [];
    foreach ($lines as $line) {
        $line = preg_replace('/^\d+[\.\)]\s*/', '', $line);
        $line = preg_replace('/^[-*•]\s*/', '', $line);
        $line = trim($line, '"\'');
        $line = trim($line);

        // Split on pipe to extract keywords
        $title = $line;
        $keywords = '';
        if (strpos($line, '|') !== false) {
            $parts = explode('|', $line, 2);
            $title = trim($parts[0], " \t\"'");
            $keywords = trim($parts[1] ?? '');
        }

        if (mb_strlen($title) >= 10 && mb_strlen($title) <= 200) {
            $titles[] = ['title' => $title, 'keywords' => $keywords];
        }
    }

    wp_send_json_success(['titles' => $titles, 'count' => count($titles)]);
});

// ─── All Blogs AJAX ─────────────────────────────────────────────────────────

add_action('wp_ajax_bq_get_all_blogs', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');

    $page     = max(1, intval($_POST['page'] ?? 1));
    $per_page = 20;
    $offset   = ($page - 1) * $per_page;
    $search   = sanitize_text_field($_POST['search'] ?? '');
    $filter   = sanitize_text_field($_POST['filter'] ?? 'all');
    $orderby  = sanitize_text_field($_POST['orderby'] ?? 'modified');
    $order    = strtoupper(sanitize_text_field($_POST['order'] ?? 'DESC')) === 'ASC' ? 'ASC' : 'DESC';

    $args = [
        'post_type'      => 'post',
        'post_status'    => 'publish',
        'posts_per_page' => -1, // Fetch all for filtering, paginate after
        'orderby'        => $orderby === 'title' ? 'title' : ($orderby === 'date' ? 'date' : 'modified'),
        'order'          => $order,
    ];
    if (!empty($search)) {
        $args['s'] = $search;
    }

    $query = new WP_Query($args);
    $all_items = [];

    // Pre-fetch full refresh post IDs from SEO AI AutoPilot history (excludes meta_only)
    $full_refreshed_ids = [];
    if (in_array($filter, ['seo_full_refreshed', 'seo_not_full_refreshed'])) {
        global $wpdb;
        $history_table = $wpdb->prefix . 'seom_refresh_history';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$history_table}'") === $history_table) {
            $full_refreshed_ids = $wpdb->get_col(
                "SELECT DISTINCT post_id FROM {$history_table} WHERE refresh_type = 'full'"
            );
            $full_refreshed_ids = array_map('intval', $full_refreshed_ids);
        }
    }

    foreach ($query->posts as $post) {
        $content    = $post->post_content;
        $word_count = str_word_count(wp_strip_all_tags($content));

        $faq_html = '';
        $faq_json = '';
        if (function_exists('get_field')) {
            $faq_html = get_field('field_6816a44480234', $post->ID) ?: '';
            $faq_json = get_field('field_6816d54e3951d', $post->ID) ?: '';
        }

        $has_faq      = !empty(trim(strip_tags($faq_html)));
        $has_schema   = !empty(trim(strip_tags($faq_json)));
        $has_image    = has_post_thumbnail($post->ID);
        $meta_desc    = get_post_meta($post->ID, 'rank_math_description', true);
        $has_meta     = !empty(trim($meta_desc));
        $focus_kw     = get_post_meta($post->ID, 'rank_math_focus_keyword', true);
        $has_keyword  = !empty(trim($focus_kw));
        $last_refresh = get_post_meta($post->ID, 'last_page_refresh', true);

        // Apply filters
        if ($filter === 'missing_faq' && $has_faq) continue;
        if ($filter === 'missing_schema' && $has_schema) continue;
        if ($filter === 'missing_image' && $has_image) continue;
        if ($filter === 'missing_meta' && $has_meta) continue;
        if ($filter === 'missing_keyword' && $has_keyword) continue;
        if ($filter === 'short_content' && $word_count >= 1000) continue;
        if ($filter === 'seo_full_refreshed' && !in_array($post->ID, $full_refreshed_ids)) continue;
        if ($filter === 'seo_not_full_refreshed' && in_array($post->ID, $full_refreshed_ids)) continue;

        $thumb_url = $has_image ? wp_get_attachment_image_url(get_post_thumbnail_id($post->ID), 'thumbnail') : '';

        $all_items[] = [
            'ID'           => $post->ID,
            'title'        => $post->post_title,
            'word_count'   => $word_count,
            'has_faq'      => $has_faq,
            'has_schema'   => $has_schema,
            'has_image'    => $has_image,
            'has_meta'     => $has_meta,
            'has_keyword'  => $has_keyword,
            'thumb_url'    => $thumb_url,
            'last_refresh' => $last_refresh ?: '',
            'modified'     => get_the_modified_date('Y-m-d H:i', $post->ID),
            'edit_url'     => get_edit_post_link($post->ID, 'raw'),
            'view_url'     => get_permalink($post->ID),
        ];
    }

    $total = count($all_items);
    $paged = array_slice($all_items, $offset, $per_page);

    wp_send_json_success([
        'rows'  => $paged,
        'total' => $total,
        'page'  => $page,
        'pages' => ceil($total / $per_page),
    ]);
});

// ─── Blog Refresh (single post) ─────────────────────────────────────────────

add_action('wp_ajax_bq_refresh_blog', function () {
    check_ajax_referer('bq_nonce', 'nonce');
    if (!current_user_can('manage_options')) wp_send_json_error('Permission denied.');
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
    @set_time_limit(600);

    // Catch fatal errors so they return JSON instead of a blank 500
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            if (!headers_sent()) {
                header('Content-Type: application/json');
                echo json_encode([
                    'success' => false,
                    'data'    => 'PHP Fatal: ' . $error['message'] . ' in ' . basename($error['file']) . ':' . $error['line'],
                ]);
            }
        }
    });

    // Buffer output to prevent stray warnings from corrupting JSON
    ob_start();

    $post_id = intval($_POST['post_id'] ?? 0);
    $type    = sanitize_text_field($_POST['type'] ?? 'full');

    if (!$post_id || get_post_type($post_id) !== 'post') {
        ob_end_clean();
        wp_send_json_error('Invalid post ID.');
    }

    // Use SEO AI AutoPilot's Blog Refresher if available
    if (class_exists('SEOM_Blog_Refresher') && SEOM_Blog_Refresher::is_available()) {
        // Get "before" metrics for history
        global $wpdb;
        $before = $wpdb->get_row($wpdb->prepare(
            "SELECT clicks, impressions, ctr, avg_position FROM {$wpdb->prefix}seom_page_metrics
             WHERE post_id = %d ORDER BY date_collected DESC LIMIT 1",
            $post_id
        ));

        // Map type labels for history
        $refresh_type_label = $type === 'meta' ? 'meta_only' : ($type === 'seo' ? 'seo_refresh' : 'full');

        if ($type === 'meta') {
            $result = SEOM_Blog_Refresher::meta_refresh($post_id);
        } elseif ($type === 'seo') {
            // SEO refresh: FAQ + Schema + Meta + Keyword + Title (no content rewrite)
            $faq = SEOM_Blog_Refresher::step_faq_html($post_id);
            if (!is_wp_error($faq)) {
                SEOM_Blog_Refresher::step_faq_json($post_id, $faq);
            }
            $meta = SEOM_Blog_Refresher::step_meta_description($post_id);
            if (is_wp_error($meta)) {
                ob_end_clean();
                wp_send_json_error($meta->get_error_message());
            }
            SEOM_Blog_Refresher::step_rankmath($post_id);
            SEOM_Blog_Refresher::step_seo_title($post_id);
            SEOM_Blog_Refresher::step_timestamp($post_id);
            $result = true;
        } else {
            $result = SEOM_Blog_Refresher::full_refresh($post_id);
        }

        $error = is_wp_error($result) ? $result->get_error_message() : null;

        // Record in SEO AI AutoPilot history
        $history_table = $wpdb->prefix . 'seom_refresh_history';
        if ($wpdb->get_var("SHOW TABLES LIKE '{$history_table}'") === $history_table) {
            $wpdb->insert($history_table, [
                'post_id'            => $post_id,
                'refresh_date'       => current_time('mysql'),
                'refresh_type'       => $refresh_type_label,
                'category'           => 'M', // Manual
                'priority_score'     => null,
                'clicks_before'      => $before->clicks ?? null,
                'impressions_before' => $before->impressions ?? null,
                'position_before'    => $before->avg_position ?? null,
                'ctr_before'         => $before->ctr ?? null,
            ]);
        }

        if ($error) {
            ob_end_clean();
            wp_send_json_error($error);
        }

        // Clear any buffered warnings before sending JSON
        ob_end_clean();

        // Return updated status for the row
        $faq_html = function_exists('get_field') ? (get_field('field_6816a44480234', $post_id) ?: '') : '';
        $faq_json = function_exists('get_field') ? (get_field('field_6816d54e3951d', $post_id) ?: '') : '';
        $content  = get_post_field('post_content', $post_id);

        wp_send_json_success([
            'post_id'     => $post_id,
            'word_count'  => str_word_count(wp_strip_all_tags($content)),
            'has_faq'     => !empty(trim(strip_tags($faq_html))),
            'has_schema'  => !empty(trim(strip_tags($faq_json))),
            'has_meta'    => !empty(trim(get_post_meta($post_id, 'rank_math_description', true))),
            'has_keyword' => !empty(trim(get_post_meta($post_id, 'rank_math_focus_keyword', true))),
            'modified'    => get_the_modified_date('Y-m-d H:i', $post_id),
        ]);
    }

    ob_end_clean();
    wp_send_json_error('SEO AI AutoPilot Blog Refresher not available. Activate the SEO AI AutoPilot plugin.');
});

// ─── Admin Page ──────────────────────────────────────────────────────────────

function bq_render_admin() {
    // Ensure tables exist
    global $wpdb;
    if ($wpdb->get_var("SHOW TABLES LIKE '{$wpdb->prefix}bq_queue'") !== $wpdb->prefix . 'bq_queue') {
        bq_create_tables();
    }

    $settings = bq_get_settings();
    $nonce = wp_create_nonce('bq_nonce');
    ?>
    <div class="wrap">
        <h1>Blog Queue</h1>

        <nav class="nav-tab-wrapper" style="border-bottom:2px solid #e2e8f0;margin-bottom:20px;">
            <a href="#" class="nav-tab nav-tab-active bq-tab" data-tab="add">Add Topics</a>
            <a href="#" class="nav-tab bq-tab" data-tab="queue">Queue <span id="bq-queue-count" style="background:#2563eb;color:#fff;padding:1px 8px;border-radius:10px;font-size:11px;margin-left:4px;"></span></a>
            <a href="#" class="nav-tab bq-tab" data-tab="blogs">All Blogs</a>
            <a href="#" class="nav-tab bq-tab" data-tab="history">History</a>
            <a href="#" class="nav-tab bq-tab" data-tab="settings">Settings</a>
        </nav>

        <!-- Add Topics Tab -->
        <div class="bq-panel active" id="bq-panel-add">
            <div style="display:flex;gap:32px;flex-wrap:wrap;">
                <div style="flex:1;min-width:350px;">
                    <h2>Add Blog Topics</h2>
                    <p style="color:#64748b;">Enter one blog topic per line, or use the AI generator to suggest topics.</p>
                    <textarea id="bq-topics" rows="15" style="width:100%;font-size:14px;padding:12px;border:1px solid #e2e8f0;border-radius:8px;" placeholder="Introduction to Cloud Computing for IT Professionals&#10;Top 10 Cybersecurity Certifications in 2026&#10;How to Build a Career in DevOps&#10;Understanding Zero Trust Security Architecture"></textarea>
                    <br>
                    <button type="button" class="button button-primary" id="bq-add-btn" style="margin-top:12px;">Add to Queue</button>
                    <span id="bq-add-status" style="margin-left:12px;"></span>
                </div>
                <div style="flex:1;min-width:350px;">
                    <h2>AI Topic Generator</h2>
                    <p style="color:#64748b;">Generate topic ideas based on your existing content, GSC keyword gaps, and trending searches. Avoids topics you've already covered.</p>
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:500;font-size:13px;display:block;margin-bottom:4px;">Keyword Gap Category (optional — uses imported SEMrush data):</label>
                        <div style="display:flex;gap:8px;">
                            <select id="bq-gap-category" style="flex:1;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;">
                                <option value="">None — use GSC data only</option>
                            </select>
                            <select id="bq-gap-kw-filter" style="padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;">
                                <option value="all">All Keywords</option>
                                <option value="high_volume">High Volume (1000+)</option>
                                <option value="low_difficulty">Low Difficulty (≤30)</option>
                                <option value="quick_wins" selected>Quick Wins (Vol 500+ & KD ≤40)</option>
                                <option value="available">Available (Not on Cooldown)</option>
                            </select>
                        </div>
                    </div>
                    <div style="margin-bottom:12px;">
                        <label style="font-weight:500;font-size:13px;display:block;margin-bottom:4px;">Focus area (optional):</label>
                        <input type="text" id="bq-focus-area" style="width:100%;padding:8px 12px;border:1px solid #e2e8f0;border-radius:6px;" placeholder="e.g., cybersecurity, cloud computing, CompTIA, DevOps" />
                    </div>
                    <div style="display:flex;gap:8px;align-items:center;margin-bottom:12px;">
                        <label style="font-weight:500;font-size:13px;">How many:</label>
                        <select id="bq-gen-count" style="padding:4px 8px;border:1px solid #e2e8f0;border-radius:6px;">
                            <option value="10">10</option>
                            <option value="20" selected>20</option>
                            <option value="30">30</option>
                            <option value="50">50</option>
                        </select>
                        <button type="button" class="button button-primary" id="bq-generate-btn">Generate Topics</button>
                    </div>
                    <div id="bq-gen-status" style="font-size:13px;margin-bottom:8px;"></div>
                    <div id="bq-gen-results" style="display:none;max-height:400px;overflow-y:auto;border:1px solid #e2e8f0;border-radius:8px;padding:4px;">
                        <div style="padding:8px 12px;background:#f8fafc;border-bottom:1px solid #e2e8f0;display:flex;justify-content:space-between;align-items:center;">
                            <label><input type="checkbox" id="bq-gen-check-all" checked /> <strong>Select All</strong></label>
                            <button type="button" class="button button-small" id="bq-gen-add-selected">Add Selected to Queue</button>
                        </div>
                        <div id="bq-gen-list"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Queue Tab -->
        <div class="bq-panel" id="bq-panel-queue">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <h2 style="margin:0;">Pending Queue</h2>
                <div style="display:flex;gap:8px;align-items:center;">
                    <span id="bq-today-info" style="color:#64748b;font-size:13px;"></span>
                    <button type="button" class="button button-primary" id="bq-run-queue">Process Queue (Background)</button>
                    <button type="button" class="button" id="bq-stop-queue-bg" style="color:#dc2626; display:none;">Stop Processing</button>
                </div>
                <div id="bq-queue-progress" style="display:none; margin-top:10px; padding:12px 16px; background:#fff; border:1px solid #e2e8f0; border-left:4px solid #2563eb; border-radius:0 8px 8px 0; font-size:13px;"></div>
            </div>
            <div id="bq-queue-bulk-bar" style="display:none;margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <select id="bq-queue-bulk-action" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;">
                    <option value="">Bulk Actions</option>
                    <option value="process">Process Selected</option>
                    <option value="delete">Delete Selected</option>
                </select>
                <button type="button" class="button" id="bq-queue-bulk-apply">Apply</button>
                <span id="bq-queue-bulk-status" style="font-size:12px;margin-left:8px;"></span>
            </div>
            <div id="bq-queue-loading">Loading...</div>
            <table class="wp-list-table widefat fixed striped" id="bq-queue-table" style="display:none;">
                <thead><tr><th style="width:30px;"><input type="checkbox" id="bq-queue-check-all" /></th><th>Title</th><th style="width:140px;">Added</th><th style="width:200px;">Actions</th></tr></thead>
                <tbody id="bq-queue-body"></tbody>
            </table>
            <div id="bq-queue-pagination" style="margin-top:12px;"></div>
            <div id="bq-queue-empty" style="display:none;color:#94a3b8;padding:20px;">Queue is empty. Add some topics.</div>
        </div>

        <!-- All Blogs Tab -->
        <div class="bq-panel" id="bq-panel-blogs">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px;flex-wrap:wrap;gap:8px;">
                <h2 style="margin:0;">All Blog Posts</h2>
                <div style="display:flex;gap:8px;align-items:center;">
                    <input type="text" id="bq-blog-search" placeholder="Search blogs..." style="padding:6px 12px;border:1px solid #e2e8f0;border-radius:6px;width:220px;" />
                    <select id="bq-blog-filter" style="padding:6px 8px;border:1px solid #e2e8f0;border-radius:6px;">
                        <option value="all">All</option>
                        <option value="missing_faq">Missing FAQ</option>
                        <option value="missing_schema">Missing Schema</option>
                        <option value="short_content">Short Content (&lt;1000 words)</option>
                        <option value="missing_image">Missing Image</option>
                        <option value="missing_meta">Missing Meta Desc</option>
                        <option value="missing_keyword">Missing Focus Keyword</option>
                        <option value="seo_full_refreshed">SEO AI AutoPilot: Full Refreshed</option>
                        <option value="seo_not_full_refreshed">SEO AI AutoPilot: Not Full Refreshed</option>
                    </select>
                </div>
            </div>
            <div id="bq-blog-bulk-bar" style="display:none;margin-bottom:12px;display:flex;gap:8px;align-items:center;flex-wrap:wrap;">
                <select id="bq-blog-bulk-action" style="padding:4px 8px;border-radius:6px;border:1px solid #e2e8f0;">
                    <option value="">Bulk Actions</option>
                    <option value="full_refresh">Full Refresh (Rewrite All)</option>
                    <option value="seo_refresh">SEO Refresh (FAQ, Schema, Meta, Keyword, Title)</option>
                    <option value="meta_refresh">CTR Fix (Meta, Keyword, Title only)</option>
                </select>
                <button type="button" class="button" id="bq-blog-bulk-apply">Apply</button>
                <span id="bq-blog-bulk-status" style="font-size:12px;margin-left:8px;"></span>
            </div>
            <div id="bq-blog-loading">Loading blogs...</div>
            <div id="bq-blog-empty" style="display:none;color:#94a3b8;padding:20px;">No blogs found.</div>
            <table class="wp-list-table widefat fixed striped" id="bq-blog-table" style="display:none;">
                <thead>
                    <tr>
                        <th style="width:30px;"><input type="checkbox" id="bq-blog-check-all" /></th>
                        <th style="width:60px;">Image</th>
                        <th data-sort="title" style="cursor:pointer;">Title</th>
                        <th style="width:80px;text-align:center;">Words</th>
                        <th style="width:70px;text-align:center;">FAQ</th>
                        <th style="width:70px;text-align:center;">Schema</th>
                        <th style="width:70px;text-align:center;">Meta</th>
                        <th style="width:70px;text-align:center;">Keyword</th>
                        <th data-sort="modified" style="width:110px;cursor:pointer;">Modified</th>
                        <th style="width:180px;">Actions</th>
                    </tr>
                </thead>
                <tbody id="bq-blog-body"></tbody>
            </table>
            <div id="bq-blog-pagination" style="margin-top:12px;"></div>
        </div>

        <!-- History Tab -->
        <div class="bq-panel" id="bq-panel-history">
            <h2>Creation History</h2>
            <div id="bq-history-loading">Loading...</div>
            <table class="wp-list-table widefat fixed striped" id="bq-history-table" style="display:none;">
                <thead><tr><th>Title</th><th style="width:100px;">Status</th><th style="width:140px;">Created</th><th style="width:200px;">Actions</th></tr></thead>
                <tbody id="bq-history-body"></tbody>
            </table>
            <div id="bq-history-pagination" style="margin-top:12px;"></div>
        </div>

        <!-- Settings Tab -->
        <div class="bq-panel" id="bq-panel-settings">
            <h2>Settings</h2>
            <table class="form-table" style="max-width:600px;">
                <tr><th>Enabled</th><td><label><input type="checkbox" id="bq-enabled" <?php checked($settings['enabled']); ?> /> Enable automated daily processing</label></td></tr>
                <tr><th>Daily Limit</th><td><input type="number" id="bq-daily-limit" value="<?php echo intval($settings['daily_limit']); ?>" min="1" max="50" style="width:80px;" /><p class="description">Max blogs to create per day. Randomly selected from queue.</p></td></tr>
                <tr><th>Notification Email</th><td><input type="email" id="bq-notify-email" value="<?php echo esc_attr($settings['notify_email']); ?>" style="width:300px;" /></td></tr>
            </table>
            <p><button type="button" class="button button-primary" id="bq-save-settings">Save Settings</button> <span id="bq-settings-status"></span></p>
        </div>
    </div>

    <style>
        .bq-panel { display: none; }
        .bq-panel.active { display: block; }
        .nav-tab-active { border-bottom-color: #2563eb !important; color: #2563eb !important; }
        .bq-thumb { width:50px; height:50px; object-fit:cover; border-radius:4px; display:block; }
        .bq-thumb--empty {
            display:flex; align-items:center; justify-content:center;
            width:50px; height:50px; background:#f0f0f0; border:1px dashed #ccc;
            border-radius:4px; font-size:10px; color:#999; text-align:center; line-height:1.2;
        }
        .bq-check-yes { color:#16a34a; font-size:18px; }
        .bq-check-no { color:#dc2626; font-size:18px; }
        .bq-word-good { color:#16a34a; font-weight:600; }
        .bq-word-ok { color:#d97706; font-weight:600; }
        .bq-word-low { color:#dc2626; font-weight:600; }
        .bq-blog-status { font-size:12px; color:#666; margin-top:4px; min-height:18px; }
        .bq-blog-status.processing { color:#b45309; }
        .bq-blog-status.done { color:#16a34a; }
        .bq-blog-status.error { color:#dc2626; }
        #bq-blog-table td { vertical-align:middle; }
    </style>

    <script>
    jQuery(document).ready(function($) {
        var nonce = '<?php echo $nonce; ?>';

        // Tabs
        $('.bq-tab').click(function(e) {
            e.preventDefault();
            $('.bq-tab').removeClass('nav-tab-active');
            $(this).addClass('nav-tab-active');
            $('.bq-panel').removeClass('active');
            $('#bq-panel-' + $(this).data('tab')).addClass('active');
            if ($(this).data('tab') === 'queue') loadQueue(1);
            if ($(this).data('tab') === 'blogs') loadBlogs(1);
            if ($(this).data('tab') === 'history') loadHistory(1);
        });

        // Add topics
        $('#bq-add-btn').click(function() {
            var btn = $(this).prop('disabled', true).text('Adding...');
            $.post(ajaxurl, { action: 'bq_add_topics', nonce: nonce, topics: $('#bq-topics').val() }, function(resp) {
                btn.prop('disabled', false).text('Add to Queue');
                if (resp.success) {
                    $('#bq-add-status').html('<span style="color:#059669;">' + resp.data.added + ' topics added to queue.</span>');
                    $('#bq-topics').val('');
                    loadQueueCount();
                } else {
                    $('#bq-add-status').html('<span style="color:#dc2626;">' + (resp.data || 'Error') + '</span>');
                }
            });
        });

        // Load keyword gap categories for the dropdown (filter-aware)
        function loadGapCategories() {
            var currentVal = $('#bq-gap-category').val();
            var filterVal = $('#bq-gap-kw-filter').val() || 'all';
            $.post(ajaxurl, { action: 'bq_get_gap_categories', nonce: nonce, gap_kw_filter: filterVal }, function(resp) {
                $('#bq-gap-category').find('option:not(:first)').remove();
                if (resp.success && resp.data.length) {
                    resp.data.forEach(function(t) {
                        var avail = parseInt(t.available || 0);
                        var total = parseInt(t.cnt || 0);
                        var label = t.tag + ' (' + avail + ' of ' + total + ' available)';
                        var selected = (t.tag === currentVal) ? ' selected' : '';
                        $('#bq-gap-category').append('<option value="' + t.tag + '"' + selected + (avail === 0 ? ' style="color:#94a3b8;"' : '') + '>' + label + '</option>');
                    });
                }
            });
        }
        loadGapCategories();

        // Reload category counts when volume filter changes
        $('#bq-gap-kw-filter').change(function() { loadGapCategories(); });

        // AI Topic Generator
        $('#bq-generate-btn').click(function() {
            var btn = $(this).prop('disabled', true).text('Generating...');
            $('#bq-gen-status').html('<span style="color:#d97706;">Analyzing your existing content, GSC keywords, and trends... (10-20 seconds)</span>');
            $('#bq-gen-results').hide();

            $.ajax({ url: ajaxurl, method: 'POST', timeout: 120000,
                data: {
                    action: 'bq_generate_topics', nonce: nonce,
                    focus_area: $('#bq-focus-area').val(),
                    gap_category: $('#bq-gap-category').val(),
                    gap_kw_filter: $('#bq-gap-kw-filter').val(),
                    count: $('#bq-gen-count').val()
                },
                success: function(resp) {
                    btn.prop('disabled', false).text('Generate Topics');
                    if (resp.success && resp.data.titles.length) {
                        $('#bq-gen-status').html('<span style="color:#059669;">' + resp.data.count + ' topics generated. Select the ones you want and add to queue.</span>');
                        var list = $('#bq-gen-list').empty();
                        resp.data.titles.forEach(function(t, i) {
                            var title = typeof t === 'object' ? t.title : t;
                            var kws = typeof t === 'object' ? (t.keywords || '') : '';
                            var kwBadge = kws ? '<div style="font-size:11px;color:#64748b;margin-left:24px;margin-top:2px;">Keywords: <span style="color:#2563eb;">' + kws + '</span></div>' : '';
                            list.append('<label style="display:block;padding:8px 12px;border-bottom:1px solid #f1f5f9;cursor:pointer;" onmouseover="this.style.background=\'#f8fafc\'" onmouseout="this.style.background=\'\'"><input type="checkbox" class="bq-gen-cb" checked data-title="' + title.replace(/"/g, '&quot;') + '" data-keywords="' + kws.replace(/"/g, '&quot;') + '" /> ' + title + kwBadge + '</label>');
                        });
                        $('#bq-gen-results').show();
                    } else {
                        $('#bq-gen-status').html('<span style="color:#dc2626;">Error: ' + (resp.data || 'No topics generated') + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Generate Topics');
                    $('#bq-gen-status').html('<span style="color:#dc2626;">Server error/timeout.</span>');
                }
            });
        });

        $('#bq-gen-check-all').change(function() {
            $('.bq-gen-cb').prop('checked', $(this).prop('checked'));
        });

        $('#bq-gen-add-selected').click(function() {
            var selected = [];
            $('.bq-gen-cb:checked').each(function() {
                selected.push({ title: $(this).data('title'), keywords: $(this).data('keywords') || '' });
            });
            if (!selected.length) { alert('Select at least one topic.'); return; }

            var gapCat = $('#bq-gap-category').val() || '';
            var btn = $(this).prop('disabled', true).text('Adding...');
            $.post(ajaxurl, { action: 'bq_add_topics', nonce: nonce, items: JSON.stringify(selected), gap_category: gapCat }, function(resp) {
                btn.prop('disabled', false).text('Add Selected to Queue');
                if (resp.success) {
                    $('#bq-gen-status').html('<span style="color:#059669;">' + resp.data.added + ' topics added to queue!</span>');
                    $('#bq-gen-results').hide();
                    loadQueueCount();
                }
            });
        });

        // Queue
        function loadQueueCount() {
            $.post(ajaxurl, { action: 'bq_get_queue', nonce: nonce, page: 1 }, function(resp) {
                if (resp.success) $('#bq-queue-count').text(resp.data.total || '');
            });
        }

        function loadQueue(page) {
            $('#bq-queue-loading').show(); $('#bq-queue-table').hide(); $('#bq-queue-empty').hide();
            $.post(ajaxurl, { action: 'bq_get_queue', nonce: nonce, page: page || 1 }, function(resp) {
                $('#bq-queue-loading').hide();
                if (!resp.success || !resp.data.rows.length) { $('#bq-queue-empty').show(); return; }
                var d = resp.data;
                $('#bq-today-info').text('Created today: ' + d.today_count + ' / ' + d.daily_limit);
                var tbody = $('#bq-queue-body').empty();
                d.rows.forEach(function(r) {
                    var gapBadge = r.gap_category ? '<span style="display:inline-block;margin-top:4px;background:#eff6ff;color:#2563eb;font-size:10px;padding:2px 6px;border-radius:3px;">' + r.gap_category + '</span>' : '';
                    var kwBadge = r.target_keywords ? '<div style="font-size:10px;color:#64748b;margin-top:2px;">Keywords: <span style="color:#059669;">' + r.target_keywords + '</span></div>' : '';
                    tbody.append('<tr data-id="' + r.id + '">'
                        + '<td><input type="checkbox" class="bq-queue-cb" value="' + r.id + '" /></td>'
                        + '<td>' + r.title + ' ' + gapBadge + kwBadge + '<div class="bq-queue-status" data-id="' + r.id + '" style="font-size:12px;margin-top:4px;min-height:16px;"></div></td>'
                        + '<td style="font-size:12px;color:#94a3b8;">' + (r.added_at || '').substring(0, 10) + '</td>'
                        + '<td>'
                        + '<button class="button button-small bq-process-one" data-id="' + r.id + '">Create Now</button> '
                        + '<button class="button button-small bq-remove" data-id="' + r.id + '" style="color:#dc2626;">Remove</button>'
                        + '</td></tr>');
                });
                $('#bq-queue-table, #bq-queue-bulk-bar').show();
                var pag = $('#bq-queue-pagination').empty();
                if (d.pages > 1) for (var i = 1; i <= d.pages; i++) pag.append('<button class="' + (i === d.page ? 'button button-primary' : 'button') + ' bq-queue-page" data-page="' + i + '">' + i + '</button> ');
            });
        }

        $(document).on('click', '.bq-queue-page', function() { loadQueue($(this).data('page')); });
        $(document).on('click', '.bq-remove', function() {
            var row = $(this).closest('tr');
            $.post(ajaxurl, { action: 'bq_remove_item', nonce: nonce, id: $(this).data('id') }, function(resp) { if (resp.success) { row.fadeOut(); loadQueueCount(); } });
        });

        // Queue select all
        $(document).on('change', '#bq-queue-check-all', function() {
            $('.bq-queue-cb').prop('checked', $(this).prop('checked'));
        });

        // Queue bulk apply
        $('#bq-queue-bulk-apply').click(function() {
            var action = $('#bq-queue-bulk-action').val();
            if (!action) { alert('Select a bulk action.'); return; }

            var ids = [];
            $('.bq-queue-cb:checked').each(function() { ids.push(parseInt($(this).val())); });
            if (!ids.length) { alert('Select at least one item.'); return; }

            if (action === 'delete') {
                if (!confirm('Delete ' + ids.length + ' item(s) from the queue?')) return;
                $.post(ajaxurl, { action: 'bq_bulk_delete', nonce: nonce, 'ids[]': ids }, function(resp) {
                    if (resp.success) {
                        $('#bq-queue-bulk-status').html('<span style="color:#059669;">' + resp.data.deleted + ' deleted.</span>');
                        loadQueue(1); loadQueueCount();
                    }
                });
            } else if (action === 'process') {
                if (!confirm('Process ' + ids.length + ' item(s) now? This may take 1-2 minutes per blog.')) return;

                // Gather rows for selected IDs
                var items = [];
                ids.forEach(function(id) {
                    var row = $('tr[data-id="' + id + '"]');
                    items.push({ id: id, row: row });
                });

                // Shuffle for variety
                for (var i = items.length - 1; i > 0; i--) {
                    var j = Math.floor(Math.random() * (i + 1));
                    var tmp = items[i]; items[i] = items[j]; items[j] = tmp;
                }

                var completed = 0, failed = 0, total = items.length;
                $('#bq-queue-bulk-status').html('<span style="color:#b45309;">Processing 0/' + total + '...</span>');
                $('.bq-process-one, .bq-remove').prop('disabled', true);
                $('#bq-queue-bulk-apply').prop('disabled', true);

                function processNextBulk(index) {
                    if (index >= total) {
                        $('.bq-process-one, .bq-remove').prop('disabled', false);
                        $('#bq-queue-bulk-apply').prop('disabled', false);
                        $('#bq-queue-bulk-status').html('<span style="color:#059669;">Done: ' + completed + ' created, ' + failed + ' failed.</span>');
                        setTimeout(function() { loadQueue(1); loadQueueCount(); }, 2000);
                        return;
                    }
                    var item = items[index];
                    var statusEl = item.row.find('.bq-queue-status');
                    item.row.css('opacity', '0.7');
                    $('#bq-queue-bulk-status').html('<span style="color:#b45309;">Processing ' + (index + 1) + '/' + total + '...</span>');

                    runBlogSteps(item.id, statusEl, function(success, data) {
                        if (success) {
                            completed++;
                            item.row.css('opacity', '0.4');
                            statusEl.html('<span style="color:#059669;">&#10003; Created</span> <a href="' + data.url + '" target="_blank" style="font-size:12px;">View</a> | <a href="' + data.edit_url + '" target="_blank" style="font-size:12px;">Edit</a>');
                        } else {
                            failed++;
                            item.row.css('opacity', '1');
                        }
                        processNextBulk(index + 1);
                    });
                }
                processNextBulk(0);
            }
        });

        var stepLabels = {
            1: 'Generating outline...',
            2: 'Writing blog content...',
            3: 'SEO: meta, keyword, title...',
            4: 'Generating FAQ & schema...'
        };

        function runBlogSteps(queueId, statusEl, callback) {
            var currentStep = 1;
            var totalSteps = 4;

            function runStep() {
                if (currentStep > totalSteps) { callback(true); return; }
                statusEl.html('<span style="color:#b45309;">Step ' + currentStep + '/' + totalSteps + ': ' + stepLabels[currentStep] + '</span>');

                $.ajax({ url: ajaxurl, method: 'POST', timeout: 180000,
                    data: { action: 'bq_process_step', nonce: nonce, id: queueId, step: currentStep },
                    success: function(resp) {
                        if (resp.success) {
                            if (resp.data.complete) {
                                callback(true, resp.data);
                            } else {
                                currentStep++;
                                runStep();
                            }
                        } else {
                            statusEl.html('<span style="color:#dc2626;">&#10007; ' + (resp.data || 'Unknown error') + '</span>');
                            callback(false);
                        }
                    },
                    error: function(xhr, status) {
                        var msg = status === 'timeout' ? 'Timed out at step ' + currentStep : 'Server error (HTTP ' + (xhr.status || '?') + ') at step ' + currentStep + ': ' + stepLabels[currentStep];
                        statusEl.html('<span style="color:#dc2626;">&#10007; ' + msg + '</span>');
                        callback(false);
                    }
                });
            }
            runStep();
        }

        $(document).on('click', '.bq-process-one', function() {
            var btn = $(this).prop('disabled', true).text('Creating...');
            var row = btn.closest('tr');
            var statusEl = row.find('.bq-queue-status');
            row.css('opacity', '0.7');

            runBlogSteps(btn.data('id'), statusEl, function(success, data) {
                if (success) {
                    row.css('opacity', '0.4');
                    statusEl.html('<span style="color:#059669;">&#10003; Created</span> <a href="' + data.url + '" target="_blank" style="font-size:12px;">View</a> | <a href="' + data.edit_url + '" target="_blank" style="font-size:12px;">Edit</a>');
                    loadQueueCount();
                } else {
                    btn.prop('disabled', false).text('Retry');
                    row.css('opacity', '1');
                }
            });
        });

        var bqPollTimer = null;
        var bqRunning = false;

        // Start background processing — triggers server-side cron chain
        $('#bq-run-queue').click(function() {
            if (bqRunning) return;
            if (!confirm('Start processing the blog queue in the background? Processing continues even if you leave this page.')) return;
            $(this).prop('disabled', true).text('Starting...');
            $.post(ajaxurl, { action: 'bq_start_queue_processing', nonce: nonce }, function(resp) {
                if (resp.success) {
                    startBqPolling();
                } else {
                    $('#bq-run-queue').prop('disabled', false).text('Process Queue (Background)');
                    alert(resp.data || 'Error starting queue.');
                }
            });
        });

        // Stop background processing
        $('#bq-stop-queue-bg').click(function() {
            $(this).prop('disabled', true).text('Stopping...');
            $.post(ajaxurl, { action: 'bq_stop_queue', nonce: nonce }, function() {
                bqRunning = false;
                if (bqPollTimer) clearTimeout(bqPollTimer);
                $('#bq-run-queue').prop('disabled', false).text('Process Queue (Background)');
                $('#bq-stop-queue-bg').hide();
                $('#bq-queue-progress').css('border-left-color', '#d97706').html('Processing stopped. Remaining items stay in queue.');
                loadQueue(1); loadQueueCount();
            });
        });

        function startBqPolling() {
            bqRunning = true;
            $('#bq-run-queue').prop('disabled', true).text('Processing...');
            $('#bq-stop-queue-bg').show().prop('disabled', false).text('Stop Processing');
            $('#bq-queue-progress').show();
            pollBqStatus();
        }

        function pollBqStatus() {
            $.post(ajaxurl, { action: 'bq_queue_status', nonce: nonce }, function(resp) {
                if (!resp.success) return;
                var s = resp.data;

                var html = '<strong>' + s.completed + ' created</strong>';
                if (s.failed > 0) html += ', <span style="color:#dc2626;">' + s.failed + ' failed</span>';
                html += ', <span style="color:#64748b;">' + s.remaining + ' remaining</span>';
                html += ' (daily limit: ' + s.daily_limit + ')';

                if (s.current && s.current.title) {
                    html += '<br><span style="font-size:12px;color:#2563eb;"><span class="spinner is-active" style="float:none;margin:0 4px 0 0;"></span>Now: <strong>' + s.current.title + '</strong></span>';
                }

                if (s.last_done && s.last_done.title) {
                    var doneColor = s.last_done.status === 'completed' ? '#059669' : '#dc2626';
                    var doneIcon = s.last_done.status === 'completed' ? '&#10003;' : '&#10007;';
                    html += '<br><span style="font-size:12px;color:' + doneColor + ';">' + doneIcon + ' ' + s.last_done.title + '</span>';
                }

                if (s.is_running && s.remaining > 0) {
                    html += '<br><span style="font-size:11px;color:#94a3b8;">Processing in background — you can leave this page.</span>';
                    $('#bq-queue-progress').css('border-left-color', '#2563eb').html(html);
                    loadQueueCount();
                    bqPollTimer = setTimeout(pollBqStatus, 15000);
                } else if (s.stopped) {
                    html += '<br><span style="font-size:12px;color:#d97706;">Processing stopped.</span>';
                    $('#bq-queue-progress').css('border-left-color', '#d97706').html(html);
                    bqRunning = false;
                    $('#bq-run-queue').prop('disabled', false).text('Process Queue (Background)');
                    $('#bq-stop-queue-bg').hide();
                    loadQueue(1); loadQueueCount();
                } else {
                    html += '<br><span style="font-size:12px;color:#059669;">Queue processing complete.</span>';
                    $('#bq-queue-progress').css('border-left-color', '#059669').html(html);
                    bqRunning = false;
                    $('#bq-run-queue').prop('disabled', false).text('Process Queue (Background)');
                    $('#bq-stop-queue-bg').hide();
                    loadQueue(1); loadQueueCount();
                }
            });
        }

        // On page load, check if processing is active
        $.post(ajaxurl, { action: 'bq_queue_status', nonce: nonce }, function(resp) {
            if (resp.success && resp.data.is_running) {
                startBqPolling();
            }
        });

        // ─── All Blogs ──────────────────────────────────────────────────────
        var blogSort = 'modified', blogOrder = 'DESC';
        var checkYes = '<span class="bq-check-yes" title="Yes">&#10003;</span>';
        var checkNo  = '<span class="bq-check-no" title="No">&#10007;</span>';

        function loadBlogs(page) {
            $('#bq-blog-loading').show();
            $('#bq-blog-table, #bq-blog-empty').hide();
            $.post(ajaxurl, {
                action: 'bq_get_all_blogs', nonce: nonce,
                page: page || 1,
                search: $('#bq-blog-search').val(),
                filter: $('#bq-blog-filter').val(),
                orderby: blogSort,
                order: blogOrder
            }, function(resp) {
                $('#bq-blog-loading').hide();
                if (!resp.success || !resp.data.rows.length) { $('#bq-blog-empty').show(); return; }
                var d = resp.data;
                var tbody = $('#bq-blog-body').empty();
                d.rows.forEach(function(r) {
                    var img = r.thumb_url
                        ? '<img src="' + r.thumb_url + '" class="bq-thumb" />'
                        : '<span class="bq-thumb--empty">No image</span>';

                    var wordClass = r.word_count >= 1500 ? 'bq-word-good' : (r.word_count >= 800 ? 'bq-word-ok' : 'bq-word-low');

                    tbody.append(
                        '<tr data-id="' + r.ID + '">' +
                        '<td><input type="checkbox" class="bq-blog-cb" value="' + r.ID + '" /></td>' +
                        '<td>' + img + '</td>' +
                        '<td><strong>' + r.title + '</strong>' +
                            '<div class="bq-blog-status" data-id="' + r.ID + '"></div>' +
                        '</td>' +
                        '<td style="text-align:center;"><span class="' + wordClass + '">' + r.word_count.toLocaleString() + '</span></td>' +
                        '<td style="text-align:center;" class="col-faq">' + (r.has_faq ? checkYes : checkNo) + '</td>' +
                        '<td style="text-align:center;" class="col-schema">' + (r.has_schema ? checkYes : checkNo) + '</td>' +
                        '<td style="text-align:center;" class="col-meta">' + (r.has_meta ? checkYes : checkNo) + '</td>' +
                        '<td style="text-align:center;" class="col-keyword">' + (r.has_keyword ? checkYes : checkNo) + '</td>' +
                        '<td style="font-size:12px;color:#64748b;">' + (r.modified || '') + '</td>' +
                        '<td>' +
                            '<a href="' + r.edit_url + '" class="button button-small" target="_blank">Edit</a> ' +
                            '<a href="' + r.view_url + '" class="button button-small" target="_blank">View</a>' +
                        '</td>' +
                        '</tr>'
                    );
                });
                $('#bq-blog-table, #bq-blog-bulk-bar').show();

                // Update sort indicators
                $('#bq-blog-table thead th[data-sort]').css('cursor', 'pointer').find('.bq-sort-arrow').remove();
                var arrow = blogOrder === 'ASC' ? ' &#9650;' : ' &#9660;';
                $('#bq-blog-table thead th[data-sort="' + blogSort + '"]').append('<span class="bq-sort-arrow">' + arrow + '</span>');

                // Pagination
                var pag = $('#bq-blog-pagination').empty();
                if (d.pages > 1) {
                    for (var i = 1; i <= d.pages; i++) {
                        pag.append('<button class="' + (i === d.page ? 'button button-primary' : 'button') + ' bq-blog-page" data-page="' + i + '">' + i + '</button> ');
                    }
                }
                pag.append('<span style="margin-left:12px;color:#64748b;font-size:13px;">' + d.total + ' blog(s)</span>');
            });
        }

        $(document).on('click', '.bq-blog-page', function() { loadBlogs($(this).data('page')); });

        // Search with debounce
        var blogSearchTimer;
        $('#bq-blog-search').on('input', function() {
            clearTimeout(blogSearchTimer);
            blogSearchTimer = setTimeout(function() { loadBlogs(1); }, 400);
        });
        $('#bq-blog-filter').change(function() { loadBlogs(1); });

        // Sortable columns
        $(document).on('click', '#bq-blog-table thead th[data-sort]', function() {
            var col = $(this).data('sort');
            if (blogSort === col) {
                blogOrder = blogOrder === 'DESC' ? 'ASC' : 'DESC';
            } else {
                blogSort = col;
                blogOrder = col === 'title' ? 'ASC' : 'DESC';
            }
            loadBlogs(1);
        });

        // Select all checkbox
        $(document).on('change', '#bq-blog-check-all', function() {
            $('.bq-blog-cb').prop('checked', $(this).prop('checked'));
        });

        // Bulk apply
        var isBlogProcessing = false;
        $('#bq-blog-bulk-apply').click(function() {
            var action = $('#bq-blog-bulk-action').val();
            if (!action) { alert('Select a bulk action.'); return; }

            var ids = [];
            $('.bq-blog-cb:checked').each(function() { ids.push(parseInt($(this).val())); });
            if (!ids.length) { alert('Select at least one blog.'); return; }

            if (isBlogProcessing) { alert('A process is already running.'); return; }

            var typeMap = { full_refresh: 'full', seo_refresh: 'seo', meta_refresh: 'meta' };
            var labelMap = { full_refresh: 'full refresh', seo_refresh: 'SEO refresh', meta_refresh: 'CTR fix' };
            var refreshType = typeMap[action] || 'full';
            var label = labelMap[action] || action;
            if (!confirm('Run ' + label + ' on ' + ids.length + ' blog(s)? This may take 1-2 minutes per blog.')) return;

            isBlogProcessing = true;
            $('#bq-blog-bulk-apply').prop('disabled', true);
            var completed = 0, failed = 0, total = ids.length;

            // Gather rows for each ID
            var items = [];
            ids.forEach(function(id) {
                var row = $('#bq-blog-table tr[data-id="' + id + '"]');
                items.push({ id: id, row: row });
            });

            function processNextBlog(index) {
                if (index >= total) {
                    isBlogProcessing = false;
                    $('#bq-blog-bulk-apply').prop('disabled', false);
                    $('#bq-blog-bulk-status').html('<span style="color:#059669;">' + label + ' complete: ' + completed + ' succeeded, ' + failed + ' failed.</span>');
                    return;
                }

                var item = items[index];
                item.row.css('opacity', '0.7');
                item.row.find('.bq-blog-status').html('<span class="processing">Processing...</span>');
                $('#bq-blog-bulk-status').html('<span style="color:#b45309;">Processing ' + (index + 1) + '/' + total + '...</span>');

                $.ajax({
                    url: ajaxurl, method: 'POST', timeout: 600000,
                    data: { action: 'bq_refresh_blog', nonce: nonce, post_id: item.id, type: refreshType },
                    success: function(resp) {
                        if (resp.success) {
                            completed++;
                            var d = resp.data;
                            item.row.css('opacity', '1');
                            item.row.find('.bq-blog-status').html('<span class="done">&#10003; Done</span>');
                            // Update check columns
                            item.row.find('.col-faq').html(d.has_faq ? checkYes : checkNo);
                            item.row.find('.col-schema').html(d.has_schema ? checkYes : checkNo);
                            item.row.find('.col-meta').html(d.has_meta ? checkYes : checkNo);
                            item.row.find('.col-keyword').html(d.has_keyword ? checkYes : checkNo);
                            // Update word count if full refresh
                            if (refreshType === 'full' && d.word_count) {
                                var wc = d.word_count;
                                var wcClass = wc >= 1500 ? 'bq-word-good' : (wc >= 800 ? 'bq-word-ok' : 'bq-word-low');
                                item.row.find('td:eq(3)').html('<span class="' + wcClass + '">' + wc.toLocaleString() + '</span>');
                            }
                        } else {
                            failed++;
                            item.row.css('opacity', '1');
                            item.row.find('.bq-blog-status').html('<span class="error">&#10007; ' + (resp.data || 'Failed') + '</span>');
                        }
                    },
                    error: function() {
                        failed++;
                        item.row.css('opacity', '1');
                        item.row.find('.bq-blog-status').html('<span class="error">&#10007; Server error</span>');
                    },
                    complete: function() { processNextBlog(index + 1); }
                });
            }

            processNextBlog(0);
        });

        // History
        function loadHistory(page) {
            $('#bq-history-loading').show(); $('#bq-history-table').hide();
            $.post(ajaxurl, { action: 'bq_get_history', nonce: nonce, page: page || 1 }, function(resp) {
                $('#bq-history-loading').hide();
                if (!resp.success || !resp.data.rows.length) return;
                var tbody = $('#bq-history-body').empty();
                resp.data.rows.forEach(function(r) {
                    var status = r.status === 'completed' ? '<span style="color:#059669;">Published</span>' : '<span style="color:#dc2626;">Failed</span>';
                    var actions = r.post_id ? '<a href="/?p=' + r.post_id + '" target="_blank" class="button button-small">View</a> <a href="<?php echo admin_url('post.php?action=edit&post='); ?>' + r.post_id + '" target="_blank" class="button button-small">Edit</a>' : (r.error_message || '');
                    tbody.append('<tr><td>' + r.title + '</td><td>' + status + '</td><td style="font-size:12px;">' + (r.processed_at || '').substring(0, 16) + '</td><td>' + actions + '</td></tr>');
                });
                $('#bq-history-table').show();
                var pag = $('#bq-history-pagination').empty();
                if (resp.data.pages > 1) for (var i = 1; i <= resp.data.pages; i++) pag.append('<button class="' + (i === resp.data.page ? 'button button-primary' : 'button') + ' bq-hist-page" data-page="' + i + '">' + i + '</button> ');
            });
        }
        $(document).on('click', '.bq-hist-page', function() { loadHistory($(this).data('page')); });

        // Settings
        $('#bq-save-settings').click(function() {
            $.post(ajaxurl, {
                action: 'bq_save_settings', nonce: nonce,
                daily_limit: $('#bq-daily-limit').val(),
                notify_email: $('#bq-notify-email').val(),
                enabled: $('#bq-enabled').is(':checked') ? '1' : '0'
            }, function(resp) {
                $('#bq-settings-status').html(resp.success ? '<span style="color:#059669;">Saved!</span>' : '<span style="color:#dc2626;">Error</span>');
                setTimeout(function() { $('#bq-settings-status').text(''); }, 3000);
            });
        });

        loadQueueCount();
    });
    </script>
    <?php
}

// Also track this plugin in git
