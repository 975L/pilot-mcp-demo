<?php

namespace App\Tests\Mcp;

use Nyholm\Psr7\Factory\Psr17Factory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

// A PSR-18 client answered by the test kernel, so an MCP client reaches /api/mcp without any server or network
final class KernelHttpClient implements ClientInterface
{
    public function __construct(
        private readonly KernelBrowser $browser,
    ) {
    }

    // The request replayed on the kernel, its answer handed back as PSR-7
    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        // Headers as the server variables HttpFoundation reads them
        $server = [];
        foreach ($request->getHeaders() as $name => $values) {
            $key = strtoupper(str_replace('-', '_', $name));
            $server[\in_array($key, ['CONTENT_TYPE', 'CONTENT_LENGTH'], true) ? $key : 'HTTP_' . $key] = implode(', ', $values);
        }
        $this->browser->request($request->getMethod(), (string) $request->getUri(), [], [], $server, (string) $request->getBody());

        $internal = $this->browser->getInternalResponse();
        $factory = new Psr17Factory();
        $response = $factory->createResponse($internal->getStatusCode())->withBody($factory->createStream($internal->getContent()));
        foreach ($internal->getHeaders() as $name => $values) {
            $response = $response->withHeader($name, $values);
        }

        return $response;
    }
}
