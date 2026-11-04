# ADR 0001. JWT и API gateway в auth_service

## Контекст

Браузеру нужен один вход. Роли и токены нельзя проверять в каждом сервисе своим секретом.

## Решение

`auth_service` выдаёт JWT (cookie `access_token` и Bearer) и проксирует `/api` в остальные сервисы. Публичные порты сервисов на хост не открываются.

Проксирование выполняется в PHP (Guzzle, `App\Services\Gateway\ForwardRequestService`), но **долгие маршруты обслуживает отдельный php-fpm pool** `[gateway]`, а не общий `[www]`.

## Почему так

Один периметр проще, чем семь проверок JWT. Роли (`admin`, `analyst`) живут рядом с пользователями.

## Изоляция долгих запросов от логина

Раньше все запросы шли через один пул `[www]` (`pm.max_children = 20`). Чат рекомендаций держит воркер до ~120 с (`ProxyOptions(timeout: 120)`, `fastcgi_read_timeout 300s`), поэтому 20 параллельных чатов выедали весь пул и `/api/login`, `/api/registration`, `/api/refresh` вставали в очередь.

Сейчас:

- `infra/php-base/zz-gateway-fpm.conf.template` — шаблон второго пула `[gateway]` (`pm.max_children` из `GATEWAY_FPM_MAX_CHILDREN`, `request_terminate_timeout = 300s`).
- `infra/php-base/entrypoint-http.sh` рендерит пул и nginx-локацию только если задан `GATEWAY_FPM_PORT` (≠ 9000). Иначе поведение прежнее — один пул.
- `auth_service/compose.yml` включает это для себя: `GATEWAY_FPM_PORT=9001`, `GATEWAY_FPM_MAX_CHILDREN=40`, `GATEWAY_ROUTES=^/api/recommendations`.
- nginx (`infra/php-base/nginx-laravel.conf.template`) подключает `/etc/nginx/gateway/*.conf` до общего `location ~ \.php$`, поэтому `/api/recommendations/*` уходит в `127.0.0.1:9001`, а логин/refresh остаются на `127.0.0.1:9000`.

Проверка: `./scripts/load-chat-login.sh` — 20+ параллельных чатов не должны увеличивать latency `/api/login` сверх обычной.

## Целевое состояние (не реализовано)

Вынести проксирование на edge (Traefik/nginx `proxy_pass` + `auth_request`/forward-auth), оставив в `auth_service` только проверку токена. Тогда долгие чаты вообще не занимают FPM-воркеры. Отложено: требует forward-auth эндпоинта и переноса инъекции `X-User-*` заголовков на edge.

## Что отклонили

Отдельный gateway-процесс и проверку JWT в каждом сервисе.
