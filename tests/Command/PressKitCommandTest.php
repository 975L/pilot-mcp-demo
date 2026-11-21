<?php

namespace App\Tests\Command;

use App\Command\PressKitCommand;
use PHPUnit\Framework\TestCase;

// The formats the title page shows, read from the card's note
class PressKitCommandTest extends TestCase
{
    // Named formats in their fixed order, plurals included, nothing for a note naming none
    public function testFormatsAreReadFromTheNote(): void
    {
        self::assertSame(['Paperback', 'Ebook'], PressKitCommand::formats('Out soon, ebook and paperbacks.'));
        self::assertSame([], PressKitCommand::formats('A novel.'));
    }
}
