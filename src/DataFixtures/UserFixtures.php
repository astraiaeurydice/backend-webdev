<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class UserFixtures extends Fixture
{
    private UserPasswordHasherInterface $passwordHasher;

    public function __construct(UserPasswordHasherInterface $passwordHasher)
    {
        $this->passwordHasher = $passwordHasher;
    }

    public function load(ObjectManager $manager): void
    {
        // Admin account
        $admin = new User();
        $admin->setFirstName('Superrr');
        $admin->setLastName('Adminn');
        $admin->setUsername('adminako');
        $admin->setEmail('admin@ako.com');
        $admin->setPhoneNumber('09123456789');
        $admin->setRoles(['ROLE_ADMIN']);
        $admin->setStatus('active');
        $admin->setIsVerified(true);
        $admin->setPassword($this->passwordHasher->hashPassword($admin, 'admin123'));
        $manager->persist($admin);



        $manager->flush();  
    }
}
