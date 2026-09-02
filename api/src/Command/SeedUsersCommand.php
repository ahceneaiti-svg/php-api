<?php

namespace App\Command;

use App\Repository\UserRepository;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:users:seed',
    description: 'Generate a sample set of users in Redis',
)]
class SeedUsersCommand extends Command
{
    private const FIRST = [
        'Ada', 'Alan', 'Grace', 'Linus', 'Katherine', 'Dennis',
        'Barbara', 'Edsger', 'Margaret', 'Donald', 'Radia', 'Ken',
    ];
    private const LAST = [
        'Lovelace', 'Turing', 'Hopper', 'Torvalds', 'Johnson', 'Ritchie',
        'Liskov', 'Dijkstra', 'Hamilton', 'Knuth', 'Perlman', 'Thompson',
    ];

    public function __construct(private readonly UserRepository $users)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('count', 'c', InputOption::VALUE_REQUIRED, 'How many users to create', '25')
            ->addOption('fresh', null, InputOption::VALUE_NONE, 'Flush the Redis database before seeding');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = max(1, (int) $input->getOption('count'));

        if (!$this->users->ping()) {
            $io->error('Redis is not reachable.');

            return Command::FAILURE;
        }

        if ($input->getOption('fresh')) {
            $this->users->flushAll();
            $io->warning('Redis database flushed.');
        }

        $created = 0;
        $skipped = 0;

        $io->progressStart($count);
        for ($i = 1; $i <= $count; ++$i) {
            $first = self::FIRST[array_rand(self::FIRST)];
            $last = self::LAST[array_rand(self::LAST)];
            $email = sprintf('%s.%s.%d@example.com', strtolower($first), strtolower($last), $i);

            if (null !== $this->users->findByEmail($email)) {
                ++$skipped;
            } else {
                $this->users->create($first.' '.$last, $email);
                ++$created;
            }

            $io->progressAdvance();
        }
        $io->progressFinish();

        $io->success(sprintf(
            '%d created, %d skipped — %d users total.',
            $created,
            $skipped,
            \count($this->users->findAll()),
        ));

        return Command::SUCCESS;
    }
}
