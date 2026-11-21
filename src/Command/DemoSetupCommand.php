<?php

namespace App\Command;

use c975L\ConfigBundle\Contract\UserInterface;
use c975L\ConfigBundle\Entity\AgentToken;
use c975L\ConfigBundle\Service\AdminUserCreator;
use c975L\ConfigBundle\Service\AgentTokenManager;
use c975L\ConfigBundle\Service\ConfigServiceInterface;
use c975L\PilotBundle\Entity\Action;
use c975L\PilotBundle\Entity\Card;
use c975L\PilotBundle\Entity\CardSet;
use c975L\PilotBundle\Entity\Machine;
use c975L\PilotBundle\Entity\Member;
use c975L\PilotBundle\Entity\Project;
use c975L\PilotBundle\Enum\MemberRole;
use c975L\UiBundle\Registry\DemoFixtureLinkerRegistry;
use c975L\UiBundle\Registry\DemoFixtureRegistry;
use Doctrine\DBAL\Platforms\SQLitePlatform;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Tools\SchemaTool;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\Filesystem\Filesystem;

// Builds the whole demo from nothing at every run: schema, the bundles' own datasets, the MCP endpoint opened, the editor's account, its "Book releases" space and card, the runner machine and the agent token
#[AsCommand(
    name: 'app:demo:setup',
    description: 'Rebuilds the demo database and prints the agent token and the runner machine token once',
)]
class DemoSetupCommand extends Command
{
    public const string EMAIL = 'editor@example.com';
    public const string PASSWORD = 'lighthouse-demo';
    public const string SPACE = 'book-releases';
    public const string ACTION = 'ai-agent';
    public const string PRESS_KIT = 'press-kit';
    public const string MACHINE = 'agent-runner';

    // The bundles whose MCP tools the agent token opens, by their getMcpKey()
    public const array BUNDLES = ['pilot', 'book', 'gallery', 'social'];

    // Days the agent token opens for, that day included
    private const int TOKEN_DAYS = 365;

    private const string NOTE = "The Lighthouse at Dawn, a novel: a keeper's daughter keeps the light burning through the winter storm that took her father. Out in two weeks, paperback and ebook.";

    // The card the agent works on, by group: its first step handed to the runner's AI agent, the press kit to a plain machine action, the proofreading and the approval staying human
    public const array STEPS = [
        ['t' => 'Draft the book in the catalog', 'g' => 'Writing', 'action' => self::ACTION],
        ['t' => 'Text proofread and validated', 'g' => 'Writing'],
        ['t' => 'Make its photo gallery', 'g' => 'Media'],
        ['t' => 'Build the press kit (PDF)', 'g' => 'Press kit', 'action' => self::PRESS_KIT],
        ['t' => 'Write the release posts', 'g' => 'Publication'],
        ['t' => 'Posts reviewed and approved in the SocialBundle calendar', 'g' => 'Publication'],
    ];

    // A few neighbours so the board looks lived in: title, days to the deadline (null for the backlog), steps, how many ticked
    private const array NEIGHBOURS = [
        ['Audiobook of Winter Tides', 6, ['Record the chapters', 'Master the audio', 'Upload to the stores'], 1],
        ['Book fair in Lyon', 21, ['Book the stand', 'Print the posters', 'Plan the signing'], 2],
        ['Spring catalog', null, ['Pick the titles', 'Write the blurbs'], 0],
    ];

    // Folders the datasets copy their files into, emptied with the database so a rebuild leaves no orphan behind
    private const array MEDIA_FOLDERS = ['public/medias', 'private'];

    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly DemoFixtureRegistry $fixtureRegistry,
        private readonly DemoFixtureLinkerRegistry $linkerRegistry,
        private readonly AdminUserCreator $adminUserCreator,
        private readonly AgentTokenManager $agentTokenManager,
        private readonly ConfigServiceInterface $configService,
        private readonly ClockInterface $clock,
        #[Autowire(service: 'cache.app')]
        private readonly CacheItemPoolInterface $cache,
        #[Autowire(param: 'kernel.project_dir')]
        private readonly string $projectDir,
        #[Autowire(param: 'kernel.environment')]
        private readonly string $environment,
    ) {
        parent::__construct();
    }

    // The address the printed lines point at, which has to be the one the site is served on, and where the runner's files go
    protected function configure(): void
    {
        $this
            ->addOption('url', null, InputOption::VALUE_REQUIRED, 'Address the site is served on', 'http://127.0.0.1:8000')
            ->addOption('runner-dir', null, InputOption::VALUE_REQUIRED, 'Folder the runner config and the agent access are written to (tests); the runner itself reads runner/', 'runner')
        ;
    }

    // Rebuilds everything, then prints what to paste: the tokens are shown here once, only their hashes being kept
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $url = rtrim((string) $input->getOption('url'), '/');

        // It drops every table it finds, so it only ever runs on the demo's own SQLite file
        if (!$this->entityManager->getConnection()->getDatabasePlatform() instanceof SQLitePlatform) {
            $io->error('app:demo:setup rebuilds the database from nothing and only runs on SQLite (see DATABASE_URL).');

            return Command::FAILURE;
        }

        $io->text('Rebuilding the schema and loading the bundles\' demo datasets...');
        $this->rebuildSchema();
        $this->runCommand('c975l:config:load-all', []);
        $this->runCommand('c975l:config:set', ['slug' => 'mcp-enabled', 'value' => 'true']);
        // The files a step names become pilot-open:// links, opened on this computer by vendor/c975l/pilot-bundle/runner/pilot-open.py
        $this->runCommand('c975l:config:set', ['slug' => 'pilot-open-scheme', 'value' => 'pilot-open']);
        $fixtures = $this->loadFixtures();

        $user = $this->adminUserCreator->create(self::EMAIL, self::PASSWORD, ['ROLE_CONTRIBUTOR', 'ROLE_EDITOR']);
        $space = $this->createSpace($user);
        $card = $this->createCards($space);
        $machineToken = $this->createMachine($space);
        $agentToken = $this->createAgentToken($user);
        $this->entityManager->flush();
        $this->configService->invalidateCache();

        $runnerFolder = $this->runnerFolder((string) $input->getOption('runner-dir'));
        $runnerConfig = $this->writeRunnerConfig($runnerFolder, $url, $machineToken, $agentToken);
        $this->report($io, $url, $fixtures, (int) $card->getId(), $agentToken, $runnerFolder, $runnerConfig);

        return Command::SUCCESS;
    }

    // Drops and recreates every table from the mappings, with the files and cached values of the previous run
    private function rebuildSchema(): void
    {
        // The tests have a database of their own, the files on disk staying the demo's
        if ('test' !== $this->environment) {
            new Filesystem()->remove(array_map(fn (string $folder): string => $this->projectDir . '/' . $folder, self::MEDIA_FOLDERS));
        }
        $this->cache->clear();

        $schemaTool = new SchemaTool($this->entityManager);
        $metadata = $this->entityManager->getMetadataFactory()->getAllMetadata();
        $schemaTool->dropDatabase();
        $schemaTool->createSchema($metadata);

        // Nothing loaded before the rebuild names a row that is still there
        $this->entityManager->clear();
    }

    // Runs one of the bundles' own console commands, its output kept unless it fails, which stops the setup
    /** @param array<string, string> $arguments */
    private function runCommand(string $name, array $arguments): void
    {
        $input = new ArrayInput($arguments);
        $input->setInteractive(false);

        $output = new BufferedOutput();

        if (Command::SUCCESS !== $this->getApplication()?->find($name)->run($input, $output)) {
            throw new \RuntimeException(\sprintf('"%s" failed: %s', $name, trim($output->fetch())));
        }
    }

    // Every installed bundle's demo dataset, as the UiBundle contract hands it over: the providers first, then the linkers needing their identifiers
    private function loadFixtures(): int
    {
        $count = 0;
        foreach ($this->fixtureRegistry->all() as $provider) {
            foreach ($provider->getDemoFixtures() as $entity) {
                $this->entityManager->persist($entity);
                ++$count;
            }
        }
        $this->entityManager->flush();

        foreach ($this->linkerRegistry->all() as $linker) {
            foreach ($linker->getLinkedDemoFixtures() as $entity) {
                $this->entityManager->persist($entity);
                ++$count;
            }
        }
        $this->entityManager->flush();

        return $count;
    }

    // The "Book releases" space, owned by the demo account, with its one collection
    private function createSpace(UserInterface $user): Project
    {
        $space = new Project(self::SPACE, 'Book releases')
            ->setColor('#8A4B2B')
            ->setPosition(9);
        $this->entityManager->persist($space);
        $this->entityManager->persist(new Member($space, $user, MemberRole::Owner));

        return $space;
    }

    // The card the agent works on, its first step naming the AI action, then its neighbours
    private function createCards(Project $space): Card
    {
        $set = new CardSet($space, 'Coming out');
        $this->entityManager->persist($set);

        $card = $this->card($space, $set, 'Release The Lighthouse at Dawn', 14, [], 0, 0)
            ->setNote(self::NOTE)
            ->setChecklist(self::STEPS);

        foreach (self::NEIGHBOURS as $rank => [$title, $due, $steps, $ticked]) {
            $this->card($space, $set, $title, $due, $steps, $ticked, $rank + 1);
        }

        return $card;
    }

    // One card of the space, its deadline counted from today, its first steps ticked
    /** @param list<string> $steps */
    private function card(Project $space, CardSet $set, string $title, ?int $due, array $steps, int $ticked, int $rank): Card
    {
        $now = $this->clock->now();
        $card = new Card($space, $title)
            ->setCardSet($set)
            ->setRank($rank)
            ->setDue(null === $due ? null : $now->setTime(0, 0)->modify(\sprintf('+%d days', $due)));
        foreach ($steps as $index => $step) {
            $card->addStep($step);
            if ($index < $ticked) {
                $card->toggleStep($index, $now);
            }
        }
        $this->entityManager->persist($card);

        return $card;
    }

    // The runner machine serving the space, and the action it runs offered there so the step shows its button before the runner first calls
    private function createMachine(Project $space): string
    {
        $machine = new Machine(self::MACHINE, '')->addProject($space);
        $token = $machine->newToken();
        $this->entityManager->persist($machine);

        $actions = [
            self::ACTION => ['Send to the AI', 'The AI agent of this machine works on the card through the site\'s MCP endpoint, ticks what it finishes and asks on the card what it misses'],
            self::PRESS_KIT => ['Build the press kit', 'Lays the card out as a two-page PDF on this machine, no AI involved'],
        ];
        $position = 0;
        foreach ($actions as $key => [$label, $help]) {
            $action = new Action($key)
                ->declare($label, ['comment'], true, $help, $position++)
                ->addProject($space);
            $action->declaredBy($machine);
            $this->entityManager->persist($action);
        }

        return $token;
    }

    // The agent token of the demo account, writing, limited to the four bundles and to a year
    private function createAgentToken(UserInterface $user): string
    {
        $agentToken = new AgentToken()
            ->setUser($user)
            ->setName('Demo agent')
            ->setWritable(true)
            ->setBundles(self::BUNDLES)
            ->setExpiration($this->clock->now()->modify(\sprintf('+%d days', self::TOKEN_DAYS)));
        $token = $this->agentTokenManager->issue($agentToken);
        $this->entityManager->persist($agentToken);

        return $token;
    }

    // The runner's own config and the agent's MCP access, both git-ignored and readable by their owner only, the runner's returned to be printed
    private function writeRunnerConfig(string $folder, string $url, string $machineToken, string $agentToken): string
    {
        $runner = "# Written by app:demo:setup: the demo site this machine serves, with its machine token\n"
            . "sites:\n  - url: " . $url . "\n    token: " . $machineToken . "\n"
            . "workdir: " . $this->projectDir . "/var/runner\n"
            . "# The folders a pilot-open:// link opens on this computer: the press kit's PDF lands there\n"
            . "open:\n  - " . $this->projectDir . "/var/runner\n";
        $agent = "# Written by app:demo:setup: the MCP endpoint and the agent token agent.sh hands to the agent\n"
            . 'MCP_URL=' . $url . "/api/mcp\nMCP_TOKEN=" . $agentToken . "\n";

        // Created unreadable by others from the start, not only once chmod has run
        $filesystem = new Filesystem();
        $umask = umask(0o077);
        try {
            foreach (['runner.yaml' => $runner, 'agent.env' => $agent] as $file => $content) {
                $filesystem->dumpFile($folder . '/' . $file, $content);
                $filesystem->chmod($folder . '/' . $file, 0o600);
            }
        } finally {
            umask($umask);
        }

        return $runner;
    }

    // The runner folder, relative paths read from the project's root
    private function runnerFolder(string $folder): string
    {
        return new Filesystem()->isAbsolutePath($folder) ? $folder : $this->projectDir . '/' . $folder;
    }

    // What to paste next, the agent token shown this once
    private function report(SymfonyStyle $io, string $url, int $fixtures, int $card, string $agentToken, string $runnerFolder, string $runnerConfig): void
    {
        $io->success(\sprintf('Demo ready: %d rows from the bundles\' datasets, the "Book releases" space and its card #%d.', $fixtures, $card));

        $io->section('1. Sign in on the board');
        $io->text([$url . '/pilot/' . self::SPACE, 'Email: ' . self::EMAIL, 'Password: ' . self::PASSWORD]);

        $io->section('2. Give your own Claude Code the site\'s tools (optional, the runner does not need it)');
        $io->text(\sprintf('claude mcp add --transport http demo %s/api/mcp --header "Authorization: Bearer %s"', $url, $agentToken));

        $io->section('3. Start the runner, its config written to ' . $runnerFolder . '/runner.yaml (with agent.env beside it)');
        $io->writeln(explode("\n", trim($runnerConfig)));
        $io->newLine();
        $io->text('PILOT_RUNNER_CONFIG=' . $this->projectDir . '/runner python3 vendor/c975l/pilot-bundle/runner/pilot-runner.py');

        // The actions and their scripts live in runner/ only, so a config written elsewhere is not the one the runner reads
        if ($runnerFolder !== $this->projectDir . '/runner') {
            $io->warning('The runner reads runner/, not ' . $runnerFolder . ': copy runner.yaml and agent.env there to use them.');
        }

        $io->section('4. Open the card and click the play button of its first step ("Send to the AI")');
        $io->text($url . '/pilot/' . self::SPACE . '/' . $card);

        $io->note('The tokens are shown once: running app:demo:setup again rebuilds everything with new ones.');
    }
}
