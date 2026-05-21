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
    name: 'app:create-admin',
    description: 'Create a new admin user (ROLE_ADMIN)'
)]
class CreateAdminCommand extends Command
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
            ->addArgument('email', InputArgument::REQUIRED, 'Admin email')
            ->addArgument('password', InputArgument::REQUIRED, 'Admin password')
            ->addArgument('username', InputArgument::REQUIRED, 'Admin username')
            ->addArgument('firstName', InputArgument::OPTIONAL, 'First name', 'Admin')
            ->addArgument('lastName', InputArgument::OPTIONAL, 'Last name', 'User')
            ->addArgument('phoneNumber', InputArgument::OPTIONAL, 'Phone number', 'N/A');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = (string) $input->getArgument('email');
        $password = (string) $input->getArgument('password');
        $username = (string) $input->getArgument('username');
        $firstName = (string) $input->getArgument('firstName');
        $lastName = (string) $input->getArgument('lastName');
        $phoneNumber = (string) $input->getArgument('phoneNumber');

        $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $email]);
        if ($existing) {
            $output->writeln("User already exists with email: {$email}");
            return Command::FAILURE;
        }

        $existingUsername = $this->entityManager->getRepository(User::class)->findOneBy(['username' => $username]);
        if ($existingUsername) {
            $output->writeln("User already exists with username: {$username}");
            return Command::FAILURE;
        }

        $user = new User();
        $user->setEmail($email);
        $user->setUsername($username);
        $user->setFirstName($firstName ?: 'Admin');
        $user->setLastName($lastName ?: 'User');
        $user->setPhoneNumber($phoneNumber ?: 'N/A');
        $user->setRoles(['ROLE_ADMIN']);
        $user->setStatus('active');
        $user->setIsVerified(true);
        $user->setVerificationToken(null);

        $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
        $user->setPassword($hashedPassword);

        $this->entityManager->persist($user);
        $this->entityManager->flush();

        $output->writeln("Admin created: {$email} (username: {$username})");
        return Command::SUCCESS;
    }
}

