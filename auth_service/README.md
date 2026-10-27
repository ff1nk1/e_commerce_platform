# Auth service

Laravel-сервис **идентификации и API gateway**: регистрация и логин, JWT, роли, проксирование HTTP во все остальные сервисы. Снаружи браузер ходит только сюда (`/api` на `frontend_nginx` → `:8001`). Порт наружу не публикуется.

Общая карта вызовов — в [корневом README](../README.md).

## Данные

| Хранилище | Что лежит |
|-----------|-----------|
| Postgres | `users`, `roles` |
| Redis | blacklist access-токенов и refresh-токены |

Роли на периметре: обычный пользователь, `admin`, `analyst`. Downstream-сервисы JWT не проверяют.

## HTTP

Свои маршруты ([`routes/api.php`](routes/api.php)): login, registration, refresh, logout, профиль, смена пароля, admin users/roles.

Остальное — прокси ([`GatewayController`](app/Http/Controllers/GatewayController.php), [`config/services.php`](config/services.php)). После проверки JWT [`ProxyService`](app/Services/Gateway/ProxyService.php) вшивает `X-User-Id` и `X-User-Role`. Для заказов ещё подставляет `user_id` в body и query.

| Кто | Куда уходит |
|-----|-------------|
| без JWT | search, recommendations health, Stripe webhook → order |
| любой пользователь | catalog storefront, orders, recommendations |
| admin и analyst | notifications, admin orders, analytics |
| admin | catalog admin API, upload, Filament `/admin-catalog`, recommendations reindex |

Таймаут прокси по умолчанию 30 с, чат рекомендаций — 120 с.

## Kafka

Консьюмеров нет. Регистрация синхронно публикует `user.registered` в `user.events` ([`PublishUserRegisteredToKafka`](app/Listeners/PublishUserRegisteredToKafka.php)). Дальше событие читают notification (приветственное письмо) и analytic.

Ошибка публикации логируется и не откатывает регистрацию.

## Trust

- Единственная проверка JWT и ролей — этот сервис.
- Catalog admin API, upload и Filament дополнительно требуют заголовки gateway (`X-User-Id` + `X-User-Role: admin`). Прямой вызов из Docker-сети без них получает 403.
- Остальные сервисы считают периметром gateway: их порты не торчат на хост.
