# fretiq

fretiq is a single-tenant prospection SaaS built for TCL France. It automates the manual email campaign workflow used to win new freight demandes (RFQs): it pulls existing clients from Zoho CRM, discovers new prospects via SerpAPI + Hunter, sends targeted email campaigns, tracks engagement, and captures resulting demandes. Scope boundary: prospection only — no RFQ processing, quotation, tariffs, or booking.

## Tech stack

| Layer | Choice |
|---|---|
| Framework | Laravel 11 |
| PHP | 8.3 |
| Frontend | Bootstrap 5 + Blade + Livewire 3 + jQuery |
| Build tool | Laravel Mix / Webpack (NOT Vite — no vite.config.js patterns) |
| Auth / RBAC | spatie/laravel-permission |
| DataTables | yajra/laravel-datatables |
| Database | MySQL 8, database name `fretiq` |
| Queue | `database` driver |
| Base kit | Metronic v8.3.3 Laravel starterkit |

## Modules

**Prospection**

- Companies & Contacts — prospect/client database (normalized: company = organisation, contact = person)
- Segments — filter pipeline to build campaign audiences
- Campaigns, Campaign Templates, Sequences — campaign engine with drip sequence support
- Demandes — captured RFQ interest from campaign replies
- Suppressions — unified opt-out / bounce suppression list
- Sender Identities — sending addresses
- Prospect Criteria & Discovery — SerpAPI + Hunter prospect discovery with quotas
- Packages — discovery quota packages
- Planner & Prospection Dashboard — KPI / funnel analytics
- Observability — runtime monitoring

**Administration**

- Users / Roles / Permissions — backend CRUD user management
- Zoho — integration status and sync

## Getting started

1. Clone the repo, then install PHP dependencies using the local composer phar (there is no global composer assumption):

   ```bash
   php composer.phar install
   ```

2. Copy the environment file and generate the application key:

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

3. Create a MySQL 8 database named `fretiq`, then set the `DB_*` variables in `.env`.

4. Run migrations and seeders (one-time setup):

   ```bash
   php artisan migrate
   php artisan db:seed
   ```

5. Build frontend assets with Laravel Mix:

   ```bash
   npm install
   npm run dev        # development build with watch
   npm run production # production build
   ```

6. Start the development server:

   ```bash
   php artisan serve
   ```

   The app is available at http://localhost:8000.

## Running the app

```bash
# Development server
php artisan serve

# Queue worker — required for campaign sends and sync jobs
php artisan queue:work --sleep=3 --tries=3

# Scheduler — run manually in dev
php artisan schedule:run

# Tests
php artisan test

# Local mail preview: campaigns in local driver mode send through SMTP on port 1025.
# Run Mailpit (or equivalent) to preview outgoing emails.
```

## Campaign driver pattern

Campaign sending goes through a `CampaignsClient` driver interface. The `local` driver (default) simulates sends via Mailpit and a tracking-pixel stub — safe for development. The `zoho` driver (Zoho Campaigns API) exists but is **UNVERIFIED** and must not be enabled in production until live OAuth credentials, empirical API verification, SPF/DKIM/DMARC, bounce handling, and legal sign-off are all in place. Switch via the `ZOHO_CAMPAIGNS_DRIVER` environment variable.

## Dedicated Zoho V2 worker (deployment template)

Zoho V2 uses its own database-queue connection and queue. The reviewed worker command is:

```bash
php artisan queue:work zoho --queue=zoho --sleep=3 --tries=5 --timeout=900 --max-time=3600
```

An example Supervisor program is provided at `deploy/supervisor/fretiq-zoho-worker.conf.example`. It is a template, not proof that Supervisor is installed or provisioned. Review its PHP binary, application directory and operating-system user before copying it into Supervisor. Its safety envelope requires `numprocs=1`, `stopwaitsecs=1500`, `ZOHO_V2_QUEUE_RETRY_AFTER=1260`, and `ZOHO_V2_BULK_LEASE_SECONDS=1500`.

After installing or changing the reviewed template, an operator can reload and inspect Supervisor explicitly:

```bash
sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl restart fretiq-worker-zoho:*
sudo supervisorctl status fretiq-worker-zoho:*
php artisan queue:monitor zoho:zoho --max=100
php artisan queue:failed
```

Keep automatic synchronization and nightly reconciliation disabled in Paramètres > Zoho until the dedicated worker is healthy and the shadow backfill, reconciliation, quarantine, security, and seven-day observation gates have passed. Enabling the worker does not authorize live Zoho calls.

## Zoho V2 controlled rollout

Zoho screens and manual actions are controlled by permissions. Automatic delta synchronization and nightly reconciliation are stored in Paramètres > Zoho and both default to disabled. The implementation does not run migrations, seed permissions, or call live Zoho automatically. Use this sequence only in an approved maintenance window:

1. Leave both Zoho automation switches disabled, provision the dedicated worker above, and confirm it is healthy.
2. Review the pending schema without changing data:

   ```bash
   php artisan migrate:status
   php artisan migrate --pretend
   ```

3. After explicit migration and ACL approval, back up the environment using the project deployment runbook, then apply the schema and permissions:

   ```bash
   php artisan migrate --force
   php artisan db:seed --class="Database\Seeders\Acl\PermissionsSeeder" --force
   php artisan permission:cache-reset
   ```

4. Verify the permission-controlled operations dashboard, explorer, and marketing screens; verify OAuth, queue health, schema manifests, and redacted error rendering.
5. Keep automatic synchronization disabled. Run the read-only inventory, then a shadow Records backfill and reconciliation:

   ```bash
   php artisan zoho:crm:inventory
   php artisan zoho:crm:sync --mode=backfill
   php artisan zoho:crm:sync --mode=reconcile
   php artisan zoho:crm:retry-failures
   ```

6. Reconcile remote/local counts, currencies, owners, quote items, tombstones, and quarantines in `/admin/zoho`. Do not continue while a critical quarantine, missing required manifest, or stale required module remains.
7. Enable automatic synchronization and nightly reconciliation in Paramètres > Zoho, then observe hourly delta plus nightly reconciliation for seven days. `ZOHO_V2_BULK_VERIFIED_MODULES` remains empty until a module-specific live export is proven complete against Records.
8. Grant CRM explorer and marketing permissions independently only after portfolio isolation and dashboard sample reconciliation pass.
9. Retire the legacy lean sync only after Accounts/Contacts parity is documented. Roll back UI access by revoking the corresponding permission, and pause automated runs from Paramètres > Zoho; do not delete mirrored or tombstoned history.

Every live Zoho endpoint, parameter, field, or Bulk module enabled after this baseline requires a sanitized `STATUS: 200 + summary` empirical verification. Never place payload PII, OAuth material, or raw exception text in rollout notes.

## Key environment flags

| Variable | Purpose |
|---|---|
| `ZOHO_CRM_DRIVER` | Zoho CRM sync driver: `local` (dev stub) or `zoho` (live API) |
| `ZOHO_CAMPAIGNS_DRIVER` | Campaign send driver: `local` (default, Mailpit simulation) or `zoho` (live — see prerequisites above) |
| `SERPAPI_API_KEY` / `HUNTER_API_KEY` | Prospect discovery APIs |
| `DISCOVERY_DRIVER` | Discovery driver: `local` or live |
| `APP_URL` | Must be a publicly reachable host for open-tracking pixels to register; `localhost` means opens never record (use ngrok or a resolvable local domain when testing tracking) |

## Conventions

All backend CRUD modules follow one pattern: `BackendController` base extended with `Crudable` and `Datatableable` traits, configured via a typed `BackendResource` config object (fail-fast constructor — throws if model class, route prefix, view path, or permission string is missing).

- **Routes:** `admin.{models}.{action}` (plural snake_case), auto-loaded from `routes/Backend/**`. **Views:** `resources/views/backend/contents/{models}/crud/`.
- Sidebar menu entries in `config/global/menu.php`; shared enums in `config/global/data.php`.
- **Roles:** `superadmin` (all permissions), `admin` (all except the six superadmin-only permissions: `manage packages`, `view provider quota`, `manage roles`, `manage permissions`, `view settings`, and `edit settings`), `commercial` (view/create/edit prospection entities only; no delete, no user/role management). Permission naming: `{action} {entity}`, plus the special strings `backend.access` and `send campaigns`.

## Safety rules

- Never use `Mail::raw()` for prospection emails — Gmail silently drops it. Always use a real Mailable class.
- Campaign sending is gated by the `send campaigns` permission, enforced at the controller layer.
- Secrets live in `.env` only — never committed, never logged.

## Further documentation

Detailed specs, planning documents, and credentials live in sibling directories outside this repo (`../structure/` for specs and planning, `../memory/` for operational notes). See `CLAUDE.md` in the repo root for agent/contributor conventions and hard rules.
