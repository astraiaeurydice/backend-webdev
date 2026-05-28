<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260528121000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add fcm_token column to user table for Firebase push delivery';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD fcm_token VARCHAR(512) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user DROP fcm_token');
    }
}

