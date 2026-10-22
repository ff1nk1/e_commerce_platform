import axios from 'axios'
import { refreshSession } from '../auth/session'

const baseURL = import.meta.env.VITE_API_URL || '/api'

export const api = axios.create({
  baseURL,
  headers: { Accept: 'application/json' },
  withCredentials: true,
})

let isRefreshing = false
let failedQueue = []

function processQueue(error, token = null) {
  failedQueue.forEach((prom) => {
    if (error) {
      prom.reject(error)
    } else {
      prom.resolve(token)
    }
  })
  failedQueue = []
}

api.interceptors.response.use(
  (response) => response,
  async (error) => {
    const originalRequest = error.config

    const shouldAttemptRefresh =
      error.response?.status === 401 &&
      !!originalRequest &&
      !originalRequest._retry &&
      !originalRequest.url?.includes('/refresh')

    if (!shouldAttemptRefresh) {
      return Promise.reject(error)
    }

    if (isRefreshing) {
      return new Promise((resolve, reject) => {
        failedQueue.push({ resolve, reject })
      })
        .then(() => api(originalRequest))
        .catch((err) => Promise.reject(err))
    }

    isRefreshing = true
    originalRequest._retry = true

    try {
      const refreshed = await refreshSession()

      if (!refreshed) {
        processQueue(new Error('Refresh failed'), null)
        window.location.href = '/login'
        return Promise.reject(error)
      }

      processQueue(null, 'Success')
      return api(originalRequest)
    } catch (err) {
      processQueue(err, null)
      window.location.href = '/login'
      return Promise.reject(err)
    } finally {
      isRefreshing = false
    }
  }
)
