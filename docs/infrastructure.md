# Инфраструктура

Централизованных логов, метрик и дашбордов (ELK, Kibana, Grafana, Prometheus) в репозитории нет.

## Логи

```bash
docker compose logs -f auth_app
docker compose logs -f catalog_kafka_order_consumer
```

Laravel дополнительно пишет в `storage/logs` внутри каталога сервиса (том смонтирован в контейнер).

Почта notification смотрится в Mailhog: [http://localhost:8025](http://localhost:8025).

ClickHouse Play слушает порт `8123` (пароль пользователя из `CLICKHOUSE_PASSWORD`).

Нагрузочный сценарий поиска: [`search_service/k6`](../search_service/k6).

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

## CI

Файл [`.github/workflows/deploy.yml`](../.github/workflows/deploy.yml). Запускается на push и pull request в `main` и вручную.

| Job | Что делает |
|-----|------------|
| lint | PHP 8.5, Go 1.23, `./scripts/lint-all.sh --no-fix` |
| test | `./scripts/bootstrap.sh`, подстановка секретов, `docker compose up` нужных контейнеров, `./scripts/test-all.sh` |
| deploy | после lint и test, только не на pull request: SCP проекта на VM и SSH. `docker compose up` на VM в скрипте закомментирован |

Секреты репозитория для test: `JWT_SECRET`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `GEMINI_API_KEY`.

Секреты для deploy: `SSH_HOST`, `SSH_PORT`, `SSH_USER`, `SSH_KEY`. Каталог на VM — `/home/ubuntu/app`.
