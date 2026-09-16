import { useRef, useState } from 'react';
import { deleteDocument, uploadDocument } from '../api';

const STATUS_STYLES = {
    processing: 'bg-amber-100 text-amber-700',
    ready: 'bg-emerald-100 text-emerald-700',
    failed: 'bg-red-100 text-red-700',
};

export default function Sidebar({ documents, onDocumentsChanged }) {
    const [uploading, setUploading] = useState(false);
    const [error, setError] = useState(null);
    const inputRef = useRef(null);

    async function handleFileChange(e) {
        const file = e.target.files?.[0];
        if (!file) return;

        setUploading(true);
        setError(null);

        try {
            await uploadDocument(file);
            await onDocumentsChanged();
        } catch (err) {
            setError(err.message);
        } finally {
            setUploading(false);
            if (inputRef.current) inputRef.current.value = '';
        }
    }

    async function handleDelete(id, filename) {
        if (!window.confirm(`Delete "${filename}"? This cannot be undone.`)) return;

        try {
            await deleteDocument(id);
            await onDocumentsChanged();
        } catch (err) {
            setError(err.message);
        }
    }

    return (
        <aside className="flex h-full w-full flex-col border-r border-slate-200 bg-slate-50 md:w-80">
            <div className="border-b border-slate-200 p-4">
                <h2 className="text-sm font-semibold text-slate-700">Documents</h2>
                <p className="mt-1 text-xs text-slate-500">PDF, DOCX, TXT or MD</p>

                <label className="mt-3 block">
                    <input
                        ref={inputRef}
                        type="file"
                        accept=".pdf,.docx,.txt,.md"
                        onChange={handleFileChange}
                        disabled={uploading}
                        className="block w-full text-xs text-slate-600 file:mr-3 file:rounded-md file:border-0 file:bg-indigo-600 file:px-3 file:py-2 file:text-xs file:font-medium file:text-white hover:file:bg-indigo-700 disabled:opacity-50"
                    />
                </label>

                {uploading && <p className="mt-2 text-xs text-indigo-600">Uploading &amp; indexing…</p>}
                {error && <p className="mt-2 text-xs text-red-600">{error}</p>}
            </div>

            <ul className="flex-1 space-y-2 overflow-y-auto p-4">
                {documents.length === 0 && (
                    <li className="text-xs text-slate-400">No documents uploaded yet.</li>
                )}
                {documents.map((doc) => (
                    <li key={doc.id} className="rounded-lg border border-slate-200 bg-white p-3 text-sm shadow-sm">
                        <div className="flex items-start justify-between gap-2">
                            <span className="break-all font-medium text-slate-800">{doc.original_filename}</span>
                            <button
                                onClick={() => handleDelete(doc.id, doc.original_filename)}
                                className="shrink-0 text-xs text-slate-400 hover:text-red-600"
                                title="Delete document"
                            >
                                ✕
                            </button>
                        </div>
                        <span
                            className={`mt-2 inline-block rounded-full px-2 py-0.5 text-[10px] font-medium ${STATUS_STYLES[doc.status] ?? 'bg-slate-100 text-slate-600'}`}
                        >
                            {doc.status}
                        </span>
                    </li>
                ))}
            </ul>
        </aside>
    );
}
