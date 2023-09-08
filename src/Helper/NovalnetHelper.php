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

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;

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
     * @var NovalnetOrderTransactionHelper
     */
    protected $transactionHelper;
    
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
     * @var EntityRepository
     */
    protected $themeRepository;
    /**
     * @var EntityRepository
     */
    protected $customerRepository;
    /**
     * @var CurrencyFormatter
     */
    protected $currencyFormatter;

    /**
     * @var SalesChannelContextPersister
     */
    protected $contextPersister;

    /**
     * @var RouterInterface
     */
    protected $router;
    
    
    /**
     * Constructs a `NovalnetHelper`
     *
     * @param TranslatorInterface $translator
     * @param ContainerInterface $container
     * @param SystemConfigService $systemConfigService
     * @param RequestStack $requestStack
     * @param CurrencyFormatter $currencyFormatter
     * @param SalesChannelContextPersister $contextPersister
     * @param RouterInterface $router
     * @param string $shopVersion

    */

    public function __construct(
        TranslatorInterface $translator,
        ContainerInterface $container,
        SystemConfigService $systemConfigService,
        RequestStack $requestStack,
        CurrencyFormatter $currencyFormatter,
        SalesChannelContextPersister $contextPersister,
        RouterInterface $router,
        string $shopVersion
    ) {
        $this->translator             = $translator;
        $this->router                 = $router;
        $this->container              = $container;
        $this->systemConfigService    = $systemConfigService;
        $this->requestStack           = $requestStack;
        $this->languageRepository     = $this->container->get('language.repository');
        $this->themeRepository        = $this->container->get('theme.repository');
        $this->customerRepository     = $this->container->get('customer.repository');
        $this->currencyFormatter      = $currencyFormatter;
        $this->contextPersister       = $contextPersister;
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
    public function sendPostRequest(array $parameters, string $url, string $accessKey) : array
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
            if (json_last_error() === 0) {
                return $result;
            }
        }

        return $result;
    }
    
    /**
     * Format the given string.
     *
     * @param string $string
     * @param string $find
     * @param string $replace
     *
     * @return string
     */
    public function formatString(string $string, string $find = 'novalnet', string $replace = '') : string
    {
        return str_ireplace($find, $replace, $string);
    }
    /**
     * Get action URL
     *
     * @param string $action
     *
     * @return string
     */
    public function getActionEndpoint(string $action = '') : string
    {
        return $this->endpoint . str_replace('_', '/', $action);
    }
    
    /**
     * Perform unserialize data.
     *
     * @param string|null $data
     * @param bool $needAsArray
     *
     * @return array
     */
    public function unserializeData(string $data = null, bool $needAsArray = true): array
    {
        $result = [];
        if (!empty($data)) {
            $result = json_decode($data, $needAsArray, 512, JSON_BIGINT_AS_STRING);
            if (json_last_error() === 0) {
                return $result;
            }
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
     * @param Context $context
     *
     * @return string
     */
    public function getVersionInfo(Context $context) : string
    {
        return $this->shopVersion . '-NN' . '13.2.0';
    }
    
    /**
     * get the novalnet Iframe From Url.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $transaction
     * @param string $paymentMethod
     * @param boolean $subscription
     *
     *
     * @return string
     */
    public function getNovalnetIframeUrl(SalesChannelContext $salesChannelContext, $transaction, string $paymentMethod, bool $subscription = false) : string
    {
        $paymentSettings = $this->getNovalnetPaymentSettings($salesChannelContext->getSalesChannel()->getId());
         // Start to built basic parameters.
        $redirectUrl = '';
        $amount = 0;
        if (method_exists($transaction, 'getOrder')) {
            $amount = $this->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice());
        } elseif (method_exists($transaction, 'getCart')) {
            $amount = $this->amountInLowerCurrencyUnit($transaction->getCart()->getprice()->getTotalPrice());
        }
        
        $themename = $this->getThemeName($salesChannelContext->getsalesChannel()->getId(), $salesChannelContext->getContext());
         // Built merchant parameters.
        $parameters['merchant'] = [
            'signature' => str_replace(' ', '', $paymentSettings['NovalnetPayment.settings.clientId']),
            'tariff'    => $paymentSettings['NovalnetPayment.settings.tariff']
        ];
        $customer = $salesChannelContext->getCustomer();
        if (!empty($customer)) {
            $parameters['customer'] = $this->getCustomerData($customer);
        }
        $parameters['transaction'] = [
            'amount'           => $amount,
            'currency'         => $salesChannelContext->getCurrency()->getisoCode(),
            'system_ip'        => $_SERVER['SERVER_ADDR'],
            'system_name'      => 'shopware6',
            'system_version'   => $this->getVersionInfo($salesChannelContext->getContext()) . '-NNT' .$themename,
        ];
        $parameters['hosted_page'] = [
            'hide_blocks'      => ['ADDRESS_FORM', 'SHOP_INFO', 'LANGUAGE_MENU', 'TARIFF','HEADER'],
            'skip_pages'       => ['CONFIRMATION_PAGE', 'SUCCESS_PAGE', 'PAYMENT_PAGE'],
            'type' => 'PAYMENTFORM'
        ];
        
        

        if (!empty($subscription)) {
            $parameters['hosted_page']['display_payments_mode'] = ['SUBSCRIPTION'];
        }
        $parameters['custom'] = [
            'lang'      => $this->getLocaleCodeFromContext($salesChannelContext->getContext()),
        ];

        $response = $this->sendPostRequest($parameters, $this->getActionEndpoint('seamless_payment'), str_replace(' ', '', $paymentSettings['NovalnetPayment.settings.accessKey']));
        
        if ($response['result']['status'] == 'SUCCESS') {
            $redirectUrl = $response['result']['redirect_url'];
        };

        return $redirectUrl;
    }
    
    /**

     * @param Context $context

     * @param string $saleschannelId
     *
     * @return string
     */
    public function getThemeName(string $saleschannelId, Context $context) : string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salesChannels.id', $saleschannelId));
        $theme = $this->themeRepository->search($criteria, $context);
        $themecollection = $theme->getelements();
        $themeTechnicalName = '';
        foreach ($themecollection as $key => $data) {
            if ($data->isActive() == 1) {
                $themeTechnicalName = $data->getTechnicalName();
                break;
            }
        }
        return $themeTechnicalName;
    }

    /**
     * Returns the Novalnet backend configuration.
     *
     * @param string $salesChannelId
     *
     * @return array
     */
    public function getNovalnetPaymentSettings(string $salesChannelId): array
    {
        return $this->systemConfigService->getDomain(
            'NovalnetPayment.settings',
            $salesChannelId,
            true
        );
    }
    
     /**
     * Get formatted Novalnet payment
     *
     * @param PaymentMethodEntity|null $paymentMethodEntity

     * @return string
     */
    public function getPaymentMethodName(PaymentMethodEntity $paymentMethodEntity = null): ?string
    {
        $paymentMethodName = '';
        if (!empty($paymentMethodEntity) && method_exists($paymentMethodEntity, 'getShortName') && $paymentMethodEntity->getShortName() !== null) {
            $paymentMethodName = (new CamelCaseToSnakeCaseNameConverter())->denormalize((string) $paymentMethodEntity->getShortName());
            $paymentMethodName = strtolower($paymentMethodName);
        } elseif (!empty($paymentMethodEntity) && !empty($paymentMethodEntity->getCustomFields()) && !empty($paymentMethodEntity->getCustomFields()['novalnet_payment_method_name'])) {
            $paymentMethodName = $paymentMethodEntity->getCustomFields()['novalnet_payment_method_name'];
        }
        return $paymentMethodName;
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
     * @param string $type
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
        return  $amount * 100;
    }
    
     /**
     * Get the system IP address.
     *
     * @param string $type
     * @return string
     */
    public function getIp(string $type = 'REMOTE') : string
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
     * @param Context $context
     * @param boolean $formattedLocale
     * @param string|null $languageId
     *
     * @return string
     */
    public function getLocaleCodeFromContext(Context $context, bool $formattedLocale = false, string $languageId = null): string
    {
        $languageId = $languageId ?? $context->getLanguageId();
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');
        $languageCollection = $this->languageRepository->search($criteria, $context)->getEntities();

        $language = $languageCollection->get($languageId);
        if (!$formattedLocale) {
            if (null === $language) {
                return 'DE';
            }

            $locale = $language->getLocale();
            if (!$locale) {
                return 'DE';
            }
            $lang = explode('-', $locale->getCode());
            return strtoupper($lang[0]);
        } else {
            if (null === $language) {
                return 'de-DE';
            }

            $locale = $language->getLocale();
            if (!$locale) {
                return 'de-DE';
            }
            if (!in_array($locale->getCode(), ['de-DE', 'en-GB'])) {
                $languageID = Defaults::LANGUAGE_SYSTEM;
                $languageCriteria = new Criteria([$languageID]);
                $languageCriteria->addAssociation('locale');
                $language = $this->languageRepository->search($languageCriteria, $context)->first();
                return $language->getLocale()->getCode();
            }

            return in_array($locale->getCode(), ['de-DE', 'en-GB']) ? $locale->getCode() : 'de-DE';
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
        return (bool) ((! empty($data['result']['status']) && 'SUCCESS' === $data['result']['status']) || (! empty($data['status']) && 'SUCCESS' === $data['status']));
    }
    
    /**
     * Form Bank details comments.
     *
     * @param array $input
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function formBankDetails(array $input, Context $context, string $languageId = null) : string
    {
        $comments = $this->formOrderComments($input, $context, $languageId);
        $localeCode = $this->getLocaleCodeFromContext($context, true, $languageId);
        $paymentType = $input['transaction']['payment_type'];
        $translator = $this->translator;
        
        if (isset($input ['transaction']['amount']) && $input ['transaction']['amount']== 0 && in_array($paymentType, ['PREPAYMENT', 'INVOICE'])) {
            $comments .= '';
        } elseif (!empty($input['transaction']['status']) && $input['transaction']['status'] != 'DEACTIVATED') {
            if (!empty($input['transaction']['status'] === 'PENDING' && preg_match('/INVOICE/', $paymentType) && (preg_match('/GUARANTEED/', $paymentType) || preg_match('/INSTALMENT/', $paymentType)))
                
            ) {
                $comments .= $this->newLine . $translator->trans('NovalnetPayment.text.invoiceGuaranteePendingMsg', [], null, $localeCode);
            } elseif (!empty($input ['transaction']['bank_details'])) {
                $bankDetails = $input ['transaction']['bank_details'];
                if ($input['transaction']['amount'] != 0) {
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
               
                // Form reference comments.
                $comments .= $this->newLine . $translator->trans('NovalnetPayment.text.paymentReferenceNote', [], null, $localeCode). $this->newLine;
                /* translators: %s:  TID */
                $comments .=  sprintf($translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '1', 'TID '. $input ['transaction']['tid']);
                
                if (! empty($input ['transaction']['invoice_ref'])) {
                    $comments .=  $this->newLine . sprintf($translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '2', $input ['transaction']['invoice_ref']);
                }
            }
            
            if (!empty($input['transaction']['nearest_stores'])) {
                if (! empty($input ['transaction']['due_date'])) {
                    $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.slipExpiryDate', [], null, $localeCode), date('d/m/Y', strtotime($input ['transaction']['due_date']))) . $this->newLine . $this->newLine;
                }

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
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.instalmentPaymentReference', [], null, $localeCode), $input['transaction']['partner_payment_reference']);
            }
        }
        
        return $comments;
    }
    
    /**
     * Form payment comments.
     *
     * @param array $input
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function formOrderComments(array $input, Context $context, string $languageId = null) : string
    {
        $comments = '';
        $localeCode = $this->getLocaleCodeFromContext($context, true, $languageId);
        $paymentType = $input['transaction']['payment_type'];
        $paymentdata = $this->getSession('novalnetPaymentdata');
        if (!empty($input ['transaction']['tid'])) {
            $comments .= sprintf($this->translator->trans('NovalnetPayment.text.transactionId', [], null, $localeCode), $input ['transaction']['tid']) ;
            if (!empty($input ['transaction'] ['test_mode'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.testOrder', [], null, $localeCode);
            }
            if ($input['transaction']['status'] === 'PENDING' &&
                (preg_match('/SEPA/', $paymentType)
                && (preg_match('/GUARANTEED/', $paymentType) || preg_match('/INSTALMENT/', $paymentType)))) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.sepaGuaranteePendingMsg', [], null, $localeCode);
            }
        }

        if (!empty($input['transaction']['status']) && $input['transaction']['status'] === 'CONFIRMED' && $input['transaction']['amount'] == 0 && in_array($paymentType, ['CREDITCARD', 'DIRECT_DEBIT_SEPA', 'GOOGLEPAY', 'DIRECT_DEBIT_ACH']) && (!empty($paymentdata['booking_details']['payment_action']) && $paymentdata['booking_details']['payment_action'] == 'zero_amount')) {
            $comments .= $this->newLine . $this->newLine . $this->translator->trans('NovalnetPayment.text.zeroAmountAlertMsg', [], null, $localeCode);
        }

        if ((! empty($input['result']['status']) && 'FAILURE' === $input['result']['status'])) {
            $comments .= $this->newLine . $this->newLine . $input ['result']['status_text'];
        }
        
        return $comments;
    }
    
    /**
     * Converting given amount into bigger unit
     *
     * @param int $amount
     * @param string $currency
     * @param Context|null $context
     *
     * @return string
     */
    public function amountInBiggerCurrencyUnit(int $amount, string $currency = '', Context $context = null) : ?string
    {
        $formatedAmount = (float) sprintf('%.2f', $amount / 100);
        if (!empty($currency)) {
            if (empty($context)) {
                $context        = Context::createDefaultContext();
            }
            $formatedAmount = $this->currencyFormatter->formatCurrencyByLanguage($formatedAmount, $currency, $context->getLanguageId(), $context);
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
    public function getResponseText(array $data) : string
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
     * @param string $accessKey
     * @param string $txnSecret
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
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    public function fetchTransactionDetails(Request $request, SalesChannelContext $salesChannelContext): array
    {
        $paymentSettings = $this->getNovalnetPaymentSettings($salesChannelContext->getSalesChannel()->getId());
        $transactionDetails= [];
        if ($request->get('tid')) {
            $parameter = [
                'transaction' => [
                    'tid' => $request->get('tid')
                ],
                'custom' => [
                    'lang' => $this->getLocaleCodeFromContext($salesChannelContext->getContext())
                ]
            ];
            $transactionDetails = $this->sendPostRequest($parameter, $this->getActionEndpoint('transaction_details'), $paymentSettings['NovalnetPayment.settings.accessKey']);
        }
        return $transactionDetails;
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
     * Update Novalnet Transaction Data
     *
     * @param array $data
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
     * @param string $orderId
     *
     * @return string
     */
    public function getLocaleFromOrder(string $orderId): string
    {
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('language');
        $orderCriteria->addAssociation('language.locale');
        $order  = $this->container->get('order.repository')->search($orderCriteria, Context::createDefaultContext())->first();
        $locale = $order->getLanguage()->getLocale();
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
     * @return bool
     */
    public function orderBackendPaymentData(array $paymentDetails, array $customer, Context $context): string
    {
        $result = '';
        if (!empty($customer)) {
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
        }
        
        return $result;
    }
    public function getCustomerDetails(string $customerid) : ?array
    {
        
        $customerCriteria = new Criteria([$customerid]);
        $customerDetails = $this->container->get('customer.repository')->search($customerCriteria, Context::createDefaultContext())->first();
        return !empty($customerDetails->getcustomFields()) ? $customerDetails->getcustomFields() : [];
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
     *
     */
    public function removeSession(string $key)
    {
        $this->requestStack->getSession()->remove($key);
    }
    
    /**
     * Get Updated Payment Type
     *
     * @param string $type
     *
     *  @return string
     */
    
    function getUpdatedPaymentType($type) : string
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
        'novalnetbancontact'        => 'BANCONTACT'
        ];
  
        if (!empty($types[$type])) {
            return $types[$type];
        }

        return $type;
    }
    
     /**
     * Get Country Code
     *
     * @param string $type
     *
     *  @return string
     */
    
    public function getCountry(string $countryId) : string
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
     *
     *  @return string
     */
    function getUpdatedPaymentName($type) : string
    {
        $types = [
        'CREDITCARD'                   => 'Credit/Debit Cards',
        'DIRECT_DEBIT_SEPA'            => 'Direct Debit SEPA',
        'GUARANTEED_DIRECT_DEBIT_SEPA' => 'Direct Debit SEPA',
        'INSTALMENT_DIRECT_DEBIT_SEPA' => 'Instalment by SEPA direct debit',
        'INVOICE'                      => 'Invoice',
        'GUARANTEED_INVOICE'           => 'Invoice',
        'INSTALMENT_INVOICE'           => 'Instalment by invoice',
        'PREPAYMENT'                   => 'Prepayment',
        'CASHPAYMENT'                  => 'Cash Payment',
        'ONLINE_TRANSFER'              => 'Sofort',
        'IDEAL'                        => 'iDEAL',
        'GIROPAY'                      => 'Giropay',
        'EPS'                          => 'eps',
        'PAYPAL'                       => 'PayPal',
        'PRZELEWY24'                   => 'Przelewy24',
        'POSTFINANCE'                  => 'PostFinance E-Finance',
        'POSTFINANCE_CARD'             => 'PostFinance Card',
        'MULTIBANCO'                   => 'Multibanco',
        'BANCONTACT'                   => 'Bancontact',
        'APPLEPAY'                     => 'Apple Pay',
        'GOOGLEPAY'                    => 'Google Pay',
        'TRUSTLY'                      => 'Trustly',
        'ALIPAY'                       => 'Alipay',
        'WECHATPAY'                    => 'WeChat Pay'
        ];
  
        if (!empty($types[$type])) {
            return $types[$type];
        }

        return $type;
    }
    
    /**
     * Get Transaction Payment Name
     *
     * @param string $type
     *
     *  @return string
     */
    function getTransactionPaymentName($type) : string
    {
        $types = [
        'CREDITCARD'                   => 'novalnetcreditcard',
        'DIRECT_DEBIT_SEPA'            => 'novalnetsepa',
        'GUARANTEED_DIRECT_DEBIT_SEPA' => 'novalnetsepaguarantee',
        'INVOICE'                      => 'novalnetinvoice',
        'GUARANTEED_INVOICE'           => 'novalnetinvoiceguarantee',
        'PREPAYMENT'                   => 'novalnetprepayment',
        'CASHPAYMENT'                  => 'novalnetcashpayment',
        'MULTIBANCO'                   => 'novalnetmultibanco',
        'APPLEPAY'                     => 'novalnetapplepay',
        'GOOGLEPAY'                    => 'novalnetgooglepay',
        ];
  
        if (!empty($types[$type])) {
            return $types[$type];
        }

        return $type;
    }
    
    /**
     * Get error message from session.
     *
     * @param string $transactionId
     * @param string $saleschannelId
     *
     * @return string
     */
    public function getNovalnetErrorMessage(string $transactionId, string $saleschannelId) : ?string
    {
        $errorMessage = '';
        if ($this->requestStack->getSession()->has('novalnetErrorMessage')) {
            $errorMessage = $this->requestStack->getSession()->get('novalnetErrorMessage');
            $this->requestStack->getSession()->remove('novalnetErrorMessage');
        }
        return $errorMessage;
    }
    
    /**
     * get language
     *
     * @param Request $request

     *
     * @return string
     */
    
    public function getCurrentRequest(Request $request) : ?string
    {
        $currentrequest = $this->requestStack->getCurrentRequest();
        return  $currentrequest;
    }
    
     /**
    * get the Order Language Id
    *
    *  @param string $orderId
    *  @param Context $context
    *
    *  @return string
    */
    
    public function getOrderLanguageId(string $orderId, Context $context) : ?string
    {
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('language');
        $orderCriteria->addAssociation('language.locale');
        $order  = $this->container->get('order.repository')->search($orderCriteria, Context::createDefaultContext())->first();
        return $order->getLanguageId() ? $order->getLanguageId() : '';
    }
}
