import {
  Form,
  Link,
  redirect,
  useActionData,
  useNavigation,
} from "react-router-dom";
import { register } from "../api/auth";
import "../styles/auth.css";

/** Route action for /registration. Redirects to /login on success. */
export async function registrationAction({ request }) {
  const formData = await request.formData();
  const payload = {
    name: String(formData.get("name") || "").trim(),
    number: String(formData.get("number") || "").trim(),
    email: String(formData.get("email") || "").trim(),
    password: String(formData.get("password") || ""),
  };

  try {
    await register(payload);
    return redirect("/login");
  } catch (error) {
    if (error.response?.status === 422) {
      return { errors: error.response.data.errors ?? {} };
    }
    return { errors: { email: "Ошибка при регистрации" } };
  }
}

function errorText(value) {
  if (!value) return null;
  return Array.isArray(value) ? value[0] : value;
}

export default function Registration() {
  const actionData = useActionData();
  const navigation = useNavigation();
  const errors = actionData?.errors ?? {};
  const processing = navigation.state !== "idle";

  return (
    <div className="auth-page">
      <div className="auth-card register-card">
        <div className="auth-header">
          <h1>Create account</h1>
          <p>Register a new account</p>
        </div>

        <Form method="post" className="auth-form">
          <div className="form-group">
            <label htmlFor="name">Name</label>
            <input
              id="name"
              name="name"
              type="text"
              className="auth-input"
              placeholder="Enter your name"
            />
            {errorText(errors.name) && (
              <span className="error">{errorText(errors.name)}</span>
            )}
          </div>

          <div className="form-group">
            <label htmlFor="number">Phone number</label>
            <input
              id="number"
              name="number"
              type="tel"
              className="auth-input"
              placeholder="+48 123 456 789"
            />
            {errorText(errors.number) && (
              <span className="error">{errorText(errors.number)}</span>
            )}
          </div>

          <div className="form-group">
            <label htmlFor="email">Email</label>
            <input
              id="email"
              name="email"
              type="email"
              className="auth-input"
              placeholder="you@example.com"
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
              placeholder="Enter your password"
            />
            {errorText(errors.password) && (
              <span className="error">{errorText(errors.password)}</span>
            )}
          </div>

          <button type="submit" className="auth-button" disabled={processing}>
            {processing ? "Registering..." : "Create account"}
          </button>
        </Form>

        <div className="auth-footer">
          <span>Уже есть аккаунт?</span>
          <Link to="/login">Войти</Link>
        </div>
      </div>
    </div>
  );
}
