<?php

namespace App\Command;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:set-user-password',
    description: 'Set password (and optional username) for an existing user by email'
)]
class SetUserPasswordCommand extends Command
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private UserPasswordHasherInterface $passwordHasher
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('email', InputArgument::REQUIRED, 'User email')
            ->addArgument('password', InputArgument::REQUIRED, 'New password')
            ->addArgument('username', InputArgument::OPTIONAL, 'New username (optional)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');
        $username = $input->getArgument('username');

        $user = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if (!$user) {
            $output->writeln("No user found with email: {$email}");
            return Command::FAILURE;
        }

        if ($username !== null) {
            $username = (string) $username;
            $taken = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
            if ($taken && $taken->getId() !== $user->getId()) {
                $output->writeln("Username already taken: {$username}");
                return Command::FAILURE;
            }
            $user->setUsername($username);
        }

        $user->setPassword($this->passwordHasher->hashPassword($user, $password));
        $user->setIsVerified(true);
        $user->setStatus('active');
        $this->entityManager->flush();

        $output->writeln("Password updated for: {$email}" . ($username ? " (username: {$username})" : ''));
        return Command::SUCCESS;
    }
}
