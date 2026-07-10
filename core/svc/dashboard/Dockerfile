FROM node:20.18-alpine AS build
WORKDIR /app
ARG VITE_API_BASE=/api/v1
ENV VITE_API_BASE=${VITE_API_BASE}
COPY package.json package-lock.json* ./
RUN if [ -f package-lock.json ]; then npm ci --no-audit --no-fund; \
    else npm install --no-audit --no-fund; fi
COPY tsconfig.json vite.config.ts index.html ./
COPY public ./public
COPY src ./src
RUN npm run build

FROM nginx:1.27-alpine
COPY nginx.conf /etc/nginx/conf.d/default.conf
COPY --from=build /app/dist /usr/share/nginx/html
EXPOSE 8080
