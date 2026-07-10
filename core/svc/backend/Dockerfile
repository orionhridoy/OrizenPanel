# ── build stage ───────────────────────────────────────────────────────────────
FROM node:20.18-alpine AS build
WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; \
    else npm install --no-audit --no-fund; fi
COPY tsconfig.json nest-cli.json ./
COPY src ./src
RUN npm run build

# ── production dependencies only ─────────────────────────────────────────────
FROM node:20.18-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci --omit=dev --no-audit --no-fund; \
    else npm install --omit=dev --no-audit --no-fund; fi

# ── runtime ───────────────────────────────────────────────────────────────────
FROM node:20.18-alpine
ENV NODE_ENV=production
WORKDIR /app
RUN apk add --no-cache tini docker-cli curl && \
    mkdir -p /usr/libexec/docker/cli-plugins && \
    curl -sL "https://github.com/docker/compose/releases/download/v2.32.0/docker-compose-linux-$(uname -m)" -o /usr/libexec/docker/cli-plugins/docker-compose && \
    chmod +x /usr/libexec/docker/cli-plugins/docker-compose
COPY --from=deps /app/node_modules ./node_modules
COPY --from=build /app/dist ./dist
COPY package.json ./
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["node", "dist/main.js"]
