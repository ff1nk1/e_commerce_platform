import { useState } from 'react'
import { Link, useNavigate } from 'react-router-dom'
import { register } from '../api/auth'
import '../styles/auth.css'

export default function Registration() {
    const [formData, setFormData] = useState({
        name: '',
        number: '',
        email: '',
        password: '',
    })
    const [errors, setErrors] = useState({})
    const [processing, setProcessing] = useState(false)
    const navigate = useNavigate()

    const handleChange = (e) => {
        setFormData({
            ...formData,
            [e.target.name]: e.target.value,
        })
    }

    const submit = async (e) => {
        e.preventDefault()

        setProcessing(true)
        setErrors({})

        try {
            await register(formData)

            // Перенаправление на страницу входа после успешной регистрации
            navigate('/login')
        } catch (error) {
            if (error.response?.status === 422) {
                setErrors(error.response.data.errors ?? {})
            } else {
                setErrors({
                    email: 'Ошибка при регистрации',
                })
            }
        } finally {
            setProcessing(false)
        }
    }

    return (
        <div className="auth-page">
            <div className="auth-card register-card">
                <div className="auth-header">
                    <h1>Create account</h1>
                    <p>Register a new account</p>
                </div>

                <form onSubmit={submit} className="auth-form">
                    <div className="form-group">
                        <label htmlFor="name">Name</label>

                        <input
                            id="name"
                            name="name"
                            type="text"
                            className="auth-input"
                            placeholder="Enter your name"
                            value={formData.name}
                            onChange={handleChange}
                        />

                        {errors.name && (
                            <span className="error">
                                {Array.isArray(errors.name) ? errors.name[0] : errors.name}
                            </span>
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
                            value={formData.number}
                            onChange={handleChange}
                        />

                        {errors.number && (
                            <span className="error">
                                {Array.isArray(errors.number) ? errors.number[0] : errors.number}
                            </span>
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
                            value={formData.email}
                            onChange={handleChange}
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
                            name="password"
                            type="password"
                            className="auth-input"
                            placeholder="Enter your password"
                            value={formData.password}
                            onChange={handleChange}
                        />

                        {errors.password && (
                            <span className="error">
                                {Array.isArray(errors.password) ? errors.password[0] : errors.password}
                            </span>
                        )}
                    </div>

                    <button
                        type="submit"
                        className="auth-button"
                        disabled={processing}
                    >
                        {processing ? 'Registering...' : 'Create account'}
                    </button>
                </form>

                <div className="auth-footer">
                    <span>Уже есть аккаунт?</span>
                    <Link to="/login">Войти</Link>
                </div>
            </div>
        </div>
    )
}