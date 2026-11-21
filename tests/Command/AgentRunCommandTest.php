<?php

namespace App\Tests\Command;

use App\Command\AgentRunCommand;
use App\Tests\DemoSetupTrait;
use App\Tests\Mcp\KernelHttpClient;
use Mcp\Client\Transport\HttpTransport;
use Mcp\Client\Transport\TransportInterface;
use Psr\Http\Client\ClientInterface;
use Symfony\AI\Platform\PlatformInterface;
use Symfony\AI\Platform\Result\ToolCall;
use Symfony\AI\Platform\Result\ToolCallResult;
use Symfony\AI\Platform\Test\MockPlatformFactory;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

// The Symfony AI agent of app:agent:run, its model scripted and its MCP tools served by the test kernel: no model, no server, no network
class AgentRunCommandTest extends WebTestCase
{
    use DemoSetupTrait;

    private const array ENV = ['AGENT_PROMPT', 'MCP_URL', 'MCP_TOKEN'];

    // The environment agent.sh would export, set per test
    protected function setUp(): void
    {
        foreach (self::ENV as $name) {
            unset($_SERVER[$name], $_ENV[$name]);
        }
    }

    // Nothing left for the next test
    protected function tearDown(): void
    {
        $this->setUp();
        parent::tearDown();
    }

    // A scripted tool call runs on the real endpoint, printed as it happens, then the final answer is printed
    public function testScriptedToolCallIsExecutedAndPrinted(): void
    {
        $client = static::createClient();
        self::setUpDemo($client->getKernel());
        $_SERVER['MCP_URL'] = 'http://localhost/api/mcp';
        $_SERVER['MCP_TOKEN'] = self::$agentToken;

        // First the model asks for the card, the toolset naming the tools after the server "demo", then it answers once the tool result reached it
        $inputs = [];
        $platform = MockPlatformFactory::createPlatform(static function ($model, $input) use (&$inputs): ToolCallResult|string {
            $inputs[] = json_encode($input);

            return 1 === \count($inputs)
                ? new ToolCallResult([new ToolCall('call-1', 'demo_pilot_card', ['card' => self::$cardId])])
                : 'Card read: <comment>six</comment> steps.';
        });

        $tester = new CommandTester(new Command('app:agent:run', $this->command($platform, new KernelHttpClient($client))));
        $tester->execute(['prompt' => 'Read the card.']);
        $tester->assertCommandIsSuccessful();

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^Symfony AI agent · scripted · \d+ MCP tools$/m', $display);
        self::assertStringContainsString('● demo_pilot_card(card: ' . self::$cardId . ')', $display);
        self::assertMatchesRegularExpression('/^  ⎿ .*Release The Lighthouse at Dawn/m', $display);
        // The answer keeps its tags as text, never read as console styles
        self::assertStringEndsWith("Card read: <comment>six</comment> steps.\n", $display);
        self::assertCount(2, $inputs);
        self::assertStringContainsString('Release The Lighthouse at Dawn', (string) $inputs[1]);
    }

    // Without what agent.sh exports, the agent does not start
    public function testMissingEnvironmentFails(): void
    {
        $tester = new CommandTester(new Command('app:agent:run', new AgentRunCommand()));
        $tester->execute([]);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('MCP_URL and MCP_TOKEN are needed', $tester->getDisplay());
    }

    // The keys being blanked for the tests, no model is found, the real .env.local key never being used
    public function testNoModelConfiguredFails(): void
    {
        $_SERVER['MCP_URL'] = 'http://localhost/api/mcp';
        $_SERVER['MCP_TOKEN'] = 'unused';

        $tester = new CommandTester(new Command('app:agent:run', new AgentRunCommand()));
        $tester->execute(['prompt' => 'Read the card.']);

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('No model configured', $tester->getDisplay());
    }

    // The command with its model scripted and its endpoint answered by the kernel
    private function command(PlatformInterface $platform, ClientInterface $httpClient): AgentRunCommand
    {
        return new class ($platform, $httpClient) extends AgentRunCommand {
            public function __construct(
                private readonly PlatformInterface $scripted,
                private readonly ClientInterface $httpClient,
            ) {
            }

            // The scripted model in place of Anthropic or an OpenAI-compatible API
            protected function platform(): array
            {
                return [$this->scripted, 'scripted'];
            }

            // The same transport as the command's, its requests handed to the kernel
            protected function transport(string $url, string $token): TransportInterface
            {
                return new HttpTransport($url, ['Authorization' => 'Bearer ' . $token], $this->httpClient);
            }
        };
    }
}
