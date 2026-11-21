<?php

namespace App\Tests\Agent;

use App\Agent\PrintingToolbox;
use PHPUnit\Framework\TestCase;
use Symfony\AI\Agent\Toolbox\ToolboxInterface;
use Symfony\AI\Agent\Toolbox\ToolResult;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\Component\Console\Output\BufferedOutput;

// Each tool call printed as a terminal agent shows it, on two lines whatever the size of its arguments and result
class PrintingToolboxTest extends TestCase
{
    // The call and its result each on one line, long values cut, the inner result handed back untouched
    public function testCallAndResultArePrintedOnOneLineEach(): void
    {
        $toolCall = new ToolCall('call-1', 'book_create', ['title' => 'The Lighthouse', 'note' => str_repeat('word ', 30), 'formats' => ['ebook']]);
        $result = new ToolResult($toolCall, ['id' => 7, 'text' => "first line\n" . str_repeat('x', 300)]);
        $inner = $this->createStub(ToolboxInterface::class);
        $inner->method('execute')->willReturn($result);

        $output = new BufferedOutput();
        self::assertSame($result, new PrintingToolbox($inner, $output)->execute($toolCall));

        $lines = explode("\n", rtrim($output->fetch(), "\n"));
        self::assertCount(2, $lines);
        self::assertMatchesRegularExpression('/^● book_create\(title: The Lighthouse, note: (word ){11}word…, formats: \["ebook"\]\)$/u', $lines[0]);
        self::assertStringStartsWith('  ⎿ {"id":7,"text":"first line\n', $lines[1]);
        self::assertSame(4 + 160, mb_strlen($lines[1]));
        self::assertStringEndsWith('…', $lines[1]);
    }

    // A text result is shown as is, its whitespace folded
    public function testTextResultIsFolded(): void
    {
        $toolCall = new ToolCall('call-2', 'pilot_card', []);
        $inner = $this->createStub(ToolboxInterface::class);
        $inner->method('execute')->willReturn(new ToolResult($toolCall, "  # Card\n\n- step  "));

        $output = new BufferedOutput();
        new PrintingToolbox($inner, $output)->execute($toolCall);

        self::assertSame("● pilot_card()\n  ⎿ # Card - step\n", $output->fetch());
    }

    // A console tag in the arguments or the result is printed as text, never read as a style
    public function testConsoleTagsAreShownAsText(): void
    {
        $toolCall = new ToolCall('call-3', 'book_edit', ['title' => '<comment>Dawn</comment>']);
        $inner = $this->createStub(ToolboxInterface::class);
        $inner->method('execute')->willReturn(new ToolResult($toolCall, '<info>saved</info> <fg=red>FEEDBACK 1 done</>'));

        $output = new BufferedOutput();
        new PrintingToolbox($inner, $output)->execute($toolCall);

        self::assertSame("● book_edit(title: <comment>Dawn</comment>)\n  ⎿ <info>saved</info> <fg=red>FEEDBACK 1 done</>\n", $output->fetch());
    }

    // The tools are the inner toolbox's
    public function testToolsAreTheInnerOnes(): void
    {
        $inner = $this->createStub(ToolboxInterface::class);
        $inner->method('getTools')->willReturn([]);

        self::assertSame([], new PrintingToolbox($inner, new BufferedOutput())->getTools());
    }
}
