import { lazy, Suspense } from "react";
import {
  createBrowserRouter,
  createRoutesFromElements,
  Navigate,
  Route,
  useParams,
} from "react-router-dom";

import Login, { loginAction } from "../pages/Login";
import Registration, { registrationAction } from "../pages/Registration";
import Profile, { profileAction } from "../pages/Profile";
import Categories from "../pages/Categories";
import CategoryProducts from "../pages/CategoryProducts";
import Stores from "../pages/Stores";
import StoreProducts from "../pages/StoreProducts";
import Product from "../pages/Product";
import Orders from "../pages/Orders";
import OrderDetail from "../pages/OrderDetail";
import Cart, { cartAction } from "../pages/Cart";
import Search from "../pages/Search";
import Assistant from "../pages/Assistant";

import AdminLayout from "../admin/AdminLayout";
import AdminHub from "../admin/AdminHub";
import AdminUsers from "../admin/AdminUsers";
import AdminOrders from "../admin/AdminOrders";
import AdminNotifications from "../admin/AdminNotifications";
import AdminNotificationDetail from "../admin/AdminNotificationDetail";

import AppLayout from "../layouts/AppLayout";
import RootErrorBoundary from "./RootErrorBoundary";
import { rootLoader } from "./rootLoader";
import {
  adminLayoutLoader,
  adminUsersLoader,
  adminUsersAction,
  adminOrdersLoader,
  adminOrdersAction,
  adminNotificationsLoader,
  adminNotificationDetailLoader,
  adminNotificationDetailAction,
  adminAnalyticsDimensionLoader,
} from "../admin/loaders";
import {
  ordersLoader,
  orderDetailLoader,
  orderDetailAction,
} from "../orders/loaders";
import {
  categoriesLoader,
  categoryProductsLoader,
  storesLoader,
  storeProductsLoader,
  productLoader,
  assistantLoader,
} from "../pages/catalogLoaders";
import { searchLoader } from "../pages/search/searchLoader";
import { requireAuth } from "../auth/requireAuth";

const AdminAnalyticsDimension = lazy(
  () => import("../admin/AdminAnalyticsDimension"),
);

function analyticsElement() {
  return (
    <Suspense fallback={<div className="table-loading">Загрузка...</div>}>
      <AdminAnalyticsDimension />
    </Suspense>
  );
}

function RedirectNotificationDetail() {
  const { id } = useParams();
  return <Navigate to={`/admin/notifications/${id}`} replace />;
}

export const router = createBrowserRouter(
  createRoutesFromElements(
    <Route errorElement={<RootErrorBoundary />} loader={rootLoader} id="root">
      <Route path="/login" element={<Login />} action={loginAction} />
      <Route
        path="/registration"
        element={<Registration />}
        action={registrationAction}
      />

      <Route element={<AppLayout />}>
        <Route path="/search" element={<Search />} loader={searchLoader} />
        <Route
          path="/assistant"
          element={<Assistant />}
          loader={assistantLoader}
        />
        <Route
          path="/profile"
          element={<Profile />}
          loader={requireAuth}
          action={profileAction}
        />
        <Route
          path="/categories"
          element={<Categories />}
          loader={categoriesLoader}
        />
        <Route
          path="/categories/:slug"
          element={<CategoryProducts />}
          loader={categoryProductsLoader}
        />
        <Route path="/stores" element={<Stores />} loader={storesLoader} />
        <Route
          path="/stores/:storeId/products"
          element={<StoreProducts />}
          loader={storeProductsLoader}
        />
        <Route
          path="/products/:id"
          element={<Product />}
          loader={productLoader}
        />
        <Route path="/cart" element={<Cart />} action={cartAction} />
        <Route path="/orders" element={<Orders />} loader={ordersLoader} />
        <Route
          path="/orders/:id"
          element={<OrderDetail />}
          loader={orderDetailLoader}
          action={orderDetailAction}
        />
      </Route>

      <Route path="/admin" element={<AdminLayout />} loader={adminLayoutLoader}>
        <Route index element={<AdminHub />} />
        <Route
          path="users"
          element={<AdminUsers />}
          loader={adminUsersLoader}
          action={adminUsersAction}
        />
        <Route
          path="orders"
          element={<AdminOrders />}
          loader={adminOrdersLoader}
          action={adminOrdersAction}
        />
        <Route
          path="notifications"
          element={<AdminNotifications />}
          loader={adminNotificationsLoader}
        />
        <Route
          path="notifications/:id"
          element={<AdminNotificationDetail />}
          loader={adminNotificationDetailLoader}
          action={adminNotificationDetailAction}
        />
        <Route
          path="analytics/:dimension"
          element={analyticsElement()}
          loader={adminAnalyticsDimensionLoader}
        />
      </Route>

      <Route
        path="/notifications"
        element={<Navigate to="/admin/notifications" replace />}
      />
      <Route
        path="/notifications/:id"
        element={<RedirectNotificationDetail />}
      />
    </Route>,
  ),
);
