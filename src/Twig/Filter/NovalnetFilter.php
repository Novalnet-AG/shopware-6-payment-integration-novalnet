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
 * If you wish to customize Novalnet payment extension for your needs, please contact technic@novalnet.de for more information.
 *
 * @category    Novalnet
 * @package     NovalnetPayment
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */

declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Twig\Filter;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Shopware\Core\Framework\Context;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig extension relate to PHP code and used by the profiler and the default exception templates.
 */
class NovalnetFilter extends AbstractExtension
{
    /**
     * Constructs a `NovalnetFilter`
     *
     * @param NovalnetHelper $helper
     * @param NovalnetOrderTransactionHelper $transactionHelper
     */
    public function __construct(private readonly NovalnetHelper $helper, private readonly NovalnetOrderTransactionHelper $transactionHelper)
    {
    }

    /**
     * Get Filters
     *
     * @return array
     */
    public function getFilters(): array
    {
        return [
            new TwigFilter('novalnetPayment', [$this->helper, 'getNovalnetIframeUrl']),
            new TwigFilter('getNovalnetInstalmentInfo', [$this, 'getNovalnetInstalmentInfo']),
            new TwigFilter('getPaymentName', [$this->transactionHelper, 'getPaymentName']),
            new TwigFilter('getPaymentDetails', [$this, 'getPaymentDetails']),
            new TwigFilter('getNovalnetErrorMessage', [$this->helper, 'getNovalnetErrorMessage']),
            new TwigFilter('getQrImage', [$this->transactionHelper, 'getQrImage']),
        ];
    }

    /**
     * Return the novalnet instalment information.
     *
     * @param Context $context
     * @param string $orderNumber
     * @param bool $paymentType
     *
     * @return array
     */
    public function getNovalnetInstalmentInfo(Context $context, string $orderNumber, bool $paymentType = false): array
    {
        $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderNumber, $context);
        if (!empty($transactionData)) {
            if ($transactionData->getGatewayStatus() === 'CONFIRMED' && $paymentType === false) {
                return $this->helper->unserializeData($transactionData->getAdditionalDetails());
            } elseif ($paymentType === true) {
                $paymentType = ['paymentName' => $this->helper->getUpdatedPaymentType($transactionData->getPaymentType())];

                return $paymentType;
            }
        }

        return [];
    }

    /**
     * Get Payment Details
     *
     * @param Context $context
     * @param string $customerNo
     *
     * @return array
     */
    public function getPaymentDetails(Context $context, string $customerNo): ?array
    {
        $customerDetails = $this->transactionHelper->getCustomerPaymentDetails($context, $customerNo);

        return $customerDetails;
    }
}
