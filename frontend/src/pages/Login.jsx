import {
  Form,
  Link,
  redirect,
  useActionData,
  useNavigation,
} from "react-router-dom";
import { login } from "../api/auth";
import PageShell from "../components/PageShell";
import Button from "../components/Button";
import "../styles/auth.css";

/** Route action for /login. Returns `{ errors }` on validation failure. */
export async function loginAction({ request }) {
  const formData = await request.formData();
  const email = String(formData.get("email") || "").trim();
  const password = String(formData.get("password") || "");

  if (!email || !password) {
    return { errors: { email: "Укажите email и пароль." } };
  }

  try {
    await login(email, password);
    return redirect("/profile");
  } catch (error) {
    if (error.response?.status === 422) {
      return { errors: error.response.data.errors ?? {} };
    }
    return { errors: { email: "Ошибка при авторизации" } };
  }
}

function errorText(value) {
  if (!value) return null;
  return Array.isArray(value) ? value[0] : value;
}

export default function Login() {
  const actionData = useActionData();
  const navigation = useNavigation();
  const errors = actionData?.errors ?? {};
  const processing = navigation.state !== "idle";

  return (
    <PageShell
      className="page-auth"
      cardClassName="auth-card"
      title="Welcome back"
      subtitle="Sign in to your account"
    >
      <Form method="post" className="auth-form">
        <div className="form-group">
          <label htmlFor="email">Email</label>
          <input
            id="email"
            name="email"
            type="email"
            className="auth-input"
            autoComplete="email"
          />
          {errorText(errors.email) && (
            <span className="error">{errorText(errors.email)}</span>
          )}
        </div>

        <div className="form-group">
          <label htmlFor="password">Password</label>
          <input
            id="password"
            name="password"
            type="password"
            className="auth-input"
            autoComplete="current-password"
          />
          {errorText(errors.password) && (
            <span className="error">{errorText(errors.password)}</span>
          )}
        </div>

        <Button type="submit" className="auth-button" disabled={processing}>
          {processing ? "Signing in..." : "Sign in"}
        </Button>
      </Form>

      <div className="auth-footer">
        <span>Первый раз на сайте?</span>
        <Link to="/registration">Зарегистрироваться</Link>
      </div>
    </PageShell>
  );
}
