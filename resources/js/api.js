// Session auth means every write needs the CSRF token. Laravel rotates the token
// whenever the session is regenerated (login, logout), so responses that cause a
// rotation hand back the new one and we keep it here.
let csrfToken = document.querySelector('meta[name="csrf-token"]')?.content ?? '';

function jsonHeaders() {
    return {
        'Content-Type': 'application/json',
        Accept: 'application/json',
        'X-CSRF-TOKEN': csrfToken,
    };
}

function uploadHeaders() {
    return { Accept: 'application/json', 'X-CSRF-TOKEN': csrfToken };
}

async function handle(response) {
    if (!response.ok) {
        const body = await response.json().catch(() => ({}));
        const firstError = body.errors ? Object.values(body.errors)[0]?.[0] : null;
        const error = new Error(firstError || body.message || `Request failed (${response.status})`);
        error.status = response.status;
        throw error;
    }
    if (response.status === 204) return null;

    const body = await response.json();
    if (body?.csrf_token) csrfToken = body.csrf_token;

    return body;
}

export function getUser() {
    return fetch('/api/user', { headers: jsonHeaders() })
        .then(handle)
        .then((body) => ({ user: body.user, guestMode: Boolean(body.guest_mode) }));
}

export function register(payload) {
    return fetch('/api/register', { method: 'POST', headers: jsonHeaders(), body: JSON.stringify(payload) })
        .then(handle)
        .then((body) => body.user);
}

export function login(payload) {
    return fetch('/api/login', { method: 'POST', headers: jsonHeaders(), body: JSON.stringify(payload) })
        .then(handle)
        .then((body) => body.user);
}

export function logout() {
    return fetch('/api/logout', { method: 'POST', headers: jsonHeaders() }).then(handle);
}

export function listDocuments() {
    return fetch('/api/documents', { headers: jsonHeaders() }).then(handle);
}

export function uploadDocument(file) {
    const formData = new FormData();
    formData.append('file', file);
    return fetch('/api/documents', { method: 'POST', body: formData, headers: uploadHeaders() }).then(handle);
}

export function deleteDocument(id) {
    return fetch(`/api/documents/${id}`, { method: 'DELETE', headers: jsonHeaders() }).then(handle);
}

export function createConversation() {
    return fetch('/api/conversations', { method: 'POST', headers: jsonHeaders(), body: JSON.stringify({}) }).then(handle);
}

export function getConversation(id) {
    return fetch(`/api/conversations/${id}`, { headers: jsonHeaders() }).then(handle);
}

export function deleteConversation(id) {
    return fetch(`/api/conversations/${id}`, { method: 'DELETE', headers: jsonHeaders() }).then(handle);
}

export async function streamMessage(conversationId, content, { onSources, onToken, onDone, onError }) {
    const response = await fetch(`/api/conversations/${conversationId}/messages`, {
        method: 'POST',
        headers: jsonHeaders(),
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
