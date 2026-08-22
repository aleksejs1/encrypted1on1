<?php

namespace App\Command;

use App\Entity\PasswordResetToken;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Mirrors CreateActivationLinkCommand's shape and purpose exactly, one step
 * later in the account lifecycle: a dev/e2e-only way to get a real,
 * backend-issued PasswordResetToken (the same one POST /api/password-reset
 * issues) without a real email round-trip. Needed because
 * docker-compose.e2e.yml's stack runs with MAILER_DSN=null://null and no
 * Mailpit — Playwright's password-reset spec has no other way to obtain the
 * raw token, which (correctly) only ever exists in the token itself, never
 * stored server-side.
 */
#[AsCommand(name: 'app:create-password-reset-link', description: 'Create a password-reset link for an existing account')]
class CreatePasswordResetLinkCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly string $frontendBaseUrl,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email address of the existing account');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $email = $input->getArgument('email');
        \assert(\is_string($email)); // InputArgument::REQUIRED (not ARRAY mode) — always a string.

        [$resetToken, $rawToken] = PasswordResetToken::issue($email);
        $this->entityManager->persist($resetToken);
        $this->entityManager->flush();

        $io->success(sprintf('Password reset link created (expires in %d hours):', PasswordResetToken::TOKEN_TTL_HOURS));
        $io->writeln(sprintf('%s/reset-password/%s', rtrim($this->frontendBaseUrl, '/'), $rawToken));

        return Command::SUCCESS;
    }
}
