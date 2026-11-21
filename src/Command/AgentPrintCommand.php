<?php

namespace App\Command;

use App\Agent\PrintingToolbox;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Style\SymfonyStyle;

// Prints Claude Code's stream-json as the Symfony AI agent prints its run: each MCP call and its result as they happen, then the answer as written
#[AsCommand(name: 'app:agent:print', description: 'Prints the stream-json output of Claude Code, one line per tool call and result, then its answer')]
class AgentPrintCommand
{
    // The prefix Claude Code gives the tools of the demo's MCP server
    private const string PREFIX = 'mcp__demo__';

    // Reads the stream line by line, so each call shows while the agent still works; fails when the agent ended in error
    public function __invoke(SymfonyStyle $io, #[Argument('The stream to read, standard input when left out')] string $source = 'php://stdin'): int
    {
        $stream = @fopen($source, 'r');
        if (false === $stream) {
            $io->error(\sprintf('Cannot read %s.', $source));

            return Command::FAILURE;
        }

        $failed = false;
        while (false !== $line = fgets($stream)) {
            $event = json_decode($line, true);
            if (\is_array($event)) {
                $failed = $this->print($io, $event) || $failed;
            }
        }
        fclose($stream);

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }

    // One event of the stream: the session start, the calls the model makes, their results, the final answer; true when that answer is an error
    /** @param array<mixed> $event */
    private function print(SymfonyStyle $io, array $event): bool
    {
        $type = $event['type'] ?? null;
        if ('system' === $type && 'init' === ($event['subtype'] ?? null)) {
            $tools = array_filter((array) ($event['tools'] ?? []), static fn (mixed $tool): bool => \is_string($tool) && str_starts_with($tool, self::PREFIX));
            $io->writeln(\sprintf('<info>Claude Code</info> · %s · %d MCP tools', OutputFormatter::escape((string) ($event['model'] ?? '')), \count($tools)));
        } elseif ('assistant' === $type || 'user' === $type) {
            foreach ((array) ($event['message']['content'] ?? []) as $block) {
                $this->printBlock($io, (array) $block);
            }
        } elseif ('result' === $type) {
            // The answer as written, its FEEDBACK lines kept whole for the runner
            $io->writeln(OutputFormatter::escape((string) ($event['result'] ?? '')));

            return true === ($event['is_error'] ?? false);
        }

        return false;
    }

    // A call the model makes, or the result it gets back; its text, already in the answer, is left out
    /** @param array<mixed> $block */
    private function printBlock(SymfonyStyle $io, array $block): void
    {
        if ('tool_use' === ($block['type'] ?? null)) {
            $name = (string) ($block['name'] ?? '');
            $io->writeln(PrintingToolbox::callLine(str_starts_with($name, self::PREFIX) ? substr($name, \strlen(self::PREFIX)) : $name, (array) ($block['input'] ?? [])));
        } elseif ('tool_result' === ($block['type'] ?? null)) {
            $content = $block['content'] ?? '';
            $io->writeln(PrintingToolbox::resultLine(\is_array($content) ? implode(' ', array_column($content, 'text')) : $content));
        }
    }
}
