# RAG Chatbot

A Laravel + React chatbot that answers questions grounded in your own documents. Upload a PDF, DOCX, TXT, or MD file, then ask a question — the answer streams back token by token, built only from text retrieved out of your uploads, with the exact source chunks and their relevance scores shown alongside it.

This is **R**etrieval-**A**ugmented **G**eneration: the model is not answering from its training knowledge, it is answering from text pulled out of your documents at question time.

## Features

- **Runs with or without login** — guest mode (on by default) skips accounts entirely and signs every visitor into one shared workspace; flip it off to restore per-user registration/sign-in with session cookies, each account's documents and conversations scoped and unreachable by anyone else
- **Multi-format ingestion** — PDF, DOCX, TXT, MD are parsed, chunked, and embedded on upload
- **Streamed answers** — tokens arrive over Server-Sent Events as the model generates them
- **Cited sources** — every answer lists the chunks it used, with filename, chunk index, and cosine similarity
- **Conversation memory** — the last 10 turns are replayed to the model, and a follow-up like *"and in 2024?"* is expanded with the previous question before retrieval so vector search still has something to match
- **Persistent history** — conversations survive a page refresh

## Architecture at a glance

| Layer | Technology |
|---|---|
| Backend | Laravel 13 (PHP 8.3) |
| Frontend | React 19 + Tailwind CSS v4, bundled by Vite 8 |
| Database | PostgreSQL — documents, chunks, conversations, messages, sources |
| Vector store | [pgvector](https://github.com/pgvector/pgvector) — `vector(768)` column with an HNSW cosine index, in the same database |
| LLM (chat) | Google Gemini (`gemini-3.5-flash-lite`, streaming) |
| LLM (embeddings) | Google Gemini (`gemini-embedding-001`, 768 dimensions) |

```
Upload → Extract text → Chunk (800 chars / 100 overlap) → Embed → Store rows + vectors (Postgres)
Ask    → Embed question → Nearest chunks by cosine distance (pgvector, top 5) → Stream answer (Gemini) → Persist answer + sources
```

Keeping the vectors in Postgres means retrieval is one SQL query that joins chunks to documents, so owner filtering (`d.user_id = ?`) and readiness filtering (`d.status = 'ready'`) happen inside the same index scan — no external vector service to sync, pay for, or clean up after a delete.

### Where things live

| Path | Responsibility |
|---|---|
| [app/Services/TextExtractor.php](app/Services/TextExtractor.php) | PDF / DOCX / plain-text extraction |
| [app/Services/TextChunker.php](app/Services/TextChunker.php) | Overlapping character-window chunking |
| [app/Services/DocumentProcessingService.php](app/Services/DocumentProcessingService.php) | Extract → chunk → embed → mark `ready` / `failed` |
| [app/Services/GeminiClient.php](app/Services/GeminiClient.php) | Gemini embeddings + SSE chat streaming |
| [app/Services/RagChatService.php](app/Services/RagChatService.php) | Retrieval, grounding prompt, history, source persistence |
| [app/Http/Controllers/](app/Http/Controllers/) | Auth, documents, conversations, streamed messages |
| [app/Http/Middleware/AuthenticateAsGuest.php](app/Http/Middleware/AuthenticateAsGuest.php) | Guest mode — auto-signs visitors into the shared guest account |
| [resources/js/components/](resources/js/components/) | Login, sidebar, chat, source viewer |

## API

All routes are session-authenticated on the same origin and run through Laravel's `web` middleware group, so writes require the CSRF token. With guest mode on, `/api/user` is never `null` — the guest middleware signs the session in before any controller runs.

| Method | Route | Purpose |
|---|---|---|
| `POST` | `/api/register` · `/api/login` | Create a session (rate limited, 10/min) — no-ops the SPA never calls while guest mode is on |
| `GET` | `/api/user` | Current user (`id`, `name`, `email`), plus `guest_mode`; `user` is `null` only when guest mode is off and nobody is signed in |
| `POST` | `/api/logout` | Destroy the session |
| `GET` `POST` `DELETE` | `/api/documents[/{id}]` | List, upload, remove documents |
| `POST` `GET` `DELETE` | `/api/conversations[/{id}]` | Start, load, remove a conversation |
| `POST` | `/api/conversations/{id}/messages` | Ask a question; responds `text/event-stream` with `sources`, `token`, and `done` events |

## Prerequisites

- PHP 8.3+ with Composer
- Node.js 20+ with npm
- PostgreSQL 14+ with the [pgvector](https://github.com/pgvector/pgvector#installation) extension available
- A [Google AI Studio](https://aistudio.google.com/) API key (free tier is enough)

## Setup

1. **Clone and install dependencies**

   ```bash
   git clone <your-repository-url> rag-chatbot
   cd rag-chatbot
   composer install
   npm install
   ```

2. **Create the database**

   The `vector` extension is enabled by the migrations, so the app's database role needs permission to create it (superuser, or a role granted `CREATE` on the database with pgvector installed).

   ```bash
   sudo -u postgres createuser rag_chatbot --pwprompt
   sudo -u postgres createdb rag_chatbot --owner rag_chatbot
   sudo -u postgres createdb rag_chatbot_test --owner rag_chatbot   # for the test suite
   ```

3. **Configure the environment**

   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

   Then set these in `.env`:

   | Variable | Value |
   |---|---|
   | `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Your Postgres database and role |
   | `GEMINI_API_KEY` | [aistudio.google.com](https://aistudio.google.com/) → Get API key |
   | `GEMINI_CHAT_MODEL` | Any current Gemini model supporting `generateContent`, e.g. `gemini-3.5-flash-lite` |
   | `GEMINI_EMBEDDING_MODEL` | e.g. `gemini-embedding-001` |
   | `RAG_CHUNK_SIZE` | Characters per chunk (default `800`) |
   | `RAG_CHUNK_OVERLAP` | Characters shared between neighbouring chunks (default `100`) |
   | `RAG_TOP_K` | Chunks retrieved per question (default `5`) |
   | `AUTH_GUEST_MODE` | `false` by default in `.env.example`; set `true` to skip login entirely — see [Guest mode vs. accounts](#guest-mode-vs-accounts) |

   > **Gemini model availability changes over time.** If a model returns a 404 "model not found", list what your key can actually reach and pick a current one:
   > ```bash
   > curl -s "https://generativelanguage.googleapis.com/v1beta/models?key=$GEMINI_API_KEY" | grep '"name"'
   > ```

   Embeddings are fixed at **768 dimensions** in `config/gemini.php` to match the `vector(768)` column. Changing one without the other requires a migration, since the column width and its HNSW index are set at creation.

4. **Migrate and run**

   ```bash
   php artisan migrate
   composer dev
   ```

   `composer dev` runs the Laravel server, queue listener, log tailer, and Vite together. Visit **http://localhost:8000** — with `AUTH_GUEST_MODE=true` you land straight in the chat UI; otherwise register an account first.

   (Or run the pieces separately: `php artisan serve` + `npm run dev`.)

## Usage

1. Register or sign in — skipped entirely when guest mode is on.
2. Click the file picker in the sidebar and choose a PDF, DOCX, TXT, or MD file (20 MB max). Upload is processed inline, so a large PDF holds the request until every chunk is embedded; the badge flips to **ready** when it is done, or **failed** if extraction produced nothing.
3. Ask a question. The answer streams in, and **Show sources** under it reveals the exact chunks, ranked by similarity.
4. Delete a document from the sidebar to drop it and its chunks (cascade delete removes the vectors with them).

If the retrieved chunks do not cover the question, the model is instructed to say the uploaded documents do not contain enough information rather than fall back on general knowledge.

## Guest mode vs. accounts

`AUTH_GUEST_MODE` switches the whole app between two modes without touching any code:

| | Guest mode (`true`) | Accounts (`false`) |
|---|---|---|
| Login screen | Skipped — every visitor is signed in automatically | Shown; register or sign in required |
| Who owns uploads/chats | One shared account (`AUTH_GUEST_EMAIL`, default `guest@rag-chatbot.local`) | Each registered user, isolated from every other |
| Header | No email / sign-out shown | Email + **Sign out** shown |
| `/api/register`, `/api/login`, `/api/logout` | Still routable, unused by the SPA | Used normally |

Mechanically: [app/Http/Middleware/AuthenticateAsGuest.php](app/Http/Middleware/AuthenticateAsGuest.php) runs in the `web` middleware group and, when the flag is on and the visitor isn't already authenticated, logs the session into a `firstOrCreate` guest `User` row (random unguessable password — the account is only ever reached through this middleware, never through `/api/login`). Ownership scoping in every controller and in `RagChatService::retrieveRelevantChunks()` is untouched — it still filters by `$request->user()`/`$conversation->user_id` — so guest mode just supplies that user for you instead of removing the checks.

Because it's every visitor sharing one account, guest mode is meant for a local demo or a single-user deployment, not a multi-tenant one — anyone who can reach the app can see and delete the guest account's documents and conversations. Set `AUTH_GUEST_MODE=false` any time to get real per-user isolation back; existing guest-owned rows stay attached to the guest account and untouched.

## Testing

The suite runs against `rag_chatbot_test` and stubs the Gemini HTTP calls, so no API key or quota is consumed. Credentials come from `phpunit.xml` — adjust them there if your local role differs. `phpunit.xml` also pins `AUTH_GUEST_MODE=false`, so the suite exercises real login regardless of what your local `.env` has it set to.

```bash
composer test
```

Coverage spans the ingestion pipeline, the full streamed chat flow with source persistence, conversation memory, auth, cross-account isolation (a second user can neither see another user's documents and conversations nor retrieve their chunks), and guest mode itself ([tests/Feature/GuestModeTest.php](tests/Feature/GuestModeTest.php) — flag off leaves visitors unauthenticated, flag on signs them in, reuses one account across requests, and reaches documents/conversations with no login step).

## Configuration notes

- **Chunk size vs. top-k** — smaller chunks sharpen retrieval but need a higher `RAG_TOP_K` to keep enough context in the prompt; larger chunks do the reverse. `800 / 100 / 5` is a reasonable starting point for prose documents.
- **Synchronous ingestion** — uploads embed in-request. `QUEUE_CONNECTION=database` is already configured, so moving `DocumentProcessingService::process()` into a queued job is the natural next step for large files.
- **Streaming behind a proxy** — the response sets `X-Accel-Buffering: no`; if answers arrive all at once instead of token by token, output buffering is being re-introduced somewhere in front of PHP.
- **Guest middleware ordering** — `AuthenticateAsGuest` is registered both in the `web` middleware group and, in [bootstrap/app.php](bootstrap/app.php), prepended in the middleware *priority* list ahead of `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests`. Laravel resorts middleware by that priority list regardless of registration order, so without the prepend the `auth` middleware on `/api/documents` etc. would run first and reject every guest request with a 401.

## License

Open-sourced under the [MIT license](https://opensource.org/licenses/MIT).
