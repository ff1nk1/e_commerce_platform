FROM node:22-alpine AS dev

WORKDIR /app

COPY package.json package-lock.json ./

RUN npm ci

COPY . .

EXPOSE 5173

CMD ["npm", "run", "dev", "--", "--host", "0.0.0.0"]

FROM dev AS assets

ARG VITE_API_URL=/api
ENV VITE_API_URL=${VITE_API_URL}

RUN npm run build

FROM nginx:1.28.0-alpine AS prod

COPY nginx.prod.conf /etc/nginx/conf.d/default.conf
COPY --from=assets /app/dist /usr/share/nginx/html

EXPOSE 80
