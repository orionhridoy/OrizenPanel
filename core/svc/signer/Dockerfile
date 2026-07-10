FROM node:20.18-alpine AS build
WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; \
    else npm install --no-audit --no-fund; fi
COPY tsconfig.json ./
COPY src ./src
RUN npm run build

FROM node:20.18-alpine AS deps
WORKDIR /app
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci --omit=dev --no-audit --no-fund; \
    else npm install --omit=dev --no-audit --no-fund; fi

FROM node:20.18-alpine
ENV NODE_ENV=production
WORKDIR /app
RUN apk add --no-cache tini \
    && mkdir -p /keystore && chown node:node /keystore && chmod 700 /keystore
COPY --from=deps /app/node_modules ./node_modules
COPY --from=build /app/dist ./dist
COPY package.json ./
USER node
VOLUME ["/keystore"]
ENTRYPOINT ["/sbin/tini", "--"]
CMD ["node", "dist/index.js"]
