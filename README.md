# FreeScout AI Assist

Unified AI assistant panel for the FreeScout reply form, powered by **OpenRouter** — one key, every model (Google, OpenAI, Anthropic, and more) through a single endpoint.

## Features

- Compose tab: write instructions, AI drafts the reply
- Templates tab: apply saved prompt templates with optional extra instructions
- Polish tab: improve grammar, tone, and clarity of your existing draft
- Dynamic model selector: populated live from OpenRouter's model list, with real per-1M input/output pricing shown next to each model
- Real cost tracking: per-call cost comes straight from OpenRouter's `usage.cost`, not an estimate; token counts shown inline
- Request logs: full request/response log at Manage → AI Logs with filters, copy, and expand
- Conversation thread panel: AI requests panel below email threads for that conversation, with session cost
- Own settings page: encrypted API key, default model, max tokens, and system prompt — no companion modules required

## Requirements

- FreeScout v1.8+
- PHP 8.0+
- An OpenRouter API key (`sk-or-...`)

## Installation

1. Upload the `HexawebAIAssist` folder to `Modules/`
2. Go to Manage → Modules and activate it
3. Go to Manage → AI Settings, paste your OpenRouter API key, pick a default model (default: `google/gemini-2.5-flash`)

## Usage

Open any conversation, click Reply. The AI Assistant panel appears below the reply editor. Pick a model from the selector (prices shown per 1M tokens) and use Compose, Templates, or Polish.

## What changed from the original

This is a fork of [`mikeyperes/freescout-ai-assist`](https://github.com/mikeyperes/freescout-ai-assist) (v1.0.0), which shipped a dual Claude/GPT panel. It was rebuilt around **OpenRouter as the single provider** (v2.0.0). Since OpenRouter is wire-compatible with the OpenAI chat API, the core switch is essentially one endpoint — but the rest was reworked to do it properly. The module name/alias (`HexawebAIAssist` / `hexawebaiassist`), routes, log/template schema, and the Compose/Templates/Polish flow are all unchanged, so it's a drop-in upgrade.

**Removed**

- The Claude/GPT provider toggle and the two hardcoded model lists.
- The dependency on the companion modules `HexawebClaudeAssist` / `HexawebGPTAssist` for API keys.
- The hardcoded per-model pricing table used to *estimate* cost.
- The native Anthropic (`callClaude`) and OpenAI (`callGpt`) code paths and the per-provider balance widgets.
- `file_get_contents` + `stream_context_create` for HTTP calls.

**Added / changed**

- Single OpenRouter call (`callOpenRouter`) to `https://openrouter.ai/api/v1/chat/completions` over **Guzzle**, with `usage: { include: true }`.
- **Real cost** per request, read from OpenRouter's `usage.cost` (falls back to cache-derived pricing only if absent).
- **Dynamic model selector** populated from `GET /models` (cached 24h, with a manual refresh) showing live per-1M input/output prices; a short built-in fallback list keeps the selector usable before the first fetch.
- An **own settings page** (Manage → AI Settings): encrypted OpenRouter key, default model, max tokens, and system prompt. No companion modules required.
- Options moved to the `openrouter_assist.*` prefix; default model `google/gemini-2.5-flash`.

> Note: dropping the native Claude/GPT providers doesn't lose access to any model — OpenRouter already serves Anthropic, OpenAI, Google, and others through the same key.
