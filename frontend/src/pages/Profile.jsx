import { useFetcher, useLoaderData, redirect, Form } from "react-router-dom";
import {
  logout as apiLogout,
  updatePassword,
  updateProfile,
} from "../api/auth";
import "../styles/profile.css";

const E164_PHONE_RE = /^\+[1-9]\d{9,14}$/;

function normalizePhone(value) {
  return String(value ?? "").replaceAll(/[\s\-()]/g, "");
}

function validatePhone(value) {
  const normalized = normalizePhone(value);
  if (!normalized) return "Укажите номер телефона.";
  if (normalized.length > 16 || !E164_PHONE_RE.test(normalized)) {
    return "Номер телефона должен быть в формате E.164 (например +48123456789).";
  }
  return null;
}

function fieldErrorMessage(error, field) {
  const fieldErrors = error.response?.data?.errors?.[field];
  if (Array.isArray(fieldErrors) && fieldErrors[0]) return fieldErrors[0];
  return error.response?.data?.message || "Ошибка при изменении данных";
}

/**
 * Route action for /profile. Handles logout, profile field updates and password
 * changes via the `intent` field. Returns a `{ status }` object for inline
 * messages (or a redirect for logout).
 */
export async function profileAction({ request }) {
  const formData = await request.formData();
  const intent = formData.get("intent");

  if (intent === "logout") {
    try {
      await apiLogout();
    } catch {
      // Session is discarded client-side regardless.
    }
    return redirect("/login");
  }

  if (intent === "field") {
    const field = formData.get("field");
    let value = formData.get("value");
    const displayValue = value;

    if (field === "number") {
      const phoneError = validatePhone(value);
      if (phoneError) {
        return { status: { type: "error", message: phoneError } };
      }
      value = normalizePhone(value);
    }

    try {
      const response = await updateProfile(field, value);
      return {
        status: { type: "success", message: "Данные успешно обновлены!" },
        field,
        value: response?.data?.user?.[field] ?? displayValue,
      };
    } catch (error) {
      return {
        status: { type: "error", message: fieldErrorMessage(error, field) },
      };
    }
  }

  if (intent === "password") {
    try {
      await updatePassword({
        current_password: formData.get("current_password"),
        new_password: formData.get("new_password"),
      });
      return {
        status: { type: "success", message: "Пароль успешно изменён!" },
        cleared: true,
      };
    } catch (error) {
      return {
        status: {
          type: "error",
          message: error.response?.data?.message || "Ошибка при смене пароля",
        },
      };
    }
  }

  return { status: { type: "", message: "" } };
}

export default function Profile() {
  const user = useLoaderData();
  const fetcher = useFetcher();
  const passwordFetcher = useFetcher();

  if (!user) return null;

  const avatarInitial = (user.name || user.email || "U")
    .charAt(0)
    .toUpperCase();

  const busy = fetcher.state !== "idle";
  const status = fetcher.data?.status;
  const passwordBusy = passwordFetcher.state !== "idle";
  const passwordStatus = passwordFetcher.data?.status;

  return (
    <div className="profile-page">
      <div className="profile-card">
        <Form method="post" className="logout-form">
          <input type="hidden" name="intent" value="logout" />
          <button
            className="logout-button"
            type="submit"
            title="Выйти из аккаунта"
            aria-label="Выйти из аккаунта"
          >
            <svg
              width="16"
              height="16"
              viewBox="0 0 24 24"
              fill="none"
              stroke="currentColor"
              strokeWidth="2"
              strokeLinecap="round"
              strokeLinejoin="round"
            >
              <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
              <polyline points="16 17 21 12 16 7" />
              <line x1="21" y1="12" x2="9" y2="12" />
            </svg>
          </button>
        </Form>

        <div className="profile-header">
          <div className="profile-avatar">{avatarInitial}</div>
          <div className="profile-title-group">
            <h1>{user.name || "Профиль"}</h1>
            <p>{user.email}</p>
          </div>
        </div>

        <div className="profile-badges">
          <span className="badge">ID: {user.id}</span>
          <span className="badge">Role ID: {user.role_id}</span>
        </div>

        {status?.message && (
          <div className={`status-message ${status.type}`}>
            {status.message}
          </div>
        )}

        <div className="profile-form">
          <div className="field-group">
            <label htmlFor="profile-name">Имя</label>
            <fetcher.Form method="post" className="input-wrapper">
              <input type="hidden" name="intent" value="field" />
              <input type="hidden" name="field" value="name" />
              <input
                id="profile-name"
                name="value"
                type="text"
                className="profile-input"
                defaultValue={user.name || ""}
                placeholder="Введите имя"
              />
              <button className="save-button" type="submit" disabled={busy}>
                {busy && fetcher.formData?.get("field") === "name"
                  ? "Сохранение..."
                  : "Изменить"}
              </button>
            </fetcher.Form>
          </div>

          <div className="field-group">
            <label htmlFor="profile-number">Номер телефона</label>
            <fetcher.Form method="post" className="input-wrapper">
              <input type="hidden" name="intent" value="field" />
              <input type="hidden" name="field" value="number" />
              <input
                id="profile-number"
                name="value"
                type="tel"
                className="profile-input"
                defaultValue={user.number || ""}
                placeholder="+7 (999) 000-00-00"
              />
              <button className="save-button" type="submit" disabled={busy}>
                {busy && fetcher.formData?.get("field") === "number"
                  ? "Сохранение..."
                  : "Изменить"}
              </button>
            </fetcher.Form>
          </div>
        </div>

        <div className="profile-form" style={{ marginTop: "24px" }}>
          <h2 style={{ marginBottom: "12px", fontSize: "18px" }}>
            Смена пароля
          </h2>

          {passwordStatus?.message && (
            <div className={`status-message ${passwordStatus.type}`}>
              {passwordStatus.message}
            </div>
          )}

          <passwordFetcher.Form method="post">
            <input type="hidden" name="intent" value="password" />
            <div className="field-group">
              <label htmlFor="profile-current-password">Текущий пароль</label>
              <div className="input-wrapper">
                <input
                  id="profile-current-password"
                  name="current_password"
                  type="password"
                  className="profile-input"
                  placeholder="Введите текущий пароль"
                  key={passwordFetcher.data?.cleared ? "cleared" : "current"}
                />
              </div>
            </div>

            <div className="field-group">
              <label htmlFor="profile-new-password">Новый пароль</label>
              <div className="input-wrapper">
                <input
                  id="profile-new-password"
                  name="new_password"
                  type="password"
                  className="profile-input"
                  placeholder="Введите новый пароль"
                  key={passwordFetcher.data?.cleared ? "cleared-new" : "new"}
                />
                <button
                  className="save-button"
                  type="submit"
                  disabled={passwordBusy}
                >
                  {passwordBusy ? "Сохранение..." : "Изменить пароль"}
                </button>
              </div>
            </div>
          </passwordFetcher.Form>
        </div>
      </div>
    </div>
  );
}
