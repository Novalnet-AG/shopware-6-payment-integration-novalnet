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

namespace Novalnet\NovalnetPayment\Helper;

use Shopware\Core\Content\Product\State;
use Shopware\Core\Framework\Context;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Mime\Exception\RfcComplianceException;

class NovalnetValidator
{
    /**
     * @var NovalnetHelper
     */
    private $helper;

    public function __construct(
        NovalnetHelper $helper
    ) {
        $this->helper = $helper;
    }

    /**
     * Check for success status
     *
     * @param array $data
     *
     * @return bool
     */
    public function isSuccessStatus(array $data): bool
    {
        return (bool) ((! empty($data['result']['status']) && 'SUCCESS' === $data['result']['status']) || (! empty($data['status']) && 'SUCCESS' === $data['status']));
    }

    /**
     * Checks for the given string in given text.
     *
     * @param string $string The string value.
     * @param string $data   The data value.
     *
     * @return boolean
     */
    public static function checkString($string, $data = 'novalnet')
    {
        if (!empty($string)) {
            return (false !== strpos($string, $data));
        }

        return false;
    }

    /**
     * Generate Checksum Token
     *
     * @param Request $request
     * @param string $accessKey
     * @param string $txnSecret
     *
     * @return bool
     */
    public function isValidChecksum(Request $request, string $accessKey, string $txnSecret): bool
    {
        $valid = false;

        if (! empty($request->query->get('checksum')) && ! empty($request->query->get('tid')) && ! empty($request->query->get('status')) && ! empty($accessKey) && ! empty($txnSecret)) {
            $checksum = hash('sha256', $request->query->get('tid') . $txnSecret . $request->query->get('status') . strrev($accessKey));
            if ($checksum === $request->query->get('checksum')) {
                return true;
            }
        }
        return $valid;
    }

    /**
     * Check for the authorize transaction
     *
     * @param string $saleschannelId
     * @param string $paymentCode
     * @param array $parameters
     *
     * @return bool
     */
    public function isAuthorize(string $saleschannelId, string $paymentCode, array $parameters): bool
    {
        $paymentName = $this->helper->formatString($paymentCode);
        $onhold  = $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $paymentName ."OnHold", $saleschannelId);
        $onholdAmount  = $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $paymentName ."OnHoldAmount", $saleschannelId);

        $manualCheckLimit = !empty($onholdAmount) ? $onholdAmount : 0;
        return (bool) (!empty($onhold) && 'authroize' === $onhold && (int) $parameters['transaction']['amount'] > 0 && (int) $parameters['transaction']['amount'] >= (int) $manualCheckLimit);
    }

    /**
     * Check the guarantee condition and return value.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $transaction
     * @param string $paymentMethod
     *
     * @return string
     */
    public function isGuaranteeAvailable(SalesChannelContext $salesChannelContext, $transaction, string $paymentMethod): string
    {
        $paymentShortName = $this->helper->formatString($paymentMethod);
        $allowB2B = $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $paymentShortName ."AllowB2B", $salesChannelContext->getSalesChannel()->getId());
        $minimumAmount = $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $paymentShortName."MinimumOrderAmount", $salesChannelContext->getSalesChannel()->getId());
        $cycles = $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $paymentShortName ."Cycles", $salesChannelContext->getSalesChannel()->getId());

        if (!$this->helper->getSupports('guarantee', $paymentMethod) && !$this->helper->getSupports('instalment', $paymentMethod)) {
            return 'NO';
        }

        if ($salesChannelContext->getCurrency()->getIsoCode() !== 'EUR') {
            return 'NO';
        }

        if ($this->helper->getRoute() == 'frontend.account.payment.page') {
            return 'YES';
        }

        $billingCustomer  = $billingAddress = $shippingCustomer = $shippingAddress = [];
        if (!is_null($salesChannelContext->getCustomer())) {
            list($billingCustomer, $billingAddress) = $this->helper->getAddress($salesChannelContext->getCustomer(), 'billling');
            list($shippingCustomer, $shippingAddress) = $this->helper->getAddress($salesChannelContext->getCustomer(), 'shipping');
        }

        if (!empty($shippingAddress) && $billingAddress !== $shippingAddress) {
            return 'NO';
        }

        if (!empty($billingAddress['company']) && !empty($allowB2B)) {
            $countriesList  = ['AT','DE','CH', 'BE', 'DK', 'BG', 'IT', 'ES', 'SE', 'PT', 'NL', 'IE', 'HU', 'GR', 'FR', 'FI', 'CZ'];
        } else {
            $countriesList  = ['AT','DE','CH'];
        }

        if (!in_array($billingAddress['country_code'], $countriesList)) {
            return 'NO';
        }

        $subproduct = [];
        $orderAmount = 0;

        if (method_exists($transaction, 'getOrder')) {
            $subscriptionorder = $transaction->getOrder()->getExtensions();
            if ((!empty($subscriptionorder['novalnetSubscription']) && !empty($subscriptionorder['novalnetSubscription']->getElements())) || !empty($subscriptionorder['subsOrders'])) {
                return 'NO';
            }
            $orderAmount = (int) sprintf('%.2f', $this->helper->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice()));
        } elseif (method_exists($transaction, 'getCart')) {
            $lineitem = $transaction->getCart()->getLineItems()->getElements();

            if (isset($transaction->getCart()->getExtensions()['novalnetConfiguration'])) {
                $getCartSubscription = $transaction->getCart()->getExtensions()['novalnetConfiguration']->all();
                $subscriptionProductId = [];
                foreach (array_keys($getCartSubscription) as $value) {
                    $subscriptionProductId[] = str_replace('_', '', $value);
                }

                foreach ($lineitem as $item => $price) {
                    if (in_array($item, $subscriptionProductId)) {
                        if (isset($price->getextensions()['novalnetConfiguration'])) {
                            if (isset($price->getExtensions()['novalnetConfiguration']['productId'])) {
                                $subproduct[$item] = [
                                    'totalPrice' => $this->helper->amountInLowerCurrencyUnit($price->getPrice()->gettotalPrice()),
                                    'type' => $price->getType(),
                                    'productId' => $price->getExtensions()['novalnetConfiguration']['productId'],
                                    'signUpFee' => $price->getExtensions()['novalnetConfiguration']['signUpFee'],
                                    'freeTrial' => $price->getExtensions()['novalnetConfiguration']['freeTrial'],
                                ];
                                if ($price->getExtensions()['novalnetConfiguration']['discount'] != null) {
                                    $subproduct[$item]['discount'] = round((($price->getPrice()->getTotalPrice() / 100) * $price->getExtensions()['novalnetConfiguration']['discount']) * 100);
                                } else {
                                    $subproduct[$item]['discount'] = 0;
                                }
                            }
                        }
                    }
                }
            }
            $orderAmount = (int) sprintf('%.2f', $this->helper->amountInLowerCurrencyUnit($transaction->getCart()->getPrice()->getTotalPrice()));
        }

        if (empty($minimumAmount)) {
            $minimumAmount = 999;
        }

        if (!empty($subproduct)) {
            $totalamount = $discountamount = 0;

            foreach ($subproduct as $item => $price) {
                if ($price['type'] == 'product') {
                    if ($price['freeTrial'] != 0) {
                        return 'NO';
                    }

                    $discountamount = $price['discount'];
                    $totalamount = $price['totalPrice'];
                }

                $productAmount = $totalamount - $discountamount;
                $subProduAmount = (int) sprintf('%.2f', $productAmount);

                if (0 < (int) $minimumAmount && (int) $minimumAmount > (int) $subProduAmount) {
                    return 'NO';
                }
            }
        }

        if ($orderAmount >= 0) {
            if (0 < (int) $minimumAmount && (int) $minimumAmount > (int) $orderAmount) {
                return 'NO';
            }
            if (in_array($paymentMethod, ['novalnetinvoiceinstalment', 'novalnetsepainstalment'])) {
                $count = 0;
                foreach ($cycles as $values) {
                    if (($orderAmount / $values) >= 999) {
                        $count++;
                    }
                }

                if ($count == 0  || empty($cycles)) {
                    return 'NO';
                }
            }
        }

        if (!empty($allowB2B)) {
            if (!empty($billingAddress['company'])) {
                return 'HIDE_DOB';
            }
        }

        return 'YES';
    }

    /**
     * Check mail if validate or not.
     *
     * @param string $mail
     *
     * @return bool
     */
    public function isValidEmail($mail): bool
    {
        return (bool) (new EmailValidator())->isValid($mail, new RFCValidation());
    }
}
