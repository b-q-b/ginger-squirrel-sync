.PHONY: api web dev build test deploy db-up db-down

# ── Local dev ─────────────────────────────────────────────────
db-up:
	docker compose -f infra/docker-compose.yml up -d postgres

db-down:
	docker compose -f infra/docker-compose.yml down

api:
	cd api && dotnet run --project src/GingerSync.Api

web:
	cd web && npm run dev

dev: db-up
	@echo "→ Run 'make api' and 'make web' in two terminals."

# ── Build ─────────────────────────────────────────────────────
build-api:
	cd api && dotnet build -c Release

build-web:
	cd web && npm ci && npm run build

build: build-api build-web

# ── Tests ─────────────────────────────────────────────────────
test-api:
	cd api && dotnet test

test-web:
	cd web && npm run typecheck

test: test-api test-web

# ── Deploy (TODO: Hostinger VPS rsync once creds in place) ────
deploy:
	@echo "Deploy target not configured yet. Will wire to Hostinger VPS rsync."
