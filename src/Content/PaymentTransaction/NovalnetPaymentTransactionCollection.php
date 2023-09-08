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

use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;

/**
 * @method void                         add(NovalnetPaymentTransactionEntity $entity)
 * @method void                         set(string $key, NovalnetPaymentTransactionEntity $entity)
 * @method NovalnetPaymentTransactionEntity[]    getIterator()
 * @method NovalnetPaymentTransactionEntity[]    getElements()
 * @method null|NovalnetPaymentTransactionEntity get(string $key)
 * @method null|NovalnetPaymentTransactionEntity first()
 * @method null|NovalnetPaymentTransactionEntity last()
 */
class NovalnetPaymentTransactionCollection extends EntityCollection
{
    protected function getExpectedClass(): string
    {
        return NovalnetPaymentTransactionEntity::class;
    }
}
