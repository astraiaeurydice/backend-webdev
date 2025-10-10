<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251010041726 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE `group` (id INT AUTO_INCREMENT NOT NULL, supplier_id INT NOT NULL, name VARCHAR(255) NOT NULL, debut_year VARCHAR(100) DEFAULT NULL, status VARCHAR(20) NOT NULL, INDEX IDX_6DC044C52ADD6D8C (supplier_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE `group` ADD CONSTRAINT FK_6DC044C52ADD6D8C FOREIGN KEY (supplier_id) REFERENCES supplier (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE product ADD group_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD CONSTRAINT FK_D34A04ADFE54D947 FOREIGN KEY (group_id) REFERENCES `group` (id) ON DELETE SET NULL');
        $this->addSql('CREATE INDEX IDX_D34A04ADFE54D947 ON product (group_id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE product DROP FOREIGN KEY FK_D34A04ADFE54D947');
        $this->addSql('ALTER TABLE `group` DROP FOREIGN KEY FK_6DC044C52ADD6D8C');
        $this->addSql('DROP TABLE `group`');
        $this->addSql('DROP INDEX IDX_D34A04ADFE54D947 ON product');
        $this->addSql('ALTER TABLE product DROP group_id');
    }
}
