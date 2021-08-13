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

namespace Novalnet\NovalnetPayment\Content\PaymentToken;

use DateTimeInterface;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityIdTrait;

class NovalnetPaymentTokenEntity extends Entity
{
    use EntityIdTrait;
    
    /**
     * @var string
     */
    protected $id;
    
    /**
     * @var string
     */
    protected $paymentType;
    
    /**
     * @var DateTimeInterface
     */
    protected $expiryDate;
    
    /**
     * @var CustomerEntity
     */
    protected $customer;
    
    /**
     * @var string
     */
    protected $customerId;
    
    /**
     * @var string
     */
    protected $accountData;
    
    /**
     * @var string
     */
    protected $type;
    
    /**
     * @var string
     */
    protected $token;
    
    /**
     * @var int
     */
    protected $tid;
    
    public function getExpiryDate(): ?DateTimeInterface
    {
        return $this->expiryDate;
    }

    public function setExpiryDate(DateTimeInterface $expiryDate): void
    {
        $this->expiryDate = $expiryDate;
    }

    public function getTid(): int
    {
        return $this->tid;
    }

    public function setTid(int $tid): void
    {
        $this->tid = $tid;
    }

    public function getToken(): string
    {
        return $this->token;
    }

    public function setToken(string $token): void
    {
        $this->token = $token;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getPaymentType(): string
    {
        return $this->paymentType;
    }

    public function setPaymentType(string $paymentType): void
    {
        $this->paymentType = $paymentType;
    }

    public function getAccountData(): string
    {
        return $this->accountData;
    }

    public function setAccountData(string $accountData): void
    {
        $this->accountData = $accountData;
    }

    public function getCustomer(): ?CustomerEntity
    {
        return $this->customer;
    }

    public function setCustomer(CustomerEntity $customer): void
    {
        $this->customer = $customer;
    }

    public function setCustomerId(string $customerId): void
    {
        $this->customerId = $customerId;
    }

    public function getCustomerId(): string
    {
        return $this->customerId;
    }
    
    public function setId(string $id): void
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }
}
