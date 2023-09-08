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
use Shopware\Core\Checkout\Payment\PaymentMethodCollection;
use Shopware\Core\Checkout\Payment\SalesChannel\AbstractPaymentMethodRoute;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
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
     * @var NovalnetOrderTransactionHelper
     */
    private $transactionHelper;
   
    /**
     * @var AbstractPaymentMethodRoute
     */
    private $paymentMethodRoute;
    
    /**
     * Constructs a `NovalnetFilter`

     * @param NovalnetHelper $helper
     * @param AbstractPaymentMethodRoute $paymentMethodRoute
     * @param NovalnetOrderTransactionHelper $transactionHelper
     *
    */
    public function __construct(
        NovalnetHelper $helper,
        AbstractPaymentMethodRoute $paymentMethodRoute,
        NovalnetOrderTransactionHelper $transactionHelper
    ) {
        $this->helper           = $helper;
        $this->paymentMethodRoute   = $paymentMethodRoute;
        $this->transactionHelper    = $transactionHelper;
    }

    public function getFilters()
    {
        return [
            new TwigFilter('novalnetPaymentHandler', [$this, 'novalnetPaymentHandler']),
            new TwigFilter('getNovalnetComments', [$this, 'getNovalnetComments']),
            new TwigFilter('cashPaymentResponse', [$this, 'cashPaymentResponse']),
            new TwigFilter('getFinishNovalnetComments', [$this->transactionHelper, 'getFinishNovalnetComments']),
            new TwigFilter('getPaymentMethodName', [$this->helper, 'getPaymentMethodName']),
            new TwigFilter('novalnetPayment', [$this->helper, 'getNovalnetIframeUrl']),
            new TwigFilter('shopVersion', [$this->helper, 'getShopVersion']),
            new TwigFilter('getNovalnetInstalmentInfo', [$this, 'getNovalnetInstalmentInfo']),
            new TwigFilter('getPaymentMethodNovalnetName', [$this, 'getPaymentMethodNovalnetName']),
            new TwigFilter('getPaymentName', [$this->transactionHelper, 'getPaymentName']),
            new TwigFilter('getChangedPaymentName', [$this->transactionHelper, 'getChangedPaymentName']),
            new TwigFilter('getNovalnetErrorMessage', [$this->helper, 'getNovalnetErrorMessage']),
        ];
    }

    /**
     * Return the novalnet instalment information.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $orderNumber
     *
     * @return array
     */
    public function getNovalnetInstalmentInfo(SalesChannelContext $salesChannelContext, $orderNumber): array
    {
        $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderNumber, $salesChannelContext->getContext());
        if (!empty($transactionData) && $transactionData->getGatewayStatus() === 'CONFIRMED') {
            return $this->helper->unserializeData($transactionData->getAdditionalDetails());
        }
        return [];
    }
    
    /**
     * Return the novalnet instalment information.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentHandler
     *
     * @return string
     */
    public function novalnetPaymentHandler(SalesChannelContext $salesChannelContext, $paymentHandler): string
    {
        if (!empty($paymentHandler)) {
            $paymentMethod = preg_match('/\w+$/', $paymentHandler, $match);
            $match = $match[0];
            return $match;
        }
        return '';
    }
    
    /*
     * Get Payment Method Novalnet payment
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $orderNumber
     *
     * @return string
     */
    public function getPaymentMethodNovalnetName(SalesChannelContext $salesChannelContext, string $orderNumber): ?string
    {
        $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderNumber, $salesChannelContext->getContext());
        
        return !empty($transactionData) ? $this->helper->getUpdatedPaymentType($transactionData->getPaymentType()) :'' ;
    }
    
     /*
     * Get Novalnet Comments
     *
     * @param string|null $comments
     *
     * @return string
     */
    public function getNovalnetComments(string $comments) : ?string
    {
        if (!empty($comments)) {
            $novalnetComments = str_replace("&&", '<dt class="col-6 col-md-5 novalnetorder-comments-header ">Comments:</dt> <dd class="col-6 col-md-7 order-item-detail-labels-value novalnetorder-comments-header">'.PHP_EOL, $comments);
        }
        return !empty($novalnetComments) ? $novalnetComments : $comments;
    }
    
    /*
     * Get Novalnet Cash Payment Resopnse
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    public function cashPaymentResponse(SalesChannelContext $salesChannelContext) : ?array
    {
        $paymentdata = $this->helper->getSession('novalnetPaymentResponse');
        return !empty($paymentdata) ? $paymentdata : [];
    }
}
