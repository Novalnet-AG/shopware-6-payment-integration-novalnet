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
 * @category 	Novalnet
 * @package 	NovalnetPayment
 * @copyright 	Copyright (c) Novalnet
 * @license 	https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
 
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Twig\Filter;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetValidator;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Symfony\Contracts\Translation\TranslatorInterface;
use Twig\Extension\AbstractExtension;
use Twig\TwigFilter;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Twig extension relate to PHP code and used by the profiler and the default exception templates.
 */
class NovalnetFilter extends AbstractExtension
{
    
    /**
     * @var SessionInterface
     */
    private $sessionInterface;

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

    public function __construct(
        SessionInterface $sessionInterface,
        NovalnetHelper $helper,
        NovalnetValidator $validator,
        NovalnetOrderTransactionHelper $transactionHelper
    ) {
        $this->sessionInterface = $sessionInterface;
        $this->validator        = $validator;
        $this->helper           = $helper;
        $this->transactionHelper	= $transactionHelper;
    }

    public function getFilters()
    {
        return [
            new TwigFilter('getPaymentMethodName', [$this->helper, 'getPaymentMethodName']),
            new TwigFilter('savedPaymentData', [$this, 'getSavedPaymentData']),
            new TwigFilter('isGuaranteeAvailable', [$this->validator, 'isGuaranteeAvailable']),
            new TwigFilter('getTokens', [$this->helper, 'getStoredData']),
            new TwigFilter('isTestModeEnabled', [$this, 'isTestModeEnabled']),
            new TwigFilter('getPaymentNotification', [$this, 'getPaymentNotification']),
            new TwigFilter('getLocaleCodeFromContext', [$this->helper, 'getLocaleCodeFromContext']),
            new TwigFilter('getNovalnetErrorMessage', [$this->helper, 'getNovalnetErrorMessage']),
            new TwigFilter('jsonEncode', [$this->helper, 'serializeData']),
            new TwigFilter('getNovalnetInstalmentInfo', [$this, 'getNovalnetInstalmentInfo']),
            new TwigFilter('getNovalnetSettings', [$this->helper, 'getNovalnetPaymentSettings']),
            new TwigFilter('shopVersion', [$this->helper, 'getShopVersion']),
        ];
    }

    /**
     * Payment test mode value
     *
     * @param string $paymentMethod
     * @param array $paymentSettings
     *
     * @return bool
     */
    public function isTestModeEnabled(string $paymentMethod, array $paymentSettings): bool
    {
        $paymentMethod = $this->helper->formatString($paymentMethod);
        return (bool) !empty($paymentSettings["NovalnetPayment.settings.$paymentMethod.testMode"]);
    }
    
    /**
     * Notification need to display in checkout page
     *
     * @param string $paymentMethod
     * @param array $paymentSettings
     *
     * @return string
     */
    public function getPaymentNotification(string $paymentMethod, array $paymentSettings): ? string
    {
        $paymentMethod = $this->helper->formatString($paymentMethod);
        $data = '';
        if (!empty($paymentSettings["NovalnetPayment.settings.$paymentMethod.notify"])) {
            $data = $paymentSettings["NovalnetPayment.settings.$paymentMethod.notify"];
        }
        return $data;
    }
    
    /**
     * Get saved Payment token data
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    public function getSavedPaymentData(SalesChannelContext $salesChannelContext) : array
    {
        $sessionData = [];
        $paymentMethod		= $this->helper->getPaymentMethodName($salesChannelContext->getPaymentMethod());
        $paymentSettings	= $this->helper->getNovalnetPaymentSettings($salesChannelContext->getSalesChannel()->getId());
        $paymentShortCode	= $this->helper->formatString($paymentMethod);
        $data = [];
        if (! empty($paymentMethod) && $this->validator->checkString($paymentMethod)) {
            $sessionData = $this->sessionInterface->get($paymentMethod . 'FormData');
            
            if (!empty($sessionData) && ! empty($sessionData['accountData'])) {
                $sessionData['accountData'] = $this->helper->getLastCharacters($this->helper->formatString($sessionData['accountData'], ' '));
                $data = [$sessionData];
            } elseif (!empty($paymentSettings["NovalnetPayment.settings.$paymentShortCode.oneclick"])) {
                $data =  $this->helper->getStoredData($salesChannelContext, $paymentMethod, true);
            }
        }
        return $data;
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
		$transactionData = $this->transactionHelper->fetchNovalnetTransactionData($orderNumber, $salesChannelContext->getContext());
		
		if($transactionData->getGatewayStatus() === 'CONFIRMED')
		{
			return $this->helper->unserializeData($transactionData->getAdditionalDetails());
		}
		
		return [];
	}
}
