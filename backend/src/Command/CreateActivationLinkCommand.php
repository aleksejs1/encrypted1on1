<?php

namespace App\Command;

use App\Entity\ActivationToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The one way to create an account right now (see the Phase 4 plan — email
 * delivery and the domain/invite registration modes are later phases). Also
 * how the very first admin gets bootstrapped: `--admin` is just a flag on
 * the same token, not a separate code path.
 */
#[AsCommand(name: 'app:create-activation-link', description: 'Create an activation link for a new account')]
class CreateActivationLinkCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $frontendBaseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address for the new account')
            ->addOption('admin', null, InputOption::VALUE_NONE, 'Grant admin on activation');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        $grantsAdmin = (bool) $input->getOption('admin');

        [$activationToken, $rawToken] = ActivationToken::issue($email, $grantsAdmin);
        $this->entityManager->persist($activationToken);
        $this->entityManager->flush();

        $io->success(sprintf('Activation link created (expires in %d hours):', ActivationToken::TOKEN_TTL_HOURS));
        $io->writeln(sprintf('%s/activate/%s', rtrim($this->frontendBaseUrl, '/'), $rawToken));

        return Command::SUCCESS;
    }
}
