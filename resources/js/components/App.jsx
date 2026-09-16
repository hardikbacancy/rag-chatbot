import { useCallback, useEffect, useState } from 'react';
import Sidebar from './Sidebar';
import Chat from './Chat';
import Login from './Login';
import { getUser, listDocuments, logout } from '../api';
import { CONVERSATION_STORAGE_KEY } from './Chat';

export default function App() {
    const [user, setUser] = useState(null);
    const [guestMode, setGuestMode] = useState(false);
    const [checkingSession, setCheckingSession] = useState(true);
    const [documents, setDocuments] = useState([]);

    useEffect(() => {
        getUser()
            .then(({ user, guestMode }) => {
                setUser(user);
                setGuestMode(guestMode);
            })
            .catch(() => setUser(null))
            .finally(() => setCheckingSession(false));
    }, []);

    const refreshDocuments = useCallback(async () => {
        setDocuments(await listDocuments());
    }, []);

    useEffect(() => {
        if (user) refreshDocuments();
    }, [user, refreshDocuments]);

    async function handleLogout() {
        await logout().catch(() => {});
        // The stored conversation belongs to the account that just signed out.
        localStorage.removeItem(CONVERSATION_STORAGE_KEY);
        setDocuments([]);
        setUser(null);
    }

    if (checkingSession) {
        return (
            <div className="flex h-screen w-screen items-center justify-center bg-slate-100">
                <p className="text-sm text-slate-400">Loading…</p>
            </div>
        );
    }

    if (!user) {
        return <Login onAuthenticated={setUser} />;
    }

    return (
        <div className="flex h-screen w-screen flex-col bg-slate-100">
            <header className="flex items-center justify-between border-b border-slate-200 bg-white px-4 py-2">
                <span className="text-sm font-semibold text-slate-700">RAG Chatbot</span>
                {!guestMode && (
                    <div className="flex items-center gap-3">
                        <span className="text-xs text-slate-500">{user.email}</span>
                        <button
                            onClick={handleLogout}
                            className="rounded-md border border-slate-300 px-2 py-1 text-xs text-slate-600 hover:bg-slate-50"
                        >
                            Sign out
                        </button>
                    </div>
                )}
            </header>

            <div className="flex min-h-0 flex-1 flex-col md:flex-row">
                <Sidebar documents={documents} onDocumentsChanged={refreshDocuments} />
                <Chat key={user.id} />
            </div>
        </div>
    );
}
