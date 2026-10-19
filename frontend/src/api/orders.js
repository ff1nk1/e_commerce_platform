import { api } from './client'

export function getOrders(params) {
  return api.get('/orders', { params })
}

export function getOrder(id) {
  return api.get(`/orders/${id}`)
}

export function createOrder(items, idempotencyKey) {
  return api.post(
    '/orders',
    { items },
    { headers: { 'Idempotency-Key': idempotencyKey } }
  )
}

export function payOrder(id, idempotencyKey) {
  return api.post(`/orders/${id}/pay`, null, {
    headers: { 'Idempotency-Key': idempotencyKey },
  })
}

export function cancelOrder(id) {
  return api.post(`/orders/${id}/cancel`)
}
