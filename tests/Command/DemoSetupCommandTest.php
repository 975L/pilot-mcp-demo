<?php

namespace App\Tests\Command;

use App\Command\DemoSetupCommand;
use c975L\ConfigBundle\Entity\AgentToken;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PilotBundle\Entity\Card;
use c975L\PilotBundle\Entity\Machine;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;

// The setup run twice on the test database: the second run starts again from nothing, with new tokens whose hashes alone are kept
class DemoSetupCommandTest extends KernelTestCase
{
    private string $runnerDir;

    // A runner folder of the test's own, so the demo's runner/ is never overwritten
    protected function setUp(): void
    {
        $this->runnerDir = sys_get_temp_dir() . '/pilot-mcp-demo-test-' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->runnerDir);
        parent::tearDown();
    }

    // Builds the demo, then checks the space, the grouped steps and their actions, the scoped token, the runner's files and the press kit
    public function testSetupIsIdempotentAndPrintsWorkingTokens(): void
    {
        $tester = new CommandTester(new Application(self::bootKernel())->find('app:demo:setup'));
        $tester->execute(['--url' => 'http://127.0.0.1:8123', '--runner-dir' => $this->runnerDir]);
        $tester->execute(['--url' => 'http://127.0.0.1:8123', '--runner-dir' => $this->runnerDir]);
        $tester->assertCommandIsSuccessful();

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $tokens = $entityManager->getRepository(AgentToken::class)->findAll();
        self::assertCount(1, $tokens);
        self::assertSame(['pilot', 'book', 'gallery', 'social'], $tokens[0]->getBundles());
        self::assertTrue($tokens[0]->isWritable());
        self::assertEqualsWithDelta(new \DateTimeImmutable('+365 days')->getTimestamp(), $tokens[0]->getExpiration()?->getTimestamp(), 3600);

        // The token printed is the one whose hash is kept
        self::assertSame(1, preg_match('/Bearer ([a-f0-9]{64})/', $tester->getDisplay(), $matches));
        self::assertSame(hash('sha256', $matches[1]), $tokens[0]->getTokenHash());
        self::assertStringContainsString('MCP_TOKEN=' . $matches[1], (string) file_get_contents($this->runnerDir . '/agent.env'));

        $card = $entityManager->getRepository(Card::class)->findOneBy(['title' => 'Release The Lighthouse at Dawn']);
        self::assertNotNull($card);
        self::assertSame(DemoSetupCommand::SPACE, $card->getProject()->getSlug());

        // Six steps in four groups, the AI and the press kit the only two machine actions, the other steps human
        $steps = $card->getSteps();
        self::assertSame(['Writing', 'Writing', 'Media', 'Press kit', 'Publication', 'Publication'], array_column($steps, 'g'));
        self::assertSame([0 => DemoSetupCommand::ACTION, 3 => DemoSetupCommand::PRESS_KIT], array_filter(array_map(static fn (array $step): ?string => $step['action'] ?? null, $steps)));
        self::assertSame('Draft the book in the catalog', $steps[0]['t']);

        $machine = $entityManager->getRepository(Machine::class)->findOneBy(['name' => DemoSetupCommand::MACHINE]);
        self::assertNotNull($machine);
        self::assertSame(1, preg_match('/token: ([a-f0-9]{64})/', (string) file_get_contents($this->runnerDir . '/runner.yaml'), $matches));
        self::assertSame(hash('sha256', $matches[1]), $machine->getTokenHash());
        self::assertStringContainsString("open:\n  - ", (string) file_get_contents($this->runnerDir . '/runner.yaml'));
        self::assertStringContainsString('MCP_URL=http://127.0.0.1:8123/api/mcp', (string) file_get_contents($this->runnerDir . '/agent.env'));

        // Both files hold a token, so only their owner reads them
        foreach (['runner.yaml', 'agent.env'] as $file) {
            self::assertSame(0o600, fileperms($this->runnerDir . '/' . $file) & 0o777);
        }

        // The runner is always pointed at runner/, where its actions live, a folder written elsewhere being flagged
        $display = (string) preg_replace('/\s+/', ' ', $tester->getDisplay());
        self::assertStringContainsString('PILOT_RUNNER_CONFIG=' . self::$kernel->getProjectDir() . '/runner python3', $display);
        self::assertStringContainsString('The runner reads runner/, not', $display);

        // The press kit of that card, written where the runner would show it
        $pressKit = new CommandTester(new Application(self::$kernel)->find('app:press-kit'));
        $pressKit->execute(['card' => (string) $card->getId(), 'dir' => $this->runnerDir . '/card']);
        $pressKit->assertCommandIsSuccessful();
        self::assertStringContainsString('FILE ' . $this->runnerDir . '/card/press-kit.pdf', $pressKit->getDisplay());
        self::assertStringStartsWith('%PDF', (string) file_get_contents($this->runnerDir . '/card/press-kit.pdf'));

        self::assertTrue(self::getContainer()->get(ConfigServiceInterface::class)->get('mcp-enabled'));
    }
}
