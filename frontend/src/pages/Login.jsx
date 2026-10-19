import { useState } from 'react'
import { useNavigate } from 'react-router-dom'
import { login } from '../api/auth'
import PageShell from '../components/PageShell'
import Button from '../components/Button'
import '../styles/auth.css'

export default function Login() {
  const [email, setEmail] = useState('')
  const [password, setPassword] = useState('')
  const [errors, setErrors] = useState({})
  const [processing, setProcessing] = useState(false)
  const navigate = useNavigate()

  const submit = async (e) => {
    e.preventDefault()

    setProcessing(true)
    setErrors({})

    try {
      await login(email, password)
      navigate('/profile')
    } catch (error) {
      if (error.response?.status === 422) {
        setErrors(error.response.data.errors ?? {})
      } else {
        setErrors({
          email: 'Ошибка при авторизации',
        })
      }
    } finally {
      setProcessing(false)
    }
  }

  return (
    <PageShell className="page-auth" cardClassName="auth-card" title="Welcome back" subtitle="Sign in to your account">
      <form onSubmit={submit} className="auth-form">
        <div className="form-group">
          <label htmlFor="email">Email</label>
          <input
            id="email"
            type="email"
            className="auth-input"
            value={email}
            onChange={(e) => setEmail(e.target.value)}
          />
          {errors.email && (
            <span className="error">
              {Array.isArray(errors.email) ? errors.email[0] : errors.email}
            </span>
          )}
        </div>

        <div className="form-group">
          <label htmlFor="password">Password</label>
          <input
            id="password"
            type="password"
            className="auth-input"
            value={password}
            onChange={(e) => setPassword(e.target.value)}
          />
          {errors.password && (
            <span className="error">
              {Array.isArray(errors.password) ? errors.password[0] : errors.password}
            </span>
          )}
        </div>

        <Button type="submit" className="auth-button" disabled={processing}>
          {processing ? 'Signing in...' : 'Sign in'}
        </Button>
      </form>
    </PageShell>
  )
}
