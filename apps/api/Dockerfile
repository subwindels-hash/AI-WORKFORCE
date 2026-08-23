FROM node:22-alpine AS build
WORKDIR /app
COPY package*.json tsconfig.base.json ./
COPY packages/core/package.json packages/core/
COPY apps/api/package.json apps/api/
RUN npm ci --omit=dev --ignore-scripts || npm install --omit=dev --ignore-scripts
COPY packages/core packages/core
COPY apps/api apps/api
RUN npm run build -w packages/core && npm run build -w apps/api

FROM node:22-alpine
WORKDIR /app
ENV NODE_ENV=production PORT=4000 HOST=0.0.0.0
COPY --from=build /app/node_modules ./node_modules
COPY --from=build /app/packages/core/dist ./packages/core/dist
COPY --from=build /app/packages/core/package.json ./packages/core/
COPY --from=build /app/apps/api/dist ./apps/api/dist
COPY --from=build /app/apps/api/package.json ./apps/api/
WORKDIR /app/apps/api
EXPOSE 4000
CMD ["node", "dist/index.js"]
