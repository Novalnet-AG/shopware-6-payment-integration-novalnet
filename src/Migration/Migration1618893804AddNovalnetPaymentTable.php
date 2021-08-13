<?php declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;

class Migration1618893804AddNovalnetPaymentTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1618893804;
    }

    public function update(Connection $connection): void
    {
        $connection->executeUpdate('
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
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Created date",
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Updated date",
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Novalnet Transaction History"
        ');
        
        $connection->executeUpdate('
            CREATE TABLE IF NOT EXISTS `novalnet_payment_token` (
			  `id` binary(16) NOT NULL,
			  `customer_id` binary(16) DEFAULT NULL COMMENT "Customer ID",
			  `payment_type` varchar(255) DEFAULT NULL COMMENT "Payment Type",
			  `account_data` varchar(255) DEFAULT NULL COMMENT "Account information",
			  `type` varchar(32) DEFAULT NULL COMMENT "token type",
			  `token` varchar(256) DEFAULT NULL COMMENT "token",
			  `tid` bigint(20) DEFAULT NULL COMMENT "tid",
			  `expiry_date` datetime DEFAULT NULL COMMENT "Expiry Date",
			  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Created date",
			  `updated_at` datetime DEFAULT NULL COMMENT "Updated date",
			  PRIMARY KEY (`id`),
			  KEY `customer_id` (`customer_id`),
			  KEY `payment_type` (`payment_type`),
			  KEY `account_data` (`account_data`),
			  KEY `type` (`type`),
			  KEY `expiry_date` (`expiry_date`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Novalnet Payment Token"
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
}
