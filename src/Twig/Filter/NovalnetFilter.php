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
use Shopware\Core\System\SalesChannel\SalesChannelContext;
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
     * Constructs a `NovalnetFilter`
     * @param NovalnetHelper $helper
     * @param NovalnetOrderTransactionHelper $transactionHelper
     *
    */
    public function __construct(
        NovalnetHelper $helper,
        NovalnetOrderTransactionHelper $transactionHelper
    ) {
        $this->helper           = $helper;
        $this->transactionHelper    = $transactionHelper;
    }
    
    /**
     * Get Filters
     *
     * @return array
     */

    public function getFilters() : array
    {
        return [
            new TwigFilter('cashPaymentResponse', [$this, 'cashPaymentResponse']),
            new TwigFilter('novalnetPayment', [$this->helper, 'getNovalnetIframeUrl']),
            new TwigFilter('getNovalnetInstalmentInfo', [$this, 'getNovalnetInstalmentInfo']),
            new TwigFilter('getPaymentName', [$this->transactionHelper, 'getPaymentName']),
            new TwigFilter('getNovalnetErrorMessage', [$this->helper, 'getNovalnetErrorMessage']),
        ];
    }

    /**
     * Return the novalnet instalment information.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $orderNumber
     * @param bool $paymentType
     *
     * @return array
     */
    public function getNovalnetInstalmentInfo(SalesChannelContext $salesChannelContext, string $orderNumber, bool $paymentType = false): array
    {
        $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderNumber, $salesChannelContext->getContext());
        if (!empty($transactionData)) {
			if($transactionData->getGatewayStatus() === 'CONFIRMED' &&  $paymentType == false){
				$instalmentDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails())['InstalmentDetails'];
				ksort($instalmentDetails);
				return $instalmentDetails;
			} else if($paymentType == true){
				$paymentType = ['paymentName' => $this->helper->getUpdatedPaymentType($transactionData->getPaymentType())];
				return $paymentType;
			}
        }
        return [];
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
