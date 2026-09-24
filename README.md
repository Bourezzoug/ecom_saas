# AI Store Generator (AISG)

Generate a complete WooCommerce store with AI, edit it visually, publish it to WordPress, and keep editing it in Elementor.

- Architecture: [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md)
- Tested WordPress stack: [docs/COMPATIBILITY.md](docs/COMPATIBILITY.md)
- Product spec: [saas-build-prompt.md](saas-build-prompt.md)

## Repository layout

```
apps/platform/      Laravel 13 + Inertia 3 + React 19 + TypeScript (the SaaS)
apps/wp-connector/  WordPress connector plugin (stub until M4)
sections/           Section library: schema.json + template.mustache + meta.json (M2)
packages/           Shared renderers / contracts (M2+)
docker/             Dockerfiles and init scripts
```

## Requirements

- Docker Desktop (Compose v2). On Windows, WSL2 backend.
- Optional: an NVIDIA GPU for Ollama. Without one, models run on CPU (slow but works).

## Setup

```bash
# 1. Root compose settings (ports, optional GPU)
cp .env.example .env
#    GPU: uncomment COMPOSE_PATH_SEPARATOR and COMPOSE_FILE in .env

# 2. Platform env
cp apps/platform/.env.example apps/platform/.env

# 3. Build the PHP image and install dependencies
docker compose build app
docker compose run --rm --no-deps app composer install
docker compose run --rm --no-deps app npm install
docker compose run --rm --no-deps app php artisan key:generate

# 4. Start everything
docker compose up -d

# 5. Database + section library
docker compose exec app php artisan migrate --seed
docker compose exec app php artisan sections:sync

# 6. Section CSS/JS runtime (rebuild after editing any template.mustache)
docker compose run --rm --no-deps -w /var/www/aisg/packages/section-styles app sh -c "npm install && npm run build"
```

The first `up` downloads the AI models (`ollama-init`, several GB) and installs the staging WordPress (`wp-init`). Follow their progress with `docker compose logs -f ollama-init wp-init`.

| Service | URL |
|---|---|
| Platform | http://localhost:8000 |
| Vite dev server (HMR) | http://localhost:5173 |
| Horizon (queues) | http://localhost:8000/horizon |
| Staging WordPress | http://localhost:8081 (admin: `admin` / `admin`) |
| Mailpit | http://localhost:8025 |
| Reverb (websockets) | ws://localhost:8090 |
| Ollama API | http://localhost:11434 |
| Postgres / Redis (host) | `localhost:54320` / `localhost:63790` |

The seeder creates `test@example.com` / `password`. New sign-ups get `CREDITS_SIGNUP_GRANT` credits (default 100 in `.env.example`).

## Everyday commands

```bash
docker compose exec app php artisan test              # Pest suite (uses the aisg_testing database)
docker compose exec app vendor/bin/pint               # PHP code style
docker compose exec app vendor/bin/phpstan analyse    # Static analysis (Larastan)
docker compose exec app npm run check                 # TS lint + format (vite-plus)
docker compose exec app npm run types:check           # TypeScript

docker compose exec app php artisan ai:ping                  # Smoke-test the configured model
docker compose exec app php artisan ai:ping --task=planner
docker compose exec app php artisan credits:grant <team-slug> 500
docker compose exec app php artisan credits:reconcile        # Ledger vs cached balance
```

## How generation works (M2)

1. **Generate store** on a project charges `generate_store` credits and queues `PlanSiteJob` on the `ai` queue.
2. The planner returns pages → section keys → one-line briefs, plus a design suggestion. The output is validated (schema + rules such as "exactly one home page"), repaired once if needed, and saved as pages, sections (with defaults) and design tokens.
3. One `FillSectionJob` per section writes that section's copy (validate → repair once → sanitise). A section that still fails keeps its defaults.
4. Progress reaches the browser through Reverb, with polling as a fallback. The finished store is shown in a read-only preview (`/{team}/projects/{id}/preview`).
5. If the planner fails or every section fails, the credits are refunded. If only some sections fail, the store is "partial" and there is no refund.

Every AI call is logged in the `generations` table (prompt, schema, raw output, errors, tokens, duration).

After changing PHP code, restart the workers: `docker compose restart horizon`.

## Editor (M3)

Open a generated project and click **Open editor** (`/{team}/projects/{id}/editor`):

- **Left:** the page's sections (drag to reorder, add, remove; header and footer are fixed), or the **Design** tab (colours, fonts, radius, spacing).
- **Centre:** live preview rendered in the browser by `@aisg/renderer`, the same templates WordPress renders. Click any section to edit it. Switch desktop / tablet / mobile.
- **Right:** a form generated from the section's `schema.json`, and **Rewrite with AI** (costs `regenerate_section` credits, refunded if it fails).
- Changes autosave (debounced, per section). **Versions** lists snapshots, and you can name one or restore any.

## Catalog & publishing (M4)

**Catalog** (`/{team}/projects/{id}/catalog`): add products by hand, import a WooCommerce CSV (Products › Export format; re-importing updates by SKU), or generate the store without products to get AI sample products as **drafts** (check the prices, then "Publish all drafts").

**Publish** (`/{team}/projects/{id}/publish`):
1. **Connect WordPress** shows a key once. Install the plugin ("Download the plugin"), open *AISG Connector* in WordPress, enter the platform URL and the key.
2. **Check connection** runs a signed health check (versions, warnings for untested versions).
3. **Publish to WordPress** pushes tokens → media → categories → products → header/footer → pages → menu → homepage → cache clear, with a per-item log. Re-publishing updates the same pages and products. Pages edited in Elementor since the last publish are skipped unless you tick "Overwrite".
4. **Export ZIP** = the plugin + a content package for WP Admin › AISG Connector › Import package (no connection needed).

The staging site in Docker is already set up for this. Platform URL for the plugin: `http://nginx`. Automated live check:
```bash
bash tools/e2e/publish-staging.sh <project-id>
```
Plugin dependencies: `docker compose run --rm --no-deps -w /var/www/aisg/apps/wp-connector app composer install --no-dev`.

## Section library

19 sections: header, footer, 3 heroes, product grid, featured product, categories, benefits, testimonials, FAQ, CTA banner, newsletter and 6 product-landing sections.

Each section lives in `sections/{key}/` as `schema.json` (fields, AI hints, constraints), `meta.json` and `template.mustache`. Rules: Mustache only (no partials or lambdas), `tw-`-prefixed classes, logical (RTL-safe) utilities, and no unescaped content. `php artisan sections:sync` validates everything against `sections/_meta-schema`, and the Pest suite checks it too. `RendererParityTest` fails if the JS and PHP renderers disagree on even one byte.

## AI configuration

All AI calls go through `App\Domain\Ai\Contracts\AiProvider`. Pick the driver and the per-task models in `apps/platform/.env`:

```dotenv
AI_DRIVER=ollama            # or "fake" for deterministic offline output
OLLAMA_HOST=http://ollama:11434
AI_MODEL_PLANNER=qwen3:4b
AI_MODEL_COPY=qwen3:4b
AI_MODEL_VISION=qwen2.5vl:3b
```

The defaults fit a 4 GB GPU. After changing a model, run `docker compose up ollama-init` to pull it.

## Troubleshooting

- **Performance on Windows**: file access from the containers to a Windows folder is ~150× slower than native. To compensate:
  - `apps/platform/vendor` lives in the `platform-vendor` Docker volume, so `composer install/require` must run in the container.
  - OPcache re-checks files at most once per second.
  - Vite pre-warms every page and pre-bundles dependencies.

  Result: ~0.1–0.3 s per page. Your IDE still reads the host copy of `vendor/`; refresh it after Composer changes with
  `docker compose cp app:/var/www/aisg/apps/platform/vendor apps/platform/`.
  For the best speed, clone the repo inside WSL2 (`\\wsl$\...`).
- **502 after recreating containers**: nginx resolves `app` per request, so this should not happen. If it does, run `docker compose restart nginx`.
- **Ollama without a GPU**: remove `COMPOSE_FILE` from `.env`. Or run Ollama natively on the host and set `OLLAMA_HOST=http://host.docker.internal:11434` in the `app`, `horizon` and `scheduler` services.
- **Tests hitting the wrong database**: `phpunit.xml` forces `DB_DATABASE=aisg_testing`, which Postgres creates on first boot (`docker/pgsql/create-testing-db.sql`). If your volume predates that script, create it manually: `docker compose exec pgsql createdb -U aisg aisg_testing`.
