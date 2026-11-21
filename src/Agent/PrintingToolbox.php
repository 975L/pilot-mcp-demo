<?php

namespace App\Agent;

use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

// Prints each tool call as it happens, then its result, as a terminal agent shows them
final class PrintingToolbox implements ToolboxInterface
{
    // The longest an argument or a result is shown, cut beyond
    private const int MAX = 160;

    public function __construct(
        private readonly ToolboxInterface $innerToolbox,
        private readonly OutputInterface $output,
    ) {
    }

    // The tools of the toolbox it wraps
    public function getTools(): array
    {
        return $this->innerToolbox->getTools();
    }

    // The call printed before it runs, its result once it answers, both escaped so a tag they hold is shown and not read as a console style
    public function execute(ToolCall $toolCall): ToolResult
    {
        $this->output->writeln(self::callLine($toolCall->getName(), $toolCall->getArguments()));
        $result = $this->innerToolbox->execute($toolCall);
        $this->output->writeln(self::resultLine($result->getResult()));

        return $result;
    }

    // The line of a call, shared with app:agent:print so both agents show their calls alike
    /** @param array<string, mixed> $arguments */
    public static function callLine(string $name, array $arguments): string
    {
        return \sprintf('<info>●</info> %s(%s)', OutputFormatter::escape($name), OutputFormatter::escape(self::arguments($arguments)));
    }

    // The line of a result, a text as is, anything else as JSON
    public static function resultLine(mixed $result): string
    {
        return '  ⎿ ' . OutputFormatter::escape(self::cut(\is_string($result) ? $result : (string) json_encode($result, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES)));
    }

    // The arguments as name: value, each value cut
    /** @param array<string, mixed> $arguments */
    public static function arguments(array $arguments): string
    {
        $parts = [];
        foreach ($arguments as $name => $value) {
            $parts[] = $name . ': ' . self::cut(\is_string($value) ? $value : (string) json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES), 60);
        }

        return implode(', ', $parts);
    }

    // A text on one line, cut to the given length
    public static function cut(string $text, int $max = self::MAX): string
    {
        $text = trim((string) preg_replace('/\s+/', ' ', $text));

        return mb_strlen($text) > $max ? mb_substr($text, 0, $max - 1) . '…' : $text;
    }
}
