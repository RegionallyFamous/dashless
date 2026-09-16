FROM node:22.23.2-bookworm-slim
RUN apt-get update && apt-get install -y --no-install-recommends zip fonts-dejavu-core ca-certificates && rm -rf /var/lib/apt/lists/*
WORKDIR /app/runtime
COPY runtime/package.json runtime/package-lock.json ./
RUN npm ci --omit=dev --no-audit --no-fund
COPY runtime/build.mjs ./build.mjs
COPY template ./template
COPY server.mjs /app/server.mjs
ENV NODE_ENV=production BUILD_DATA_DIR=/data BUILD_RUNTIME=/app/runtime
EXPOSE 3000
CMD ["node", "/app/server.mjs"]
