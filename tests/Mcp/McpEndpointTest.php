<?php

namespace App\Tests\Mcp;

use App\Command\DemoSetupCommand;
use App\Tests\DemoSetupTrait;
use c975L\ConfigBundle\Entity\AgentToken;
use c975L\ConfigBundle\Service\AgentTokenManager;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

// The site's MCP endpoint end to end, as an agent reaches it: Streamable HTTP, an agent token as a Bearer
class McpEndpointTest extends WebTestCase
{
    use DemoSetupTrait;

    private KernelBrowser $client;

    // A browser on the demo built once for the class
    protected function setUp(): void
    {
        $this->client = static::createClient();
        self::setUpDemo($this->client->getKernel());
    }

    // The demo token sees the tools of the four bundles it is scoped to
    public function testToolsListOffersTheFourBundles(): void
    {
        $initialize = $this->rpc(self::$agentToken, 'initialize', [
            'protocolVersion' => '2025-06-18',
            'capabilities' => new \stdClass(),
            'clientInfo' => ['name' => 'test', 'version' => '1.0'],
        ]);
        self::assertArrayHasKey('serverInfo', $initialize['result'] ?? []);


        // Each bundle of the token adds at least one tool, and no other bundle any
        $names = $this->toolNames(self::$agentToken);
        $prefixes = array_values(array_unique(array_map(static fn (string $name): string => strstr($name, '_', true) ?: $name, $names)));
        self::assertEqualsCanonicalizing(DemoSetupCommand::BUNDLES, $prefixes);
        self::assertGreaterThan(\count(DemoSetupCommand::BUNDLES), \count($names));
        self::assertContains('pilot_card', $names);
    }

    // No token, no tools
    public function testRequestWithoutTokenIsRefused(): void
    {
        $this->client->request('POST', '/api/mcp', [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json, text/event-stream'], '{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

        self::assertResponseStatusCodeSame(401);
    }

    // A token scoped without social is given no social_* tool, the other bundles' tools kept
    public function testScopedTokenHidesTheBundlesLeftOut(): void
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $demoToken = $entityManager->getRepository(AgentToken::class)->findOneBy([]);
        self::assertNotNull($demoToken);

        $scoped = new AgentToken()
            ->setUser($demoToken->getUser())
            ->setName('Without social')
            ->setWritable(true)
            ->setBundles(['pilot', 'book', 'gallery']);
        $token = self::getContainer()->get(AgentTokenManager::class)->issue($scoped);
        $entityManager->persist($scoped);
        $entityManager->flush();

        $names = $this->toolNames($token);
        self::assertNotEmpty($names);
        self::assertSame([], array_values(array_filter($names, static fn (string $name): bool => str_starts_with($name, 'social_'))));
        self::assertContains('pilot_card', $names);
    }

    // pilot_card answers the demo card with its six steps
    public function testToolCallReadsTheDemoCard(): void
    {
        $answer = $this->rpc(self::$agentToken, 'tools/call', ['name' => 'pilot_card', 'arguments' => ['card' => self::$cardId]]);

        self::assertFalse($answer['result']['isError'] ?? true);
        $card = json_decode((string) ($answer['result']['content'][0]['text'] ?? ''), true);
        self::assertIsArray($card);
        self::assertStringContainsString('Release The Lighthouse at Dawn', (string) ($card['markdown'] ?? ''));
        self::assertCount(6, $card['steps'] ?? []);
    }

    // The names of the tools the token is given
    /** @return list<string> */
    private function toolNames(string $token): array
    {
        return array_column($this->rpc($token, 'tools/list')['result']['tools'] ?? [], 'name');
    }

    // One JSON-RPC request to the endpoint, its answer decoded whether it comes as JSON or as an event stream
    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function rpc(string $token, string $method, array $params = []): array
    {
        $body = ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method];
        if ([] !== $params) {
            $body['params'] = $params;
        }
        $this->client->request('POST', '/api/mcp', [], [], [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_ACCEPT' => 'application/json, text/event-stream',
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
        ], (string) json_encode($body));
        self::assertResponseIsSuccessful();

        $content = (string) $this->client->getInternalResponse()->getContent();
        if (1 === preg_match('/^data: (.+)$/m', $content, $matches)) {
            $content = $matches[1];
        }

        return (array) json_decode($content, true);
    }
}
