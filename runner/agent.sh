#!/bin/bash
# Hands the card the runner gives (agent.sh <workdir> [comment], the card id read from the workdir's basename card-<id>, the comment holding its numbered comments handed back) to an AI agent, the site's MCP endpoint as its only tools: Claude Code by default, any other agent through AGENT_COMMAND
set -euo pipefail

# The endpoint and the agent token app:demo:setup wrote beside this script
Here=$(cd "$(dirname "$0")" && pwd)
if [[ ! -f $Here/agent.env ]]; then
    echo "runner/agent.env is missing: run php bin/console app:demo:setup first" >&2
    exit 1
fi
set -a
source "$Here/agent.env"
set +a

# The card this run is about, and the comments handed back with it
Card=$(basename "$1")
export CARD_ID=${Card#card-}
export AGENT_COMMENT=${2:-}

# What the agent is asked, the same whichever agent runs it: the card's steps on a first run, the numbered comments when they are handed back
Rules="Write everything in English. Never publish, approve or delete anything: the posts stay drafts for a human to approve in the SocialBundle calendar."
if [[ -z $AGENT_COMMENT ]]; then
    export AGENT_PROMPT="You work for the user on card $CARD_ID of the project board, reached through the MCP server \"demo\" (the site's own endpoint; your client may prefix its tool names, demo_pilot_card or mcp__demo__pilot_card for pilot_card). Read the card with pilot_card. From the card's note: draft the book in the catalog, unpublished (book_create); make its photo gallery (gallery_create); write the release posts as drafts (social_create). Tick with pilot_tick_step the steps \"Make its photo gallery\" and \"Write the release posts\" once done. Leave alone the step \"Draft the book in the catalog\" (the runner ticks it), the human steps (\"Text proofread and validated\", \"Posts reviewed and approved in the SocialBundle calendar\") and the press kit step (a machine action of its own). Then leave one short comment on the card with pilot_comment, saying what you did and asking the one thing you need before release. $Rules"
else
    export AGENT_PROMPT="You work for the user on card $CARD_ID of the project board, reached through the MCP server \"demo\" (the site's own endpoint; your client may prefix its tool names, demo_pilot_card or mcp__demo__pilot_card for pilot_card). Read the card with pilot_card, then find the book, the gallery and the posts you made for it (book_list, gallery_list, social_list). The user answered with these numbered comments, do only what they ask, editing what exists (book_edit, gallery_edit, social_edit) rather than creating it again:
$AGENT_COMMENT
Add no comment to the card. For each numbered comment you cannot do, end your answer with a line of its own, exactly: FEEDBACK <number> <short reason>. Print no FEEDBACK line for a comment you did. $Rules"
fi

# Another agent, run from the project root: AGENT_COMMAND gets the prompt as its last argument, MCP_URL, MCP_TOKEN, CARD_ID, AGENT_COMMENT and AGENT_PROMPT in its environment
if [[ -n ${AGENT_COMMAND:-} ]]; then
    (cd "$Here/.." && bash -c "$AGENT_COMMAND \"\$AGENT_PROMPT\"")
else
    # Claude Code, given the demo server alone through a config readable by its owner only, removed when it ends
    McpConfig=$(mktemp)
    chmod 600 "$McpConfig"
    trap 'rm -f "$McpConfig"' EXIT
    printf '{"mcpServers":{"demo":{"type":"http","url":"%s","headers":{"Authorization":"Bearer %s"}}}}\n' "$MCP_URL" "$MCP_TOKEN" > "$McpConfig"
    # Its stream printed by app:agent:print, each MCP call as it happens, like the Symfony AI agent
    claude -p "$AGENT_PROMPT" --mcp-config "$McpConfig" --strict-mcp-config --allowedTools "mcp__demo" --output-format stream-json --verbose | php "$Here/../bin/console" app:agent:print
fi
echo "✓ Done · the card is back to you"

# Links left under the step once the agent is done, the same whatever it wrote: the drafts it made, and where a human approves the posts
Site=${PILOT_URL:-${MCP_URL%/api/mcp}}
echo "LINK $Site/management/book Book drafts"
echo "LINK $Site/management/social-calendar Posts to approve"
