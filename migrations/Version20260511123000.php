<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260511123000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add avatar URL, conversations, and conversation messages';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE user ADD avatar_url VARCHAR(255) DEFAULT NULL');
        $this->addSql('CREATE TABLE conversation (id INT AUTO_INCREMENT NOT NULL, owner_id INT NOT NULL, participant_id INT DEFAULT NULL, name VARCHAR(120) NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_53167D9D7E3C61F9 (owner_id), INDEX IDX_53167D9D9D1C3019 (participant_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE conversation_message (id INT AUTO_INCREMENT NOT NULL, conversation_id INT NOT NULL, sender_id INT DEFAULT NULL, message LONGTEXT NOT NULL, created_at DATETIME NOT NULL, INDEX IDX_97B4118F8E5A16B4 (conversation_id), INDEX IDX_97B4118FF624B39D (sender_id), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_53167D9D7E3C61F9 FOREIGN KEY (owner_id) REFERENCES user (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation ADD CONSTRAINT FK_53167D9D9D1C3019 FOREIGN KEY (participant_id) REFERENCES user (id) ON DELETE SET NULL');
        $this->addSql('ALTER TABLE conversation_message ADD CONSTRAINT FK_97B4118F8E5A16B4 FOREIGN KEY (conversation_id) REFERENCES conversation (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE conversation_message ADD CONSTRAINT FK_97B4118FF624B39D FOREIGN KEY (sender_id) REFERENCES user (id) ON DELETE SET NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_53167D9D7E3C61F9');
        $this->addSql('ALTER TABLE conversation DROP FOREIGN KEY FK_53167D9D9D1C3019');
        $this->addSql('ALTER TABLE conversation_message DROP FOREIGN KEY FK_97B4118F8E5A16B4');
        $this->addSql('ALTER TABLE conversation_message DROP FOREIGN KEY FK_97B4118FF624B39D');
        $this->addSql('DROP TABLE conversation');
        $this->addSql('DROP TABLE conversation_message');
        $this->addSql('ALTER TABLE user DROP avatar_url');
    }
}
