# ── Dependencies ─────────────────────────────────────────
FROM node:22-alpine AS deps
WORKDIR /app
# Reproducible install with lockfile when present
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci; else npm install; fi

# ── Build ────────────────────────────────────────────────
FROM node:22-alpine AS build
WORKDIR /app
COPY --from=deps /app/node_modules ./node_modules
COPY . .

# NEXT_PUBLIC_* values bake at build time. Default to relative paths
# so the same image works behind Traefik (api + web on one host).
ARG NEXT_PUBLIC_API_BASE=""
ENV NEXT_PUBLIC_API_BASE=$NEXT_PUBLIC_API_BASE
ENV NEXT_TELEMETRY_DISABLED=1

RUN npm run build

# ── Runtime (Next.js standalone) ─────────────────────────
FROM node:22-alpine AS runtime
WORKDIR /app
ENV NODE_ENV=production \
    NEXT_TELEMETRY_DISABLED=1 \
    PORT=3000 \
    HOSTNAME=0.0.0.0

# Non-root user
RUN addgroup -S app && adduser -S app -G app

# Copy the standalone server + static assets
COPY --from=build --chown=app:app /app/.next/standalone ./
COPY --from=build --chown=app:app /app/.next/static ./.next/static
COPY --from=build --chown=app:app /app/public ./public

USER app
EXPOSE 3000
HEALTHCHECK --interval=30s --timeout=5s --start-period=15s --retries=3 \
    CMD wget -q --spider http://localhost:3000/ || exit 1

CMD ["node", "server.js"]
