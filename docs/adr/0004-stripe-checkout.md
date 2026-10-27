# ADR 0004. Оплата через Stripe Checkout

## Контекст

Заказ должен взять деньги и только после успешной оплаты списать остаток. Карточные данные в order_service хранить нельзя.

## Решение

`POST /api/orders/{id}/pay` создаёт Stripe Checkout Session. Stripe шлёт `checkout.session.completed` и `checkout.session.expired` на gateway (`/api/webhooks/stripe`), gateway проксирует сырое тело в order. Подпись проверяет order по `STRIPE_WEBHOOK_SECRET`.

Успешная оплата публикует `stock.commit_requested`. Истечение сессии и отмена в `awaiting_payment` публикуют `stock.release_requested`.

## Почему так

Checkout держит PCI на стороне Stripe. Вебхук идёт тем же периметром, что и остальной API, но без JWT: аутентификация — подпись Stripe.

## Что отклонили

Приём карты своим PaymentIntent на нашей странице и подтверждение оплаты только редиректом success URL без вебхука.
