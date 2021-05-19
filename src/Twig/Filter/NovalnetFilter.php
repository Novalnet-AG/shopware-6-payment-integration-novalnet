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
 * @license 	https://www.novalnet.com/payment-plugins/free/license
 */
 
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Twig\Filter;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetValidator;
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

    public function __construct(
        SessionInterface $sessionInterface,
        NovalnetHelper $helper,
        NovalnetValidator $validator
    ) {
        $this->sessionInterface = $sessionInterface;
        $this->validator        = $validator;
        $this->helper           = $helper;
    }

    public function getFilters()
    {
        return [
            new TwigFilter('wordwrap', [$this, 'wordwrap']),
            new TwigFilter('getPaymentMethodName', [$this->helper, 'getPaymentMethodName']),
            new TwigFilter('savedPaymentData', [$this, 'getSavedPaymentData']),
            new TwigFilter('isGuaranteeAvailable', [$this->validator, 'isGuaranteeAvailable']),
            new TwigFilter('amountInLowerCurrencyUnit', [$this->helper, 'amountInLowerCurrencyUnit']),
            new TwigFilter('getTokens', [$this->helper, 'getStoredData']),
            new TwigFilter('isTestModeEnabled', [$this, 'isTestModeEnabled']),
            new TwigFilter('getPaymentNotification', [$this, 'getPaymentNotification']),
            new TwigFilter('getLocaleCodeFromContext', [$this->helper, 'getLocaleCodeFromContext']),
            new TwigFilter('getNovalnetErrorMessage', [$this->helper, 'getNovalnetErrorMessage']),
            new TwigFilter('jsonEncode', [$this->helper, 'serializeData']),
            
            
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
        return (bool) !empty($paymentSettings[$paymentMethod]['testMode']);
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
        if (!empty($paymentSettings[$paymentMethod]['notify'])) {
            $data = $paymentSettings[$paymentMethod]['notify'];
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
     * Add word wrap filter.
     *
     * @param string $text
     * @param int $length
     *
     * @return string
     */
    public function wordwrap($text, $length): string
    {
        return wordwrap($text, $length, "\n", false);
    }
}
