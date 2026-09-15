import { useCallback, useEffect, useState } from 'react';
import Sidebar from './Sidebar';
import Chat from './Chat';
import { listDocuments } from '../api';

export default function App() {
    const [documents, setDocuments] = useState([]);

    const refreshDocuments = useCallback(async () => {
        setDocuments(await listDocuments());
    }, []);

    useEffect(() => {
        refreshDocuments();
    }, [refreshDocuments]);

    return (
        <div className="flex h-screen w-screen flex-col bg-slate-100 md:flex-row">
            <Sidebar documents={documents} onDocumentsChanged={refreshDocuments} />
            <Chat />
        </div>
    );
}
