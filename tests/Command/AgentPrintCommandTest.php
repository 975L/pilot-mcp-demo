<?php

namespace App\Tests\Command;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

// Claude Code's stream-json printed as the Symfony AI agent prints its run, from a recorded stream: no Claude Code, no model
class AgentPrintCommandTest extends KernelTestCase
{
    private string $stream;

    // A temporary file standing for the stream agent.sh pipes in
    protected function setUp(): void
    {
        $this->stream = new Filesystem()->tempnam(sys_get_temp_dir(), 'stream-');
    }

    // Nothing left behind
    protected function tearDown(): void
    {
        new Filesystem()->remove($this->stream);
        parent::tearDown();
    }

    // The session line, each call without its server prefix and its result, the model's own text left out, then the answer whole
    public function testCallsAndAnswerArePrinted(): void
    {
        $tester = $this->print([
            ['type' => 'system', 'subtype' => 'init', 'model' => 'claude-sonnet-5-5', 'tools' => ['Read', 'mcp__demo__pilot_card', 'mcp__demo__book_create']],
            ['type' => 'assistant', 'message' => ['content' => [
                ['type' => 'text', 'text' => 'Reading the card first.'],
                ['type' => 'tool_use', 'id' => 'tu-1', 'name' => 'mcp__demo__pilot_card', 'input' => ['card' => 10]],
            ]]],
            ['type' => 'user', 'message' => ['content' => [
                ['type' => 'tool_result', 'tool_use_id' => 'tu-1', 'content' => [['type' => 'text', 'text' => "card read:\n6 steps"]]],
            ]]],
            ['type' => 'result', 'subtype' => 'success', 'is_error' => false, 'result' => "Book drafted.\nFEEDBACK 2 <no> date given"],
        ]);

        $tester->assertCommandIsSuccessful();
        self::assertSame(
            "Claude Code · claude-sonnet-5-5 · 2 MCP tools\n● pilot_card(card: 10)\n  ⎿ card read: 6 steps\nBook drafted.\nFEEDBACK 2 <no> date given\n",
            $tester->getDisplay(),
        );
    }

    // A string result is shown as is, a line that is not JSON skipped, an answer in error failing the command
    public function testErrorFailsAndNoiseIsSkipped(): void
    {
        $tester = $this->print([
            ['type' => 'user', 'message' => ['content' => [['type' => 'tool_result', 'content' => 'Not allowed']]]],
            ['type' => 'result', 'subtype' => 'error_during_execution', 'is_error' => true, 'result' => 'Stopped'],
        ], "not json\n");

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertSame("  ⎿ Not allowed\nStopped\n", $tester->getDisplay());
    }

    // A source that cannot be read is said so
    public function testUnreadableSourceFails(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:agent:print'));

        self::assertSame(Command::FAILURE, $tester->execute(['source' => $this->stream . '-missing']));
        self::assertStringContainsString('Cannot read', $tester->getDisplay());
    }

    // The events written one JSON per line, after any noise, then printed
    /** @param list<array<string, mixed>> $events */
    private function print(array $events, string $noise = ''): CommandTester
    {
        file_put_contents($this->stream, $noise . implode("\n", array_map(static fn (array $event): string => (string) json_encode($event), $events)) . "\n");
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:agent:print'));
        $tester->execute(['source' => $this->stream]);

        return $tester;
    }
}
