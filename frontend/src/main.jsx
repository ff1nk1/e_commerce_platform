import React from 'react'
import { createRoot } from 'react-dom/client'
import App from './app/App.jsx'
import { CartProvider } from './cart/CartContext.jsx'
import './styles/tokens.css'
import './styles/base.css'

createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <CartProvider>
      <App />
    </CartProvider>
  </React.StrictMode>
)
