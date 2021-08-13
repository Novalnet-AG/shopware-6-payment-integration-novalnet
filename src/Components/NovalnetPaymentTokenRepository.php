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

namespace Novalnet\NovalnetPayment\Components;

use DateTime;
use Novalnet\NovalnetPayment\Content\PaymentToken\NovalnetPaymentTokenEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * NovalnetPaymentTokenRepository Class.
 */
class NovalnetPaymentTokenRepository
{
    /**
     * @var EntityRepositoryInterface
     */
    private $tokenRepository;

    /**
     * Constructs a `NovalnetPaymentTokenRepository`
     *
     * @param EntityRepositoryInterface $tokenRepository
     */
    public function __construct(EntityRepositoryInterface $tokenRepository)
    {
        $this->tokenRepository = $tokenRepository;
    }

    /**
     * Insert/update the given paymennt token details
     *
     * @param SalesChannelContext $salesChannelContext
     * @param array $data
     */
    public function savePaymentToken(SalesChannelContext $salesChannelContext, array $data): void
    {
        $result = null;
        if (!is_null($salesChannelContext->getCustomer())) {
            $card = $this->getExistingPaymentToken($salesChannelContext, $data);
            if ($card === null) {
                $data ['id'] = Uuid::randomHex();
            } else {
                $data ['id'] = $card->getId();
            }
            $data ['customerId'] = $salesChannelContext->getCustomer()->getId();

            $this->tokenRepository->upsert([$data], $salesChannelContext->getContext());
        }
    }

    /**
     * Get existing paymennt token details
     *
     * @param SalesChannelContext $salesChannelContext
     * @param array $data
     *
     * @return NovalnetPaymentTokenEntity|null
     */
    public function getExistingPaymentToken(SalesChannelContext $salesChannelContext, array $data): ?NovalnetPaymentTokenEntity
    {
        $result = null;
        if (!is_null($salesChannelContext->getCustomer())) {
            $criteria = new Criteria();

            $criteria->addFilter(
                new EqualsFilter('novalnet_payment_token.customerId', $salesChannelContext->getCustomer()->getId())
            );

            if (! empty($data['accountData'])) {
                $criteria->addFilter(
                    new EqualsFilter('novalnet_payment_token.accountData', $data['accountData']),
                    new EqualsFilter('novalnet_payment_token.paymentType', $data['paymentType'])
                );
            } else {
                $criteria->addFilter(
                    new EqualsFilter('novalnet_payment_token.token', $data['token'])
                );
            }

            $result = $this->tokenRepository->search($criteria, $salesChannelContext->getContext())->first();
        }
        return $result;
    }

    /**
     * Remove the given paymennt token details
     *
     * @param SalesChannelContext $salesChannelContext
     * @param array $data
     */
    public function removePaymentToken(SalesChannelContext $salesChannelContext, array $data): void
    {
        $card = $this->getExistingPaymentToken($salesChannelContext, $data);

        if (!is_null($card)) {
            $this->tokenRepository->delete([['id' => $card->getId()]], $salesChannelContext->getContext());
        }
    }

    /**
     * Get last inserted paymennt token details
     *
     * @param SalesChannelContext $salesChannelContext
     * @param array $additionalFilter
     *
     * @return NovalnetPaymentTokenEntity|null
     */
    public function getLastPaymentToken(SalesChannelContext $salesChannelContext, $additionalFilter = []): ?NovalnetPaymentTokenEntity
    {
        $result = null;
        if (!is_null($salesChannelContext->getCustomer())) {
            $criteria = new Criteria();

            $criteria->addFilter(
                new EqualsFilter('novalnet_payment_token.customerId', $salesChannelContext->getCustomer()->getId())
            );

            foreach ($additionalFilter as $key => $value) {
				$criteria->addFilter(new EqualsFilter('novalnet_payment_token.' . $key, $value));
            }
            
            $criteria->addSorting(
                new FieldSorting('updatedAt', FieldSorting::DESCENDING)
            );
            
            $criteria->addSorting(
                new FieldSorting('createdAt', FieldSorting::DESCENDING)
            );
            $result = $this->tokenRepository->search($criteria, $salesChannelContext->getContext())->first();
        }
        return $result;
    }

    /**
     * Get paymennt token details
     *
     * @param SalesChannelContext $salesChannelContext
     * @param array $additionalFilter
     *
     * @return EntitySearchResult|null
     */
    public function getPaymentTokens(SalesChannelContext $salesChannelContext, array $additionalFilter = []): ?EntitySearchResult
    {
        $result = null;
        if (!is_null($salesChannelContext->getCustomer())) {
            $criteria = new Criteria();

            $criteria->addFilter(
                new EqualsFilter('novalnet_payment_token.customerId', $salesChannelContext->getCustomer()->getId())
            );

            foreach ($additionalFilter as $key => $value) {
                $criteria->addFilter(new EqualsFilter('novalnet_payment_token.' . $key, $value));
            }
			
			$criteria->addSorting(
                new FieldSorting('updatedAt', FieldSorting::DESCENDING)
            );
            	
            $criteria->addSorting(
                new FieldSorting('createdAt', FieldSorting::DESCENDING)
            );
            $result = $this->tokenRepository->search($criteria, $salesChannelContext->getContext());
        }
        return $result;
    }
}
