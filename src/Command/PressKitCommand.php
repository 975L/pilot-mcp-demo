<?php

namespace App\Command;

use c975L\PilotBundle\Repository\CardRepository;
use Dompdf\Dompdf;
use Dompdf\Options;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Twig\Environment;

// The press kit of a release card as a two-page PDF: a title page from the card, then where its steps stand; a plain machine action, no AI involved
#[AsCommand(name: 'app:press-kit', description: 'Writes the press kit PDF of a release card into a folder')]
final class PressKitCommand
{
    public const string FILE = 'press-kit.pdf';

    // The formats a note may name, as the title page shows them
    private const array FORMATS = ['hardcover' => 'Hardcover', 'paperback' => 'Paperback', 'ebook' => 'Ebook', 'audiobook' => 'Audiobook'];

    public function __construct(
        private readonly CardRepository $cardRepository,
        private readonly Environment $twig,
    ) {
    }

    // Renders the card into the PDF, then names the folder and the file for the runner to show them under the step
    public function __invoke(OutputInterface $output, #[Argument('Id of the card')] int $card, #[Argument('Folder the PDF is written to')] string $dir): int
    {
        $found = $this->cardRepository->find($card);
        if (null === $found) {
            $output->writeln(\sprintf('No card %d.', $card));

            return Command::FAILURE;
        }

        $output->writeln('PROGRESS 30 Laying out the press kit');
        $html = $this->twig->render('press_kit/press_kit.html.twig', [
            'title' => (string) preg_replace('/^Release\s+/i', '', $found->getTitle()),
            'blurb' => (string) $found->getNote(),
            'due' => $found->getDue(),
            'formats' => self::formats((string) $found->getNote()),
            'steps' => $found->getSteps(),
            'space' => $found->getProject()->getName(),
        ]);

        $output->writeln('PROGRESS 70 Writing the PDF');
        $dompdf = new Dompdf(new Options()->setIsRemoteEnabled(false));
        $dompdf->loadHtml($html);
        $dompdf->setPaper('A4');
        $dompdf->render();

        $folder = rtrim($dir, '/');
        new Filesystem()->dumpFile($folder . '/' . self::FILE, (string) $dompdf->output());
        $output->writeln('FILE ' . $folder);
        $output->writeln('FILE ' . $folder . '/' . self::FILE);

        return Command::SUCCESS;
    }

    // The formats the note names, in the order of FORMATS
    /** @return list<string> */
    public static function formats(string $note): array
    {
        $formats = [];
        foreach (self::FORMATS as $word => $label) {
            if (1 === preg_match('/\b' . $word . 's?\b/i', $note)) {
                $formats[] = $label;
            }
        }

        return $formats;
    }
}
