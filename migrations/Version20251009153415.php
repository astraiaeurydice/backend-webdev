<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251009153415 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stock_request ADD notes LONGTEXT DEFAULT NULL, ADD unit_price NUMERIC(10, 2) DEFAULT NULL, ADD total_price NUMERIC(10, 2) DEFAULT NULL, CHANGE status status VARCHAR(50) DEFAULT \'pending\' NOT NULL');
        $this->addSql('ALTER TABLE supplier CHANGE phone phone VARCHAR(50) DEFAULT NULL, CHANGE address address LONGTEXT DEFAULT NULL, CHANGE status status VARCHAR(20) NOT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE stock_request DROP notes, DROP unit_price, DROP total_price, CHANGE status status VARCHAR(50) NOT NULL');
        $this->addSql('ALTER TABLE supplier CHANGE phone phone VARCHAR(20) DEFAULT NULL, CHANGE address address VARCHAR(255) DEFAULT NULL, CHANGE status status VARCHAR(100) DEFAULT NULL');
    }
}
