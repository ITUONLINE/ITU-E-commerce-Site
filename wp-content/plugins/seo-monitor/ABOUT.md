# SEO & Content Automation Platform — Executive Summary

**Prepared:** April 2, 2026
**Last Updated:** April 4, 2026
**Platform:** WordPress / WooCommerce
**Sites:** ITU Online Training, Vision Training Systems

> **Note:** The rendered HTML version of this document is at `about.html` in this same folder and is accessible via the "Documentation" link on the WordPress Plugins page for both SEO AI AutoPilot and Blog Queue.

---

## Overview

We have built an integrated SEO and content automation ecosystem consisting of two core WordPress plugins — **SEO AI AutoPilot** and **Blog Queue** — that work together to create a closed-loop content intelligence and production system. The platform connects to Google Search Console for real-time performance data, imports competitive intelligence from SEMrush, and uses AI to both identify content opportunities and produce publication-ready blog posts with full SEO optimization.

The system is designed to be **site-agnostic** — all AI prompts, branding references, and configurations dynamically pull the site name, making the entire platform portable across multiple properties.

---

## Plugin 1: SEO AI AutoPilot

### What It Does

SEO AI AutoPilot is the intelligence and optimization engine. It continuously monitors every indexed page on the site through Google Search Console, scores pages against a multi-factor algorithm, and automatically refreshes underperforming content using AI. It also provides a goal-driven framework for tracking and improving site-wide SEO metrics over time.

### Data Collection & Intelligence

- **Google Search Console Integration** — Authenticated via OAuth2 service account. Collects daily snapshots of clicks, impressions, CTR, and average position for every published page. Stores top 5 search queries per page for AI context injection during content refresh.

- **True Site-Wide GSC Totals** — In addition to per-page data, the collector fetches aggregate site-wide totals directly from GSC (no page dimension). This ensures total clicks, impressions, CTR, and position reflect the full site — including non-WordPress URLs (category pages, tag archives, pagination, etc.) that aren't tracked as individual posts.

- **GSC Data Reconciliation** — The Indexed Pages tab displays a reconciliation bar showing tracked (matched WordPress posts) vs. untracked (non-WordPress URLs) vs. GSC total, with a visual proportional breakdown and percentages. This ensures full auditability of all metrics.

- **Untracked URL Monitoring** — GSC URLs that don't match any WordPress post are stored in a dedicated table with automatic type classification (category, tag, pagination, author, product_cat, product_tag, feed, search, other). A collapsible UI section lets you browse, sort, filter by type, and understand where unmonitored impressions are coming from.

- **Normalized URL Matching** — URLs are normalized (scheme stripped, query parameters removed, trailing slashes standardized) before matching GSC data to WordPress posts, significantly reducing data loss from URL format differences.

- **Keyword Intelligence** — Maintains a growing keyword database that tracks 28-day trends (rising, stable, declining), maps keywords to posts using fuzzy matching, detects keyword cannibalization (multiple pages ranking for the same query), and calculates opportunity scores (0-100) for each keyword.

- **Competitive Gap Analysis** — Imports keyword data from SEMrush CSV exports. Supports AI-powered auto-tagging into categories, programmatic deduplication of similar tags, and batch consolidation to keep categories manageable. Tracks keyword usage with a 90-day cooldown to prevent over-optimization.

- **Google Autocomplete Expansion** — Automatically enriches top-performing keywords with Google Autocomplete suggestions weekly, discovering long-tail variations and related queries.

- **Historical Site Totals** — Site-wide GSC totals are stored daily with 90-day retention, enabling historical baseline lookups for goal tracking and trend comparison.

### Page Scoring & Prioritization

Every monitored page is scored across four weighted dimensions:

| Factor | Weight | What It Measures |
|--------|--------|-----------------|
| Opportunity | 35% | Position, search volume, room for improvement |
| Momentum | 25% | Click trend direction (rising vs. declining) |
| Quick Win | 25% | Category-based probability of improvement |
| Staleness | 15% | Time since last refresh |

Pages are categorized into six performance buckets:

| Category | Description | Example Trigger |
|----------|-------------|----------------|
| A — Ghost Pages | Zero or near-zero impressions | Content not being discovered |
| B — CTR Fix | Good position, high impressions, poor CTR | Needs better title/meta |
| C — Near Wins | Page 2 positions (11-20) with decent volume | Close to page 1 breakthrough |
| D — Declining | 30%+ click drop vs. prior period | Content losing relevance |
| E — Visible but Ignored | 500+ impressions but fewer than 10 clicks | High exposure, no engagement |
| F — Buried Potential | Position 20+ with meaningful impressions | Significant untapped opportunity |

**Top Performer Protection** — Pages whose CTR meets or exceeds 60% of the expected CTR benchmark for their position are automatically protected from auto-refresh. Expected CTR benchmarks are calibrated by position (Position 1: ~30%, Position 5: ~7%, Position 10: ~3%, etc.).

### Goal-Driven SEO Management

The Goals system provides structured, measurable targets for SEO improvement with AI-assisted planning and automated progress tracking.

**Available Goal Metrics:**

| Metric | Description |
|--------|-------------|
| Ghost Pages | Pages with zero/near-zero impressions |
| Total Clicks (28d) | Site-wide clicks from GSC |
| Total Impressions (28d) | Site-wide impressions from GSC |
| Average Position | Site-wide average search position |
| Average CTR (%) | Site-wide click-through rate |
| Pages on Page 1 / Page 2 | Count of pages by ranking position |
| Pages With Impressions | Pages receiving any search visibility |
| Total Published Content | Total published pages/posts/products |
| Stale Pages | Pages not refreshed in 90+ days |
| Refreshed This Month | Monthly refresh throughput |

**Goal Features:**
- **Historical Baselines** — Set a start date and the system looks up metrics as of that date. For content growth goals, baseline = total pages published before the start date.
- **AI Feasibility Check** — Before creating a goal, AI evaluates whether the target is achievable, stretch, unlikely, or unrealistic given current capacity and timelines. Provides confidence scores and alternative suggestions.
- **Goal-Weighted Queue Allocation** — Active goals influence how the refresh queue is allocated. Categories serving behind-schedule goals get more queue slots via urgency multipliers.
- **Capacity Analysis** — Calculates how many daily refreshes each goal requires based on success rates per category, remaining change needed, and days until deadline.
- **Adaptive Daily Limits** — Three modes: Fixed (static cap), Adaptive (automatically increases to meet goal demands), Burst (temporary 50% increase when goals fall behind).
- **Progress Tracking** — Current values updated on each refresh, with visual progress bars, direction indicators (on track / behind / wrong direction), and percentage completion.
- **Monthly Auto-Creation** — On the 1st of each month, AI analyzes current metrics and last month's goal performance to automatically suggest and create new goals.
- **Daily Goal Email** — Progress report showing each goal's baseline → current → target, with on-track/behind status and time remaining.
- **Priority System** — 5 levels (Critical, High, Medium, Low, Backlog) that weight queue allocation and urgency calculations.

### Competitive Research (AI Web Search)

Before generating content, the system can perform live web searches to analyze what top-ranking competitors cover for the target search queries. This produces strategically informed content that addresses real competitive gaps.

**Supported Providers:**
- **Perplexity Sonar** — Purpose-built search AI with built-in web access. Best quality/cost ratio (~$0.006/search). Requires separate API key.
- **OpenAI Web Search** — Uses gpt-4.1-mini with the Responses API `web_search` tool (~$0.03/search). Uses existing OpenAI API key.

**How It Works:**
1. When a refresh starts, the researcher builds a query from the page's top GSC search queries and current ranking position
2. The search provider analyzes the top 5 currently ranking pages and returns: content gaps, content depth comparison, unique angles, People Also Ask questions, and a strategic recommendation
3. Research results are stored per-post (`_seom_research` post meta) with a timestamp, persisting across refreshes
4. Every subsequent AI step (outline, content, meta, FAQ, title) receives the competitive intelligence as context
5. The AI is instructed on what to DO with research (cover gaps, match depth, target PAA questions) and what NOT to do (don't fabricate details, don't copy competitors, don't invent course content)

**Research Controls:**
- **Per-category thresholds** — Configure which page categories (A-F) trigger automatic research. Manual queue items always get research.
- **Minimum impressions** — Only research pages with meaningful search visibility
- **Minimum position** — Only research pages ranking below a threshold (skip pages already dominating)
- **Manual collection** — "Research" button on every page in the Indexed Pages tab, works regardless of auto-research settings
- **Research viewer** — Click any "Research [date]" button to view the full competitive analysis in a modal, with option to refresh

**Research is also stored in `seom_refresh_history.research_results`** for audit trail of what competitive intelligence was used for each specific refresh.

**Centralized Model Control** — Research provider and model are configurable via the AI Settings page alongside all other per-step model selections.

### SEO Performance Context Injection

Every AI step in the refresh pipeline receives the page's actual GSC performance data, enabling category-specific optimization strategies:

| Category | AI Strategy |
|----------|-------------|
| A — Ghost | Establish topical authority, strong keyword targeting, definition-style openings |
| B — CTR Fix | Compelling opening, differentiate from competitors, deliver on a strong promise |
| C — Near Win | Deepen authority, strengthen E-E-A-T, comprehensive coverage beyond page-1 competitors |
| D — Declining | Update outdated info, add current-year data, new sections on recent developments |
| E — Visible/Ignored | Rewrite to match search intent, answer core question in first 100 words |
| F — Buried | Major content upgrade, substantially better than page-1 competitors |

The AI also receives: current position, clicks, impressions, CTR, top 5 search queries with per-query metrics, and the competitive research brief (when available).

### Trademark & Copyright Compliance

All AI-generated content enforces trademark and copyright rules:
- Registered trademark (®) and trademark (™) symbols required on first mention of vendor and certification names
- EC-Council® receives special handling due to aggressive enforcement — official C|EH™ notation required
- Course-specific trademark reminders are dynamically injected based on the course title
- Trademark disclaimer paragraph appended to all content mentioning vendor certifications

### Content Refresh Engine

Refreshes are executed through a multi-step AI pipeline:

0. **Competitive Research** (optional) — Web search analysis of top-ranking competitors
1. **Outline Generation** — AI creates a structured 6-8 section outline informed by GSC data and research
2. **Full Content Rewrite** — 2,000-2,500 word HTML article with callout boxes, comparison tables, lists, and blockquotes
3. **Meta Description** — 140-155 characters optimized for click-through, CTR-aware for categories B/E
4. **FAQ Generation** — 5 detailed FAQ entries based on real search queries from GSC
5. **JSON-LD Schema** — FAQPage structured data for rich results
6. **Focus Keyword & SEO Title** — Informed by actual GSC search queries and CTR data, Rank Math integration

Three refresh tiers are available:
- **Full Refresh** — Complete content rewrite with new outline
- **SEO Refresh** — FAQ, schema, meta, keyword, and title only
- **Meta Refresh** — Title and meta description only (for CTR fixes)

Smart escalation: if a meta-only refresh doesn't improve performance, the system automatically escalates to a full refresh on the next cycle. Meta-only refreshes use a shorter 30-day cooldown; full refreshes use the configured cooldown period (default 90 days).

### Automation Schedule

| Time | Task |
|------|------|
| 1:00 AM | Collect GSC page metrics + site-wide totals |
| 1:30 AM | Collect keyword intelligence & trends |
| 2:00 AM | Analyze pages, score, and populate refresh queue |
| 6:00 AM | Begin processing queue (10-min intervals between items) |
| Daily | Goal progress email with status updates |
| 1st of Month | Auto-close expired goals, AI-generate new monthly goals |
| Wednesday 3:00 AM | Backfill 30-day and 60-day performance metrics |
| Sunday 4:00 AM | Expand top keywords via Google Autocomplete |

### Performance Tracking

The Performance Tracker provides before/after comparison for every refresh:
- 30-day and 60-day post-refresh metrics
- Trend filtering: Strong Improvement, Clicks Up, Ranking Up, Declining, Stable
- Visual comparison of clicks, impressions, CTR, and position changes

### Dashboard Tabs

| Tab | Purpose |
|-----|---------|
| Overview | KPIs, queue status, processing stats, goal mini-cards, today's refresh count |
| Indexed Pages | All tracked pages with GSC metrics, date range filtering, GSC data reconciliation, untracked URLs |
| Performance Tracker | Before/after charts with trend filters (30d & 60d comparison) |
| Queue | Pending refreshes with priority scores, categories, bulk actions |
| History | Completed refreshes with before/after metric comparisons |
| Keywords | GSC keyword intelligence, opportunity scores, content gaps, lost keywords |
| Keyword Gaps | Imported competitive keywords with AI tagging, filters, usage tracking |
| Goals | Active/completed/missed goals with progress bars, AI feasibility, capacity analysis |
| Settings | GSC credentials, thresholds, cooldown periods, processing limits, notification email |

---

## Plugin 2: Blog Queue

### What It Does

Blog Queue is the content production engine. It manages a pipeline of blog topics from ideation through AI-powered creation to publication, with full keyword targeting and SEO optimization baked into every step.

### AI Topic Generation

The topic generator analyzes multiple data sources to suggest unique, non-overlapping blog ideas:

- **GSC Content Gaps** — Keywords where the site has impressions but no dedicated post
- **Trending Keywords** — Rising search queries from GSC
- **Competitive Gap Keywords** — Imported from SEMrush, filtered by category and volume
- **LSI Keywords** — Semantically related variants for topic differentiation
- **Existing Content Audit** — Cross-references all existing post titles and queued topics to prevent duplication

**Filtering options:**
- By keyword gap category (e.g., "Cloud Computing", "Cybersecurity")
- By volume tier: High Volume (1000+), Low Difficulty (KD ≤30), Quick Wins
- By focus area (free-text)
- Quantity: 5-50 topics per generation

Each generated topic includes a primary keyword and 3-5 LSI/long-tail variants. The category dropdown dynamically updates available keyword counts based on the selected volume filter.

### Content Creation Pipeline

Blog creation is a 4-step AJAX process, each step executing independently to avoid server timeouts:

| Step | Action | Output |
|------|--------|--------|
| 1 | Generate Outline | 6-8 section structure with bullet points |
| 2 | Write Content | 2,000-2,500 word HTML article with citations, formatting, keyword injection |
| 3 | SEO Metadata | Meta description, focus keyword, SEO title (Rank Math) |
| 4 | FAQ & Schema | 5 FAQs in accordion format + JSON-LD FAQPage schema (ACF fields) |

### Keyword Lifecycle Management

The system implements a complete keyword lifecycle:

```
Available → Reserved (queued) → Used (published) → Cooldown (90 days) → Available
```

- **Reservation**: Keywords are locked when topics are added to the queue
- **Usage Tracking**: Stamped with post ID and date when the blog is published
- **Cooldown**: 90-day configurable period prevents the same keyword from being targeted in multiple posts
- **Release**: Keywords are freed if a queue item is deleted before processing

### Queue Processing & Distribution

- **Round-Robin Distribution** — When processing the queue (either via cron or manually), items are distributed across gap categories in rotation. If the queue contains items from 10 different categories, the first 10 blogs created will each be from a different category — preventing clusters of same-topic posts from being published consecutively.

- **Daily Limits** — Configurable cap (default 5, max 50) prevents runaway processing
- **Manual Processing** — "Process Queue Now" selects up to 25 items from the full queue using round-robin distribution

### Content Quality Standards

The AI content prompt enforces:

- **Depth**: 2,000-2,500 words minimum, each section 200-350 words
- **Formatting Variety**: Paragraphs, bullet lists, numbered lists, callout boxes (tip/info/warning/key takeaway), comparison tables, blockquotes
- **Authoritative Citations**: Minimum 3-5 references per article from different source categories:
  - **Governing Bodies**: CompTIA, Cisco, Microsoft, AWS, ISC2, ISACA, PMI, EC-Council, and more
  - **Compliance Frameworks**: NIST, ISO 27001, PCI DSS, HIPAA, GDPR, SOC 2, FedRAMP, CMMC
  - **Government & Workforce**: BLS, DoD, DHS, NSA, FTC, Dept of Labor
  - **Professional Associations**: SHRM, ISSA, IAPP, ACM, IEEE, Cloud Security Alliance, NICE Framework
  - **Industry Research**: Gartner, Forrester, IDC, McKinsey, Deloitte, PwC, SANS Institute, Verizon DBIR, IBM
  - **Technical Standards**: OWASP, CIS Benchmarks, MITRE ATT&CK, IETF RFCs
  - **Salary Data**: BLS, Glassdoor, PayScale, Robert Half, Dice, SHRM
- **Competitor Exclusion**: Explicit blacklist prevents any reference to competing training providers (Coursera, Udemy, Pluralsight, CBT Nuggets, etc.)
- **AI Search Optimization**: Content structured for citation by Google AI Overview, Perplexity, and ChatGPT — definition-style sentences, comparison tables, FAQ-style headings

### All Blogs Management

A dedicated tab provides oversight of the entire blog library:

- **Filters**: Missing FAQ, missing schema, missing featured image, missing meta, missing focus keyword, short content (<1,000 words), refresh status
- **Bulk Actions**: Select multiple posts for Full Refresh, SEO Refresh, or Meta Refresh
- **Metrics**: Word count (color-coded), presence/absence of FAQ, schema, meta, keywords
- **Direct Actions**: View, Edit, individual Refresh per post

### Email Notifications

- **Per-Blog Alert**: Sent immediately when each blog is created or fails
- **Daily Summary**: Aggregate report of all blogs created/failed during the day's processing run

---

## Integration Architecture

```
                    Google Search Console
                 (clicks, impressions, CTR, position)
                              |
                              v
    +----------------------------------------------------+
    |                    SEO AI AutoPilot                      |
    |  Collector  --->  Analyzer  --->  Processor         |
    |  (GSC API)       (Score)        (Refresh)           |
    |                                                     |
    |  Site Totals       Goal Engine     Capacity Planner |
    |  (reconciliation)  (AI feasibility) (adaptive limits)|
    |                                                     |
    |  Keyword Researcher    Keyword Gap Importer          |
    |  (trends, mapping)     (SEMrush CSV)                |
    +----------+---------------------+-------------------+
               |                     |
               v                     v
    +----------------------------------------------------+
    |                    Blog Queue                       |
    |  Topic Generator --> Queue Manager --> Content      |
    |  (AI + keywords)    (round-robin)    Pipeline       |
    |                                        |            |
    |  Gap Keywords + GSC Gaps + LSI --------+            |
    +----------------------------------------------------+
               |
               v
    +----------------------------------------------------+
    |             WordPress / WooCommerce                 |
    |  Rank Math (SEO Meta)  |  ACF (FAQ/JSON-LD)        |
    |  Posts / Products      |  Categories / Tags        |
    +----------------------------------------------------+
```

---

## Database Schema Summary

### SEO AI AutoPilot Tables (10)

| Table | Purpose | Key Fields |
|-------|---------|------------|
| seom_page_metrics | Daily GSC snapshots per page | clicks, impressions, ctr (decimal 7,4), position, top_queries |
| seom_refresh_queue | Pending content refreshes | post_id, category (A-F), priority_score, refresh_type |
| seom_refresh_history | Audit trail of all refreshes | before/after metrics at 30d and 60d |
| seom_keywords | GSC keyword intelligence | trend_direction, opportunity_score, is_content_gap, cannibalization_count |
| seom_keyword_gaps | Imported competitive keywords | search_volume, keyword_difficulty, tag, last_used_at |
| seom_keyword_usage | Keyword cooldown tracking | status (reserved/used), used_at, post_id, queue_id |
| seom_lsi_keywords | LSI keyword relationships | seed_keyword → lsi_keyword pairs |
| seom_keyword_suggestions | Autocomplete expansion results | seed_keyword → suggestion |
| seom_goals | SEO goal tracking | metric, baseline_value, current_value, target_value, deadline, priority, ai_assessment |
| seom_untracked_metrics | Non-WordPress GSC URLs | url, clicks, impressions, ctr, avg_position, url_type |

### SEO AI AutoPilot Options (stored in wp_options)

| Option | Purpose |
|--------|---------|
| seom_site_totals | Latest true GSC site-wide totals (clicks, impressions, CTR, position) |
| seom_site_totals_history | Daily site totals with 90-day retention for historical comparisons |

### Blog Queue Tables (2)

| Table | Purpose | Key Fields |
|-------|---------|------------|
| bq_queue | Pending blog topics | title, gap_category, target_keywords, status |
| bq_history | Creation audit log | title, post_id, status, error_message |

---

## Technical Resilience

### Timeout Prevention
All long-running operations are broken into batched AJAX chains to prevent nginx 504 gateway timeouts:
- Blog creation: 4-step pipeline
- SEO AI AutoPilot queue processing: step-based with 10-minute cron intervals
- GSC data collection: 2-phase batch processing (50 posts per batch) + site totals + untracked URL storage
- SEMrush import: multi-phase (parse → upsert batches)
- Tag consolidation: 3-phase (dedup → AI batch → apply batch)
- Keyword collection: batched at 200 keywords per AJAX call
- Auto-tagging: 40 keywords per AI call with running progress

### Data Integrity
- Growing keyword intelligence (upsert, never delete-rebuild)
- Lost keyword detection (keywords that disappear from GSC are flagged, not deleted)
- Keyword cooldown prevents over-optimization
- Round-robin queue distribution prevents topic clustering
- Top performer protection (CTR benchmarked by position — pages meeting 60% of expected CTR are never auto-refreshed)
- CTR stored at 4-decimal precision (decimal 7,4) throughout the pipeline for accurate fractional comparisons
- GSC data reconciliation ensures tracked + untracked metrics account for full site totals
- Historical site totals retained for 90 days for baseline verification

### Security
- Nonce verification on all AJAX endpoints
- Capability checks (manage_options / manage_woocommerce)
- Prepared SQL statements throughout (no injection risk)
- Input sanitization on all user-provided data

---

## Key Metrics & Capacity

| Metric | Value |
|--------|-------|
| AJAX Endpoints | 45+ (SEO AI AutoPilot) + 13 (Blog Queue) |
| Database Tables | 12 total (10 SEO AI AutoPilot + 2 Blog Queue) |
| Cron Jobs | 8 scheduled events |
| AI Steps per Blog | 4 (outline, content, meta, FAQ/schema) |
| AI Steps per Refresh | Up to 7 (outline, content, meta, FAQ HTML, FAQ JSON, keyword, title) |
| Page Categories | 6 (A through F) |
| Scoring Factors | 4 weighted dimensions |
| Goal Metrics | 11 trackable metrics |
| Source Categories for Citations | 7 distinct types |
| Keyword Sources | 5 (GSC, SEMrush, Autocomplete, LSI, Manual) |

---

## Summary

This platform transforms SEO management from a manual, reactive process into an automated, data-driven system. It identifies what content needs attention through GSC intelligence, prioritizes work using a composite scoring algorithm, executes content improvements through AI with strict quality controls, tracks the results, and closes the loop with goal-driven planning that adjusts capacity and priorities based on measurable outcomes — creating a continuous improvement engine that scales across thousands of pages without manual intervention.
