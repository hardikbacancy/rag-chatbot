const JSON_HEADERS = { 'Content-Type': 'application/json', Accept: 'application/json' };

async function handle(response) {
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        throw new Error(body.message || `Request failed (${response.status})`);
    }
    if (response.status === 204) return null;
    return response.json();
}

export function listDocuments() {
    return fetch('/api/documents', { headers: JSON_HEADERS }).then(handle);
}

export function uploadDocument(file) {
    const formData = new FormData();
    formData.append('file', file);
    return fetch('/api/documents', { method: 'POST', body: formData, headers: { Accept: 'application/json' } }).then(handle);
}

export function deleteDocument(id) {
    return fetch(`/api/documents/${id}`, { method: 'DELETE', headers: JSON_HEADERS }).then(handle);
}

export function createConversation() {
    return fetch('/api/conversations', { method: 'POST', headers: JSON_HEADERS, body: JSON.stringify({}) }).then(handle);
}

export function getConversation(id) {
    return fetch(`/api/conversations/${id}`, { headers: JSON_HEADERS }).then(handle);
}

export function deleteConversation(id) {
    return fetch(`/api/conversations/${id}`, { method: 'DELETE', headers: JSON_HEADERS }).then(handle);
}

export async function streamMessage(conversationId, content, { onSources, onToken, onDone, onError }) {
    const response = await fetch(`/api/conversations/${conversationId}/messages`, {
        method: 'POST',
        headers: JSON_HEADERS,
        body: JSON.stringify({ content }),
    });

    if (!response.ok || !response.body) {
        const body = await response.json().catch(() => ({}));
        onError?.(body.message || `Request failed (${response.status})`);
        return;
    }

    const reader = response.body.getReader();
    const decoder = new TextDecoder();
    let buffer = '';

    while (true) {
        const { done, value } = await reader.read();
        if (done) break;

        buffer += decoder.decode(value, { stream: true });
        let boundary;

        while ((boundary = buffer.indexOf('\n\n')) !== -1) {
            const event = buffer.slice(0, boundary);
            buffer = buffer.slice(boundary + 2);

            for (const line of event.split('\n')) {
                if (!line.startsWith('data:')) continue;
                const payload = JSON.parse(line.slice(5).trim());

                if (payload.type === 'sources') onSources?.(payload.sources);
                else if (payload.type === 'token') onToken?.(payload.text);
                else if (payload.type === 'done') onDone?.(payload.message_id);
            }
        }
    }
}
