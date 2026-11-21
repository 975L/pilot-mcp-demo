<?php

namespace App\Tests\Runner;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

// The SaveToEnvLocal function of bin/demo, run alone in a temporary folder
class DemoScriptTest extends TestCase
{
    private string $folder;

    // A folder of the test's own, so the project's .env.local is never touched
    protected function setUp(): void
    {
        $this->folder = sys_get_temp_dir() . '/pilot-mcp-demo-env-' . bin2hex(random_bytes(4));
        new Filesystem()->dumpFile($this->folder . '/.env.local', "OTHER=1\nANTHROPIC_API_KEY=old\n");
    }

    protected function tearDown(): void
    {
        new Filesystem()->remove($this->folder);
    }

    // A value holding spaces, quotes, $ and # is read back as written, its line replaced, the file private and the caller's umask kept
    public function testValuesAreSavedForDotenvWithoutChangingTheUmask(): void
    {
        $script = \dirname(__DIR__, 2) . '/bin/demo';
        $process = new Process(['bash', '-c', 'umask 022; eval "$(sed -n \'/^SaveToEnvLocal() (/,/^)$/p\' "$1")"; SaveToEnvLocal ANTHROPIC_API_KEY ANTHROPIC_MODEL GENERIC_MODEL; umask', 'bash', $script], $this->folder, [
            'ANTHROPIC_API_KEY' => "sk-a b \$HOME #c it's",
            'ANTHROPIC_MODEL' => 'model-1',
            'GENERIC_MODEL' => false,
        ]);
        $process->mustRun();

        self::assertSame("0022\n", $process->getOutput());
        self::assertSame(0o600, fileperms($this->folder . '/.env.local') & 0o777);
        self::assertSame(
            ['OTHER' => '1', 'ANTHROPIC_API_KEY' => "sk-a b \$HOME #c it's", 'ANTHROPIC_MODEL' => 'model-1'],
            new Dotenv()->parse((string) file_get_contents($this->folder . '/.env.local')),
        );
    }
}
