<?php declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1618893588AlterTransactionTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1618893588;
    }

    public function update(Connection $connection): void
    {
        // implement update
        $isTableExists	= $connection->executeQuery('
            SELECT COUNT(*) as exists_tbl
            FROM information_schema.tables
            WHERE table_name IN ("novalnet_transaction_details")
            AND table_schema = database()
        ')->fetch();
        
        if(!empty($isTableExists['exists_tbl']))
        {
			$isColumnExists	= $connection->fetchColumn('SHOW COLUMNS FROM `novalnet_transaction_details` LIKE "lang"');
			
			if($isColumnExists)
			{
				$connection->exec('
					ALTER TABLE `novalnet_transaction_details`
					ADD `currency` VARCHAR(11) DEFAULT NULL COMMENT "Transaction currency",
					ADD `refunded_amount` INT(11) UNSIGNED DEFAULT 0 COMMENT "Refunded amount",
					ADD `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Created date",
					ADD `updated_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Updated date",
					DROP COLUMN `lang`;
				');
			}
		}
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
