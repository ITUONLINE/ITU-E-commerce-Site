# SEO & Content Automation Platform — Executive Summary

**Prepared:** April 2, 2026
**Last Updated:** April 10, 2026
**Platform:** WordPress / WooCommerce
**Sites:** ITU Online Training, Vision Training Systems

> **Note:** The rendered HTML version of this document is at `about.html` in this same folder and is accessible via the "Documentation" link on the WordPress Plugins page for both SEO AI AutoPilot and Blog Queue.

---

## Overview

We have built an integrated SEO and content automation ecosystem consisting of three core WordPress plugins — **SEO AI AutoPilot**, **Blog Queue**, and **AI Product Manager** — that work together to create a closed-loop content intelligence, production, and merchandising system. The platform connects to Google Search Console for real-time performance data, imports competitive intelligence from SEMrush, uses AI to both identify content opportunities and produce publication-ready blog posts with full SEO optimization, and cross-promotes products within blog content via a Featured Product system.

The system is designed to be **site-agnostic** — all AI prompts, branding references, and configurations dynamically pull the site name, making the entire platform portable across multiple properties.

---

## Plugin 1: SEO AI AutoPilot

### What It Does

SEO AI AutoPilot is the intelligence and optimization engine. It continuously monitors every indexed page on the site through Google Search Console, scores pages against a multi-factor algorithm, and automatically refreshes underperforming content using AI. It also provides a goal-driven framework for tracking and improving site-wide SEO metrics over time.

### Data Collection & Intelligence

- **Google Search Console Integration** — Authenticated via OAuth2 (service account or hosted SaaS proxy for non-technical resellers). Collects daily snapshots of clicks, impressions, CTR, and average position for every published page. Stores top 5 search queries per page for AI context injection during content refresh.

- **True Site-Wide GSC Totals** — In addition to per-page data, the collector fetches aggregate site-wide totals directly from GSC (no page dimension). This ensures total clicks, impressions, CTR, and position reflect the full site — including non-WordPress URLs (category pages, tag archives, pagination, etc.) that aren't tracked as individual posts.

- **Multi-Range Site Totals Cache** — The nightly collector fetches GSC no-dimension aggregates for 7, 14, 28, 60, 90, and 180-day windows and caches them in `seom_site_totals_by_range`. The Indexed Pages tab uses these cached values for the unfiltered All/All view so the cards match GSC exactly for any predefined date range. Filtered or custom date ranges fall back to per-page weighted calculation with an "(approx)" label.

- **Admin Bar GSC Stats** — When logged in as an admin viewing any single post/product/page on the front-end, the admin bar displays the page's most recent 28-day GSC snapshot: clicks, impressions, and average position. Click to jump to the Indexed Pages tab.

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
- **Per-category thresholds** — Configure which page categories (A-F) trigger automatic research
- **Minimum impressions** — Only research pages with meaningful search visibility
- **Minimum position** — Only research pages ranking below a threshold
- **Manual collection** — "Research" button on every page in the Indexed Pages tab
- **Research viewer** — Click any "Research [date]" button to view the full competitive analysis in a modal

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

**Shared Blog Writer Instruction** — The blog body writing prompt is maintained as a single source of truth in `SEOM_Blog_Refresher::build_blog_writer_instruction()`. Both the refresh pipeline (mode='rewrite') and Blog Queue's new-blog pipeline (mode='new') call this method, so changes to tone, depth rules, citation requirements, trademark handling, or AI-search optimization only need to be made once. Optional context blocks (GSC keywords, SEO performance data, gap keywords, featured product info) are appended only when supplied.

Three refresh tiers are available:
- **Full Refresh** — Complete content rewrite with new outline
- **SEO Refresh** — FAQ, schema, meta, keyword, and title only
- **Meta Refresh** — Title and meta description only (for CTR fixes)

**SEO Title Quality Controls:**
- **Number Accuracy Validator** — If the AI generates a title containing a number (e.g., "5 Ways to..."), the system extracts real countable structures from the post body (H2 sections, `<ol>` items, `<ul>` items). Only numbers ≤ 12 that match a body count (±1 tolerance) are allowed. Mismatches trigger a retry with the specific invented number called out, then a local strip as fallback.
- **Hallucination Guard** — Every capitalized word in the generated SEO title (likely a brand/product/technology name) is checked against the post's actual title and content. Words that don't appear anywhere in the post are flagged as hallucinations. The system retries once with the specific hallucinated topics called out, then falls back to the original post title if the retry also hallucinates.

**SEO Title Audit Tool** — A maintenance tool in the Settings tab finds all published blog posts whose SEO title contains a standalone integer ≤ 12 and re-runs only `step_seo_title` against the new accuracy rules. Does NOT update `last_page_refresh`, refresh history, focus keyword, meta description, or post body — pure title rewrite. Batched processing with live progress and per-row before/after logging.

### Featured Product System

Blog posts can be associated with a WooCommerce product. The system injects `[itu_featured_product id="123"]` shortcodes into the post body at two canonical positions (after the first `</p>` and before the last `<h2>`). The shortcode renders at page-view time from a user-editable HTML template.

**Architecture:**
- `_seom_featured_product_id` post meta stores the association (shared constant `SEOM_FEATURED_PRODUCT_META_KEY`)
- Shortcode renders using the template stored in `seom_featured_product_template` option (editable via Settings → Featured Product Template)
- Template placeholders: `{title}`, `{short_description}`, `{button_url}`, `{button_label}`, `{button_target}`, `{permalink}`, `{udemy_url}`
- Products with an ACF `udemy_url` field get "Get this course on Udemy at the lowest price →" button; otherwise "View Course →"
- Shortcode + template = render-time evaluation, so changes to product data (title, description, Udemy URL) take effect immediately without re-generating the blog

**Integration Points:**
- **SEO Monitor Dashboard** — "Featured" button on each blog row in the Indexed Pages tab opens a product search modal. Saves the meta AND injects the shortcode immediately.
- **Admin Bar** — "Inject Featured Product" node on single blog post views (front-end). Opens a modal, search for product, click to assign. Injects shortcode + reloads page.
- **Blog Queue** — Topics generated against a product carry `featured_product_id` through the queue. The blog creation pipeline injects the shortcode during content generation.
- **AI Product Manager** — "Find Blogs" button per product row. Search by keyword for blogs without a featured product, bulk-assign with batched processing.
- **Full Refresh Survival** — `SEOM_Blog_Refresher::full_refresh()` re-injects the shortcode after every content rewrite by reading the post meta.

**All shortcode injection paths preserve the original `post_modified` date** to prevent featured product assignments from bumping posts to the top of date-sorted archives.

### Stale Year Detection

Before any refresh runs, titles are scanned for outdated years and automatically updated:
- "Job Trends for 2025" → "Job Trends for 2026" (current year)
- **Product version protection** — "Windows Server 2016", "Word 2019", "SQL Server 2022" are NOT modified
- SEO titles (RankMath) are updated alongside post titles
- Permalinks are preserved — only the display title changes, not the URL

### Queue Processing

Both SEO AI AutoPilot and Blog Queue use **inline worker processing** with `fastcgi_finish_request()`:
- Click "Process Queue (Background)" → AJAX response detaches immediately, worker runs inline in the same PHP process
- No dependence on WP-Cron loopback (which silently fails on Local by Flywheel and many shared hosts)
- UI polls every 2 seconds for live status updates (current item, completed count, remaining)
- **Stop button** — sets a DB flag read via direct `$wpdb` query (bypasses object cache)
- **Reset State** — clears stuck locks, processing flags, and resets stuck items to pending

**Queue Resilience:**
- **Stuck item recovery** — Items in `processing` for > 5 minutes are auto-reset to `pending`
- **Failed item retry** — Failed items reset to `pending` on next queue run
- **Duplicate prevention** — Same post_id cannot be added to queue if already pending, processing, or recently completed
- **Completed item cleanup** — Items with `completed` status are deleted after 24 hours (audit trail preserved in refresh_history)
- **Stall detection** — If no progress for 90 seconds, polling stops with a warning and the user can click again to resume

### Admin Bar Integration

When browsing the frontend as an admin, the WordPress admin bar shows:

- **GSC Stats** — Clicks, impressions, and average position for the current page (28-day rolling snapshot)
- **AI Refresh** menu on any single post, product, or page:
  - **Products & Blog Posts**: Full Refresh, SEO Refresh, Meta Only
  - **Pages**: Meta Only
  - Queues immediately via AJAX with visual confirmation
- **Inject Featured Product** — Blog posts only. Opens a search modal to associate a WooCommerce product and inject the shortcode immediately without running an AI refresh.

### Automation Schedule

| Time | Task |
|------|------|
| 1:00 AM | Collect GSC 28-day rolling page metrics + site-wide totals (7/14/28/60/90/180 day ranges) |
| 1:00 AM | Collect true single-day per-page GSC data for T-3 (finalized daily metrics) |
| 1:00 AM | Capture untracked URLs + update daily summary for trend charts |
| 1:30 AM | Collect keyword intelligence & 28-day trends |
| 2:00 AM | Analyze pages, score, and populate refresh queue |
| 6:00 AM | Begin processing queue (10-min intervals between items) |
| Daily | Goal progress email with status updates |
| 1st of Month | Auto-close expired goals, AI-generate new monthly goals |
| Wednesday 3:00 AM | Backfill 30-day and 60-day performance metrics |
| Sunday 4:00 AM | Expand top keywords via Google Autocomplete |

### Trend Charts (Chart.js)

Interactive line charts powered by Chart.js on both the Indexed Pages and Performance Tracker tabs:

**Indexed Pages chart:**
- Impressions and Clicks trend lines (separate Y-axes) with prior-period dashed overlay
- 7/14/28/60/90/180-day range selector plus custom date picker
- Group-by: Daily / Weekly / Monthly / Quarterly
- Responds to Type (All/Products/Blogs/Pages/Other) and Filter selections
- Summary bar with period totals + prior-period delta + date ranges

**Performance Tracker charts (side by side):**
- Left: Refreshed pages performance over time (impressions + clicks with prior-period overlay)
- Right: Daily refresh activity (stacked bar — full vs meta-only)
- Group-by: Daily / Weekly / Monthly / Quarterly
- Both respond to Type, Period, and Trend filters
- Summary bar with before → now comparisons for filtered pages

### Performance Tracking

The Performance Tracker provides window-based before/after comparison for every refresh:

**Trend Classification** (single source of truth — shared between backend `seom_classify_refresh_trend()` and frontend `getTrend()`):

| Trend | Criteria |
|-------|----------|
| Strong | Clicks UP AND impressions UP AND position improved (>0.3) |
| Declining | Clicks DOWN AND impressions DOWN AND position worsened |
| Clicks Up | Clicks increased (not Strong) |
| Clicks Down | Clicks decreased (not Declining) |
| Impressions Up | Impressions increased, clicks flat |
| Impressions Down | Impressions decreased, clicks flat |
| Ranking Up | Position improved or newly ranked, clicks/impressions flat |
| Ranking Down | Position worsened or lost ranking, clicks/impressions flat |
| Too Early | < 5 days since refresh |
| Flat | No meaningful change |

**Window-based metrics:**
- Classification uses daily per-page metrics from `seom_daily_page_metrics` aggregated over the current window vs. the prior window of the same length
- Cards, chart bar, and table all operate on the exact same filtered row set
- One row per distinct post (latest refresh in the window via MAX(id) dedupe)
- Custom date ranges supported with explicit start/end date pickers

**Trend descriptions** shown beneath the filter buttons explain what each bucket means and why counts may be small.

### True Daily GSC Data Collection

The system collects two types of GSC data each night:

1. **28-day rolling snapshot** (existing) — Per-page aggregate used for scoring, analysis, and refresh decisions
2. **True single-day per-page data** (T-3) — Actual metrics for one specific day, stored in `seom_daily_page_metrics`

**Why T-3?** GSC data is typically finalized 3 days after the date. Collecting for today-minus-3 ensures accurate, stable numbers.

**Manual collection** — Available on the History tab: select any date and click "Collect Daily Data" to backfill missing days or verify specific dates.

### Dashboard Tabs

| Tab | Purpose |
|-----|---------|
| Overview | KPIs, queue status, goal mini-cards, recent improvements/declines, today's refresh count |
| Indexed Pages | All tracked pages with GSC metrics, trend chart, date range filtering (including custom dates), type filters, category filters, cooldown display with override, Featured Product button per blog row |
| Performance Tracker | Window-based refresh performance chart + activity chart, trend classification with 10 buckets, custom dates, group-by, sortable columns |
| Queue | Pending refreshes with background processing, stop/reset, priority scores, bulk actions |
| History | GSC Collection History, Refresh History with before/after metrics |
| Keywords | GSC keyword intelligence, opportunity scores, content gaps, lost keywords |
| Keyword Gaps | Imported competitive keywords with AI tagging, filters, usage tracking |
| Goals | Active/completed/missed goals with progress bars, AI feasibility, capacity analysis |
| Settings | GSC credentials, thresholds, cooldown periods, processing limits, competitive research config, notification email, Featured Product Template editor, SEO Title Audit tool |

### Windowed Pagination

All paginated tables across the platform use a shared `window.seomBuildPagination()` helper that renders:

```
« First  ‹ Prev  1 … 4  5  [6]  7  8 … 71  Next ›  Last »
Page 6 of 71 · 3,550 items
```

Applied to: Indexed Pages, Performance Tracker, GSC History, Refresh History, Keywords, Keyword Gaps.

---

## Plugin 2: Blog Queue

### What It Does

Blog Queue is the content production engine. It manages a pipeline of blog topics from ideation through AI-powered creation to publication, with full keyword targeting, SEO optimization, and featured product integration baked into every step.

### AI Topic Generation

The topic generator analyzes multiple data sources to suggest unique, non-overlapping blog ideas:

- **GSC Content Gaps** — Keywords where the site has impressions but no dedicated post
- **Trending Keywords** — Rising search queries from GSC
- **Competitive Gap Keywords** — Imported from SEMrush, filtered by category and volume
- **LSI Keywords** — Semantically related variants for topic differentiation
- **Featured Product** — Optional WooCommerce product picker. When selected, all generated topics are supporting blogs for that course, and a Featured Product block is automatically injected into each published blog.
- **Existing Content Audit** — Cross-references all existing post titles and queued topics to prevent duplication

**Filtering options:**
- By keyword gap category
- By volume tier: High Volume (1000+), Low Difficulty (KD ≤30), Quick Wins
- By focus area (free-text)
- By featured product (WooCommerce product search)
- Quantity: 5-50 topics per generation

### Content Creation Pipeline

Blog creation uses `SEOM_Blog_Refresher::build_blog_writer_instruction()` — the same shared prompt used by the content refresh engine — ensuring identical quality standards for both new and refreshed content.

| Step | Action | Output |
|------|--------|--------|
| 1 | Generate Outline | 6-8 section structure with bullet points |
| 2 | Write Content | 2,000-2,500 word HTML article with citations, formatting, keyword injection + Featured Product shortcode injection |
| 3 | SEO Metadata | Meta description, focus keyword, SEO title (via SEOM_Blog_Refresher canonical steps) |
| 4 | FAQ & Schema | 5 FAQs in accordion format + JSON-LD FAQPage schema (via SEOM_Blog_Refresher canonical steps) |

### Keyword Lifecycle Management

```
Available → Reserved (queued) → Used (published) → Cooldown (90 days) → Available
```

- **Reservation**: Keywords are locked when topics are added to the queue
- **Usage Tracking**: Stamped with post ID and date when the blog is published
- **Cooldown**: 90-day configurable period prevents the same keyword from being targeted in multiple posts
- **Release**: Keywords are freed if a queue item is deleted before processing (both single-row Remove and bulk Delete release keywords from both `seom_keyword_usage` and `seom_keyword_gaps` tables)

### Queue Processing & Distribution

- **Round-Robin Distribution** — Items are distributed across dimensions in rotation. The bucket key uses a composite priority: featured product ID (if set) → gap category (if set) → fallback. This ensures blogs for different products and categories interleave rather than clustering by topic.

- **Daily Limits (Automated)** — Configurable cap enforced for nightly cron runs
- **Manual Override** — Each click of "Process Queue" provides a fresh +25 quota independent of the daily count. Multiple clicks = multiple quotas.
- **Inline Worker** — Processing uses `fastcgi_finish_request()` to detach the AJAX response, then runs `bq_process_queue(true, 25)` inline. No WP-Cron loopback dependency.
- **Per-Blog Notifications** — Email sent immediately after each blog is created or fails, plus a batch summary email at the end.

### Content Taxonomy & AI Categorization

A custom hierarchical taxonomy `itu_content_category` (non-URL-rewriting) provides structured content categorization across 7 parent categories and ~70 subcategories.

**Taxonomy Structure:**
1. Certification Tracks (CompTIA, Cisco, Microsoft, AWS, etc.)
2. Technology Domains (Cybersecurity, Cloud, Networking, DevOps, etc.)
3. Career & Professional Development
4. Training & Learning
5. Industry Trends & News
6. Beginner & Foundational Content
7. Tools & Resources

**AI Categorization Tool** (Content Categories tab in Blog Queue):
- **Run Batch** — Picks N uncategorized posts, sends title + meta description + first 200 words + tags to the AI, validates the response against the actual taxonomy terms.
- **Confidence Tiers** — Configurable thresholds: auto-apply (≥0.90), flagged/spot-check (≥0.70), manual review (<0.70).
- **Deterministic Vendor Override** — A regex-based detector scans the post title + first 300 words for certification names, exam codes, and vendor brands. When detected, the correct vendor is forced as primary (or added to secondaries) regardless of the AI's pick. This prevents the AI from confusing "Security+" with Cisco or assigning generic content to a vendor.
- **Review Queue** — Staging table (`bq_categorization_log`) with filter by status, bulk approve/reject/delete, inline override editor for primary + secondaries (max 5 total: 1 primary + 4 secondary).
- **Auto-categorize on Publish** — `transition_post_status` hook fires on every new blog publish, running the full categorizer pipeline with confidence thresholds respected.
- **Reset All Categories** — Nuclear reset button strips all `itu_content_category` assignments and clears the log.

**Helper Function** — `bq_get_content_taxonomy_tree($args)` returns a nested array of parent categories with their children, post counts rolled up to parents, and optional empty-category filtering. Used by the admin taxonomy panel, the categorizer prompt builder, and the front-end archive sidebar.

### Blog Archive Front-End

The blog archive page (`archive.html` → `[itu_blog_archive]` shortcode) renders a two-column layout:

**Left: Card Grid**
- 3-column responsive grid (`repeat(auto-fill, minmax(280px, 1fr))`)
- 19 blog cards + 2 promo cards = 21 items = 3×7 even grid per page
- Promo cards (from the admin-managed ITU Promo Cards option) injected at positions 5 and 10, on every page load including Load More
- Ordered by published date (descending)
- Practice tests (posts in `practice-tests` category) excluded
- Load More button appends next 19+2 cards via AJAX

**Right: Category Sidebar (desktop only, hidden on mobile)**
- Styled to match the `itu-plans-bar` visual language (fuchsia dashed border, tinted background, Fira Code headers)
- 7 collapsible parent groups (+/− toggle, one open at a time)
- Subcategory checkboxes with post counts
- Only categories with posts shown
- Parent counts roll up child counts
- "Clear all" button
- Thin scrollbar (light gray)

**Filtering:**
- Checkbox changes fire AJAX → grid replaces with filtered results
- OR semantics — checking Microsoft + Cisco shows posts in either
- Active filter chips appear above the grid with individual × remove buttons
- URL state via `history.replaceState` (`?cats=12,15,28`) — back/forward, bookmarkable, shareable
- Filter changes scroll to the top of the grid

**Practice Tests** — The `/category/practice-tests/` archive renders a clean 3-column card grid with standard numbered pagination, no sidebar.

### Discover More Sticky Panel

A sticky "Discover More, Learn More" panel on single blog posts that promotes related content:

**Design:**
- Full-width bar fixed to viewport bottom, dark fuchsia background (`#86198f`)
- Tab peeks up from the bottom at all times (desktop only, hidden < 1024px)
- Panel slides up with smooth animation when triggered
- 6 related blog cards in a horizontal grid (title + 12-word excerpt)
- Cards: white text on semi-transparent background, flip to white-on-dark on hover
- Prominent × close button

**Trigger:** Either condition fires the panel:
- 15 seconds on page (proves the user is reading)
- Scrolled past the first `<h2>` (intro is done, they're into the body)

**Behavior:**
- Dismissible — × closes the panel, sessionStorage remembers per post
- Tab always clickable — click to reopen even after dismissing
- Related posts sourced from the same cached data as the `[itu_related_articles]` shortcode (tags, categories, focus keyword, title word overlap scoring, 24-hour cache)
- 200px padding added to footer so the panel never covers footer links

### Email Notifications

- **Per-Blog Alert**: Sent immediately when each blog is created or fails
- **Daily Summary**: Aggregate report of all blogs created/failed during the day's processing run

---

## Plugin 3: AI Product Manager

### What It Does

AI Product Manager provides bulk AI-generated content for WooCommerce products (descriptions, short descriptions, FAQ, schema, SEO metadata) and manages featured product associations across the blog library.

### Product Processing Pipeline

6-step AJAX process per product:

| Step | Action |
|------|--------|
| 1 | Generate long-form product description |
| 2 | Generate short description |
| 3 | Generate FAQ HTML (accordion format) |
| 4 | Generate FAQ JSON-LD schema |
| 5 | Update RankMath SEO (focus keyword + meta description) |
| 6 | Save timestamp |

Supports bulk processing with visual per-row status tracking.

### Featured Product Blog Management

**Blogs Column** — Each product row shows a count of blog posts featuring that product. Clickable to open the Assigned Blogs modal.

**Find Blogs** — Per-product button opens a keyword search modal:
- Search published blog posts by keyword (title + first 2000 chars of content)
- Only shows posts WITHOUT an existing featured product
- Cap at 100 results
- Bulk-assign with batched processing (5 per AJAX call with live progress)
- All assignments preserve original `post_modified` date

**Assigned Blogs Modal** — Click the blog count to manage existing assignments:
- View all blogs featuring this product
- Bulk **unassign** — removes meta + strips shortcode (batched with progress)
- Bulk **reassign** to a different product — updates meta + swaps shortcode (e.g., migrating N10-008 blogs to N10-009)
- Product dropdown for reassignment target

---

## Integration Architecture

```
                    Google Search Console
                 (clicks, impressions, CTR, position)
                              |
                              v
    +----------------------------------------------------+
    |                    SEO AI AutoPilot                 |
    |  Collector  --->  Analyzer  --->  Processor         |
    |  (GSC API)       (Score)        (Refresh)           |
    |                                                     |
    |  Site Totals       Goal Engine     Capacity Planner |
    |  (multi-range)    (AI feasibility) (adaptive limits) |
    |                                                     |
    |  Keyword Researcher    Keyword Gap Importer          |
    |  (trends, mapping)     (SEMrush CSV)                |
    |                                                     |
    |  Featured Product     SEO Title Validator            |
    |  (shortcode+template) (number+hallucination guard)  |
    +----------+---------------------+-------------------+
               |                     |
               v                     v
    +----------------------------------------------------+
    |                    Blog Queue                       |
    |  Topic Generator --> Queue Manager --> Content      |
    |  (AI + keywords     (round-robin     Pipeline       |
    |   + products)       + product dim)   (shared prompt)|
    |                                        |            |
    |  Content Taxonomy    Archive Front-End  |            |
    |  (AI categorize)     (sidebar filter)  |            |
    |                                        |            |
    |  Discover More Panel  Promo Cards      |            |
    +----------------------------------------------------+
               |
               v
    +----------------------------------------------------+
    |               AI Product Manager                   |
    |  Bulk AI Processing    Featured Product Search      |
    |  (6-step pipeline)     (find + assign + reassign)   |
    +----------------------------------------------------+
               |
               v
    +----------------------------------------------------+
    |             WordPress / WooCommerce                 |
    |  Rank Math (SEO Meta)  |  ACF (FAQ/JSON-LD)        |
    |  Posts / Products      |  itu_content_category      |
    +----------------------------------------------------+
```

---

## Database Schema Summary

### SEO AI AutoPilot Tables (12)

| Table | Purpose | Key Fields |
|-------|---------|------------|
| seom_page_metrics | 28-day rolling GSC snapshots per page | clicks, impressions, ctr (decimal 7,4), position, top_queries |
| seom_daily_page_metrics | True single-day per-page GSC data (T-3) | date, post_id, url, clicks, impressions, ctr, position |
| seom_daily_summary | Pre-aggregated daily metrics for trend charts | total_pages, clicks, impressions, position, gsc totals, daily totals |
| seom_refresh_queue | Pending content refreshes | post_id, category (A-F/M), priority_score, refresh_type, status |
| seom_refresh_history | Audit trail of all refreshes | before/after metrics at 30d and 60d, research_results |
| seom_keywords | GSC keyword intelligence | trend_direction, opportunity_score, is_content_gap |
| seom_keyword_gaps | Imported competitive keywords | search_volume, keyword_difficulty, tag, last_used_at |
| seom_keyword_usage | Keyword cooldown tracking | status (reserved/used), used_at, post_id, queue_id |
| seom_lsi_keywords | LSI keyword relationships | seed_keyword → lsi_keyword pairs |
| seom_keyword_suggestions | Autocomplete expansion results | seed_keyword → suggestion |
| seom_goals | SEO goal tracking | metric, baseline_value, current_value, target_value, deadline, priority |
| seom_untracked_metrics | Non-WordPress GSC URLs | url, clicks, impressions, ctr, avg_position, url_type |

### SEO AI AutoPilot Options (stored in wp_options)

| Option | Purpose |
|--------|---------|
| seom_site_totals | Latest true GSC site-wide totals (clicks, impressions, CTR, position) |
| seom_site_totals_by_range | Multi-range cache: 7/14/28/60/90/180-day GSC no-dimension aggregates |
| seom_site_totals_history | Daily site totals with 90-day retention for historical comparisons |
| seom_featured_product_template | User-editable HTML template for the `[itu_featured_product]` shortcode |
| seom_queue_processing | Manual queue processing state flag |

### Blog Queue Tables (3)

| Table | Purpose | Key Fields |
|-------|---------|------------|
| bq_queue | Pending blog topics | title, gap_category, target_keywords, featured_product_id, status |
| bq_history | Creation audit log | title, post_id, status, error_message |
| bq_categorization_log | AI categorization staging | post_id, batch_id, primary_term_id, secondary_term_ids (JSON), confidence, status, error_message |

### Key Post Meta

| Meta Key | Purpose |
|----------|---------|
| _seom_featured_product_id | Featured product association (shared across all three plugins) |
| _bq_primary_content_category | Primary content category term ID (distinguishes primary from secondaries) |
| rank_math_title | SEO title (generated by step_seo_title with number accuracy + hallucination guards) |
| rank_math_description | Meta description |
| rank_math_focus_keyword | Focus keyword (GSC-driven or AI-generated) |
| last_page_refresh | Timestamp of last AI refresh |

---

## Technical Resilience

### Timeout Prevention
All long-running operations use batched processing with live progress:
- Blog creation: 4-step AJAX pipeline
- SEO AI AutoPilot queue: inline worker with `fastcgi_finish_request()`
- Blog Queue processing: same inline worker pattern with +25 manual quota
- Featured product assignment: batched in chunks of 5 with sequential AJAX calls
- AI categorization: detached background worker with 2-second progress polling + 90-second stall detection
- GSC data collection: 2-phase batch processing (50 posts per batch)
- SEMrush import: multi-phase (parse → upsert batches)
- SEO title audit: batched with per-row before/after logging

### Data Integrity
- Growing keyword intelligence (upsert, never delete-rebuild)
- Featured product shortcode injection preserves `post_modified` date
- Deterministic vendor override for content categorization (regex-based, never trusts the AI for vendor assignment)
- Content taxonomy seeded once on activation, admin deletions/renames persist
- Window-based Performance Tracker metrics — cards, chart, and table all use the same filtered row set
- SEO title number accuracy validated against actual body content structures
- SEO title hallucination guard prevents invented brands/topics
- Shared blog writer instruction — single source of truth for refresh and new-blog prompts
- Round-robin queue distribution with product + category composite bucketing

### AI Model Management
- Centralized AI Settings page (`mu-plugins/ai-settings.php`) for per-step model selection
- Supports OpenAI (GPT-4.1/5.x), Anthropic (Claude), Google (Gemini) providers
- Auto-detects reasoning models (GPT-5, o1, o3, o4) and sends `reasoning_effort` parameter (not `reasoning` which is the Responses API shape)
- API key validation — prevents selecting models for providers without configured keys

### Security
- Nonce verification on all AJAX endpoints (non-fatal for public archive filter to support page caching)
- Capability checks (manage_options / manage_woocommerce)
- Prepared SQL statements throughout (no injection risk)
- Input sanitization on all user-provided data
- Featured product template stored with `wp_kses` filtering (no scripts/event handlers)

---

## Key Metrics & Capacity

| Metric | Value |
|--------|-------|
| AJAX Endpoints | 55+ (SEO AI AutoPilot) + 25+ (Blog Queue) + 10+ (AI Product Manager) |
| Database Tables | 15 total (12 SEO AI AutoPilot + 3 Blog Queue) |
| Cron Jobs | 8 scheduled events + manual queue chains |
| AI Steps per Blog | 4 (outline, content, meta, FAQ/schema) |
| AI Steps per Refresh | Up to 7 (outline, content, meta, FAQ HTML, FAQ JSON, keyword, title) |
| Page Categories | 6 (A through F) |
| Trend Classifications | 10 (Strong, Declining, Clicks Up/Down, Impressions Up/Down, Ranking Up/Down, Too Early, Flat) |
| Scoring Factors | 4 weighted dimensions |
| Goal Metrics | 11 trackable metrics |
| Content Taxonomy | 7 parent categories, ~70 subcategories |
| Source Categories for Citations | 7 distinct types |
| Keyword Sources | 5 (GSC, SEMrush, Autocomplete, LSI, Manual) |

---

## WordPress Theme Integration

The child theme (twentytwentyfive-child) includes SEO-focused enhancements:

- **Blog archive** — `[itu_blog_archive]` shortcode renders the sidebar + filterable card grid with promo cards and Load More pagination
- **Blog listing ordered by published date** — Newest content first, unaffected by featured product assignments or content refreshes
- **Related Articles** (`[itu_related_articles]`) — Multi-signal relevance engine on every blog post showing 10 related articles. Scores by shared tags (5pts), categories (3pts), RankMath keyword match (8pts), and title word overlap (2pts). Cached 24 hours per post.
- **Discover More panel** — Sticky bottom panel on single blog posts with 6 related content cards, triggered by scroll + time, dismissible, desktop-only
- **Admin bar** — GSC stats + AI Refresh menu + Featured Product injector on every single post/product/page view
- **Plans Bar** — Configurable CTA bar with fuchsia dashed border styling, used as the visual language for the archive sidebar and featured product blocks
- **Practice test exclusion** — Practice tests render their own clean archive template without the category sidebar

---

## Summary

This platform transforms SEO management from a manual, reactive process into an automated, data-driven system. It identifies what content needs attention through GSC intelligence, prioritizes work using a composite scoring algorithm, executes content improvements through AI with competitive research and strict quality controls, tracks results with verified daily GSC data, cross-promotes products within blog content via a shortcode-based Featured Product system, organizes content with AI-powered taxonomy categorization, and closes the loop with goal-driven planning that adjusts capacity and priorities based on measurable outcomes — creating a continuous improvement engine that scales across thousands of pages without manual intervention.
