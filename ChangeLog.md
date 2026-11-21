# Changelog

## v1.4.1

Changed video link

- Film linked on YouTube, with PilotBundle's page beside it (21/11/2026)

## v1.4.0

Where the press kit PDF lands, in the README

- Folder of the press kit PDF and the optional `pilot-open://` handler said in the README (21/11/2026)

## v1.3.0

CI on actions/checkout v5

- CI on `actions/checkout@v5`, running on Node 24 as the bundles' (21/11/2026)

## v1.2.0

CI started by hand too

- CI started by hand with `gh workflow run CI` (21/11/2026)

## v1.1.0

Link to the film in the README

- Link to the 90-second film under the README's poster (21/11/2026)
- php-cs-fixer cache no longer versioned (21/11/2026)
- ESLint and Stylelint configurations, those of the c975L bundles (21/11/2026)

## v1.0.0

Pilot MCP demo for the Symfony AI & MCP Hackathon

- One-command demo: `composer demo` checks the requirements, installs, builds the demo on SQLite, serves it and starts the runner (21/11/2026)
- `app:demo:setup` building the editor account, the "Book releases" space with its card, a runner machine and a one-year agent token (21/11/2026)
- The card's first step handed to an AI agent working through the site's MCP endpoint: Claude Code, or the Symfony AI agent `app:agent:run` (21/11/2026)
- `app:agent:print` showing each MCP call of Claude Code as the Symfony AI agent shows its own (21/11/2026)
- Press kit PDF built by a plain machine action, `app:press-kit` (21/11/2026)
- Social posts written for a demo network that sends nothing (21/11/2026)
- Tests of the setup, the MCP endpoint, the agent and the runner scripts, run by the CI with a fresh `composer demo` (21/11/2026)
