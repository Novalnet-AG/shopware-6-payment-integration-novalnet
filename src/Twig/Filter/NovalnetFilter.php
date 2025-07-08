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
use Novalnet\NovalnetPayment\Helper\NovalnetValidator;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;

/**
 * Twig extension relate to PHP code and used by the profiler and the default exception templates.
 */
class NovalnetFilter extends AbstractExtension
{
    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var NovalnetValidator
     */
    private $validator;

    /**
     * @var NovalnetOrderTransactionHelper
     */
    private $transactionHelper;

    /**
     * @var AbstractPaymentMethodRoute
     */
    private $paymentMethodRoute;

    public function __construct(
        NovalnetHelper $helper,
        NovalnetValidator $validator,
        NovalnetOrderTransactionHelper $transactionHelper,
        AbstractPaymentMethodRoute $paymentMethodRoute
    ) {
        $this->validator = $validator;
        $this->helper    = $helper;
        $this->transactionHelper  = $transactionHelper;
        $this->paymentMethodRoute = $paymentMethodRoute;
    }

    public function getFilters()
    {
        return [
            new TwigFilter('getCountry', [$this->helper, 'getCountryFromId']),
            new TwigFilter('isGuaranteeAvailable', [$this->validator, 'isGuaranteeAvailable']),
            new TwigFilter('getTokens', [$this->helper, 'getStoredData']),
            new TwigFilter('enabledPayments', [$this, 'getEnabledPayments']),
            new TwigFilter('getLocaleCodeFromContext', [$this->helper, 'getLocaleCodeFromContext']),
            new TwigFilter('getNovalnetErrorMessage', [$this->helper, 'getNovalnetErrorMessage']),
            new TwigFilter('jsonEncode', [$this->helper, 'serializeData']),
            new TwigFilter('getNovalnetInstalmentInfo', [$this->transactionHelper, 'getNovalnetInstalmentInfo']),
            new TwigFilter('cashPaymentResponse', [$this, 'cashPaymentResponse']),
            new TwigFilter('isApplePayValid', [$this->helper, 'getApplePayInfo'])
        ];
    }

    /**
     * Return the salesChannel assigned payment
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return PaymentMethodCollection
     */
    public function getEnabledPayments(SalesChannelContext $salesChannelContext): PaymentMethodCollection
    {
        $request = new Request();
        $request->query->set('onlyAvailable', '1');

        return $this->paymentMethodRoute->load($request, $salesChannelContext, new Criteria())->getPaymentMethods();
    }
    
    /*
     * Get Novalnet Cash Payment Resopnse
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    public function cashPaymentResponse(SalesChannelContext $salesChannelContext, string $orderNumber) : ?array
    {
        $paymentdata = []; 

        $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderNumber, $salesChannelContext->getContext());

       if (!empty($transactionData)) {
			$additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
			$paymentdata = isset($additionalDetails['cashpayment']) ? $additionalDetails['cashpayment'] : [];
        }

        return !empty($paymentdata) ? $paymentdata : [];
    }
}
