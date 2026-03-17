# FreeScout AI Assist

Unified AI assistant panel for the FreeScout reply form. Supports Claude (Anthropic) and GPT (OpenAI) with a single interface.

## Features

- Compose tab: write instructions, AI drafts the reply
- Templates tab: apply saved prompt templates with optional extra instructions
- Polish tab: improve grammar, tone, and clarity of your existing draft
- Provider toggle: switch between Claude and GPT per request
- Model selector: choose model per request
- Cost tracking: per-call cost and token counts shown inline
- Request logs: full request/response log at Manage → AI Logs with filters, copy, and expand
- Conversation thread panel: AI requests panel below email threads for that conversation
- Balance display: GPT live balance + Claude manual balance shown on conversation page

## Requirements

- FreeScout v1.8+
- PHP 8.0+
- HexawebClaudeAssist and/or HexawebGPTAssist module (for API keys)

## Installation

1. Upload the `HexawebAIAssist` folder to `Modules/`
2. Go to Manage → Modules and activate it
3. Configure Claude and/or GPT API keys in their respective settings pages

## Usage

Open any conversation, click Reply. The AI Assistant panel appears below the reply editor.
