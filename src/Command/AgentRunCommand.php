<?php

namespace App\Command;

use App\Agent\PrintingToolbox;
use Mcp\Client\Client;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\TransportInterface;
use Symfony\AI\Agent\Agent;
use Symfony\AI\Agent\Bridge\Mcp\ClientToolset;
use Symfony\AI\Agent\Bridge\Mcp\McpToolbox;
use Symfony\AI\Agent\Toolbox\FaultTolerantToolbox;
use Symfony\AI\Platform\Bridge\Anthropic\Factory as AnthropicFactory;
use Symfony\AI\Platform\Bridge\Generic\Factory as GenericFactory;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

// An AI agent written with Symfony AI: its model on Anthropic or any OpenAI-compatible API, its tools drawn from the site's MCP endpoint through the official PHP MCP SDK client
#[AsCommand(name: 'app:agent:run', description: 'Runs the Symfony AI agent on a prompt, with the site\'s MCP endpoint as its tools')]
class AgentRunCommand
{
    // What the agent is told about itself, whatever the card
    private const string SYSTEM = 'You are an AI agent working for the user of a Symfony site through its MCP tools only. Do what the prompt asks, one tool call at a time when a call depends on the one before, then answer with a short summary.';

    // Reads the model and the endpoint from the environment agent.sh exports, then lets the agent loop until it answers
    public function __invoke(SymfonyStyle $io, #[Argument('What the agent is asked, AGENT_PROMPT when left out')] ?string $prompt = null): int
    {
        $prompt ??= self::env('AGENT_PROMPT');
        $url = self::env('MCP_URL');
        $token = self::env('MCP_TOKEN');
        if (null === $prompt || null === $url || null === $token) {
            $io->error('AGENT_PROMPT (or the argument), MCP_URL and MCP_TOKEN are needed: runner/agent.sh exports them.');

            return Command::FAILURE;
        }

        [$platform, $model] = $this->platform();
        if (null === $platform) {
            $io->error('No model configured: set ANTHROPIC_API_KEY, or GENERIC_BASE_URL, GENERIC_API_KEY and GENERIC_MODEL for an OpenAI-compatible API, in .env.local.');

            return Command::FAILURE;
        }

        // The site's endpoint as a toolset, its tools named after the server "demo" (demo_pilot_card)
        $toolset = new ClientToolset('demo', Client::builder()->build(), $this->transport($url, $token));
        // A call the model gets wrong (a tool it invents, an argument missing) goes back to it as an error to correct, rather than ending the run, each call printed as it happens
        $agent = new Agent($platform, $model, toolbox: new PrintingToolbox(new FaultTolerantToolbox(new McpToolbox($toolset)), $io), maxToolCalls: 30);

        $io->writeln(sprintf('<info>Symfony AI agent</info> · %s · %d MCP tools', $model, \count($toolset->getTools())));
        $result = $agent->call(new MessageBag(Message::forSystem(self::SYSTEM), Message::ofUser($prompt)));
        // The answer is the model's text, shown as written rather than read as console styles
        $io->writeln(OutputFormatter::escape($result->asText()));

        return Command::SUCCESS;
    }

    // Anthropic when its key is set, else an OpenAI-compatible API, else nothing; protected for the tests to script a model
    /** @return array{0: ?PlatformInterface, 1: string} */
    protected function platform(): array
    {
        if (null !== $key = self::env('ANTHROPIC_API_KEY')) {
            return [AnthropicFactory::createPlatform($key), self::env('ANTHROPIC_MODEL') ?? 'claude-sonnet-5-5'];
        }
        $baseUrl = self::env('GENERIC_BASE_URL');
        $model = self::env('GENERIC_MODEL');
        if (null !== $baseUrl && null !== $model) {
            return [GenericFactory::createPlatform($baseUrl, self::env('GENERIC_API_KEY'), completionsPath: self::env('GENERIC_COMPLETIONS_PATH') ?? '/v1/chat/completions'), $model];
        }

        return [null, ''];
    }

    // The site's endpoint over Streamable HTTP, the agent token as a Bearer; protected for the tests to reach the kernel without a server
    protected function transport(string $url, string $token): TransportInterface
    {
        return new HttpTransport($url, ['Authorization' => 'Bearer ' . $token]);
    }

    // An environment value, null when unset or empty
    private static function env(string $name): ?string
    {
        $value = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

        return \is_string($value) && '' !== trim($value) ? $value : null;
    }
}
