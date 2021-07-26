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

use DateTimeInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class NovalnetPaymentTransactionEntity extends Entity
{
    use EntityIdTrait;
    
    /**
     * @var string
     */
    protected $id;
    
    /**
     * @var int
     */
    protected $tid;
    
    /**
     * @var string
     */
    protected $paymentType;
    
    /**
     * @var int
     */
    protected $amount;
    
    /**
     * @var string
     */
    protected $currency;
    
    /**
     * @var int
     */
    protected $paidAmount;
    
    /**
     * @var int
     */
    protected $refundedAmount;
    
    /**
     * @var string
     */
    protected $gatewayStatus;
    
    /**
     * @var string
     */
    protected $orderNo;
    
    /**
     * @var string
     */
    protected $customerNo;
    
    /**
     * @var string
     */
    protected $additionalDetails;

    public function getTid(): int
    {
        return $this->tid;
    }

    public function setTid(int $tid): void
    {
        $this->tid = $tid;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getPaymentType(): string
    {
        return $this->paymentType;
    }

    public function setPaymentType(string $paymentType): void
    {
        $this->paymentType = $paymentType;
    }
    
    public function getAmount(): ?int
    {
        return (int) $this->amount;
    }

    public function setAmount(int $amount): void
    {
        $this->amount = $amount;
    }
    
    public function getCurrency(): ?string
    {
        return (string) $this->currency;
    }

    public function setCurrency(string $currency): void
    {
        $this->currency = $currency;
    }
    
    public function getPaidAmount(): ?int
    {
        return (int) $this->paidAmount;
    }

    public function setPaidAmount(int $paidAmount): void
    {
        $this->paidAmount = $paidAmount;
    }
    
    public function getRefundedAmount(): ?int
    {
        return (int) $this->refundedAmount;
    }

    public function setRefundedAmount(int $refundedAmount): void
    {
        $this->refundedAmount = $refundedAmount;
    }
    
    public function getGatewayStatus(): string
    {
        return $this->gatewayStatus;
    }

    public function setGatewayStatus(string $gatewayStatus): void
    {
        $this->gatewayStatus = $gatewayStatus;
    }
    
    public function getOrderNo(): string
    {
        return $this->orderNo;
    }

    public function setOrderNo(string $orderNo): void
    {
        $this->orderNo = $orderNo;
    }
    
    public function getCustomerNo(): string
    {
        return $this->customerNo;
    }

    public function setCustomerNo(string $customerNo): void
    {
        $this->customerNo = $customerNo;
    }
    
    public function getAdditionalDetails(): ?string
    {
        return $this->additionalDetails;
    }

    public function setAdditionalDetails(string $additionalDetails): void
    {
        $this->additionalDetails = $additionalDetails;
    }
}
