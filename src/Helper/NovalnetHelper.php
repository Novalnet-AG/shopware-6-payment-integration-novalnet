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

use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
use Shopware\Storefront\Framework\Routing\RequestTransformer;

/**
 * NovalnetHelper Class.
 */
class NovalnetHelper
{
    /**
     * @var string
     */
    protected $endpoint = 'https://payport.novalnet.de/v2/';

    /**
     * @var SystemConfigService
     */
    protected $systemConfigService;

    /**
     * @var TranslatorInterface
     */
    public $translator;

    /**
     * @var ContainerInterface
     */
    protected $container;

    /**
     * @var string
     */
    protected $shopVersion;

    /**
     * @var string
     */
    protected $newLine = '/ ';

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var EntityRepository
     */
    protected $languageRepository;

    /**
     * @var CurrencyFormatter
     */
    protected $currencyFormatter;

    /**
     * Constructs a `NovalnetHelper`
     *
     * @param TranslatorInterface $translator
     * @param ContainerInterface  $container
     * @param SystemConfigService $systemConfigService
     * @param RequestStack        $requestStack
     * @param CurrencyFormatter   $currencyFormatter
     * @param string              $shopVersion
     */
    public function __construct(
        TranslatorInterface $translator,
        ContainerInterface $container,
        SystemConfigService $systemConfigService,
        RequestStack $requestStack,
        CurrencyFormatter $currencyFormatter,
        string $shopVersion
    ) {
        $this->translator             = $translator;
        $this->container              = $container;
        $this->systemConfigService    = $systemConfigService;
        $this->requestStack           = $requestStack;
        $this->languageRepository     = $this->container->get('language.repository');
        $this->currencyFormatter      = $currencyFormatter;
        $this->shopVersion            = $shopVersion;
    }

    /**
     * Request to payment gateway action.
     *
     * @param array  $parameters
     * @param string $url
     * @param string $accessKey
     *
     * @return array
     */
    public function sendPostRequest(array $parameters, string $url, string $accessKey): array
    {
        $client = new Client([
            'headers'  => [
                'charset' => 'utf-8',
                'Accept' => 'application/json',
                'X-NN-Access-Key' => base64_encode(str_replace(' ', '', $accessKey)),
            ],
            'json' => $parameters
        ]);

        try {
            $response = $client->post($url)->getBody()->getContents();
        } catch (RequestException $requestException) {
            return [
                'result' => [
                    'status'      => 'FAILURE',
                    'status_code' => '106',
                    'status_text' => $requestException->getMessage(),
                ],
            ];
        }

        return $this->unserializeData($response);
    }

    /**
     * Perform serialize data.
     *
     * @param array $data
     *
     * @return string
     */
    public function serializeData(array $data): string
    {
        $result = '{}';

        if (!empty($data)) {
            $result = json_encode($data, JSON_UNESCAPED_SLASHES);
        }

        return $result;
    }

    /**
     * Get action URL
     *
     * @param string $action
     *
     * @return string
     */
    public function getActionEndpoint(string $action = ''): string
    {
        return $this->endpoint . str_replace('_', '/', $action);
    }

    /**
     * Perform unserialize data.
     *
     * @param string|null $data
     * @param bool        $needAsArray
     *
     * @return array|null
     */
    public function unserializeData(string $data = null, bool $needAsArray = true): ?array
    {
        $result = [];
        if (!empty($data)) {
            $result = json_decode($data, $needAsArray, 512, JSON_BIGINT_AS_STRING);
        }

        return $result;
    }

    /**
     * Return Shop Version.
     *
     * @return string
     */
    public function getShopVersion(): string
    {
        return $this->shopVersion;
    }

    /**
     * Get Shopware & Novalnet version information
     *
     * @param Context $context
     *
     * @return string
     */
    public function getVersionInfo(Context $context): string
    {
        return $this->shopVersion . '-NN' . '13.6.0';
    }

    /**
     * get the novalnet Iframe From Url.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param mixed               $transaction
     * @param boolean             $subscription
     *
     * @return string
     */
    public function getNovalnetIframeUrl(SalesChannelContext $salesChannelContext, $transaction, bool $subscription = false): string
    {
        $amount = 0;
        if (method_exists($transaction, 'getOrder')) {
            $amount = $this->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice());
        } elseif (method_exists($transaction, 'getCart')) {
            $amount = $this->amountInLowerCurrencyUnit($transaction->getCart()->getprice()->getTotalPrice());
        }

        $requiredFields = ['amount' => $amount, 'currency' => $salesChannelContext->getCurrency()->getIsoCode() ? $salesChannelContext->getCurrency()->getIsoCode() : $salesChannelContext->getSalesChannel()->getCurrency()->getIsoCode()];

        if (empty($salesChannelContext->getCustomer())) {
            return '';
        }
        $subscriptionOrder = '';
        if (!empty($subscription)) {
            $subscriptionOrder = 'SUBSCRIPTION';
        }
        $response = $this->getNovalnetIframeResponse($salesChannelContext->getSaleschannel()->getId(), $salesChannelContext->getCustomer(), $requiredFields, $salesChannelContext->getContext(), 'seamless_payment', $subscriptionOrder);
        if ($response['result']['status'] == 'SUCCESS') {
            $this->setSession('nnIframeUrl', $response['result']['redirect_url']);
            return $response['result']['redirect_url'];
        };

        return '';
    }

    /**
     * get the theme name.
     *
     * @param Context $context
     * @param string  $saleschannelId
     *
     * @return string
     */
    public function getThemeName(string $saleschannelId, Context $context): string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannels.id', $saleschannelId));
        $theme = $this->container->get('theme.repository')->search($criteria, $context);
        $themecollection = $theme->getelements();
        $themeTechnicalName = '';
        foreach ($themecollection as $key => $data) {
            if ($data->isActive() == 1) {
                $themeTechnicalName = !is_null($data->getTechnicalName()) ? $data->getTechnicalName() : str_replace(' ', '', $data->getName());
                break;
            }
        }
        return $themeTechnicalName;
    }

    /**
     * Returns the Novalnet backend configuration.
     *
     * @param string $key
     * @param string $salesChannelId
     *
     * @return mixed
     */
    public function getNovalnetPaymentSettings(string $key, string $salesChannelId): mixed
    {
        if (is_null($this->systemConfigService->get($key, $salesChannelId))) {
            return $this->systemConfigService->get($key, null);
        }
        return $this->systemConfigService->get($key, $salesChannelId);
    }

    /**
     * Fetch the customer data from database
     *
     * @param string  $customerId
     * @param Context $context
     *
     * @return CustomerEntity|null
     */
    public function getCustomerDetails(string $customerId, Context $context): ?CustomerEntity
    {
        $criteria = new Criteria([$customerId]);
        $criteria->addAssociation('defaultBillingAddress');
        $criteria->addAssociation('defaultBillingAddress.country');
        $criteria->addAssociation('defaultShippingAddress');
        $criteria->addAssociation('defaultShippingAddress.country');
        $criteria->addAssociation('activeBillingAddress');
        $criteria->addAssociation('activeBillingAddress.country');
        $criteria->addAssociation('activeShippingAddress');
        $criteria->addAssociation('activeShippingAddress.country');
        $criteria->addAssociation('addresses');
        return $this->container->get('customer.repository')->search($criteria, $context)->first();
    }

    /**
     * Get customer data
     *
     * @param CustomerEntity $customerEntity
     *
     * @return array
     */
    public function getCustomerData(CustomerEntity $customerEntity)
    {
        $customer  = [];
        // Get billing details.
        list($billingCustomer, $billingAddress) = $this->getAddress($customerEntity, 'billing');

        if (! empty($billingCustomer)) {
            $customer = $billingCustomer;
        }
        $customer ['billing'] = $billingAddress;

        if (!empty($customerEntity->getActiveBillingAddress()->getPhoneNumber())) {
            $customer['tel'] = $customerEntity->getActiveBillingAddress()->getPhoneNumber();
        }

        list($shippingCustomer, $shippingAddress) = $this->getAddress($customerEntity, 'shipping');

        // Add shipping details.
        if (!empty($shippingAddress)) {
            if ($billingAddress === $shippingAddress) {
                $customer ['shipping'] ['same_as_billing'] = 1;
            } else {
                $customer ['shipping'] = $shippingAddress;
            }
        }
        if (!empty($customerEntity->getSalutation())) {
            $salutationKey = $customerEntity->getSalutation()->getSalutationKey();
            $customer ['gender'] = ($salutationKey == 'mr') ? 'm' : ($salutationKey == 'mrs' ? 'f' : 'u');
        }

        $customer['customer_ip'] = $this->getIp();
        if (empty($customer['customer_ip'])) {
            $customer['customer_ip'] = $customerEntity->getRemoteAddress();
        }
        $customer['customer_no'] = $customerEntity->getCustomerNumber();

        return $customer;
    }

    /**
     * Get address
     *
     * @param CustomerEntity|null $customerEntity
     * @param string              $type
     *
     * @return array
     */
    public function getAddress(CustomerEntity $customerEntity = null, string $type): array
    {
        $address  = [];
        $customer = [];
        if (!empty($customerEntity)) {
            if ($type === 'shipping') {
                $addressData = $customerEntity->getActiveShippingAddress() ?? $customerEntity->getDefaultShippingAddress();
            } else {
                $addressData = $customerEntity->getActiveBillingAddress() ?? $customerEntity->getDefaultBillingAddress();
            }
            if (!empty($addressData) && !empty($addressData->getCountry())) {
                $customer['first_name'] = $addressData->getFirstName();
                $customer['last_name'] = $addressData->getLastName();
                $customer['email'] = $customerEntity->getEmail();
                if (!empty($addressData->getCompany())) {
                    $address['company'] = $addressData->getCompany();
                }

                $address['street'] = $addressData->getStreet().' '.$addressData->getAdditionalAddressLine1().' '.$addressData->getAdditionalAddressLine2();
                $address['city'] = $addressData->getCity();
                $address['zip'] = $addressData->getZipCode();
                $address['country_code'] = $addressData->getCountry()->getIso();
            }
        }

        return [$customer, $address];
    }

    /**
     * Converting given amount into smaller unit
     *
     * @param float|null $amount
     *
     * @return float
     */
    public function amountInLowerCurrencyUnit(float $amount = null): ?float
    {
        return  (float) sprintf('%0.0f', ($amount * 100));
    }

    /**
     * Get the system IP address.
     *
     * @param  string $type
     * @return string
     */
    public function getIp(string $type = 'REMOTE'): string
    {
        $ipAddress = '';

        // Check to determine the IP address type
        if ($type === 'REMOTE') {
            if (!empty($this->requestStack->getCurrentRequest())) {
                $ipAddress = $this->requestStack->getCurrentRequest()->getClientIp();
            }
        } else {
            if (empty($_SERVER['SERVER_ADDR']) && !empty($_SERVER['SERVER_NAME'])) {
                // Handled for IIS server
                $ipAddress = gethostbyname($_SERVER['SERVER_NAME']);
            } elseif (!empty($_SERVER['SERVER_ADDR'])) {
                $ipAddress = $_SERVER['SERVER_ADDR'];
            }
        }
        return $ipAddress;
    }

    /**
     * To fetch the shop language from context.
     *
     * @param Context     $context
     * @param boolean     $formattedLocale
     * @param string|null $languageId
     *
     * @return string
     */
    public function getLocaleCodeFromContext(Context $context, bool $formattedLocale = false, string $languageId = null): string
    {
        $languageId = $languageId ?? $context->getLanguageId();
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');

        /** @var LanguageEntity|null */
        $languageCollection = $this->languageRepository->search($criteria, $context)->getEntities();

        $language = $languageCollection->get($languageId);
        if (!$formattedLocale) {
            if ($language === null || !($language->getLocale())) {
                return 'DE';
            }
            $locale = $language->getLocale();
            $lang = explode('-', $locale->getCode());
            return strtoupper($lang[0]);
        } else {
            $languageCode = 'de-DE';
            if ($language === null || !($language->getLocale())) {
                return $languageCode;
            }
            $locale = $language->getLocale();
            if (!in_array($locale->getCode(), ['de-DE', 'en-GB'])) {
                $languageID = Defaults::LANGUAGE_SYSTEM;
                $languageCriteria = new Criteria([$languageID]);
                $languageCriteria->addAssociation('locale');
                /** @var LanguageEntity|null */
                $language = $this->languageRepository->search($languageCriteria, $context)->first();
                $languageCode = $language->getLocale()->getCode();
            } else {
                $languageCode = in_array($locale->getCode(), ['de-DE', 'en-GB']) ? $locale->getCode() : 'de-DE';
            }

            return $languageCode;
        }
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
        return (bool) ((isset($data['result']['status']) && 'SUCCESS' === $data['result']['status']) || (isset($data['status']) && 'SUCCESS' === $data['status']));
    }

    /**
     * Form Bank details comments.
     *
     * @param array       $input
     * @param Context     $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function formBankDetails(array $input, Context $context, string $languageId = null): string
    {
        $comments = $this->formOrderComments($input, $context, $languageId);
        $localeCode = $this->getLocaleCodeFromContext($context, true, $languageId);
        $paymentType = $input['transaction']['payment_type'];
        $translator = $this->translator;

        if (isset($input ['transaction']['amount']) && $input ['transaction']['amount'] == 0 && in_array($paymentType, ['PREPAYMENT', 'INVOICE'])) {
            $comments .= '';
        } elseif (!empty($input['transaction']['status']) && $input['transaction']['status'] != 'DEACTIVATED') {
            if (($input['transaction']['status'] === 'PENDING' && in_array($paymentType, ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE']))

            ) {
                $comments .= $this->newLine . $translator->trans('NovalnetPayment.text.invoiceGuaranteePendingMsg', [], null, $localeCode);
            } elseif (!empty($input ['transaction']['bank_details'])) {
                $bankDetails = $input ['transaction']['bank_details'];

                if (isset($input['instalment']) && isset($input['instalment']['cycle_amount'])  && $input['instalment']['cycle_amount'] != 0) {
                    $amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit($input ['instalment']['cycle_amount'], isset($input ['instalment']['currency']) ? $input ['instalment']['currency'] : $input ['transaction']['currency']);
                } elseif ($input['transaction']['amount'] != 0) {
                    $amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit($input ['transaction']['amount'], $input ['transaction']['currency']);
                }

                if (!empty($amountInBiggerCurrencyUnit)) {
                    if (in_array($input['transaction']['status'], [ 'CONFIRMED', 'PENDING' ], true) && ! empty($input ['transaction']['due_date'])) {
                        $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.amountTransaferNoteWithDueDate', [], null, $localeCode), $amountInBiggerCurrencyUnit, date('d/m/Y', strtotime($input ['transaction']['due_date']))) . $this->newLine;
                    } else {
                        $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.amountTransaferNote', [], null, $localeCode), $amountInBiggerCurrencyUnit) . $this->newLine;
                    }
                }

                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.accountHolder', [], null, $localeCode), $bankDetails['account_holder']);
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.bank', [], null, $localeCode), $bankDetails['bank_name']);
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.bankPlace', [], null, $localeCode), $bankDetails['bank_place']);
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.iban', [], null, $localeCode), $bankDetails['iban']);
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.bic', [], null, $localeCode), $bankDetails['bic']) . $this->newLine;


                if (! empty($input ['transaction']['invoice_ref'])) {
                    // Form reference comments.
                    $comments .= $this->newLine . $translator->trans('NovalnetPayment.text.paymentReferenceNoteAny', [], null, $localeCode). $this->newLine;
                    $comments .=  sprintf($translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '1', $input ['transaction']['tid']);
                    $comments .=  $this->newLine . sprintf($translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '2', $input ['transaction']['invoice_ref']). $this->newLine;
                } else {
                    // Form reference comments.
                    $comments .= $this->newLine . $translator->trans('NovalnetPayment.text.paymentReferenceNote', [], null, $localeCode). $this->newLine;
                    $comments .=  sprintf($translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '', $input ['transaction']['tid']);
                }
            }

            if (!empty($input ['transaction']['due_date']) && $paymentType == 'CASHPAYMENT') {
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.slipExpiryDate', [], null, $localeCode), date('d/m/Y', strtotime($input ['transaction']['due_date']))) . $this->newLine . $this->newLine;
            }

            if (!empty($input['transaction']['nearest_stores'])) {

                $comments .= $translator->trans('NovalnetPayment.text.cashpaymentStore', [], null, $localeCode) . $this->newLine;

                foreach ($input['transaction']['nearest_stores'] as $key => $nearestStore) {
                    $comments .= $nearestStore['store_name'] . $this->newLine;
                    $comments .= $nearestStore['street'] . $this->newLine;
                    $comments .= $nearestStore['city'] . $this->newLine;
                    $comments .= $nearestStore['zip'] . $this->newLine;

                    if (!empty($nearestStore['country_code'])) {
                        $comments .= Countries::getName($nearestStore['country_code']) . $this->newLine . $this->newLine;
                    }
                }
            }


            if (! empty($input['transaction']['partner_payment_reference'])) {
                $amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit($input ['transaction']['amount'], $input ['transaction']['currency']);

                $comments .= $this->newLine . $this->newLine . sprintf($translator->trans('NovalnetPayment.text.multibancoReference', [], null, $localeCode), $amountInBiggerCurrencyUnit);
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '', $input['transaction']['partner_payment_reference']);
                if (!empty($input['transaction']['service_supplier_id'])) {
                    $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.paymentEntityReference', [], null, $localeCode), $input['transaction']['service_supplier_id']);
                }
            }
        }
        return $comments;
    }

    /**
     * Form payment comments.
     *
     * @param array       $input
     * @param Context     $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function formOrderComments(array $input, Context $context, string $languageId = null): string
    {
        $comments = '';
        $paymentdata = [];

        $localeCode = $this->getLocaleCodeFromContext($context, true, $languageId);
        $paymentType = $input['transaction']['payment_type'];

        if (!empty($input['isRecurringOrder']) && !empty($input['paymentData'])) {
            $paymentdata = $input['paymentData'];
        } else {
            $paymentdata = $this->getSession('novalnetPaymentdata');
        }

        if (!empty($input ['transaction']['tid'])) {
            $comments .= sprintf($this->translator->trans('NovalnetPayment.text.transactionId', [], null, $localeCode), $input ['transaction']['tid']);
            if (!empty($input ['transaction'] ['test_mode'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.testOrder', [], null, $localeCode);
            }
            if (!empty($input['transaction']['status']) && $input['transaction']['status'] === 'PENDING' &&
                in_array($paymentType, ['GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.sepaGuaranteePendingMsg', [], null, $localeCode);
            }
        }

        if (!empty($input['transaction']['status']) && $input['transaction']['status'] === 'CONFIRMED' && $input['transaction']['amount'] == 0 && in_array($paymentType, ['CREDITCARD', 'DIRECT_DEBIT_SEPA', 'GOOGLEPAY', 'DIRECT_DEBIT_ACH', 'APPLEPAY']) && ((!empty($paymentdata['booking_details']['payment_action']) && $paymentdata['booking_details']['payment_action'] == 'zero_amount') || (!empty($input['event']) && !empty($input['event']['checksum'])))) {
            if (isset($input['custom']) && isset($input['custom']['input3']) && $input['custom']['input3'] == 'ZeroBooking') {
                $comments .= $this->newLine . $this->newLine . $this->translator->trans('NovalnetPayment.text.zeroAmountAlertMsg', [], null, $localeCode);
            }
        }

        if ((isset($input['result']['status']) && 'FAILURE' === $input['result']['status'])) {
            $comments .= $this->newLine . $this->newLine . $input ['result']['status_text'];
        }

        return $comments;
    }

    /**
     * Converting given amount into bigger unit
     *
     * @param int          $amount
     * @param string       $currency
     * @param Context|null $context
     *
     * @return string
     */
    public function amountInBiggerCurrencyUnit(int $amount, string $currency = '', Context $context = null): ?string
    {
        $formatedAmount = (float) sprintf('%.2f', $amount / 100);
        if (!empty($currency)) {
			if (empty($context)) {
                $context        = Context::createDefaultContext();
            }
            $formatedAmount = $this->currencyFormatter->formatCurrencyByLanguage($formatedAmount, $currency, $context->getLanguageId(), $context ?? Context::createDefaultContext());
        }
        return (string) $formatedAmount;
    }

    /**
     * Retrieves messages from server response.
     *
     * @param array $data
     *
     * @return string
     */
    public function getResponseText(array $data): string
    {
        if (! empty($data ['result']['status_text'])) {
            return $data ['result']['status_text'];
        }

        if (! empty($data ['status_text'])) {
            return $data ['status_text'];
        }
        return $this->translator->trans('NovalnetPayment.text.paymentError');
    }

    /**
     * Generate Checksum Token
     *
     * @param Request $request
     * @param string  $accessKey
     * @param string  $txnSecret
     *
     * @return bool
     */
    public function isValidChecksum(Request $request, string $accessKey, string $txnSecret): bool
    {
        if (! empty($request->get('checksum')) && ! empty($request->get('tid')) && ! empty($request->get('status')) && ! empty($accessKey) && ! empty($txnSecret)) {
            $checksum = hash('sha256', $request->get('tid') . $txnSecret . $request->get('status') . strrev($accessKey));
            if ($checksum === $request->get('checksum')) {
                return true;
            }
        }
        return false;
    }

    /**
     * Fetch transaction details
     *
     * @param Request             $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    public function fetchTransactionDetails(Request $request, SalesChannelContext $salesChannelContext): array
    {
        $paymentAccessKey = $this->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $salesChannelContext->getSalesChannel()->getId());
        $transactionDetails = [];
        if ($request->get('tid')) {
            $parameter = [
                'transaction' => [
                    'tid' => $request->get('tid')
                ],
                'custom' => [
                    'lang' => $this->getLocaleCodeFromContext($salesChannelContext->getContext())
                ]
            ];
            $transactionDetails = $this->sendPostRequest($parameter, $this->getActionEndpoint('transaction_details'), $paymentAccessKey);
        }
        return $transactionDetails;
    }

    /**
     * Update Novalnet Transaction Data
     *
     * @param array   $data
     * @param Context $context
     *
     * @return void
     */
    public function updateTransactionData(array $data, Context $context)
    {
        $this->container->get('novalnet_transaction_details.repository')->upsert([$data], $context);
    }


    /**
     * To fetch the shop language from order id.
     * Fixed language issue in translator.
     *
     * @param string  $orderId
     * @param boolean $getLanguageId
     *
     * @return string
     */
    public function getLocaleFromOrder(string $orderId, $getLanguageId = false): string
    {
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('language');
        $orderCriteria->addAssociation('language.locale');
        $order  = $this->container->get('order.repository')->search($orderCriteria, Context::createDefaultContext())->first();
        $locale = $order->getLanguage()->getLocale();

        if ($getLanguageId) {
            return $order->getLanguageId() ? $order->getLanguageId() : '';
        }

        if (!$locale) {
            return 'de-DE';
        }
        return in_array($locale->getCode(), ['de-DE', 'en-GB']) ? $locale->getCode() : 'de-DE';
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

    /**
     * Check mail if validate or not.
     *
     * @param array $paymentDetails
     * @param array $customer
     *
     * @return string
     */
    public function orderBackendPaymentData(array $paymentDetails, array $customer, Context $context): string
    {
        $result = '';

        $customerid = $customer['id'];
        $customerCriteria = new Criteria([$customerid]);
        $customerDetails = $this->container->get('customer.repository')->search($customerCriteria, $context)->first();

        if (!empty($paymentDetails)) {
            $novalnetPaymentDetails = [
                'novalnetOrderBackendParameters' => $paymentDetails
            ];

            $customerDetails->setcustomFields($novalnetPaymentDetails);

            $upsertData = [
                'id'            => $customerid,
                'customFields'  => $customerDetails->getCustomFields()
            ];

            $this->container->get('customer.repository')->update([$upsertData], $context);

            $result = 'success';
        }


        return $result;
    }

    /**
     * Set Session using key and data
     *
     * @param string $key
     * @param $data
     *
     * @return void
     */
    public function setSession(string $key, $data): void
    {
        $this->requestStack->getSession()->set($key, $data);
    }

    /**
     * Get Session using key
     *
     * @param string $key
     */
    public function getSession(string $key)
    {
        return $this->requestStack->getSession()->get($key);
    }

    /**
     * Has Session using key
     *
     * @param string $key
     *
     * @return bool
     */
    public function hasSession(string $key): bool
    {
        return $this->requestStack->getSession()->has($key);
    }

    /**
     * Remove Session
     *
     * @param string $key
     */
    public function removeSession(string $key)
    {
        $this->requestStack->getSession()->remove($key);
    }

    /**
     * Get Updated Payment Type
     *
     * @param string  $type
     * @param boolean $getOldPayment
     *
     * @return string
     */
    public function getUpdatedPaymentType(string $type, bool $getOldPayment = false): string
    {
        $types = [
        'novalnetinvoice'           => 'INVOICE',
        'novalnetprepayment'        => 'PREPAYMENT',
        'novalnetsepa'              => 'DIRECT_DEBIT_SEPA',
        'novalnetsepaguarantee'     => 'GUARANTEED_DIRECT_DEBIT_SEPA',
        'novalnetinvoiceguarantee'  => 'GUARANTEED_INVOICE',
        'novalnetcreditcard'        => 'CREDITCARD',
        'novalnetinvoiceinstalment' => 'INSTALMENT_INVOICE',
        'novalnetsepainstalment'    => 'INSTALMENT_DIRECT_DEBIT_SEPA',
        'novalnetcashpayment'       => 'CASHPAYMENT',
        'novalnetmultibanco'        => 'MULTIBANCO',
        'novalnetsofort'            => 'ONLINE_TRANSFER',
        'novalnetideal'             => 'IDEAL',
        'novalneteps'               => 'EPS',
        'novalnettrustly'           => 'TRUSTLY',
        'novalnetgiropay'           => 'GIROPAY',
        'novalnetpaypal'            => 'PAYPAL',
        'novalnetpostfinancecard'   => 'POSTFINANCE_CARD',
        'novalnetpostfinance'       => 'POSTFINANCE',
        'novalnetgooglepay'         => 'GOOGLEPAY',
        'novalnetapplepay'          => 'APPLEPAY',
        'novalnetwechatpay'         => 'WECHATPAY',
        'novalnetalipay'            => 'ALIPAY',
        'novalnetprzelewy24'        => 'PRZELEWY24',
        'novalnetbancontact'        => 'BANCONTACT',
        'novalnetonlinebanktransfer' => 'ONLINE_BANK_TRANSFER'
        ];

        if ($getOldPayment) {
            $oldPaymentValues = array_flip($types);
            return (!empty($oldPaymentValues[$type])) ? $oldPaymentValues[$type] : $type;
        }

        if (!empty($types[$type])) {
            return $types[$type];
        }

        return $type;
    }

    /**
     * Get Country Code
     *
     * @param string $countryId
     *
     * @return string
     */
    public function getCountry(string $countryId): string
    {
        $countryCode = '';
        if (!empty($countryId)) {
            $countryCriteria = new Criteria([$countryId]);
            $countryDetails  = $this->container->get('country.repository')->search($countryCriteria, Context::createDefaultContext())->first();
            $countryCode = $countryDetails->getIso();
        }
        return $countryCode;
    }

    /**
     * Get Updated Payment Name
     *
     * @param string $type
     * @param string $local
     *
     * @return string|null
     */
    public function getUpdatedPaymentName($type, $local): ?string
    {
        return $this->translator->trans('NovalnetPayment.text.'.strtolower($type), [], null, $local);
    }

    /**
     * Get Payment Description
     *
     * @param string $type
     * @param string $local
     *
     * @return string|null
     */
    public function getPaymentDescription($type, $local): ?string
    {
        return $this->translator->trans('NovalnetPayment.description.'.strtolower($type), [], null, $local);
    }

    /**
     * Get error message from session.
     *
     * @return string|null
     */
    public function getNovalnetErrorMessage(): ?string
    {
        $errorMessage = '';
        if ($this->requestStack->getSession()->has('novalnetErrorMessage')) {
            $errorMessage = $this->requestStack->getSession()->get('novalnetErrorMessage');
            $this->requestStack->getSession()->remove('novalnetErrorMessage');
        }
        return $errorMessage;
    }

    /**
     * Return the iframe reponse data
     *
     * @param string  $saleschannelId
     * @param CustomerEntity  $customer
     * @param array   $requiredFields
     * @param Context $context
     * @param string  $endPoint
     * @param string  $type
     *
     * @return array
     */
    public function getNovalnetIframeResponse(string $saleschannelId, CustomerEntity $customer, array $requiredFields, Context $context, string $endPoint, string $type = ''): array
    {
        $parameters['customer'] = $this->getCustomerData($customer);
        $session = [
            'amount' => $this->getSession('cartAmount'),
            'billing' => $this->getSession('billingAddress'),
            'shipping' => $this->getSession('shippingAddress')
        ];


        if (!empty($session['amount']) && !empty($session['billing']) && !empty($session['shipping'])) {

            if (($session['amount'] == $requiredFields['amount']) && ($session['billing'] == $parameters['customer']['billing']) && ($session['shipping'] == $parameters['customer']['shipping'])) {
                if (!empty($this->getSession('nnIframeUrl'))) {
                    $response['result'] = ['status' => 'SUCCESS', 'redirect_url' => $this->getSession('nnIframeUrl')];
                    return $response;
                }
            }
        }

        $paymentAccessKey = $this->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $saleschannelId);
        $parameters['merchant'] = $this->merchantParameter($saleschannelId);
        $parameters['custom']['lang'] = $this->getLocaleCodeFromContext($context);

        $this->setSession('cartAmount', $requiredFields['amount']);
        $this->setSession('billingAddress', $parameters['customer']['billing']);
        $this->setSession('shippingAddress', $parameters['customer']['shipping']);

        $parameters['transaction'] = [
            'amount'         => $requiredFields['amount'],
            'currency'       => $requiredFields['currency']
        ];

        $parameters['transaction'] = $this->systemParameter($context, $parameters['transaction']);
        $parameters['transaction']['system_version'] = $this->getVersionInfo($context) . '-NNT' . $this->getThemeName($saleschannelId, $context);

        $parameters['hosted_page'] = [
            'hide_blocks' => ['ADDRESS_FORM', 'SHOP_INFO', 'LANGUAGE_MENU', 'TARIFF','HEADER'],
            'skip_pages'  => ['CONFIRMATION_PAGE', 'SUCCESS_PAGE', 'PAYMENT_PAGE'],
            'type'        => 'PAYMENTFORM'
        ];

        if (!empty($type)) {
            $parameters['hosted_page']['display_payments_mode'] = [$type];
        }

        return $this->sendPostRequest($parameters, $this->getActionEndpoint($endPoint), str_replace(' ', '', $paymentAccessKey));
    }

    /**
     * Return the payment request data
     *
     * @param float               $amount
     * @param string              $orderNumber
     * @param array               $data
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    public function getNovalnetRequestData(float $amount, string $orderNumber, array $data, SalesChannelContext $salesChannelContext): array
    {
        // Built merchant parameters.
        $parameters['merchant'] = $this->merchantParameter($salesChannelContext->getSalesChannel()->getId());

        // Built customer parameters.
        if (!empty($salesChannelContext->getCustomer())) {
            $parameters['customer'] = $this->getCustomerData($salesChannelContext->getCustomer());
        }

        //Build Transaction paramters.
        $parameters['transaction'] = [
            'amount'         => $amount,
            'order_no'       => $orderNumber,
            'currency'       => $salesChannelContext->getCurrency()->getIsoCode() ? $salesChannelContext->getCurrency()->getIsoCode() : $salesChannelContext->getSalesChannel()->getCurrency()->getIsoCode(),
            'test_mode'      => (int) $data['booking_details']['test_mode'],
            'payment_type'   => $data['payment_details']['type'],
        ];

        $parameters['transaction'] = $this->systemParameter($salesChannelContext->getContext(), $parameters['transaction']);

        if (($salesChannelContext->getSalesChannel() != null) && ($salesChannelContext->getSalesChannel()->getDomains() != null)) {
            $domain  = $salesChannelContext->getSalesChannel()->getDomains()->first();
            $hookUrl   = $domain->getUrl() . '/novalnet/callback';
            $parameters['transaction']['hook_url'] = $hookUrl;
        }
        $keys = ['account_holder', 'iban', 'bic', 'wallet_token', 'pan_hash', 'unique_id', 'account_number', 'routing_number'];

        foreach ($keys as $key) {
            if (!empty($data['booking_details'][$key])) {
                $parameters['transaction']['payment_data'][$key] = $data['booking_details'][$key];
            }
        }

        if (!empty($data['booking_details']['payment_ref']['token'])) {
            $parameters['transaction']['payment_data']['token'] = $data['booking_details']['payment_ref']['token'];
        }

        if (!empty($data['booking_details']['birth_date'])) {
            $parameters['customer']['birth_date'] = $data['booking_details']['birth_date'];
            unset($parameters['customer']['billing']['company']);
        }

        if (!empty($data['booking_details']['due_date'])) {
            $parameters['transaction']['due_date'] = date('Y-m-d', strtotime('+' . $data['booking_details']['due_date'] . ' days'));
        }

        if (isset($data['booking_details']['mobile']) && !empty($data['booking_details']['mobile'])) {
            $parameters['customer']['mobile'] = $data['booking_details']['mobile'];
        }

        if (!empty($data['booking_details']['payment_action']) && $data['booking_details']['payment_action'] == 'zero_amount') {
            $parameters['transaction']['amount'] = 0;
            $parameters['transaction']['create_token'] = 1;
        }

        if (!empty($data['booking_details']['enforce_3d'])) {
            $parameters['transaction']['enforce_3d'] = $data['booking_details']['enforce_3d'];
        }

        if (!empty($data['booking_details']['create_token'])) {
            $parameters['transaction']['create_token'] = $data['booking_details']['create_token'];
        }

        //Build custom paramters.
        $parameters['custom'] = [
            'lang' => $this->getLocaleCodeFromContext($salesChannelContext->getContext())
        ];

        return $parameters;
    }

    /**
     * Get customer address using the order address details.
     *
     * @param $address
     *
     * @return string|null
     */
    public function getAddressId($address): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new AndFilter([
            new EqualsFilter('customer_address.countryId', $address->getCountryId()),
            new EqualsFilter('customer_address.firstName', $address->getFirstName()),
            new EqualsFilter('customer_address.lastName', $address->getLastName()),
            new EqualsFilter('customer_address.zipcode', $address->getZipcode()),
            new EqualsFilter('customer_address.city', $address->getCity()),
            new EqualsFilter('customer_address.street', $address->getStreet()),
            new EqualsFilter('customer_address.company', $address->getCompany()),
        ]));

        $address = $this->container->get('customer_address.repository')->search($criteria, Context::createDefaultContext())->first();
        return $address ? $address->getId() : null;
    }

    /**
     * Get user remote ip address
     *
     * @param string $novalnetHostIp
     *
     * @return bool
     */
    public function checkWebhookIp(string $novalnetHostIp): bool
    {
        $ipKeys = ['HTTP_X_FORWARDED_HOST', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                if (in_array($key, ['HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_FOR'])) {
                    $forwardedIps = (!empty($_SERVER[$key])) ? explode(",", $_SERVER[$key]) : [];
                    if (in_array($novalnetHostIp, $forwardedIps)) {
                        return true;
                    }
                }

                if ($_SERVER[$key] ==  $novalnetHostIp) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Return the payment system parameter data
     *
     * @param Context $context
     * @param array   $transaction

     * @return array
     */
    public function systemParameter(Context $context, array $transaction): array
    {
        $transaction['system_name'] = 'shopware6';
        $transaction['system_ip'] = $this->getIp('SYSTEM');
        $transaction['system_version'] = $this->getVersionInfo($context);
        if (!empty($this->requestStack->getCurrentRequest())) {
            $requested = $this->requestStack->getCurrentRequest();
            $systemUrl =  $requested->attributes->get(RequestTransformer::SALES_CHANNEL_ABSOLUTE_BASE_URL)
            . $requested->attributes->get(RequestTransformer::SALES_CHANNEL_BASE_URL);

            if(!empty($systemUrl)) {
                $transaction['system_url'] = $systemUrl;
            }

        }

        return $transaction;
    }

    /**
     * Return the payment merchant parameter data
     *
     * @param string $saleschannelId

     * @return array
     */
    public function merchantParameter(string $saleschannelId): array
    {
        return [
            'signature' => $this->getNovalnetPaymentSettings('NovalnetPayment.settings.clientId', $saleschannelId),
            'tariff'    => $this->getNovalnetPaymentSettings('NovalnetPayment.settings.tariff', $saleschannelId),
            'referrer_id' => '42'
        ];
    }

    /**
     * Get the Novalnet Payment Id
     *
     * @param Context $context
     *
     * @return string|null
     */
    public function getNovalnetPaymentId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', 'Novalnet\NovalnetPayment\Service\NovalnetPayment'));

        $paymentMethod = $this->container->get('payment_method.repository')->search($criteria, $context)->first();

        return $paymentMethod?->getId();
    }

    /**
     * Get the order number from the novalnet subscription table
     *
     * @param string $aboId
     * @param Context $context
     *
     * @return string|null
     */
    public function getOrderNumber(string $aboId, Context $context): ?string
    {
        $criteria = new Criteria([$aboId]);
        $criteria->addAssociation('order');
        $criteria->setLimit(1);

        $abo = $this->container->get('novalnet_subscription.repository')->search($criteria, $context)->first();

        return $abo?->get('order')?->getOrderNumber();
    }
}
