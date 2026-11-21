<?php

namespace App\Tests\Runner;

use App\Tests\DemoSetupTrait;
use c975L\PilotBundle\Entity\Card;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The scripts the runner starts, run as it runs them: a work folder card-<id>, the comment handed back, the lines it reads from their output
class RunnerScriptsTest extends KernelTestCase
{
    use DemoSetupTrait;

    // The demo built on the test database, with its runner files in a temporary folder
    protected function setUp(): void
    {
        self::setUpDemo(self::bootKernel());
    }

    // The press kit names the folder and the PDF, and leaves each numbered comment open for the AI
    public function testPressKitPrintsFilesAndFeedback(): void
    {
        // A title only the test database holds, so the PDF proves the console read it and not the demo's
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $title = 'Release Runner Test ' . bin2hex(random_bytes(3));
        $entityManager->find(Card::class, self::$cardId)?->setTitle($title);
        $entityManager->flush();

        $workdir = self::$runnerDir . '/work/card-' . self::$cardId;
        $process = $this->runScript(\dirname(__DIR__, 2) . '/runner/press-kit.sh', $workdir, "1. Add the release date\n2. Link the posts\nno number here");

        self::assertSame(['FILE ' . $workdir, 'FILE ' . $workdir . '/press-kit.pdf'], $this->lines($process, 'FILE'));
        $feedback = $this->lines($process, 'FEEDBACK');
        self::assertCount(2, $feedback);
        self::assertStringStartsWith('FEEDBACK 1 ', $feedback[0]);
        self::assertStringStartsWith('FEEDBACK 2 ', $feedback[1]);

        $pdf = (string) file_get_contents($workdir . '/press-kit.pdf');
        self::assertStringStartsWith('%PDF', $pdf);
        // Dompdf keeps the title, without "Release", in the document's metadata as UTF-16
        self::assertTrue(str_contains($pdf, mb_convert_encoding(substr($title, 8) . ' - Press kit', 'UTF-16BE', 'UTF-8')), 'The PDF is the test card\'s');
    }

    // agent.sh hands the card to AGENT_COMMAND with CARD_ID read from the work folder, then prints the two links left under the step
    public function testAgentScriptRunsTheCommandAndPrintsTheLinks(): void
    {
        // A copy beside the agent.env the setup wrote in the temporary folder, the demo's runner/agent.env left alone
        new Filesystem()->copy(\dirname(__DIR__, 2) . '/runner/agent.sh', self::$runnerDir . '/agent.sh');

        $process = $this->runScript(self::$runnerDir . '/agent.sh', self::$runnerDir . '/work/card-' . self::$cardId, '', ['AGENT_COMMAND' => 'echo "card=$CARD_ID token=${#MCP_TOKEN}"']);
        self::assertStringStartsWith('card=' . self::$cardId . ' token=64 You work for the user on card ' . self::$cardId . ' ', $process->getOutput());
        self::assertSame([
            'LINK http://127.0.0.1:8123/management/book Book drafts',
            'LINK http://127.0.0.1:8123/management/social-calendar Posts to approve',
        ], $this->lines($process, 'LINK'));
        self::assertStringContainsString("\n✓ Done · the card is back to you\n", $process->getOutput());

        // Comments handed back change the prompt: the agent works on them only and answers the ones it cannot do with FEEDBACK lines
        $process = $this->runScript(self::$runnerDir . '/agent.sh', self::$runnerDir . '/work/card-' . self::$cardId, '1. Add the release date', ['AGENT_COMMAND' => 'echo "comment=$AGENT_COMMENT"']);
        self::assertStringStartsWith('comment=1. Add the release date ', $process->getOutput());
        self::assertStringContainsString('FEEDBACK <number> <short reason>', $process->getOutput());
        self::assertCount(2, $this->lines($process, 'LINK'));
    }

    // One script run as the runner runs it, on the test environment and with no model key
    /** @param array<string, string> $env */
    private function runScript(string $script, string $workdir, string $comment, array $env = []): Process
    {
        new Filesystem()->mkdir($workdir);
        $process = new Process(['bash', $script, $workdir, $comment], $workdir, $env + [
            'APP_ENV' => 'test',
            'ANTHROPIC_API_KEY' => '',
            'PILOT_URL' => false,
            'AGENT_COMMAND' => false,
        ]);
        $process->mustRun();

        return $process;
    }

    // The output lines starting with the given keyword
    /** @return list<string> */
    private function lines(Process $process, string $keyword): array
    {
        return array_values(array_filter(explode("\n", $process->getOutput()), static fn (string $line): bool => str_starts_with($line, $keyword . ' ')));
    }
}
