<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * The only way to grant `isPlatformAdmin` to an account that doesn't already have
 * it — deliberately CLI-only (see User::$isPlatformAdmin's own docblock): no HTTP
 * endpoint a company admin (or anyone without the flag already) can reach ever sets
 * this. An *existing* platform admin can also grant/revoke it via
 * PlatformAdminController, mirroring how a company admin can grant `isAdmin` to a
 * peer once one exists — this command is specifically the bootstrap path for when
 * none does yet, same role `app:create-activation-link --admin` plays for the very
 * first company admin.
 */
#[AsCommand(name: 'app:grant-platform-admin', description: 'Grant or revoke platform-admin status on an existing account')]
class GrantPlatformAdminCommand extends Command
{
    public function __construct(private readonly EntityManagerInterface $entityManager)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'Email address of an existing account')
            ->addOption('revoke', null, InputOption::VALUE_NONE, 'Revoke platform-admin status instead of granting it');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        \assert(\is_string($email)); // InputArgument::REQUIRED (not ARRAY mode) — always a string.
        $revoke = (bool) $input->getOption('revoke');

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (null === $user) {
            $io->error(sprintf('No account found for "%s". This command only toggles the flag on an existing account — it never creates one.', $email));

            return Command::FAILURE;
        }

        $user->setPlatformAdmin(!$revoke);
        $this->entityManager->flush();

        $io->success(sprintf(
            '%s is now %sa platform admin.',
            $email,
            $revoke ? 'no longer ' : '',
        ));

        return Command::SUCCESS;
    }
}
