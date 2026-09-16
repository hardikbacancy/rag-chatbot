import { useState } from 'react';
import { login, register } from '../api';

export default function Login({ onAuthenticated }) {
    const [mode, setMode] = useState('login');
    const [form, setForm] = useState({ name: '', email: '', password: '', password_confirmation: '' });
    const [error, setError] = useState(null);
    const [submitting, setSubmitting] = useState(false);

    const isRegister = mode === 'register';

    function update(field) {
        return (e) => setForm((prev) => ({ ...prev, [field]: e.target.value }));
    }

    async function handleSubmit(e) {
        e.preventDefault();
        if (submitting) return;

        setError(null);
        setSubmitting(true);

        try {
            const user = isRegister
                ? await register(form)
                : await login({ email: form.email, password: form.password });

            onAuthenticated(user);
        } catch (err) {
            setError(err.message);
        } finally {
            setSubmitting(false);
        }
    }

    return (
        <div className="flex h-screen w-screen items-center justify-center bg-slate-100 p-4">
            <form onSubmit={handleSubmit} className="w-full max-w-sm space-y-4 rounded-2xl bg-white p-6 shadow-sm">
                <div>
                    <h1 className="text-lg font-semibold text-slate-800">
                        {isRegister ? 'Create an account' : 'Sign in'}
                    </h1>
                    <p className="mt-1 text-xs text-slate-500">Your documents and chats stay private to your account.</p>
                </div>

                {isRegister && (
                    <label className="block">
                        <span className="text-xs font-medium text-slate-600">Name</span>
                        <input
                            type="text"
                            value={form.name}
                            onChange={update('name')}
                            required
                            autoComplete="name"
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
                        />
                    </label>
                )}

                <label className="block">
                    <span className="text-xs font-medium text-slate-600">Email</span>
                    <input
                        type="email"
                        value={form.email}
                        onChange={update('email')}
                        required
                        autoComplete="email"
                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
                    />
                </label>

                <label className="block">
                    <span className="text-xs font-medium text-slate-600">Password</span>
                    <input
                        type="password"
                        value={form.password}
                        onChange={update('password')}
                        required
                        autoComplete={isRegister ? 'new-password' : 'current-password'}
                        className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
                    />
                </label>

                {isRegister && (
                    <label className="block">
                        <span className="text-xs font-medium text-slate-600">Confirm password</span>
                        <input
                            type="password"
                            value={form.password_confirmation}
                            onChange={update('password_confirmation')}
                            required
                            autoComplete="new-password"
                            className="mt-1 w-full rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none"
                        />
                    </label>
                )}

                {error && <p className="text-xs text-red-600">{error}</p>}

                <button
                    type="submit"
                    disabled={submitting}
                    className="w-full rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    {submitting ? 'Please wait…' : isRegister ? 'Create account' : 'Sign in'}
                </button>

                <button
                    type="button"
                    onClick={() => {
                        setMode(isRegister ? 'login' : 'register');
                        setError(null);
                    }}
                    className="w-full text-xs text-slate-500 hover:text-indigo-600"
                >
                    {isRegister ? 'Already have an account? Sign in' : "No account? Create one"}
                </button>
            </form>
        </div>
    );
}
