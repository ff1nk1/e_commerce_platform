# Запуск

```bash
./scripts/bootstrap.sh
docker compose up -d --build
./scripts/test-all.sh
./scripts/lint-all.sh
./scripts/demo-prep.sh
```

`bootstrap.sh` копирует `.env.example` → `.env` (если файла ещё нет), генерирует пустые `APP_KEY` и ставит PHP `vendor/` через Docker. Пароли БД/Redis/MinIO/ClickHouse уже с локальными дефолтами — их обычно трогать не нужно.

Laravel HTTP-контейнеры (`*_app`) слушают свои порты через **nginx + php-fpm** (общий образ `php-project-php-base:8.5`). Kafka/scheduler/outbox в тех же образах запускают `php artisan …` через CLI. Edge `frontend_nginx` / Traefik без изменений.

## Секреты (заполнить вручную)

Реальные ключи **не** лежат в репозитории (только в локальных `.env` и в GitHub Actions secrets). После bootstrap отредактируй:

| Переменная | Файл | Обязательно? | Как получить |
|------------|------|--------------|--------------|
| `JWT_SECRET` | `auth_service/.env` | Да (логин / gateway) | `openssl rand -hex 32` |
| `STRIPE_SECRET` | `order_service/.env` | Для оплаты и Stripe integration-тестов | Stripe Dashboard → test mode → Secret key (`sk_test_…`) |
| `STRIPE_WEBHOOK_SECRET` | `order_service/.env` | Для вебхуков и тех же тестов | вывод `stripe listen` (`whsec_…`) |
| `GEMINI_API_KEY` | `recommendation_service/.env` | Для ассистента / demo / Gemini-тестов | [Google AI Studio](https://aistudio.google.com/apikey) |

Пример:

```bash
# auth
openssl rand -hex 32   # вставить в auth_service/.env → JWT_SECRET=...

# Stripe (отдельная консоль; держать запущенным при оплате)
stripe login
stripe listen --forward-to https://app.localhost/api/webhooks/stripe \
  --events checkout.session.completed,checkout.session.expired \
  --skip-verify
# whsec_… → order_service/.env → STRIPE_WEBHOOK_SECRET=
# sk_test_… → order_service/.env → STRIPE_SECRET=

# после правки Stripe/JWT:
docker compose up -d --force-recreate order_app auth_app
```

Без Stripe стек и юнит-тесты работают; `./scripts/test-all.sh` упадёт на order integration, пока ключи не заданы. Без Gemini ассистент пустой, Gemini-тесты skip.

## Полезные команды

```bash
# весь стек
docker compose up -d --build

# тесты / линт (контейнеры уже должны быть up)
./scripts/test-all.sh              # все сервисы
./scripts/test-all.sh order auth   # фильтр по имени
./scripts/lint-all.sh

# сиды / демо-данные (нужен GEMINI_API_KEY для recommendation)
./scripts/demo-prep.sh
```

Для CI задай repository secrets: `JWT_SECRET`, `STRIPE_SECRET`, `STRIPE_WEBHOOK_SECRET`, `GEMINI_API_KEY` (см. `.github/workflows/deploy.yml`).
