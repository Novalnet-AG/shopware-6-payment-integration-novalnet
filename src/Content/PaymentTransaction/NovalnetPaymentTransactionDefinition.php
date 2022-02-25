<?php

/**
 * Novalnet payment plugin
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to Novalnet End User License Agreement
 *
 * DISCLAIMER
 *
 * If you wish to customize Novalnet payment extension for your needs,
 * please contact technic@novalnet.de for more information.
 *
 * @category    Novalnet
 * @package     NovalnetPayment
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
 
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Content\PaymentTransaction;

use Shopware\Core\Framework\DataAbstractionLayer\EntityDefinition;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\PrimaryKey;
use Shopware\Core\Framework\DataAbstractionLayer\Field\Flag\Required;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IdField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\StringField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\IntField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\FloatField;
use Shopware\Core\System\NumberRange\DataAbstractionLayer\NumberRangeField;
use Shopware\Core\Framework\DataAbstractionLayer\Field\LongTextField;
use Shopware\Core\Framework\DataAbstractionLayer\FieldCollection;

class NovalnetPaymentTransactionDefinition extends EntityDefinition
{
    public function getEntityName(): string
    {
        return 'novalnet_transaction_details';
    }

    public function getCollectionClass(): string
    {
        return NovalnetPaymentTransactionCollection::class;
    }

    public function getEntityClass(): string
    {
        return NovalnetPaymentTransactionEntity::class;
    }

    protected function defineFields(): FieldCollection
    {
        return new FieldCollection([
            (new IdField('id', 'id'))->setFlags(new PrimaryKey(), new Required()),

            (new IntField('tid', 'tid'))->setFlags(new Required()),
            (new StringField('payment_type', 'paymentType'))->setFlags(new Required()),
            (new FloatField('amount', 'amount'))->setFlags(),
            (new StringField('currency', 'currency'))->setFlags(),
            (new FloatField('paid_amount', 'paidAmount'))->setFlags(),
            (new FloatField('refunded_amount', 'refundedAmount'))->setFlags(),
            (new StringField('gateway_status', 'gatewayStatus'))->setFlags(new Required()),
            (new NumberRangeField('order_no', 'orderNo'))->setFlags(new Required()),
            (new NumberRangeField('customer_no', 'customerNo'))->setFlags(new Required()),
            (new LongTextField('additional_details', 'additionalDetails'))->setFlags(),
        ]);
    }
}
