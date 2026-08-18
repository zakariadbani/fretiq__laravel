#!/usr/bin/env bash
# fretiq Zoho mirror export — dump zoho_* mirror tables for import into another
# environment, so that environment skips a full Zoho API backfill.
# Excludes: zoho_tokens (secrets); run-machinery tables (zoho_sync_logs,
# zoho_sync_failures, zoho_sync_batches, zoho_bulk_read_jobs, zoho_sync_work_items,
# zoho_standard_sync_runs, zoho_standard_sync_work_items) which hold leases/resume
# state; zoho_marketing_links + zoho_user_mappings which hold local integer ids
# (rebuild on the target with `php artisan zoho:crm:link-identities`).
set -euo pipefail

MYSQLDUMP="${MYSQLDUMP:-/c/wamp64/bin/mysql/mysql8.4.7/bin/mysqldump}"
DB="${DB:-fretiq}"
DB_USER="${DB_USER:-root}"
OUT="${1:-storage/app/exports/zoho-data-$(date +%Y%m%d_%H%M%S).sql.gz}"

TABLES="zoho_users zoho_accounts zoho_contacts zoho_leads zoho_deals zoho_products \
zoho_quotes zoho_quote_items zoho_activities zoho_deal_stage_history \
zoho_quote_status_history zoho_actions_commercials zoho_transport_international \
zoho_field_manifests zoho_sync_checkpoints"

mkdir -p "$(dirname "$OUT")"

"$MYSQLDUMP" -u "$DB_USER" "$DB" $TABLES \
  --single-transaction \
  --no-create-info \
  --complete-insert \
  --replace \
  --set-gtid-purged=OFF \
  --no-tablespaces \
  --default-character-set=utf8mb4 \
  | gzip -9 > "$OUT"

echo "wrote $OUT ($(du -h "$OUT" | cut -f1))"
