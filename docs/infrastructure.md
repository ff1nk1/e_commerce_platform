# Инфраструктура

Централизованных логов, метрик и дашбордов (ELK, Kibana, Grafana, Prometheus) в репозитории нет.

## Логи

```bash
docker compose logs -f auth_app
docker compose logs -f catalog_kafka_order_consumer
```

Laravel дополнительно пишет в `storage/logs` внутри каталога сервиса (том смонтирован в контейнер).

Почта notification смотрится в Mailpit: [http://localhost:8025](http://localhost:8025).

ClickHouse Play слушает порт `8123` (пароль пользователя из `CLICKHOUSE_PASSWORD`) и доступен как [http://clickhouse.localhost](http://clickhouse.localhost) и `http://localhost:8123/play`.

## Порты на хосте

Наружу публикуется только инфраструктура и UI; сами сервисы сидят в сети `php_project_net` на внутренних портах `8001`…`8007` (`expose`, без `ports`).

| Порт | Что | Откуда |
|------|-----|--------|
| `80` / `443` | Traefik (`app.localhost`) | `infra/compose.yml` |
| `8081` | Traefik dashboard | `infra/compose.yml` |
| `9092` | Kafka EXTERNAL-листенер | `infra/compose.yml` |
| `8080` | kafbat-ui | `infra/compose.yml` |
| `8123` | ClickHouse Play | `analytic_service/compose.yml` |
| `5433`…`5438` | Postgres сервисов | `*/compose.yml` |
| `6380`…`6382` | Redis сервисов | `*/compose.yml` |
| `9000` / `8999` | MinIO | `catalog_service/compose.yml` |
| `1025` / `8025` | Mailpit SMTP / UI | `notification_service/compose.yml` |
| `27017` | MongoDB notification | `notification_service/compose.yml` |
| `9200` / `5601` | OpenSearch / Dashboards | `search_service/compose.yml` |

Нагрузочный сценарий поиска: [`search_service/k6`](../search_service/k6).

## TLS-сертификаты

Traefik (`websecure`) читает сертификаты из `infra/traefik/certs/`:

- `infra/traefik/certs/app.localhost.pem`
- `infra/traefik/certs/app.localhost-key.pem`

Файлы **не коммитятся** (каталог игнорируется `.gitignore`) — их кладут вручную. Подойдёт self-signed сертификат или `mkcert app.localhost`. Без этих двух файлов HTTPS-роутеры Traefik (`app.localhost`) не поднимутся.

## Локальный запуск

Из корня репозитория:

```bash
./scripts/bootstrap.sh
docker compose up -d --build
./scripts/test-all.sh
./scripts/lint-all.sh
./scripts/demo-prep.sh
```

`bootstrap.sh` копирует `.env.example` в `.env`, если файла ещё нет, и ставит PHP-зависимости. Секреты `JWT_SECRET`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `GEMINI_API_KEY` после этого заполняются вручную. Подробности — в [корневом README](../README.md).

Тесты и линт можно сузить по короткому имени: `auth`, `catalog`, `notification`, `order`, `search`, `recommendation`, `analytic`.

```bash
./scripts/test-all.sh order auth
./scripts/lint-all.sh analytic
./scripts/lint-all.sh --no-fix
```

`test-all.sh` гоняет тесты в уже поднятых контейнерах. `lint-all.sh` работает на хосте: Pint и PHPStan для Laravel, golangci-lint для analytic. Docker для линта не нужен.

`check-deps.sh` проверяет, что сервисы не запинены на точную версию `shop/messaging` и что пакет объявляет широкие ограничения на `illuminate/*` (см. [ADR 0002](adr/0002-kafka-and-outbox.md)). `load-chat-login.sh` — нагрузочная проверка изоляции gateway-пула: параллельные чаты не должны замедлять `/api/login` (см. [ADR 0001](adr/0001-gateway-in-auth.md)).

## CI

Файл [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml). Запускается на push и pull request в `main` и вручную.

| Job | Что делает |
|-----|------------|
| lint | PHP 8.5, Go 1.27.0, `./scripts/lint-all.sh --no-fix`, `./scripts/check-deps.sh` |
| test | `./scripts/bootstrap.sh`, подстановка секретов, `docker compose up` нужных контейнеров, `./scripts/test-all.sh` |
| test-live | только по `workflow_dispatch`: те же шаги с `RUN_LIVE_TESTS=1` (Stripe + Gemini) |
| deploy | **отключён** — job `deploy:` полностью закомментирован в workflow (SCP на VM и SSH не выполняются) |

Секреты репозитория для test: `JWT_SECRET`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `GEMINI_API_KEY`.

Секреты для отключённого deploy-job (оставлены на случай включения): `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_KEY`. Каталог на VM — `/home/ubuntu/app`.
