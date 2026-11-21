# Changelog

## v1.0.0

Pilot MCP demo for the Symfony AI & MCP Hackathon

- One-command demo: `composer demo` checks the requirements, installs, builds the demo on SQLite, serves it and starts the runner (21/11/2026)
- `app:demo:setup` building the editor account, the "Book releases" space with its card, a runner machine and a one-year agent token (21/11/2026)
- The card's first step handed to an AI agent working through the site's MCP endpoint: Claude Code, or the Symfony AI agent `app:agent:run` (21/11/2026)
- `app:agent:print` showing each MCP call of Claude Code as the Symfony AI agent shows its own (21/11/2026)
- Press kit PDF built by a plain machine action, `app:press-kit` (21/11/2026)
- Social posts written for a demo network that sends nothing (21/11/2026)
- Tests of the setup, the MCP endpoint, the agent and the runner scripts, run by the CI with a fresh `composer demo` (21/11/2026)
