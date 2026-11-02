import { Outlet, useRouteLoaderData } from "react-router-dom";
import AppHeader from "../components/AppHeader";

export default function AppLayout() {
  const data = useRouteLoaderData("root");
  const user = data?.user ?? null;

  return (
    <>
      <AppHeader user={user} />
      <Outlet />
    </>
  );
}
