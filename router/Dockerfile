FROM --platform=linux/amd64 node:22-alpine
WORKDIR /app
COPY router/package.json router/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY router/server.js ./server.js
ENV NODE_ENV=production PORT=8080 REGISTRY_PATH=/config/server-routes.json
EXPOSE 8080
HEALTHCHECK --interval=30s --timeout=3s --start-period=3s --retries=3 \
  CMD wget -Y off --quiet --tries=1 --spider http://127.0.0.1:8080/health || exit 1
CMD ["node", "server.js"]
