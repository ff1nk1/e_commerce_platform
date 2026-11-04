# ADR 0005. Downstream не проверяет JWT

## Контекст

После gateway запрос уже внутри Docker-сети. Повторять проверку подписи в каждом сервисе значит тащить `JWT_SECRET` повсюду.

## Решение

Сервисы доверяют периметру. Единственный канал identity — доверенные заголовки `X-User-Id` / `X-User-Role` (и `X-User-Name` / `X-User-Email` для каталога), которые gateway ставит из JWT. Клиентские `X-User-*` JSON-прокси не пересылает.

`user_id` **не** дублируется в body/query: прокси больше не добавляет его туда. Заказ и рекомендации читают `X-User-Id` (middleware `ResolveGatewayIdentity` в order, `ResolveGatewayUserId` в recommendation) — тело клиента не решает, чей это заказ. Контакт заказа (`email`/`name`) приходит теми же заголовками `X-User-Email` / `X-User-Name`; значения из заголовков перезаписывают тело.

Админский JSON каталога и Filament дополнительно требуют `X-User-Id` и `X-User-Role: admin`.

## Проверка роли downstream

Помимо gateway, сервисы сами проверяют роль из `X-User-Role` (defense-in-depth, если запрос пришёл по внутренней сети напрямую):

- order: `DenyAnalystWrites` (analyst — read-only) и `ResolveGatewayIdentity` (`X-User-Id` → `user_id`, `X-User-Email` → `email`, `X-User-Name` → `name`);
- recommendation: `EnsureGatewayRole` (только admin/analyst/user) и `ResolveGatewayUserId`;
- analytic (Go): `requireRole` (только admin/analyst), `X-User-Role`.

## Почему так

Утечка порта сервиса в сеть всё ещё опасна, но секрет логина один. Один канал identity исключает рассинхрон между заголовком и телом (например, когда клиент прислал чужой `user_id` в body).

## Что отклонили

Общий JWT-секрет во всех `.env`; приём `X-User-Role` от браузера как есть; дублирование `user_id` в body/query.
