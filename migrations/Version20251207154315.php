<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20251207154315 extends AbstractMigration
{
    public function getDescription(): string
    {
        return '';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE trade_post (id INT AUTO_INCREMENT NOT NULL, user_id INT NOT NULL, item_offered VARCHAR(255) NOT NULL, item_offered_description LONGTEXT DEFAULT NULL, item_offered_image VARCHAR(255) DEFAULT NULL, item_wanted VARCHAR(255) NOT NULL, item_wanted_description LONGTEXT DEFAULT NULL, status VARCHAR(50) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_38711B76A76ED395 (user_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE trade_request (id INT AUTO_INCREMENT NOT NULL, trade_post_id INT NOT NULL, requester_id INT NOT NULL, status VARCHAR(50) NOT NULL, message LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, INDEX IDX_C817D769B8095BDA (trade_post_id), INDEX IDX_C817D769ED442CF4 (requester_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE trade_transaction (id INT AUTO_INCREMENT NOT NULL, trade_request_id INT NOT NULL, owner_id INT NOT NULL, requester_id INT NOT NULL, verified_by_id INT DEFAULT NULL, status VARCHAR(50) NOT NULL, verified_at DATETIME DEFAULT NULL, admin_notes LONGTEXT DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, UNIQUE INDEX UNIQ_ACA2084F6CEDE348 (trade_request_id), INDEX IDX_ACA2084F7E3C61F9 (owner_id), INDEX IDX_ACA2084FED442CF4 (requester_id), INDEX IDX_ACA2084F69F4B775 (verified_by_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE trade_post ADD CONSTRAINT FK_38711B76A76ED395 FOREIGN KEY (user_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE trade_request ADD CONSTRAINT FK_C817D769B8095BDA FOREIGN KEY (trade_post_id) REFERENCES trade_post (id)');
        $this->addSql('ALTER TABLE trade_request ADD CONSTRAINT FK_C817D769ED442CF4 FOREIGN KEY (requester_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE trade_transaction ADD CONSTRAINT FK_ACA2084F6CEDE348 FOREIGN KEY (trade_request_id) REFERENCES trade_request (id)');
        $this->addSql('ALTER TABLE trade_transaction ADD CONSTRAINT FK_ACA2084F7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE trade_transaction ADD CONSTRAINT FK_ACA2084FED442CF4 FOREIGN KEY (requester_id) REFERENCES user (id)');
        $this->addSql('ALTER TABLE trade_transaction ADD CONSTRAINT FK_ACA2084F69F4B775 FOREIGN KEY (verified_by_id) REFERENCES user (id)');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE trade_post DROP FOREIGN KEY FK_38711B76A76ED395');
        $this->addSql('ALTER TABLE trade_request DROP FOREIGN KEY FK_C817D769B8095BDA');
        $this->addSql('ALTER TABLE trade_request DROP FOREIGN KEY FK_C817D769ED442CF4');
        $this->addSql('ALTER TABLE trade_transaction DROP FOREIGN KEY FK_ACA2084F6CEDE348');
        $this->addSql('ALTER TABLE trade_transaction DROP FOREIGN KEY FK_ACA2084F7E3C61F9');
        $this->addSql('ALTER TABLE trade_transaction DROP FOREIGN KEY FK_ACA2084FED442CF4');
        $this->addSql('ALTER TABLE trade_transaction DROP FOREIGN KEY FK_ACA2084F69F4B775');
        $this->addSql('DROP TABLE trade_post');
        $this->addSql('DROP TABLE trade_request');
        $this->addSql('DROP TABLE trade_transaction');
    }
}
