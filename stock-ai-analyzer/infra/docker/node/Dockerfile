# Development image for the Next.js frontend. Source is bind-mounted at /app;
# node_modules lives in a named volume so Linux-native binaries are used.
FROM node:24-bookworm-slim

ENV NEXT_TELEMETRY_DISABLED=1
WORKDIR /app

COPY package.json package-lock.json ./
RUN npm ci

EXPOSE 3000
CMD ["npm", "run", "dev", "--", "--hostname", "0.0.0.0", "--port", "3000"]
