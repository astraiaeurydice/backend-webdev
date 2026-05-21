<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260520120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add receipt_number to custom_order for grouped checkout receipts';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE custom_order ADD receipt_number VARCHAR(64) DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_CUSTOM_ORDER_RECEIPT ON custom_order (receipt_number)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX IDX_CUSTOM_ORDER_RECEIPT ON custom_order');
        $this->addSql('ALTER TABLE custom_order DROP receipt_number');
    }
}
