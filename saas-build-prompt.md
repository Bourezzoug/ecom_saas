# Build Prompt — AI E-commerce Site Generator SaaS (WooCommerce first)

You are a senior full-stack engineer and software architect. You will design and build a production-quality SaaS from scratch, milestone by milestone. Work carefully, explain decisions briefly, and never skip the planning phase.

---

## 0. How you must work

1. **Phase 1 — Architecture only (no code yet).** Produce:
   - Full architecture overview (components, data flow, deployment)
   - Complete database schema (tables, columns, types, indexes, relations)
   - Folder structure for the monorepo
   - Section JSON schema format and site-plan JSON format
   - AI provider interface design
   - WordPress plugin architecture
   - API endpoint list (platform ↔ WordPress plugin)
   - Risks and open questions
   Then STOP and wait for my approval.
2. **Phase 2 — Implement milestone by milestone** (see section 9). After each milestone: summarize what was built, how to run it, how to test it, then STOP and wait.
3. Never invent requirements outside this document. If something is ambiguous, ask.
4. Write tests for every core feature. Keep code clean, typed, and documented.

---

## 1. Product summary

A SaaS where users generate a complete, ready-to-sell **WooCommerce store** and **product landing pages** with AI, edit them in a live visual editor on our platform, then publish them to their own WordPress site in one click. After publishing, everything stays fully editable in **Elementor**.

- **Target users:** freelancers and agencies building stores for clients (primary), store owners (secondary).
- **Business model:** monthly subscription plans that include AI credits; extra credit packs.
- **v1 scope:** WooCommerce + Elementor only.
- **Future (architecture must allow it, do NOT build now):** Shopify renderer, general WordPress business sites, managed hosting.

---

## 2. Tech stack (fixed)

**Platform**
- Laravel (latest stable) + Inertia.js + React + TypeScript
- Tailwind CSS (+ Alpine.js inside rendered section templates only)
- MySQL (or PostgreSQL — propose and justify)
- Redis + Laravel Queues + Horizon (all AI generation runs in queued jobs)
- Laravel Reverb (or polling fallback) for live generation progress
- Sanctum (API keys for the WordPress connector plugin)
- Laravel Cashier (Paddle) for subscriptions and credit purchases
- Pest for tests

**WordPress side**
- Custom WordPress plugin ("connector") — PHP 8.1+
- Base theme: Hello Elementor (or a minimal custom theme — propose)
- Elementor **free** (must NOT require Elementor Pro), using **Flexbox Containers** (not legacy sections/columns)
- WooCommerce
- mustache.php for rendering section templates

**Dev environment**
- Docker Compose with: Laravel app, MySQL, Redis, Ollama, and a **staging WordPress** (WordPress + MySQL + WP-CLI auto-installing pinned versions of Elementor and WooCommerce + our plugin)

---

## 3. AI layer (critical design)

### 3.1 Provider abstraction
Create an `AiProvider` interface with drivers. v1 driver: **Ollama (local)**. Architecture must allow adding Anthropic (Claude) and OpenAI drivers later with zero changes to business logic.

```
interface AiProvider {
    generateJson(string $system, string $prompt, array $jsonSchema, array $options = []): AiResult;
    generateText(string $system, string $prompt, array $options = []): AiResult;
    generateFromImage(string $system, string $prompt, string $imagePath, array $jsonSchema): AiResult; // for design import
}
```

- Config via `.env`: `AI_DRIVER=ollama`, `OLLAMA_HOST=http://ollama:11434`, per-task model routing, e.g.:
  - `AI_MODEL_PLANNER` (site plan)
  - `AI_MODEL_COPY` (section content)
  - `AI_MODEL_VISION` (screenshot/design import)
- Use Ollama's structured output (`format` = JSON schema) on `/api/chat`.
- `AiResult` contains: parsed data, raw output, model, tokens in/out, duration, cost-in-credits.

### 3.2 Reliability rules
- **The AI NEVER writes code (PHP, HTML, CSS, Liquid, classes).** It only outputs JSON that fills our predefined schemas.
- Validate every AI output against its JSON schema. On failure: one automatic repair attempt (send the validation errors back), then fail gracefully and refund credits.
- Keep each AI call **small and focused** (one section at a time) so small local models perform well. The planner outputs structure only; a second pass fills each section's content.
- Sanitize all text output (strip HTML/script).
- Log every generation in a `generations` table.
- A `FakeAiProvider` for tests returning deterministic fixtures.

### 3.3 Credits
- Every AI action has a configurable credit cost (config file): generate full store, generate landing page, regenerate one section, import design, generate product descriptions.
- Manual edits are free.
- Deduct credits atomically (DB transaction), refund on failure.
- `credit_transactions` ledger (never store only a balance).

---

## 4. Section system (single source of truth)

Each section type is defined ONCE and rendered in two places (React preview + Elementor widget). Structure per section:

```
/sections/{section-key}/
    schema.json       # editable fields: type, label, default, constraints, AI hint
    template.mustache # HTML using prefixed Tailwind classes + Alpine attributes
    meta.json         # name, category, thumbnail, which page types it fits
```

Rules:
- Templates use Mustache only (logic-less) so JS and PHP render identically.
- Tailwind classes are **prefixed** (e.g. `tw-`) and preflight is **disabled** to avoid conflicts with Elementor/WooCommerce.
- Tailwind CSS is compiled once from all templates and shipped with both the platform and the WP plugin. The AI never adds classes.
- Design tokens (colors, fonts, radius, spacing scale) are **CSS variables**. Templates read variables; the site's design settings set them. In Elementor, these variables are editable through widget/site controls.
- Alpine.js only for interactivity (tabs, accordions, variant pickers, galleries, mini-cart).

**Initial section library (v1, ~15):**
Header, Hero (3 variants), Product grid, Featured product, Categories grid, Benefits/USPs, Testimonials, FAQ, CTA banner, Newsletter, Footer.
**Product landing page sections:** Product hero with gallery + add-to-cart, Features, Before/after or comparison, Reviews, Guarantee, Sticky add-to-cart bar, FAQ.

WooCommerce-dependent sections (product grid, add-to-cart, gallery) must be rendered by **our own custom Elementor widgets** that pull live WooCommerce data — no Elementor Pro dependency.

---

## 5. Core user flows

### 5.1 Onboarding / project creation
User chooses one:
- **Describe:** store name, niche, target audience, language, tone, style preferences, brand colors (optional), logo upload (optional)
- **Import products:** CSV upload (WooCommerce format) or manual entry
- **Import design:** screenshot upload or URL → vision model extracts a site plan mapped onto OUR section library (never pixel copying of third-party designs)

### 5.2 Generation
1. Planner job → site plan JSON: pages → ordered sections (by section key) + global design tokens.
2. Content jobs (one per section, parallel) → fill each section's schema.
3. Live progress shown to the user.

### 5.3 Editor (React)
- Live preview rendering the Mustache templates with the project's JSON + CSS variables
- Page list, section list (add / remove / reorder via drag-and-drop)
- Click a section → side panel form generated from its `schema.json`
- "Regenerate this section with AI" (+ optional instruction) → costs credits
- Global design panel (colors, fonts, radius)
- Desktop / tablet / mobile preview
- Autosave + version history (restore previous versions)

### 5.4 Product landing page generator
Select a product (or create one) → AI generates a full landing page from the landing section set → editable in the same editor → published as a WooCommerce product page layout or a standalone page.

### 5.5 Publish
- User installs our WP connector plugin, pastes an API key → platform verifies connection (health check endpoint: WP version, Elementor version, WooCommerce version, PHP version).
- Publish pushes: design tokens, pages as `_elementor_data` (containers), products/categories into WooCommerce, menus, homepage setting.
- Plugin clears Elementor CSS cache after import.
- Re-publish updates existing pages (map by our IDs), never duplicates.
- Publish log with status per item.

### 5.6 Export
"Download ZIP" alternative: plugin + content import package for users who don't want to connect their site.

---

## 6. WordPress connector plugin

- Authenticated REST endpoints (API key sent in header, validated against platform; keys stored encrypted on platform).
- Endpoints: health check, receive design tokens, create/update page, create/update products & categories, set menus, set homepage, clear cache.
- Registers custom Elementor widgets (one per section type) that render the shared Mustache templates with mustache.php.
- Widget controls generated from `schema.json` so everything is editable in the Elementor panel.
- Enqueues compiled Tailwind CSS and Alpine only on pages that use our widgets (Elementor script/style dependencies).
- Re-initialize Alpine inside the Elementor editor on widget render (Elementor frontend `element_ready` hooks).
- Enable Elementor performance defaults where possible (optimized DOM, improved asset loading).
- Tested against pinned Elementor + WooCommerce versions (document them).

---

## 7. Database (propose final schema in Phase 1)

Minimum entities: users, teams (for agencies, multiple members), projects, pages, page_sections (order + JSON content), section_types (synced from /sections), design_tokens, products (local copy before publish), wp_connections, publish_logs, generations, credit_transactions, plans, subscriptions, project_versions.

---

## 8. Non-functional requirements

- Security: policies/authorization on every project resource, rate limiting on AI endpoints, encrypted API keys, no execution of AI output, file upload validation.
- Performance: all AI + publish work in queues; retries with backoff; timeouts.
- Observability: Horizon dashboard, structured logs, failed-job alerts.
- i18n-ready UI (English first; generated sites support any language the user selects, including RTL for Arabic).
- Clean README with setup via `docker compose up`.

---

## 9. Milestones (target: 4 weeks)

**M1 — Foundation**
Docker env (app, db, redis, ollama, staging WP), Laravel + Inertia + React + TS setup, auth, teams, projects CRUD, AI provider interface + Ollama driver + Fake driver, credits ledger.

**M2 — Section system + generation**
Section registry and 5 starter sections, Tailwind build pipeline (prefix, no preflight, CSS variables), site plan + content generation jobs with schema validation/repair, live progress.

**M3 — Editor**
Live preview renderer (Mustache in JS), section add/remove/reorder, schema-driven edit forms, regenerate section with AI, design panel, responsive preview, autosave + versions. Complete the full v1 section library.

**M4 — WordPress plugin + publish**
Connector plugin, custom Elementor widgets rendering shared templates, WooCommerce product/category import, publish + re-publish, cache clearing, health check, ZIP export.

**M5 — Landing pages + billing**
Product landing page generator, Paddle subscriptions + credit packs, plan limits, usage dashboard.

**M6 — Hardening**
Tests for all flows, error handling, rate limits, logging, README and deployment guide.

---

## 10. Out of scope for v1

Shopify, managed hosting, general (non-store) business sites, team billing per seat, marketplace of third-party sections, custom domains, white-label.

---

## 11. Start now

Begin with **Phase 1 (architecture only)** as described in section 0. Do not write implementation code until I approve the architecture.
