import { createContext, useCallback, useContext, useMemo, useState } from 'react'
import { loadCart, saveCart } from './cartStorage'

const CartContext = createContext(null)

export function CartProvider({ children }) {
  const [items, setItems] = useState(() => loadCart())

  const persist = useCallback((updater) => {
    setItems((prev) => {
      const next = typeof updater === 'function' ? updater(prev) : updater
      saveCart(next)
      return next
    })
  }, [])

  const addItem = useCallback(
    (product, quantity = 1) => {
      const normalizedQuantity = Math.max(1, Math.floor(Number(quantity) || 1))
      const resolvedProductId = Number(product.id ?? product.product_id)
      const resolvedStoreId = product.store_id ?? product.store?.id ?? null
      persist((prev) => {
        const existing = prev.find((i) => i.product_id === resolvedProductId)
        if (existing) {
          return prev.map((i) =>
            i.product_id === resolvedProductId
              ? { ...i, quantity: i.quantity + normalizedQuantity }
              : i
          )
        }
        return [
          ...prev,
          {
            product_id: resolvedProductId,
            name: product.name ?? '',
            price: product.price,
            image_url: product.image_url ?? null,
            store_id: resolvedStoreId,
            quantity: normalizedQuantity,
          },
        ]
      })
    },
    [persist]
  )

  const updateQuantity = useCallback(
    (productId, quantity) => {
      const normalizedQuantity = Math.max(1, Math.floor(Number(quantity) || 1))
      persist((prev) =>
        prev.map((i) =>
          i.product_id === productId ? { ...i, quantity: normalizedQuantity } : i
        )
      )
    },
    [persist]
  )

  const removeItem = useCallback(
    (productId) => {
      persist((prev) => prev.filter((i) => i.product_id !== productId))
    },
    [persist]
  )

  const clear = useCallback(() => {
    persist([])
  }, [persist])

  const totalCount = useMemo(
    () => items.reduce((sum, i) => sum + i.quantity, 0),
    [items]
  )

  const totalPrice = useMemo(
    () =>
      items.reduce((sum, i) => sum + Number(i.price || 0) * i.quantity, 0),
    [items]
  )

  const value = useMemo(
    () => ({
      items,
      addItem,
      updateQuantity,
      removeItem,
      clear,
      totalCount,
      totalPrice,
    }),
    [items, addItem, updateQuantity, removeItem, clear, totalCount, totalPrice]
  )

  return <CartContext.Provider value={value}>{children}</CartContext.Provider>
}

export function useCart() {
  const ctx = useContext(CartContext)
  if (!ctx) {
    throw new Error('useCart must be used within CartProvider')
  }
  return ctx
}
