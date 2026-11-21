<?php

namespace App\Tests;

use c975L\PilotBundle\Entity\Card;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;

// The demo built once per test class on the test database, its runner files in a temporary folder, the tokens it prints kept for the tests
trait DemoSetupTrait
{
    private static ?string $runnerDir = null;
    private static string $agentToken = '';
    private static int $cardId = 0;

    // Runs app:demo:setup on the kernel given, the first time a test of the class needs it
    private static function setUpDemo(KernelInterface $kernel): void
    {
        if (null !== self::$runnerDir) {
            return;
        }

        self::$runnerDir = sys_get_temp_dir() . '/pilot-mcp-demo-test-' . bin2hex(random_bytes(4));
        $tester = new CommandTester(new Application($kernel)->find('app:demo:setup'));
        $tester->execute(['--url' => 'http://127.0.0.1:8123', '--runner-dir' => self::$runnerDir]);
        $tester->assertCommandIsSuccessful();

        // The agent token is printed once, in the claude mcp add line
        preg_match('/Bearer ([a-f0-9]{64})/', $tester->getDisplay(), $matches);
        self::$agentToken = $matches[1] ?? '';

        $card = $kernel->getContainer()->get('doctrine')->getManager()->getRepository(Card::class)->findOneBy(['title' => 'Release The Lighthouse at Dawn']);
        self::$cardId = (int) $card?->getId();
    }

    // The temporary runner folder removed, the next class building its own demo
    public static function tearDownAfterClass(): void
    {
        if (null !== self::$runnerDir) {
            new Filesystem()->remove(self::$runnerDir);
            self::$runnerDir = null;
        }
        parent::tearDownAfterClass();
    }
}
