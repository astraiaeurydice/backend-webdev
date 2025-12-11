<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251206081011 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE custom_order (id INT AUTO_INCREMENT NOT NULL, customer_id INT NOT NULL, product_id INT NOT NULL, quantity INT NOT NULL, total_price DOUBLE PRECISION NOT NULL, status VARCHAR(50) DEFAULT \'pending\' NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_36246BF19395C3F3 (customer_id), INDEX IDX_36246BF14584665A (product_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE custom_order ADD CONSTRAINT FK_36246BF19395C3F3 FOREIGN KEY (customer_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE custom_order ADD CONSTRAINT FK_36246BF14584665A FOREIGN KEY (product_id) REFERENCES product (id) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE custom_order DROP FOREIGN KEY FK_36246BF19395C3F3');
        $this->addSql('ALTER TABLE custom_order DROP FOREIGN KEY FK_36246BF14584665A');
        $this->addSql('DROP TABLE custom_order');
    }
}
