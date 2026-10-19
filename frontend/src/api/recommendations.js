import { api } from './client'

export function getConversation(conversationId) {
  return api.get(`/recommendations/conversations/${conversationId}`)
}

export function sendChatMessage(payload) {
  return api.post('/recommendations/chat', payload)
}

export function deleteConversation(conversationId) {
  return api.delete(`/recommendations/conversations/${conversationId}`)
}
