<?php

namespace SyliusMolliePlugin\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

class Version20240627164350 extends AbstractMigration
{
    //table name
    private const TABLE_NAME = 'mollie_configuration';

    public function getDescription(): string
    {
        return '';
    }

    /**
     * Delete giropay payment method from mollie configuration table
     *
     * @param Schema $schema
     * @return void
     */
    public function up(Schema $schema): void
    {
        $this->addSql('DELETE FROM ' . self::TABLE_NAME . ' WHERE method_id = \'giropay\'');
    }

    public function down(Schema $schema): void
    {
    }
}
