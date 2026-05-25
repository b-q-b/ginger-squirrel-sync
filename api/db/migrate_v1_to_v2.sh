#!/usr/bin/env bash
#
# Copy the v1 `ginger_sync` schema (data + structure) from the old Supabase
# project (pmrgraakfhisuptdzxqn) into the v2 project (ttojhhtdcvrcviuguflx).
#
# Uses Supabase's Transaction Pooler endpoints (port 6543, IPv4-only) so it
# works on VPSes without IPv6 connectivity. The direct-connection
# `db.<ref>.supabase.co` host is IPv6-only on modern Supabase projects.
#
# Get the pooler details from each Supabase project:
#   Project Settings → Database → Connection string → "Transaction pooler" tab
#
# Run on the Hostinger VPS — uses a one-off Postgres 16 Docker container
# for pg_dump + psql so no host packages are needed.

set -euo pipefail

# ── v2 (target) — already known ─────────────────────────────────
V2_REF="ttojhhtdcvrcviuguflx"
V2_USER="postgres.${V2_REF}"
V2_DB="postgres"
V2_PASSWORD='AB-}KF6'"'"'3$NBwbNp+<bQ]VU^K'"'"'uW+CRW'

# ── v1 (source) — prompt user ──────────────────────────────────
V1_REF="pmrgraakfhisuptdzxqn"
V1_USER="postgres.${V1_REF}"
V1_DB="postgres"

echo "──────────────────────────────────────────────────────────────"
echo " v1 → v2 migration — prompts below"
echo "──────────────────────────────────────────────────────────────"
echo
echo "Get the *Transaction pooler* host from each Supabase project:"
echo "   Project Settings → Database → Connection string → Transaction pooler tab"
echo "   The host looks like:  aws-0-<region>.pooler.supabase.com"
echo

read -rp "v1 pooler host (Supabase project ${V1_REF}): " V1_HOST
read -rp "v2 pooler host (Supabase project ${V2_REF}) [hit Enter to reuse v1's host]: " V2_HOST
V2_HOST="${V2_HOST:-$V1_HOST}"

echo
read -rsp "v1 Postgres password (project ${V1_REF}): " V1_PASSWORD
echo
[ -z "$V1_PASSWORD" ] && { echo "v1 password is required"; exit 1; }

DUMP_FILE="/tmp/ginger_sync_v1_$(date +%Y%m%d_%H%M%S).sql"
PORT=6543

# ── 1. Dump v1 ──────────────────────────────────────────────────
echo
echo "── Dumping v1 ginger_sync schema → ${DUMP_FILE} ─────────────"
docker run --rm \
    -e PGPASSWORD="${V1_PASSWORD}" \
    -v /tmp:/tmp \
    postgres:16-alpine \
    pg_dump \
        --host="${V1_HOST}" \
        --port="${PORT}" \
        --username="${V1_USER}" \
        --dbname="${V1_DB}" \
        --schema=ginger_sync \
        --no-owner --no-acl --no-privileges \
        --clean --if-exists \
        --file="${DUMP_FILE}"

echo "✓ Dumped $(wc -l < "${DUMP_FILE}") lines, $(du -h "${DUMP_FILE}" | cut -f1)"

# ── 2. Restore into v2 ──────────────────────────────────────────
echo
echo "── Restoring into v2 (${V2_REF}) ────────────────────────────"
docker run --rm \
    -e PGPASSWORD="${V2_PASSWORD}" \
    -v "${DUMP_FILE}":"${DUMP_FILE}":ro \
    postgres:16-alpine \
    psql \
        --host="${V2_HOST}" \
        --port="${PORT}" \
        --username="${V2_USER}" \
        --dbname="${V2_DB}" \
        --set ON_ERROR_STOP=on \
        --file="${DUMP_FILE}"

# ── 3. Verify row counts ────────────────────────────────────────
echo
echo "── v2 row counts (post-restore) ─────────────────────────────"
docker run --rm -e PGPASSWORD="${V2_PASSWORD}" postgres:16-alpine \
    psql -h "${V2_HOST}" -p "${PORT}" -U "${V2_USER}" -d "${V2_DB}" -c "
    SELECT 'mappings'              AS tbl, count(*) FROM ginger_sync.mappings              UNION ALL
    SELECT 'sync_map',              count(*) FROM ginger_sync.sync_map                     UNION ALL
    SELECT 'sync_events',           count(*) FROM ginger_sync.sync_events                  UNION ALL
    SELECT 'tasks',                 count(*) FROM ginger_sync.tasks                        UNION ALL
    SELECT 'webhook_registrations', count(*) FROM ginger_sync.webhook_registrations        UNION ALL
    SELECT 'hot_plate_items',       count(*) FROM ginger_sync.hot_plate_items              UNION ALL
    SELECT 'hot_plate_categories',  count(*) FROM ginger_sync.hot_plate_categories         UNION ALL
    SELECT 'meetings',              count(*) FROM ginger_sync.meetings                     UNION ALL
    SELECT 'settings',              count(*) FROM ginger_sync.settings;
    "

echo
echo "✓ Migration complete."
echo "  Dump kept at ${DUMP_FILE} (safe to remove once you've verified)."
echo
echo "Next: in v2 Supabase dashboard → Settings → API → Data API Settings,"
echo "      add 'ginger_sync' to Exposed schemas, then ask Claude for the v1 cleanup SQL."
