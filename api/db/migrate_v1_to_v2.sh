#!/usr/bin/env bash
#
# Copy the v1 `ginger_sync` schema (data + structure) from the old Supabase
# project (pmrgraakfhisuptdzxqn) into the v2 project (ttojhhtdcvrcviuguflx).
#
# Run on the Hostinger VPS — uses a one-off Postgres 16 Docker container
# for pg_dump + psql so no host packages are needed.
#
# Usage:
#   chmod +x api/db/migrate_v1_to_v2.sh
#   ./api/db/migrate_v1_to_v2.sh
#
# Then verify in the v2 Supabase SQL Editor that row counts match v1 before
# running the DELETE statements (printed by this script at the end).

set -euo pipefail

V1_HOST="db.pmrgraakfhisuptdzxqn.supabase.co"
V1_PORT="5432"
V1_USER="postgres"
V1_DB="postgres"

V2_HOST="db.ttojhhtdcvrcviuguflx.supabase.co"
V2_PORT="5432"
V2_USER="postgres"
V2_DB="postgres"
V2_PASSWORD='AB-}KF6'"'"'3$NBwbNp+<bQ]VU^K'"'"'uW+CRW'   # already shared

echo "── 1. v1 password ───────────────────────────────────────"
read -rsp "Enter v1 Supabase Postgres password (project pmrgraakfhisuptdzxqn): " V1_PASSWORD
echo
[ -z "$V1_PASSWORD" ] && { echo "v1 password is required"; exit 1; }

DUMP_FILE="/tmp/ginger_sync_v1_$(date +%Y%m%d_%H%M%S).sql"

echo
echo "── 2. Dumping v1 ginger_sync schema → ${DUMP_FILE} ─────"
docker run --rm \
    -e PGPASSWORD="${V1_PASSWORD}" \
    -v /tmp:/tmp \
    postgres:16-alpine \
    pg_dump \
        --host="${V1_HOST}" \
        --port="${V1_PORT}" \
        --username="${V1_USER}" \
        --dbname="${V1_DB}" \
        --schema=ginger_sync \
        --no-owner --no-acl --no-privileges \
        --clean --if-exists \
        --file="${DUMP_FILE}"

echo "✓ Dumped $(wc -l < "${DUMP_FILE}") lines, $(du -h "${DUMP_FILE}" | cut -f1)"

echo
echo "── 3. Restoring into v2 (project ttojhhtdcvrcviuguflx) ─"
docker run --rm \
    -e PGPASSWORD="${V2_PASSWORD}" \
    -v "${DUMP_FILE}":"${DUMP_FILE}":ro \
    postgres:16-alpine \
    psql \
        --host="${V2_HOST}" \
        --port="${V2_PORT}" \
        --username="${V2_USER}" \
        --dbname="${V2_DB}" \
        --set ON_ERROR_STOP=on \
        --file="${DUMP_FILE}"

echo
echo "── 4. Row counts ────────────────────────────────────────"
echo "v1:"
docker run --rm -e PGPASSWORD="${V1_PASSWORD}" postgres:16-alpine \
    psql -h "${V1_HOST}" -U "${V1_USER}" -d "${V1_DB}" -At -c "
    SELECT table_name || ': ' || (SELECT count(*) FROM ginger_sync.\"\" || quote_ident(table_name) || \"\")::text
    FROM information_schema.tables WHERE table_schema='ginger_sync' ORDER BY table_name;
    " 2>/dev/null || \
docker run --rm -e PGPASSWORD="${V1_PASSWORD}" postgres:16-alpine \
    psql -h "${V1_HOST}" -U "${V1_USER}" -d "${V1_DB}" -c "\dt ginger_sync.*"

echo
echo "v2:"
docker run --rm -e PGPASSWORD="${V2_PASSWORD}" postgres:16-alpine \
    psql -h "${V2_HOST}" -U "${V2_USER}" -d "${V2_DB}" -c "\dt ginger_sync.*"

echo
echo "── 5. Detailed v2 row counts ────────────────────────────"
docker run --rm -e PGPASSWORD="${V2_PASSWORD}" postgres:16-alpine \
    psql -h "${V2_HOST}" -U "${V2_USER}" -d "${V2_DB}" -c "
    SELECT 'mappings' AS tbl, count(*) FROM ginger_sync.mappings
    UNION ALL SELECT 'sync_map', count(*) FROM ginger_sync.sync_map
    UNION ALL SELECT 'sync_events', count(*) FROM ginger_sync.sync_events
    UNION ALL SELECT 'tasks', count(*) FROM ginger_sync.tasks
    UNION ALL SELECT 'webhook_registrations', count(*) FROM ginger_sync.webhook_registrations
    UNION ALL SELECT 'hot_plate_items', count(*) FROM ginger_sync.hot_plate_items
    UNION ALL SELECT 'hot_plate_categories', count(*) FROM ginger_sync.hot_plate_categories
    UNION ALL SELECT 'meetings', count(*) FROM ginger_sync.meetings
    UNION ALL SELECT 'settings', count(*) FROM ginger_sync.settings;
    "

echo
echo "✓ Migration complete."
echo "  Dump file kept at ${DUMP_FILE} (delete after verifying)."
echo
echo "──  Once you've verified v2 row counts look right, the next step is to "
echo "    expose the schema in v2 Supabase (Settings → API → Exposed schemas → add 'ginger_sync')"
echo "    and then drop the v1 schema using the SQL printed in the next step."
