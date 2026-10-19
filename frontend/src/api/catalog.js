import { api } from './client'

export function getCategories() {
  return api.get('catalog/categories')
}

export function getCategoryProducts(slug, params) {
  return api.get(`catalog/categories/${slug}/products`, { params })
}

export function getStores() {
  return api.get('catalog/stores')
}

export function getStoreProducts(storeId, params) {
  return api.get(`catalog/stores/${storeId}/products`, { params })
}

export function getProduct(id) {
  return api.get(`catalog/products/${id}`)
}

export function searchProducts(params) {
  return api.get('search', { params })
}

export function searchAutocomplete(params) {
  return api.get('search/autocomplete', { params })
}
