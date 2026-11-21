<?php

namespace App\Social;

use c975L\SocialBundle\Contract\NetworkPublisherInterface;
use c975L\SocialBundle\Model\MediaRules;
use c975L\UiBundle\Model\SocialContent;

// A network the demo is always connected to and which sends nothing, so a post can be written without connecting a real account
final class DemoNetworkPublisher implements NetworkPublisherInterface
{
    // The network's name on the posts
    public function getName(): string
    {
        return 'demo';
    }

    // Always connected, no account to link
    public function isConfigured(): bool
    {
        return true;
    }

    // The longest text a post takes
    public function getMaxLength(): int
    {
        return 500;
    }

    // The files a post may carry
    public function getMediaRules(): MediaRules
    {
        return new MediaRules(
            maxImages: 4,
            video: true,
            imageTypes: ['image/jpeg', 'image/png', 'image/webp', 'image/gif'],
        );
    }

    // An id of its own making, as a network would return one, nothing leaving the machine
    public function publish(string $text, SocialContent $content, array $medias = []): string
    {
        return 'demo-' . bin2hex(random_bytes(8));
    }

    // The post as it would go out
    public function preview(string $text, SocialContent $content, array $medias = []): array
    {
        return ['text' => $text, 'medias' => \count($medias)];
    }
}
