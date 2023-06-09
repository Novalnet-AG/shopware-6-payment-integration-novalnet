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
use Novalnet\NovalnetPayment\Components\NovalnetPaymentTokenRepository;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
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
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\Country\CountryEntity;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Intl\Countries;
use Symfony\Component\Serializer\NameConverter\CamelCaseToSnakeCaseNameConverter;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

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
     * @var SessionInterface
     */
    protected $session;

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
     * @var NovalnetPaymentTokenRepository
     */
    public $paymentTokenRepository;

    /**
     * @var RequestStack
     */
    protected $requestStack;

    /**
     * @var EntityRepositoryInterface
     */
    protected $languageRepository;

    /**
     * @var CurrencyFormatter
     */
    protected $currencyFormatter;

    /**
     * @var RouterInterface
     */
    protected $router;

    /**
     * @var array
     */
    protected $supports = [
        'authorize'        => [
            'novalnetapplepay',
            'novalnetgooglepay',
            'novalnetcreditcard',
            'novalnetsepa',
            'novalnetpaypal',
            'novalnetinvoice',
            'novalnetsepaguarantee',
            'novalnetinvoiceguarantee',
            'novalnetinvoiceinstalment',
            'novalnetsepainstalment'
        ],
        'guarantee'        => [
            'novalnetsepaguarantee',
            'novalnetinvoiceguarantee'
        ],
        'instalment'      => [
            'novalnetinvoiceinstalment',
            'novalnetsepainstalment'
        ],
        'payLater'        => [
            'novalnetinvoice',
            'novalnetprepayment',
            'novalnetcashpayment',
            'novalnetmultibanco',
        ]
    ];

    public function __construct(
        NovalnetPaymentTokenRepository $paymentTokenRepository,
        TranslatorInterface $translator,
        ContainerInterface $container,
        SystemConfigService $systemConfigService,
        RequestStack $requestStack,
        CurrencyFormatter $currencyFormatter,
        RouterInterface $router,
        string $shopVersion
    ) {
        $this->paymentTokenRepository = $paymentTokenRepository;
        $this->translator          = $translator;
        $this->router              = $router;
        $this->container           = $container;
        $this->systemConfigService = $systemConfigService;
        $this->requestStack        = $requestStack;
        $this->languageRepository  = $this->container->get('language.repository');
        $this->currencyFormatter   = $currencyFormatter;
        $this->shopVersion         = $shopVersion;
    }

    /**
     * Get Shopware & Novalnet version information
     * @param Context $context
     *
     * @return string
     */
    public function getVersionInfo(Context $context) : string
    {
        return $this->shopVersion . '-NN' . '12.5.5';
    }

    /**
     * Returns the supported Novalnet payment based on process
     *
     * @param string $process
     * @param string $paymentType
     *
     * @return bool
     */
    public function getSupports(string $process, string $paymentType) : bool
    {
        if (! empty($this->supports[ $process ])) {
            if ('' !== $paymentType) {
                return in_array($paymentType, $this->supports[ $process ], true);
            }
        }
        return false;
    }
    
    
    /**
     * Set Session using key and data
     *
     * @param string $key
     * @param $
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
     * Format due_date.
     *
     * @param int $days
     *
     * @return string
     */
    public function formatDueDate(int $days) : string
    {
        return date('Y-m-d', mktime(0, 0, 0, (int) date('m'), (int) (date('d') + $days), (int) date('Y')));
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
            $ipAddress = $this->getRemoteAddress();
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
     * Get user remote ip address
     *
     * @return string|null
     */
    public function getRemoteAddress()
    {
        $ipKeys = ['HTTP_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                foreach (explode(',', $_SERVER[$key]) as $ip) {
                    // trim for safety measures
                    return trim($ip);
                }
            }
        }

        return $_SERVER['REMOTE_ADDR'];
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

                // The charset should be "utf-8"
                'charset' => 'utf-8',

                // Optional
                'Accept' => 'application/json',

                // The formed authenticate value (case-sensitive)
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
     * Returns the Novalnet backend configuration.
     *
     * @param string $key
     * @param string $salesChannelId
     *
     * @return mixed
     */
    public function getNovalnetPaymentSettings(string $key, string $salesChannelId): mixed
    {
		if (empty($this->systemConfigService->get($key, $salesChannelId)))
		{
			return $this->systemConfigService->get($key, null);
		}
        return $this->systemConfigService->get($key, $salesChannelId);
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
     * Get last charaters from the string
     *
     * @param string $input
     *
     * @return string
     */
    public function getLastCharacters(string $input) : string
    {
        return substr($input, -4);
    }

    /**
     * Get formatted Novalnet payment
     *
     * @param PaymentMethodEntity $paymentMethodEntity
     *
     * @return string
     */
    public function getPaymentMethodName(PaymentMethodEntity $paymentMethodEntity): ?string
    {
        $paymentMethodName = '';
        if (method_exists($paymentMethodEntity, 'getShortName') && $paymentMethodEntity->getShortName() !== null) {
            $paymentMethodName = (new CamelCaseToSnakeCaseNameConverter())->denormalize((string) $paymentMethodEntity->getShortName());
            $paymentMethodName = strtolower($paymentMethodName);
        } elseif (!empty($paymentMethodEntity->getCustomFields()['novalnet_payment_method_name'])) {
            $paymentMethodName = $paymentMethodEntity->getCustomFields()['novalnet_payment_method_name'];
        }
        return $paymentMethodName;
    }

    /**
     * Get address
     *
     * @param CustomerEntity|null $customerEntity
     * @param string $type
     *
     * @return array
     */
    public function getAddress(CustomerEntity $customerEntity = null, string $type = 'billing'): array
    {
        $address  = [];
        $customer = [];
        if (!is_null($customerEntity)) {
            if ($type === 'shipping') {
                $addressData = $customerEntity->getActiveShippingAddress() ?? $customerEntity->getDefaultShippingAddress();
            } else {
                $addressData = $customerEntity->getActiveBillingAddress() ?? $customerEntity->getDefaultBillingAddress();
            }
            if (!is_null($addressData) && !is_null($addressData->getCountry())) {
                list($customer ['first_name'], $customer ['last_name']) = $this->retrieveName(
                    [
                        $addressData->getFirstName(),
                        $addressData->getLastName(),
                    ]
                );

                $customer['email'] = $customerEntity->getEmail();
                if (!is_null($addressData->getCompany())) {
                    $address['company'] = $addressData->getCompany();
                }

                $address['street'] = $addressData->getStreet().' '.$addressData->getAdditionalAddressLine1().' '.$addressData->getAdditionalAddressLine2();
                $address['city'] = $addressData->getCity();
                $address['zip'] = $addressData->getZipCode();
                $address['country_code'] = $addressData->getCountry()->getIso();
                if (!empty($addressData->getCountryState())) {
                    $address['state'] = strtolower($addressData->getCountryState()->getTranslated()['name']);
                }
            }
        }

        return [$customer, $address];
    }

    /**
     * Retrieve the name of the end user.
     *
     * @param array $name
     *
     * @return array
     */
    public function retrieveName(array $name) : array
    {

        // Retrieve first name and last name from order objects.
        if (empty($name['0'])) {
            $name['0'] = $name['1'];
        }
        if (empty($name['1'])) {
            $name['1'] = $name['0'];
        }
        return $name;
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
        if (! empty($currency)) {
            if (is_null($context)) {
                $context        = Context::createDefaultContext();
            }
            $formatedAmount = $this->currencyFormatter->formatCurrencyByLanguage($formatedAmount, $currency, $context->getLanguageId(), $context);
        }
        return (string) $formatedAmount;
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
     * Form payment comments.
     *
     * @param array $input
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function formBasicComments(array $input, Context $context, string $languageId = null) : string
    {
        $comments = '';
        $localeCode = $this->getLocaleCodeFromContext($context, true, $languageId);

        if ($input ['transaction']['payment_type'] == 'GOOGLEPAY' && !empty($input['transaction']['payment_data']['card_brand'])) {
            $comments .= sprintf($this->translator->trans('NovalnetPayment.text.googleMessage', [], null, $localeCode), strtolower($input['transaction']['payment_data']['card_brand']), $input['transaction']['payment_data']['last_four']). $this->newLine;
        }

        if ($input ['transaction']['payment_type'] == 'APPLEPAY' && !empty($input['transaction']['payment_data']['card_brand'])) {
            $comments .= sprintf($this->translator->trans('NovalnetPayment.text.applepayMessage', [], null, $localeCode), strtolower($input['transaction']['payment_data']['card_brand']), $input['transaction']['payment_data']['last_four']). $this->newLine;
        }

        if (! empty($input ['transaction']['tid'])) {
            $comments .= sprintf($this->translator->trans('NovalnetPayment.text.transactionId', [], null, $localeCode), $input ['transaction']['tid']);
            if (! empty($input ['transaction'] ['test_mode'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.testOrder', [], null, $localeCode);
            }

            if ($input['transaction']['status'] === 'PENDING' && in_array($input['transaction']['payment_type'], ['GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.sepaGuaranteePendingMsg', [], null, $localeCode);
            }
        }

        if (($input ['transaction']['payment_type'] == 'CREDITCARD' || $input ['transaction']['payment_type'] == 'DIRECT_DEBIT_SEPA') && $input['transaction']['status'] === 'CONFIRMED' && $input['transaction']['amount'] == 0) {
            $comments .= $this->newLine . $this->newLine . $this->translator->trans('NovalnetPayment.text.zeroAmountAlertMsg', [], null, $localeCode);
        }

        if ((! empty($input['result']['status']) && 'FAILURE' === $input['result']['status'])) {
            $comments .= $this->newLine . $this->newLine . $input ['result']['status_text'];
        }
        return $comments;
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
        $comments = $this->formBasicComments($input, $context, $languageId);
        $localeCode = $this->getLocaleCodeFromContext($context, true, $languageId);
        
        if ($input ['transaction']['amount'] == 0 && in_array($input['transaction']['payment_type'], ['PREPAYMENT', 'INVOICE'])) {
            $comments .= '';
        } else {
            if ($input['transaction']['status'] === 'PENDING' && in_array($input['transaction']['payment_type'], ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.invoiceGuaranteePendingMsg', [], null, $localeCode);
            } elseif (!empty($input ['transaction']['bank_details']) && empty($input ['instalment']['prepaid']) && in_array($input['transaction']['payment_type'], ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE', 'PREPAYMENT', 'INVOICE'])) {
                if (! empty($input['instalment']['cycle_amount'])) {
                    $amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit($input['instalment']['cycle_amount'], $input ['transaction']['currency']);
                } else {
                    $amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit($input ['transaction']['amount'], $input ['transaction']['currency']);
                }

                if (!empty($amountInBiggerCurrencyUnit)) {
                    if (in_array($input['transaction']['status'], [ 'CONFIRMED', 'PENDING' ], true) && ! empty($input ['transaction']['due_date'])) {
                        $comments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.amountTransaferNoteWithDueDate', [], null, $localeCode), $amountInBiggerCurrencyUnit, date('d/m/Y', strtotime($input ['transaction']['due_date']))) . $this->newLine . $this->newLine;
                    } else {
                        $comments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.amountTransaferNote', [], null, $localeCode), $amountInBiggerCurrencyUnit) . $this->newLine . $this->newLine;
                    }
                }

                foreach ([
                    'account_holder' => $this->translator->trans('NovalnetPayment.text.accountHolder', [], null, $localeCode),
                    'bank_name'      => $this->translator->trans('NovalnetPayment.text.bank', [], null, $localeCode),
                    'bank_place'     => $this->translator->trans('NovalnetPayment.text.bankPlace', [], null, $localeCode),
                    'iban'           => $this->translator->trans('NovalnetPayment.text.iban', [], null, $localeCode),
                    'bic'            => $this->translator->trans('NovalnetPayment.text.bic', [], null, $localeCode),
                ] as $key => $text) {
                    if (! empty($input ['transaction']['bank_details'][ $key ])) {
                        $comments .= sprintf($text, $input ['transaction']['bank_details'][ $key ]) . $this->newLine;
                    }
                }

                // Form reference comments.
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.paymentReferenceNote', [], null, $localeCode). $this->newLine;
                /* translators: %s:  TID */
                $comments .=  sprintf($this->translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '1', 'TID '. $input ['transaction']['tid']);

                /* translators: %s: invoice_ref */
                if (! empty($input ['transaction']['invoice_ref'])) {
                    $comments .=  $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '2', $input ['transaction']['invoice_ref']);
                }
            }
        }

        return $comments;
    }

    /**
     * Form Barzahlen/Slip details comments.
     *
     * @param array $input
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function formSlipDetails(array $input, Context $context, string $languageId = null) : string
    {
        $comments = $this->formBasicComments($input, $context, $languageId);
        $localeCode = $this->getLocaleCodeFromContext($context, true, $languageId);

        if (! empty($input ['transaction']['due_date'])) {
            /* translators: %1$s: amount, %2$s: due date */
            $comments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.slipExpiryDate', [], null, $localeCode), date('d/m/Y', strtotime($input ['transaction']['due_date']))) . $this->newLine . $this->newLine;
        }

        $comments .= $this->translator->trans('NovalnetPayment.text.cashpaymentStore', [], null, $localeCode) . $this->newLine;

        foreach ($input['transaction']['nearest_stores'] as $key => $nearestStore) {
            foreach ([
                'store_name',
                'street',
                'city',
                'zip',
            ] as $addressData) {
                if (! empty($nearestStore[$addressData])) {
                    $comments .= $nearestStore[$addressData] . $this->newLine;
                }
            }
            if (! empty($nearestStore['country_code'])) {
                if (array_key_last($input['transaction']['nearest_stores']) == $key) {
                    $comments .= Countries::getName($nearestStore['country_code']);
                } else {
                    $comments .= Countries::getName($nearestStore['country_code']) . $this->newLine . $this->newLine;
                }
            }
        }

        return $comments;
    }

    /**
     * Form Multibanco details comments.
     *
     * @param array $input
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function formMultibancoDetails(array $input, Context $context, string $languageId = null) : string
    {
        $comments = $this->formBasicComments($input, $context, $languageId);
        $localeCode = $this->getLocaleCodeFromContext($context, true, $languageId);

        if (! empty($input['transaction']['partner_payment_reference'])) {
            $amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit($input ['transaction']['amount'], $input ['transaction']['currency']);
            $comments .= $this->newLine . $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.multibancoReference', [], null, $localeCode), $amountInBiggerCurrencyUnit). $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentPaymentReference', [], null, $localeCode), $input['transaction']['partner_payment_reference']);
        }

        return $comments;
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
     * Get stored payment token.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $paymentMethod
     * @param bool $default
     *
     * @return array
     */
    public function getStoredData(SalesChannelContext $salesChannelContext, string $paymentMethod, bool $default = false) : array
    {
        $additionalFilter = [];
        $tokens =[];

        $additionalFilter['paymentType'] = $paymentMethod;

        if ($this->getSupports('guarantee', $paymentMethod)) {
            $additionalFilter['paymentType'] = $this->formatString($paymentMethod, 'guarantee');
        } elseif ($this->getSupports('instalment', $paymentMethod)) {
            $additionalFilter['paymentType'] = $this->formatString($paymentMethod, 'instalment');
        }

        if ($default && $this->requestStack->getSession()->has($paymentMethod . 'FormData') && ! empty($paymentMethod)) {
            $sessionData = $this->requestStack->getSession()->get($paymentMethod . 'FormData');
            if (! empty($sessionData['paymentToken'])) {
                $additionalFilter ['token'] = $sessionData['paymentToken'];
            }
        }

        if ($default) {
            $storedData [] = $this->paymentTokenRepository->getLastPaymentToken($salesChannelContext, $additionalFilter);
        } else {
            $storedData = $this->paymentTokenRepository->getPaymentTokens($salesChannelContext, $additionalFilter);
        }

        if (! empty($storedData)) {
            foreach ($storedData as $data) {
                if (! empty($data)) {
                    if (! empty($data->getToken()) && !empty($data->getAccountData())) {
                        $tokens[$data->getToken()]['token'] = $data->getToken();
                        if (! empty($data->getAccountData() && $paymentMethod !== 'novalnetcreditcard')) {
                            $tokens[$data->getToken()]['accountData'] = $data->getAccountData();
                        } else {
                            $tokens[$data->getToken()]['accountData'] = substr($data->getAccountData(), -4);
                        }

                        if (! empty($data->getExpiryDate())) {
                            $tokens[$data->getToken()]['expiryDate'] = date('m/y', strtotime($data->getExpiryDate()->format(DateTime::ATOM)));
                        }
                        if (! empty($data->getType())) {
                            $tokens[$data->getToken()]['type'] = $data->getType();
                        }
                        if (! empty($data->getSubscription())) {
                            $tokens[$data->getSubscription()]['subscription'] = $data->getSubscription();
                        }
                    }
                }
            }
        }

        return $tokens;
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

        if (! empty($data)) {
            $result = json_encode($data, JSON_UNESCAPED_SLASHES);
        }
        return $result;
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
        if (empty($data)) {
            return [];
        }
        $result = json_decode($data, $needAsArray, 512, JSON_BIGINT_AS_STRING);

        if (json_last_error() === 0) {
            return $result;
        }
        return $result ? $result : [];
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
     * Return Shop Version.
     *
     * @return string
     */
    public function getShopVersion(): string
    {
        return $this->shopVersion;
    }

    /**
     * Return the country ID from the country code.
     *
     * @param string $countryCode
     * @param Context $context
     *
     * @return string
     */
    public function getCountryIdFromCode(string $countryCode, Context $context) : ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('iso', $countryCode));
        return $this->container->get('country.repository')
            ->searchIds($criteria, $context)
            ->firstId();
    }

    /**
     * Return the country from the country ID.
     *
     * @param string $countryId
     * @param Context $context
     *
     * @return CountryEntity|null
     */
    public function getCountryFromId(string $countryId, Context $context) : ?CountryEntity
    {
        $criteria = new Criteria([$countryId]);
        return $this->container->get('country.repository')
            ->search($criteria, $context)
            ->first();
    }

    /**
     * Return the country state ID from the state name.
     *
     * @param string $stateCode
     * @param Context $context
     *
     * @return string
     */
    public function getCountryStateIdFromCode(string $stateCode, Context $context) : ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new ContainsFilter('shortCode', $stateCode));
        return $this->container->get('country_state.repository')
            ->searchIds($criteria, $context)
            ->firstId();
    }

    /**
     * Get the salutationID for not specified category.
     *
     * @param Context $context
     *
     * @return string
     */
    public function getSalutationId(Context $context) : ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('salutationKey', 'not_specified'));
        return $this->container->get('salutation.repository')
            ->searchIds($criteria, $context)
            ->firstId();
    }

    /**
     * Get the ApplePay Payment ID from the handlerIdentifier.
     *
     * @param Cart $cart
     *
     * @return float
     */
    public function getShippingCosts(Cart $cart): float
    {
        return $cart->getDeliveries()->getShippingCosts()->sum()->getTotalPrice();
    }

    /**
     * Built redirect parameters for google pay payment
     *
     * @param array $parameters
     */
    public function getGooglePayRedirectParams(array &$parameters): void
    {
        $parameters ['transaction'] ['return_url']  = $parameters ['transaction'] ['error_return_url']  = $this->router->generate('frontend.novalnet.googlePayRedirect', [], UrlGeneratorInterface::ABSOLUTE_URL);
    }

    /**
     * Return the active shipping methods.
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return ShippingMethodCollection
     */
    public function getActiveShippingMethods(SalesChannelContext $salesChannelContext): ShippingMethodCollection
    {
        $criteria = (new Criteria())
            ->addFilter(new EqualsFilter('active', true))
            ->addFilter(new EqualsFilter('salesChannels.id', $salesChannelContext->getSalesChannel()->getId()))
            ->addFilter(new EqualsAnyFilter('availabilityRuleId', $salesChannelContext->getRuleIds()))
            ->addAssociation('prices')
            ->addAssociation('salesChannels');

        /** @var ShippingMethodCollection $shippingMethods */
        $shippingMethods = $this->container->get('shipping_method.repository')
            ->search($criteria, $salesChannelContext->getContext())
            ->getEntities();

        return $shippingMethods->filterByActiveRules($salesChannelContext);
    }

    /**
     * update customer address.
     *
     * @param array $customerData
     * @param SalesChannelContext $salesChannelContext
     *
     */
    public function updateCustomerShippingAddress(array $customerData, SalesChannelContext $salesChannelContext): void
    {
        $this->container->get('customer_address.repository')->update([$customerData], $salesChannelContext->getContext());
    }

    /**
     * get the order reference details.
     *
     * @param string|null $orderId
     * @param Context $context
     * @param string|null $customerId
     *
     * @return OrderEntity
     */
    public function getOrderCriteria(string $orderId = null, Context $context, string $customerId = null): OrderEntity
    {
        if (!empty($orderId)) {
            $orderCriteria = new Criteria([$orderId]);
        } else {
            $orderCriteria = new Criteria([]);
        }
        if (!empty($customerId)) {
            $orderCriteria->addFilter(
                new EqualsFilter('order.orderCustomer.customerId', $customerId)
            );
        }
        $orderCriteria->addAssociation('orderCustomer.salutation');
        $orderCriteria->addAssociation('orderCustomer.customer');
        $orderCriteria->addAssociation('currency');
        $orderCriteria->addAssociation('stateMachineState');
        $orderCriteria->addAssociation('lineItems');
        $orderCriteria->addAssociation('transactions');
        $orderCriteria->addAssociation('transactions.paymentMethod');
        $orderCriteria->addAssociation('addresses');
        $orderCriteria->addAssociation('deliveries.shippingMethod');
        $orderCriteria->addAssociation('addresses.country');
        $orderCriteria->addAssociation('deliveries.shippingOrderAddress.country');
        $orderCriteria->addAssociation('salesChannel');
        $orderCriteria->addAssociation('price');
        $orderCriteria->addAssociation('taxStatus');
        $orderCriteria->addSorting(
            new FieldSorting('transactions.createdAt', FieldSorting::ASCENDING)
        );
        return $this->container->get('order.repository')->search($orderCriteria, $context)->first();
    }

    /**
     * Retrieve transaction details
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array
     */
    public function retrieveTransactionDetails(Request $request, SalesChannelContext $salesChannelContext): array
    {
        $accessKey = $this->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $salesChannelContext->getSalesChannel()->getId());
        $transactionDetails= [];
        
        if ($request->query->get('tid')) {
            $parameter = [
                'transaction' => [
                    'tid' => $request->query->get('tid')
                ],
                'custom' => [
                    'lang' => $this->getLocaleCodeFromContext($salesChannelContext->getContext())
                ]
            ];
            $transactionDetails = $this->sendPostRequest($parameter, $this->getActionEndpoint('transaction_details'), $accessKey);
        }
        return $transactionDetails;
    }

    /**
     * Return the vat label for sheet.
     *
     * @param float $taxRate
     * @param Context $context
     *
     * @return string
     */
    public function getVatLabel(float $taxRate, Context $context) : string
    {
        $localeCode = $this->getLocaleCodeFromContext($context);
        return str_replace('%vat%', (string) $taxRate, $this->translator->trans('NovalnetPayment.text.vatLabel', [], null, $localeCode));
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

        if (!is_null($customerEntity->getActiveBillingAddress())) {
            if (!is_null($customerEntity->getActiveBillingAddress()->getPhoneNumber())) {
                $customer['tel'] = $customerEntity->getActiveBillingAddress()->getPhoneNumber();
            }
        }

        list($shippingCustomer, $shippingAddress) = $this->getAddress($customerEntity, 'shipping');

        // Add shipping details.
        if (! empty($shippingAddress)) {
            if ($billingAddress === $shippingAddress) {
                $customer ['shipping'] ['same_as_billing'] = 1;
            } else {
                $customer ['shipping'] = $shippingAddress;
                if (! empty($shippingCustomer)) {
                    $customer ['shipping'] = array_merge($customer ['shipping'], $shippingCustomer);
                }
            }
        }

        if (!is_null($customerEntity->getSalutation())) {
            $salutationKey = $customerEntity->getSalutation()->getSalutationKey();
            $customer ['gender'] = ($salutationKey == 'mr') ? 'm' : ($salutationKey == 'mrs' ? 'f' : 'u');
        }

        $customer['customer_ip'] = $this->getIp();
        if (is_null($customer['customer_ip'])) {
            $customer['customer_ip'] = $customerEntity->getRemoteAddress();
        }
        $customer['customer_no'] = $customerEntity->getCustomerNumber();
        return $customer;
    }

    /**
     * Insert/Update Novalnet Transaction Data
     *
     * @param array $data
     * @param Context $context
     *
     * @return void
     */
    public function upsertTransactionData(array $data, Context $context)
    {
        $this->container->get('novalnet_transaction_details.repository')->upsert([$data], $context);
    }

    /**
     * Get Payment method entity
     *
     * @param string $handlerIdentifier
     *
     * @retrun PaymentMethodEntity|null
     */
    public function getPaymentMethodEntity(string $handlerIdentifier): ?PaymentMethodEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', $handlerIdentifier));

        return $this->container->get('payment_method.repository')->search($criteria, Context::createDefaultContext())->first();
    }
    
    /**
     * Insert Subscription Data
     *
     * @param array $data
     * @param Context $context
     *
     * @retrun string
     */
    public function insertSubscriptionData(array $data, Context $context): string
    {
        $data ['id'] = Uuid::randomHex();
        $this->container->get('novalnet_subscription.repository')->upsert([$data], $context);
        return $data ['id'];
    }
    
    /**
     * Insert Subscription Data
     *
     * @param array $data
     * @param Context $context
     *
     * @retrun void
     */
    public function insertSubscriptionCycleData(array $data, Context $context): void
    {
        $this->container->get('novalnet_subs_cycle.repository')->upsert([$data], $context);
    }
}
