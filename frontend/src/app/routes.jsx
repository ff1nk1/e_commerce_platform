import {
  createBrowserRouter,
  createRoutesFromElements,
  Navigate,
  Route,
  useParams,
} from 'react-router-dom'

import Login from '../pages/Login'
import Registration from '../pages/Registration'
import Profile from '../pages/Profile'
import Categories from '../pages/Categories'
import CategoryProducts from '../pages/CategoryProducts'
import Stores from '../pages/Stores'
import StoreProducts from '../pages/StoreProducts'
import Product from '../pages/Product'
import Orders from '../pages/Orders'
import OrderDetail from '../pages/OrderDetail'
import Cart from '../pages/Cart'
import Search from '../pages/Search'
import Assistant from '../pages/Assistant'

import AdminLayout from '../admin/AdminLayout'
import AdminHub from '../admin/AdminHub'
import AdminUsers from '../admin/AdminUsers'
import AdminOrders from '../admin/AdminOrders'
import AdminNotifications from '../admin/AdminNotifications'
import AdminNotificationDetail from '../admin/AdminNotificationDetail'
import AdminAnalyticsUsers from '../admin/AdminAnalyticsUsers'
import AdminAnalyticsOrders from '../admin/AdminAnalyticsOrders'
import AdminAnalyticsSearch from '../admin/AdminAnalyticsSearch'
import AdminAnalyticsStores from '../admin/AdminAnalyticsStores'
import AdminAnalyticsCategories from '../admin/AdminAnalyticsCategories'

import AppLayout, { appLayoutLoader } from '../layouts/AppLayout'
import RootErrorBoundary from './RootErrorBoundary'
import {
  adminLayoutLoader,
  adminUsersLoader,
  adminOrdersLoader,
  adminNotificationsLoader,
  adminNotificationDetailLoader,
  adminAnalyticsUsersLoader,
  adminAnalyticsOrdersLoader,
  adminAnalyticsSearchLoader,
  adminAnalyticsStoresLoader,
  adminAnalyticsCategoriesLoader,
} from '../admin/loaders'
import { ordersLoader, orderDetailLoader } from '../orders/loaders'
import { requireAuth } from '../auth/requireAuth'

function RedirectNotificationDetail() {
  const { id } = useParams()
  return <Navigate to={`/admin/notifications/${id}`} replace />
}

export const router = createBrowserRouter(
  createRoutesFromElements(
    <Route errorElement={<RootErrorBoundary />}>
      <Route path="/login" element={<Login />} />
      <Route path="/registration" element={<Registration />} />

      <Route element={<AppLayout />} loader={appLayoutLoader}>
        <Route path="/search" element={<Search />} />
        <Route path="/assistant" element={<Assistant />} loader={requireAuth} />
        <Route path="/profile" element={<Profile />} loader={requireAuth} />
        <Route path="/categories" element={<Categories />} loader={requireAuth} />
        <Route path="/categories/:slug" element={<CategoryProducts />} loader={requireAuth} />
        <Route path="/stores" element={<Stores />} loader={requireAuth} />
        <Route path="/stores/:storeId/products" element={<StoreProducts />} loader={requireAuth} />
        <Route path="/products/:id" element={<Product />} loader={requireAuth} />
        <Route path="/cart" element={<Cart />} loader={requireAuth} />
        <Route path="/orders" element={<Orders />} loader={ordersLoader} />
        <Route path="/orders/:id" element={<OrderDetail />} loader={orderDetailLoader} />
      </Route>

      <Route path="/admin" element={<AdminLayout />} loader={adminLayoutLoader}>
        <Route index element={<AdminHub />} />
        <Route path="users" element={<AdminUsers />} loader={adminUsersLoader} />
        <Route path="orders" element={<AdminOrders />} loader={adminOrdersLoader} />
        <Route
          path="notifications"
          element={<AdminNotifications />}
          loader={adminNotificationsLoader}
        />
        <Route
          path="notifications/:id"
          element={<AdminNotificationDetail />}
          loader={adminNotificationDetailLoader}
        />
        <Route
          path="analytics/users"
          element={<AdminAnalyticsUsers />}
          loader={adminAnalyticsUsersLoader}
        />
        <Route
          path="analytics/orders"
          element={<AdminAnalyticsOrders />}
          loader={adminAnalyticsOrdersLoader}
        />
        <Route
          path="analytics/search"
          element={<AdminAnalyticsSearch />}
          loader={adminAnalyticsSearchLoader}
        />
        <Route
          path="analytics/stores"
          element={<AdminAnalyticsStores />}
          loader={adminAnalyticsStoresLoader}
        />
        <Route
          path="analytics/categories"
          element={<AdminAnalyticsCategories />}
          loader={adminAnalyticsCategoriesLoader}
        />
      </Route>

      <Route path="/notifications" element={<Navigate to="/admin/notifications" replace />} />
      <Route path="/notifications/:id" element={<RedirectNotificationDetail />} />
    </Route>
  )
)
