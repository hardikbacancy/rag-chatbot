import { useEffect, useRef, useState } from 'react';
import { createConversation, getConversation, streamMessage } from '../api';

export const CONVERSATION_STORAGE_KEY = 'rag_chatbot_conversation_id';
const STORAGE_KEY = CONVERSATION_STORAGE_KEY;

export default function Chat() {
    const [conversationId, setConversationId] = useState(() => localStorage.getItem(STORAGE_KEY));
    const [messages, setMessages] = useState([]);
    const [input, setInput] = useState('');
    const [sending, setSending] = useState(false);
    const [error, setError] = useState(null);
    const bottomRef = useRef(null);

    useEffect(() => {
        if (!conversationId) return;

        getConversation(conversationId)
            .then((conversation) => {
                setMessages(
                    conversation.messages.map((m) => ({
                        id: m.id,
                        role: m.role,
                        content: m.content,
                        sources: (m.sources ?? []).map((s) => ({
                            rank: s.rank,
                            similarity: s.similarity_score,
                            filename: s.chunk.document.original_filename,
                            chunk_index: s.chunk.chunk_index,
                            content: s.chunk.content,
                        })),
                    })),
                );
            })
            .catch(() => {
                localStorage.removeItem(STORAGE_KEY);
                setConversationId(null);
            });
    }, [conversationId]);

    useEffect(() => {
        bottomRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [messages]);

    async function handleSend(e) {
        e.preventDefault();
        const question = input.trim();
        if (!question || sending) return;

        setError(null);
        setInput('');
        setSending(true);

        try {
            let id = conversationId;
            if (!id) {
                const conversation = await createConversation();
                id = conversation.id;
                setConversationId(id);
                localStorage.setItem(STORAGE_KEY, id);
            }

            setMessages((prev) => [...prev, { role: 'user', content: question }]);
            setMessages((prev) => [...prev, { role: 'assistant', content: '', sources: [], streaming: true }]);

            await streamMessage(id, question, {
                onSources: (sources) => {
                    setMessages((prev) => {
                        const next = [...prev];
                        next[next.length - 1] = { ...next[next.length - 1], sources };
                        return next;
                    });
                },
                onToken: (text) => {
                    setMessages((prev) => {
                        const next = [...prev];
                        const last = next[next.length - 1];
                        next[next.length - 1] = { ...last, content: last.content + text };
                        return next;
                    });
                },
                onDone: (messageId) => {
                    setMessages((prev) => {
                        const next = [...prev];
                        next[next.length - 1] = { ...next[next.length - 1], id: messageId, streaming: false };
                        return next;
                    });
                },
                onError: (message) => {
                    setError(message);
                    setMessages((prev) => prev.slice(0, -1));
                },
            });
        } catch (err) {
            setError(err.message);
        } finally {
            setSending(false);
        }
    }

    return (
        <main className="flex h-full flex-1 flex-col">
            <div className="flex-1 space-y-4 overflow-y-auto p-4">
                {messages.length === 0 && (
                    <p className="mt-8 text-center text-sm text-slate-400">
                        Upload a document, then ask a question about it.
                    </p>
                )}

                {messages
                    .filter((message) => message.content || message.streaming)
                    .map((message, i) => (
                        <div key={message.id ?? i} className={`flex ${message.role === 'user' ? 'justify-end' : 'justify-start'}`}>
                            <div
                                className={`max-w-2xl rounded-2xl px-4 py-3 text-sm shadow-sm ${
                                    message.role === 'user' ? 'bg-indigo-600 text-white' : 'bg-white text-slate-800'
                                }`}
                            >
                                <p className="whitespace-pre-wrap">
                                    {message.content}
                                    {message.streaming && <span className="animate-pulse">▍</span>}
                                </p>
                            </div>
                        </div>
                    ))}
                <div ref={bottomRef} />
            </div>

            {error && <p className="px-4 pb-2 text-xs text-red-600">{error}</p>}

            <form onSubmit={handleSend} className="flex gap-2 border-t border-slate-200 bg-white p-4">
                <input
                    value={input}
                    onChange={(e) => setInput(e.target.value)}
                    placeholder="Ask a question about your documents…"
                    disabled={sending}
                    className="flex-1 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-indigo-500 focus:outline-none disabled:opacity-50"
                />
                <button
                    type="submit"
                    disabled={sending || !input.trim()}
                    className="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-50"
                >
                    Send
                </button>
            </form>
        </main>
    );
}
