import { useState } from 'react'
import { useLoaderData, useNavigate } from 'react-router-dom'
import { logout as apiLogout, updatePassword, updateProfile } from '../api/auth'
import '../styles/profile.css'

export default function Profile() {
  const user = useLoaderData()
  const navigate = useNavigate()
  const [name, setName] = useState(user?.name || '')
  const [number, setNumber] = useState(user?.number || '')

  // --- Добавлено: состояния для смены пароля ---
  const [currentPassword, setCurrentPassword] = useState('');
  const [newPassword, setNewPassword] = useState('');
  const [passwordLoading, setPasswordLoading] = useState(false);
  const [passwordStatus, setPasswordStatus] = useState({ message: '', type: '' });
  // ---------------------------------------------


  const [loadingField, setLoadingField] = useState(null);
  const [status, setStatus] = useState({ message: '', type: '' });

  const handleUpdate = async (field, value) => {
    setLoadingField(field);
    setStatus({ message: '', type: '' });

    try {
      // Используем твой api, он сам подставит baseURL, куки и токены (withCredentials: true)
      // Если бэкенд вернет 401, твой интерсептор сам обновит сессию и повторит этот запрос!
      await updateProfile(field, value)

      setStatus({ message: 'Данные успешно обновлены!', type: 'success' });
    } catch (error) {
      // Axios кладет ответ сервера в error.response
      const errorMessage = error.response?.data?.message || 'Ошибка при изменении данных';
      setStatus({ message: errorMessage, type: 'error' });
    } finally {
      setLoadingField(null);
    }
  };

  // --- Отредактировано: выход из аккаунта ---
  const logout = async () => {
    try {
      await apiLogout()
    } catch (error) {
      const errorMessage = error.response?.data?.message || 'Ошибка при выходе из аккаунта'
      console.warn(errorMessage)
    } finally {
      navigate('/login', { replace: true })
    }
  };
  // -------------------------------------------

  // --- Добавлено: функция смены пароля ---
  const handleChangePassword = async () => {
    setPasswordLoading(true);
    setPasswordStatus({ message: '', type: '' });

    try {
      await updatePassword({
        current_password: currentPassword,
        new_password: newPassword,
      })

      setPasswordStatus({ message: 'Пароль успешно изменён!', type: 'success' });
      setCurrentPassword('');
      setNewPassword('');
    } catch (error) {
      const errorMessage = error.response?.data?.message || 'Ошибка при смене пароля';
      setPasswordStatus({ message: errorMessage, type: 'error' });
    } finally {
      setPasswordLoading(false);
    }
  };
  // ---------------------------------------

  if (!user) return null

  const avatarInitial = (name || user.email || 'U').charAt(0).toUpperCase()

  return (
    <div className="profile-page">
      <div className="profile-card">

        {/* --- Добавлено: кнопка выхода в углу --- */}
        <button
          className="logout-button"
          onClick={logout}
          title="Выйти из аккаунта"
          aria-label="Выйти из аккаунта"
        >
          {/* Иконка "выход" (стрелка из двери). Можно заменить на свой SVG или текст "→]" */}
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4" />
            <polyline points="16 17 21 12 16 7" />
            <line x1="21" y1="12" x2="9" y2="12" />
          </svg>
        </button>
        {/* ------------------------------------ */}

        {/* Заголовок с информацией о пользователе */}
        <div className="profile-header">
          <div className="profile-avatar">{avatarInitial}</div>
          <div className="profile-title-group">
            <h1>{name || 'Профиль'}</h1>
            <p>{user.email}</p>
          </div>
        </div>

        {/* Служебные данные (ID и Role ID) */}
        <div className="profile-badges">
          <span className="badge">ID: {user.id}</span>
          <span className="badge">Role ID: {user.role_id}</span>
        </div>

        {/* Статусное сообщение об успехе или ошибке */}
        {status.message && (
          <div className={`status-message ${status.type}`}>
            {status.message}
          </div>
        )}

        {/* Интерактивные поля ввода */}
        <div className="profile-form">
          <div className="field-group">
            <label htmlFor="profile-name">Имя</label>
            <div className="input-wrapper">
              <input
                id="profile-name"
                type="text"
                className="profile-input"
                value={name}
                onChange={(e) => setName(e.target.value)}
                placeholder="Введите имя"
              />
              <button
                className="save-button"
                onClick={() => handleUpdate('name', name)}
                disabled={loadingField === 'name'}
              >
                {loadingField === 'name' ? 'Сохранение...' : 'Изменить'}
              </button>
            </div>
          </div>

          <div className="field-group">
            <label htmlFor="profile-number">Номер телефона</label>
            <div className="input-wrapper">
              <input
                id="profile-number"
                type="tel"
                className="profile-input"
                value={number}
                onChange={(e) => setNumber(e.target.value)}
                placeholder="+7 (999) 000-00-00"
              />
              <button
                className="save-button"
                onClick={() => handleUpdate('number', number)}
                disabled={loadingField === 'number'}
              >
                {loadingField === 'number' ? 'Сохранение...' : 'Изменить'}
              </button>
            </div>
          </div>
        </div>

        <div className="profile-form" style={{ marginTop: '24px' }}>
          <h2 style={{ marginBottom: '12px', fontSize: '18px' }}>Смена пароля</h2>

          {passwordStatus.message && (
            <div className={`status-message ${passwordStatus.type}`}>
              {passwordStatus.message}
            </div>
          )}

          <div className="field-group">
            <label htmlFor="profile-current-password">Текущий пароль</label>
            <div className="input-wrapper">
              <input
                id="profile-current-password"
                type="password"
                className="profile-input"
                value={currentPassword}
                onChange={(e) => setCurrentPassword(e.target.value)}
                placeholder="Введите текущий пароль"
              />
            </div>
          </div>

          <div className="field-group">
            <label htmlFor="profile-new-password">Новый пароль</label>
            <div className="input-wrapper">
              <input
                id="profile-new-password"
                type="password"
                className="profile-input"
                value={newPassword}
                onChange={(e) => setNewPassword(e.target.value)}
                placeholder="Введите новый пароль"
              />
              <button
                className="save-button"
                onClick={handleChangePassword}
                disabled={passwordLoading || !currentPassword || !newPassword}
              >
                {passwordLoading ? 'Сохранение...' : 'Изменить пароль'}
              </button>
            </div>
          </div>
        </div>

      </div>
    </div>
  );
}