<?php

declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Defaults;

class Migration1678945880AddNovalnetPaymentTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1678945880;
    }

    public function update(Connection $connection): void
    {
        $connection->executeStatement('
            CREATE TABLE IF NOT EXISTS `novalnet_transaction_details` (
              `id` binary(16) NOT NULL,
              `tid` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT "Novalnet Transaction Reference ID",
              `payment_type` VARCHAR(50) DEFAULT NULL COMMENT "Executed Payment type of this order",
              `amount` INT(11) UNSIGNED DEFAULT 0 COMMENT "Transaction amount",
              `currency` VARCHAR(11) DEFAULT NULL COMMENT "Transaction currency",
              `paid_amount` INT(11)  UNSIGNED DEFAULT 0 COMMENT "Paid amount",
              `refunded_amount` INT(11) UNSIGNED DEFAULT 0 COMMENT "Refunded amount",
              `gateway_status` VARCHAR(30) DEFAULT NULL COMMENT "Novalnet transaction status",
              `order_no` VARCHAR(64) DEFAULT NULL COMMENT "Order ID from shop",
              `customer_no` VARCHAR(255) COMMENT "Customer Number from shop",
              `additional_details` LONGTEXT DEFAULT NULL COMMENT "Additional details",
              `token_info` VARCHAR(255) DEFAULT NULL COMMENT "Transaction Token",
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Created date",
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Updated date",
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Novalnet Transaction History"
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
