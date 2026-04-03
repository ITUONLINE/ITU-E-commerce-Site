# SEO & Content Automation Platform — Executive Summary

**Prepared:** April 2, 2026
**Platform:** WordPress / WooCommerce
**Sites:** ITU Online Training, Vision Training Systems

> **Note:** The rendered HTML version of this document is at `about.html` in this same folder and is accessible via the "Documentation" link on the WordPress Plugins page for both SEO Monitor and Blog Queue.

---

## Overview

We have built an integrated SEO and content automation ecosystem consisting of two core WordPress plugins — **SEO Monitor** and **Blog Queue** — that work together to create a closed-loop content intelligence and production system. The platform connects to Google Search Console for real-time performance data, imports competitive intelligence from SEMrush, and uses AI to both identify content opportunities and produce publication-ready blog posts with full SEO optimization.

The system is designed to be **site-agnostic** — all AI prompts, branding references, and configurations dynamically pull the site name, making the entire platform portable across multiple properties.

---

## Plugin 1: SEO Monitor

### What It Does

SEO Monitor is the intelligence and optimization engine. It continuously monitors every indexed page on the site through Google Search Console, scores pages against a multi-factor algorithm, and automatically refreshes underperforming content using AI.

### Data Collection & Intelligence

- **Google Search Console Integration** — Authenticated via OAuth2 service account. Collects daily snapshots of clicks, impressions, CTR, and average position for every published page. Stores top 5 search queries per page for AI context injection during content refresh.

- **Keyword Intelligence** — Maintains a growing keyword database that tracks 28-day trends (rising, stable, declining), maps keywords to posts using fuzzy matching, detects keyword cannibalization (multiple pages ranking for the same query), and calculates opportunity scores (0-100) for each keyword.

- **Competitive Gap Analysis** — Imports keyword data from SEMrush CSV exports. Supports AI-powered auto-tagging into categories, programmatic deduplication of similar tags, and batch consolidation to keep categories manageable. Tracks keyword usage with a 90-day cooldown to prevent over-optimization.

- **Google Autocomplete Expansion** — Automatically enriches top-performing keywords with Google Autocomplete suggestions weekly, discovering long-tail variations and related queries.

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

The queue is populated using **round-robin balancing** across categories so the daily refresh mix covers different problem types rather than concentrating on one.

### Content Refresh Engine

Refreshes are executed through a multi-step AI pipeline:

1. **Outline Generation** — AI creates a structured 6-8 section outline
2. **Full Content Rewrite** — 2,000-2,500 word HTML article with callout boxes, comparison tables, lists, and blockquotes
3. **Meta Description** — 140-155 characters optimized for click-through
4. **FAQ Generation** — 5 detailed FAQ entries in accordion HTML format
5. **JSON-LD Schema** — FAQPage structured data for rich results
6. **Focus Keyword & SEO Title** — Rank Math integration

Three refresh tiers are available:
- **Full Refresh** — Complete content rewrite with new outline
- **SEO Refresh** — FAQ, schema, meta, keyword, and title only
- **Meta Refresh** — Title and meta description only (for CTR fixes)

Smart escalation: if a meta-only refresh doesn't improve performance, the system automatically escalates to a full refresh on the next cycle.

### Automation Schedule

| Time | Task |
|------|------|
| 1:00 AM | Collect GSC page metrics |
| 1:30 AM | Collect keyword intelligence & trends |
| 2:00 AM | Analyze pages, score, and populate refresh queue |
| 6:00 AM | Begin processing queue (10-min intervals between items) |
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
| Overview | KPIs, queue status, processing stats, today's refresh count |
| Indexed | All tracked pages with current GSC metrics |
| Performance Tracker | Before/after charts with trend filters |
| Queue | Pending refreshes with priority scores, bulk actions |
| History | Completed refreshes with metric comparisons |
| Keywords | GSC keyword intelligence, opportunity scores, content gaps, lost keywords |
| Keyword Gaps | Imported competitive keywords with AI tagging, filters, usage tracking |
| Settings | GSC credentials, thresholds, processing limits, notification email |

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
    |                    SEO Monitor                      |
    |  Collector  --->  Analyzer  --->  Processor         |
    |  (GSC API)       (Score)        (Refresh)           |
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

### SEO Monitor Tables (8)

| Table | Purpose | Key Fields |
|-------|---------|------------|
| seom_page_metrics | Daily GSC snapshots per page | clicks, impressions, ctr, position, top_queries |
| seom_refresh_queue | Pending content refreshes | post_id, category (A-F), priority_score, refresh_type |
| seom_refresh_history | Audit trail of all refreshes | before/after metrics at 30d and 60d |
| seom_keywords | GSC keyword intelligence | trend_direction, opportunity_score, is_content_gap, cannibalization_count |
| seom_keyword_gaps | Imported competitive keywords | search_volume, keyword_difficulty, tag, last_used_at |
| seom_keyword_usage | Keyword cooldown tracking | status (reserved/used), used_at, post_id, queue_id |
| seom_lsi_keywords | LSI keyword relationships | seed_keyword → lsi_keyword pairs |
| seom_keyword_suggestions | Autocomplete expansion results | seed_keyword → suggestion |

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
- SEO Monitor queue processing: step-based with 10-minute cron intervals
- GSC data collection: 2-phase batch processing (50 posts per batch)
- SEMrush import: multi-phase (parse → upsert batches)
- Tag consolidation: 3-phase (dedup → AI batch → apply batch)
- Keyword collection: batched at 200 keywords per AJAX call
- Auto-tagging: 40 keywords per AI call with running progress

### Data Integrity
- Growing keyword intelligence (upsert, never delete-rebuild)
- Lost keyword detection (keywords that disappear from GSC are flagged, not deleted)
- Keyword cooldown prevents over-optimization
- Round-robin queue distribution prevents topic clustering
- Top performer protection (pages with >5 clicks are never auto-refreshed)

### Security
- Nonce verification on all AJAX endpoints
- Capability checks (manage_options / manage_woocommerce)
- Prepared SQL statements throughout (no injection risk)
- Input sanitization on all user-provided data

---

## Key Metrics & Capacity

| Metric | Value |
|--------|-------|
| AJAX Endpoints | 25+ (SEO Monitor) + 13 (Blog Queue) |
| Database Tables | 10 total |
| Cron Jobs | 6 scheduled events |
| AI Steps per Blog | 4 (outline, content, meta, FAQ/schema) |
| AI Steps per Refresh | Up to 7 (outline, content, meta, FAQ HTML, FAQ JSON, keyword, title) |
| Page Categories | 6 (A through F) |
| Scoring Factors | 4 weighted dimensions |
| Source Categories for Citations | 7 distinct types |
| Keyword Sources | 5 (GSC, SEMrush, Autocomplete, LSI, Manual) |

---

## Summary

This platform transforms SEO management from a manual, reactive process into an automated, data-driven system. It identifies what content needs attention through GSC intelligence, prioritizes work using a composite scoring algorithm, executes content improvements through AI with strict quality controls, and tracks the results — creating a continuous improvement loop that scales across hundreds of pages without manual intervention.
