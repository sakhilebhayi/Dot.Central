# AgentKnowledge — Document Grounding Design Spec

## Context

`wiki.md` §4 has named this gap since the wiki's earliest version: "Knowledge-base upload / document grounding per agent (no `AgentKnowledge` model exists yet despite being listed as a domain model)." §7's roadmap lists it as open. This session already built the AI-agent domain's previously-missing chat UI (Agent/Conversation/Message, `AgentController`/`ConversationController`, `AgentChat` Livewire component) and streaming responses (`AgentChatService::chat()` now streams from Anthropic's real SSE API, returns `array{text: ?string, complete: bool}`).

Checked directly before proposing anything, not assumed:

- **No embedding/vector infrastructure exists anywhere.** `composer.json` has no embedding/vector-search package; the shared PostgreSQL 16 ecosystem instance has no `pgvector` extension installed (confirmed via `SELECT * FROM pg_extension WHERE extname = 'vector'`, empty result); Anthropic's Messages API has no native embeddings endpoint — real semantic search would need a third-party provider (Voyage AI, OpenAI, etc.) as a new external dependency and a new extension on a database shared with every other Dot platform, not something to add unilaterally for one platform's v1.
- **This app runs real PostgreSQL in dev/prod but SQLite in tests** (`phpunit.xml` forces `DB_CONNECTION=sqlite`, `DB_DATABASE=:memory:`). Postgres' richer full-text search (`tsvector`/`to_tsquery`, stemming, ranking) has no SQLite equivalent Eloquent can transparently bridge — using it would mean tests exercising different logic than what actually runs in production, a real gap between tested and deployed behavior.
- **No PDF-parsing package is installed.** Two real options exist: `smalot/pdfparser` (pure PHP, no system dependency) or `spatie/pdf-to-text` (wraps the `pdftotext` CLI binary from `poppler-utils`, an OS-level dependency not guaranteed present in every environment this app runs in).

These findings shaped every decision below — this is a genuinely scoped-down v1 (keyword search, not semantic; whole-document retrieval, not chunked; no new database extension), not a compromise made silently.

## Goal

Let a team upload reference documents (pasted text, `.txt`/`.md`, or PDF) and assign them to specific agents, so those agents' replies can be grounded in real, retrieved content — using only infrastructure that already exists or is trivially portable, with zero behavior change for agents that don't use it.

## Design

### 1. Data model

New `AgentKnowledge` model: `team_id`, `title`, `content` (extracted plain text, capped at 50,000 characters — a bounded, documented cost to every request that retrieves it, not an unbounded one), `source_type` (`pasted`/`text_file`/`pdf`), `original_filename` (nullable, only set for file uploads). Team-scoped via `HasTeamScope`, exactly matching `Agent`'s existing pattern (both are team-owned, shared-within-team resources).

New `agent_agent_knowledge` pivot table for the many-to-many assignment to `Agent` — same naming convention as the existing `agent_agent_skill` table (`AgentSkill`'s own assignment pattern), same shape: `agent_id`, `agent_knowledge_id`.

PDF extraction uses `smalot/pdfparser` (pure PHP, no system binary) rather than `spatie/pdf-to-text` (wraps `pdftotext`, an OS-level dependency) — matching this session's established portability concern for any new dependency.

### 2. Retrieval & injection

Before calling Anthropic, `AgentChatService::chat()` gains a retrieval step: naive-tokenize the user's message into words (split on whitespace, lowercase, filter very short words), query the agent's assigned `AgentKnowledge` rows with `WHERE LOWER(content) LIKE '%word%' OR LOWER(title) LIKE '%word%'` for each token, rank by count of distinct matched tokens (a simple, honest relevance proxy — no stemming, no real scoring), take the top 3. Matches are appended to `$agent->system_prompt` as a synthesized block:

```
{original system_prompt}

Relevant reference material:

---
{title}
{content}
---
{title}
{content}
---
```

before the request is built — `AgentChatService`'s existing request shape (`{model, max_tokens, system, messages}`) is unchanged; `system` is just computed per-request instead of being the raw column value passed straight through. No change to the streaming logic added in the prior session pass.

If an agent has no assigned `AgentKnowledge` rows, or nothing matches the message, `system_prompt` is used exactly as before — byte-identical requests for every agent not using this feature.

### 3. UI & screens

Unlike `AgentSkill` (a peripheral tag catalog, deliberately left without management UI in the earlier chat-UI work — only *assignment* was in scope), documents are the actual point of this feature, so they get real CRUD. New `AgentKnowledgeController`: team-scoped `index`/`create`/`store`/`destroy` — no `update`. An outdated or wrong document gets deleted and re-uploaded, not edited in place; this keeps "what does the agent actually know" auditable (a document's content never silently changes under an agent that's already using it) and avoids designing an edit form for three different input modes (paste/text-file/PDF) that would need to somehow reconcile with `source_type`/`original_filename`. Matches `AgentController`'s established plain-controller convention.

`store` accepts either pasted text (a textarea) or an uploaded file — `.txt`/`.md` read directly, `.pdf` parsed via `smalot/pdfparser`. The agent create/edit forms (existing, from the chat-UI build) gain a second multi-select checkbox list — documents — styled identically to the existing skills multi-select, assigning existing `AgentKnowledge` rows to that agent.

### 4. Error handling

- **Upload validation:** 5MB file size cap; a PDF that parses to empty or whitespace-only text (a scanned/image-only PDF with no real text layer) is rejected with a clear message, never silently stored as an empty, useless document; unsupported file types rejected by Laravel's standard file-type validation.
- **Retrieval failure is never a chat failure.** If the knowledge search throws for any reason, catch it, log it, and proceed with the unmodified `system_prompt` — a broken search must never block someone from chatting with an agent that happens to have documents assigned. This is a deliberate fail-open design for a feature that's meant to enhance replies, not gate them.

### 5. Testing

- `AgentKnowledgeFactory` (new — matches every other model's `HasFactory` convention established this session).
- `AgentKnowledgeControllerTest`: upload pasted text, upload a small real PDF fixture (checked into `tests/Fixtures/` or similar — a real file, not a mock, so the actual `smalot/pdfparser` code path is exercised), reject an oversized file, reject a text-less PDF, reject an unsupported file type, team-scoping/cross-team 404 matching `HasTeamScope`'s already-proven pattern (`HasTeamScopeTest`).
- `AgentChatServiceTest` addition: an agent with a matching assigned `AgentKnowledge` document produces a request (asserted via `Http::fake()`) whose `system` field contains the expected excerpt; an agent with no assigned documents produces a request identical to today's — proving zero behavior change for the common case.

## Explicitly out of scope

- Real semantic/vector search — see Context; this is a deliberate v1 scoping decision, not a gap to silently fix later without the infrastructure questions (pgvector on a shared database, a new embeddings provider) being decided first.
- Document chunking — whole documents, capped at 50,000 characters, are the retrievable unit. Long documents get truncated at storage time (with the cap enforced and visible, not a silent mid-request truncation), not split into paragraphs/sections.
- Editing an uploaded document's content — delete and re-upload instead (see §3).
- Any change to `AgentChatService`'s streaming mechanism, return shape, or error-handling outcomes (immediate failure / mid-stream interruption / normal completion) built in the prior session pass — this work only adds a retrieval step before the existing request is built.
