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

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\RFCValidation;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\RequestException;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\Currency\CurrencyFormatter;
use Shopware\Core\System\Language\LanguageEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Shopware\Storefront\Framework\Routing\RequestTransformer;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * NovalnetHelper Class.
 */
class NovalnetHelper
{
    /**
     * @var TranslatorInterface
     */
    public $translator;

    /**
     * @var string
     */
    protected $endpoint = 'https://payport.novalnet.de/v2/';

    /**
     * @var SystemConfigService
     */
    protected $systemConfigService;

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
     * @param ContainerInterface $container
     * @param SystemConfigService $systemConfigService
     * @param RequestStack $requestStack
     * @param CurrencyFormatter $currencyFormatter
     * @param string $shopVersion
     */
    public function __construct(
        TranslatorInterface $translator,
        ContainerInterface $container,
        SystemConfigService $systemConfigService,
        RequestStack $requestStack,
        CurrencyFormatter $currencyFormatter,
        string $shopVersion
    ) {
        $this->translator = $translator;
        $this->container = $container;
        $this->systemConfigService = $systemConfigService;
        $this->requestStack = $requestStack;
        $this->languageRepository = $this->container->get('language.repository');
        $this->currencyFormatter = $currencyFormatter;
        $this->shopVersion = $shopVersion;
    }

    /**
     * Sends a POST request
     *
     * @param array $parameters
     * @param string $url
     * @param string $accessKey
     *
     * @return array
     */
    public function sendPostRequest(array $parameters, string $url, string $accessKey): array
    {
        $client = new Client([
            'headers' => [
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
                    'status' => 'FAILURE',
                    'status_code' => '106',
                    'status_text' => $requestException->getMessage(),
                ],
            ];
        }

        return $this->unserializeData($response);
    }

    /**
     * Serializes the given array into a JSON string.
     *
     * @param array $data
     *
     * @return string
     */
    public function serializeData(array $data): string
    {
        $result = '{}';

        if (!empty($data)) {
            $result = json_encode($data, \JSON_UNESCAPED_SLASHES);
        }

        return $result;
    }

    /**
     * Return the endpoint URL by appending the given action.
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
     * Unserializes the given JSON string into an array.
     *
     * @param string|null $data The JSON string to unserialize.
     * @param bool $needAsArray Whether to force the result to be an array.
     * @return array|null The unserialized array or null if the input is empty.
     */
    public function unserializeData(?string $data = null, bool $needAsArray = true): ?array
    {
        $result = [];
        if (!empty($data)) {
            $result = json_decode($data, $needAsArray, 512, \JSON_BIGINT_AS_STRING);
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
     * Return the version info to be used in API calls.
     *
     * @param Context $context The context to use for the API call.
     * @return string The version info to be used in API calls.
     */
    public function getVersionInfo(Context $context): string
    {
        return $this->shopVersion . '-NN13.6.1';
    }

    /**
     * Returns the Novalnet iframe URL to be used in the checkout.
     *
     * @param SalesChannelContext $salesChannelContext The sales channel context.
     * @param $transaction The transaction object.
     * @param string $type Whether the payment is for a subscription.
     * 
     * @return string The Novalnet iframe URL to be used in the checkout.
     */
    public function getNovalnetIframeUrl(SalesChannelContext $salesChannelContext, $transaction, string $type = ''): string
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
        $response = $this->getNovalnetIframeResponse($salesChannelContext->getSaleschannel()->getId(), $salesChannelContext->getCustomer(), $requiredFields, $salesChannelContext->getContext(), $type);
        if ($response['result']['status'] === 'SUCCESS') {
            $this->setSession('nnIframeUrl', $response['result']['redirect_url']);

            return $response['result']['redirect_url'];
        }

        return '';
    }

    /**
     * Retrieves the technical name of the currently active theme for a given sales channel.
     *
     * @param string $saleschannelId The ID of the sales channel to retrieve the theme for.
     * @param Context $context The context to use for the retrieval.
     * @return string The technical name of the active theme.
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
                $themeTechnicalName = $data->getTechnicalName() !== null ? $data->getTechnicalName() : str_replace(' ', '', $data->getName());
                break;
            }
        }

        return $themeTechnicalName;
    }

    /**
     * Retrieve the Novalnet payment settings value for a given key and sales channel.
     * If the value is not set for the given sales channel, it will fall back to the global value.
     *
     * @param string $key The setting key to retrieve.
     * @param string $salesChannelId The ID of the sales channel to retrieve the setting for.
     * @return mixed The retrieved setting value.
     */
    public function getNovalnetPaymentSettings(string $key, string $salesChannelId): mixed
    {
        if ($this->systemConfigService->get($key, $salesChannelId) === null) {
            return $this->systemConfigService->get($key, null);
        }

        return $this->systemConfigService->get($key, $salesChannelId);
    }

    /**
     * Retrieve the customer entity for a given customer ID and context.
     *
     * @param string $customerId The ID of the customer to retrieve.
     * @param Context $context The context to use for the retrieval.
     * @return CustomerEntity|null The retrieved customer entity, or null if not found.
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
     * Retrieve the customer data for a given customer entity.
     *
     * @param CustomerEntity $customerEntity The customer entity to retrieve the data for.
     * @return array The retrieved customer data.
     */
    public function getCustomerData(CustomerEntity $customerEntity)
    {
        $customer = [];
        // Get billing details.
        list($billingCustomer, $billingAddress) = $this->getAddress($customerEntity, 'billing');

        if (!empty($billingCustomer)) {
            $customer = $billingCustomer;
        }
        $customer['billing'] = $billingAddress;

        if (!empty($customerEntity->getActiveBillingAddress()->getPhoneNumber())) {
            $customer['tel'] = $customerEntity->getActiveBillingAddress()->getPhoneNumber();
        }

        list($shippingCustomer, $shippingAddress) = $this->getAddress($customerEntity, 'shipping');

        // Add shipping details.
        if (!empty($shippingAddress)) {
            if ($billingAddress === $shippingAddress) {
                $customer['shipping']['same_as_billing'] = 1;
            } else {
                $customer['shipping'] = $shippingAddress;
            }
        }
        if (!empty($customerEntity->getSalutation())) {
            $salutationKey = $customerEntity->getSalutation()->getSalutationKey();
            $customer['gender'] = ($salutationKey === 'mr') ? 'm' : ($salutationKey === 'mrs' ? 'f' : 'u');
        }

        $customer['customer_ip'] = $this->getIp();
        if (empty($customer['customer_ip'])) {
            $customer['customer_ip'] = $customerEntity->getRemoteAddress();
        }
        $customer['customer_no'] = $customerEntity->getCustomerNumber();

        return $customer;
    }

    /**
     * Retrieve the customer data and address for a given customer entity and type.
     *
     * @param CustomerEntity|null $customerEntity The customer entity to retrieve the data for.
     * @param string $type The type of address to retrieve, either 'billing' or 'shipping'.
     * @return array The retrieved customer data and address.
     */
    public function getAddress(?CustomerEntity $customerEntity, string $type): array
    {
        $address = [];
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

                $address['street'] = $addressData->getStreet() . ' ' . $addressData->getAdditionalAddressLine1() . ' ' . $addressData->getAdditionalAddressLine2();
                $address['city'] = $addressData->getCity();
                $address['zip'] = $addressData->getZipCode();
                $address['country_code'] = $addressData->getCountry()->getIso();
            }
        }

        return [$customer, $address];
    }

    /**
     * Converts the given amount to the lower currency unit.
     *
     * @param float|null $amount The amount to convert.
     * @return float|null The converted amount in the lower currency unit.
     */
    public function amountInLowerCurrencyUnit(?float $amount = null): ?float
    {
        return (float) sprintf('%0.0f', $amount * 100);
    }

    /**
     * Get the IP address
     *
     * @param string $type The type of IP address to get. Defaults to 'REMOTE'.
     *
     * @return string The IP address
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
     * Get the locale code based on the given context.
     *
     * @param Context $context
     * @param bool $formattedLocale
     * @param string|null $languageId
     *
     * @return string
     */
    public function getLocaleCodeFromContext(Context $context, bool $formattedLocale = false, ?string $languageId = null): string
    {
        $languageId = $languageId ?? $context->getLanguageId();
        $criteria = new Criteria([$languageId]);
        $criteria->addAssociation('locale');

        /** @var LanguageEntity|null */
        $languageCollection = $this->languageRepository->search($criteria, $context)->getEntities();

        $language = $languageCollection->get($languageId);
        if (!$formattedLocale) {
            if ($language === null || !$language->getLocale()) {
                return 'DE';
            }
            $locale = $language->getLocale();
            $lang = explode('-', $locale->getCode());

            return strtoupper($lang[0]);
        }
        $languageCode = 'de-DE';
        if ($language === null || !$language->getLocale()) {
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

    /**
     * Checks if the given Novalnet API response is success.
     *
     * @param array $data
     *
     * @return bool
     */
    public function isSuccessStatus(array $data): bool
    {
        return (bool) ((isset($data['result']['status']) && $data['result']['status'] === 'SUCCESS') || (isset($data['status']) && $data['status'] === 'SUCCESS'));
    }

    /**
     * Generate the bank details for the given Novalnet API response.
     *
     * @param array $input
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function formBankDetails(array $input, Context $context, ?string $languageId = null): string
    {
        $comments = $this->formOrderComments($input, $context, $languageId);
        $localeCode = $this->getLocaleCodeFromContext($context, true, $languageId);
        $paymentType = $input['transaction']['payment_type'];
        $translator = $this->translator;

        if (isset($input['transaction']['amount']) && $input['transaction']['amount'] === 0 && in_array($paymentType, ['PREPAYMENT', 'INVOICE'])) {
            $comments .= '';
        } elseif (!empty($input['transaction']['status']) && $input['transaction']['status'] !== 'DEACTIVATED') {
            if ($input['transaction']['status'] === 'PENDING' && in_array($paymentType, ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE'])
            ) {
                $comments .= $this->newLine . $translator->trans('NovalnetPayment.text.invoiceGuaranteePendingMsg', [], null, $localeCode);
            } elseif (!empty($input['transaction']['bank_details'])) {
                $bankDetails = $input['transaction']['bank_details'];

                if (isset($input['instalment']) && isset($input['instalment']['cycle_amount']) && $input['instalment']['cycle_amount'] !== 0) {
                    $amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit($input['instalment']['cycle_amount'], isset($input['instalment']['currency']) ? $input['instalment']['currency'] : $input['transaction']['currency']);
                } elseif ($input['transaction']['amount'] !== 0) {
                    $amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit($input['transaction']['amount'], $input['transaction']['currency']);
                }

                if (!empty($amountInBiggerCurrencyUnit)) {
                    if (in_array($input['transaction']['status'], ['CONFIRMED', 'PENDING']) && !empty($input['transaction']['due_date'])) {
                        $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.amountTransaferNoteWithDueDate', [], null, $localeCode), $amountInBiggerCurrencyUnit, date('d/m/Y', strtotime($input['transaction']['due_date']))) . $this->newLine;
                    } else {
                        $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.amountTransaferNote', [], null, $localeCode), $amountInBiggerCurrencyUnit) . $this->newLine;
                    }
                }

                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.accountHolder', [], null, $localeCode), $bankDetails['account_holder']);
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.bank', [], null, $localeCode), $bankDetails['bank_name']);
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.bankPlace', [], null, $localeCode), $bankDetails['bank_place']);
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.iban', [], null, $localeCode), $bankDetails['iban']);
                $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.bic', [], null, $localeCode), $bankDetails['bic']) . $this->newLine;

                if (!empty($input['transaction']['invoice_ref'])) {
                    // Form reference comments.
                    $comments .= $this->newLine . $translator->trans('NovalnetPayment.text.paymentReferenceNoteAny', [], null, $localeCode) . $this->newLine;
                    $comments .= sprintf($translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '1', $input['transaction']['tid']);
                    $comments .= $this->newLine . sprintf($translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '2', $input['transaction']['invoice_ref']) . $this->newLine;
                } else {
                    // Form reference comments.
                    $comments .= $this->newLine . $translator->trans('NovalnetPayment.text.paymentReferenceNote', [], null, $localeCode) . $this->newLine;
                    $comments .= sprintf($translator->trans('NovalnetPayment.text.paymentReference', [], null, $localeCode), '', $input['transaction']['tid']);
                }
            }

            if (!empty($input['transaction']['partner_payment_reference'])) {
                $amountInBiggerCurrencyUnit = $this->amountInBiggerCurrencyUnit($input['transaction']['amount'], $input['transaction']['currency']);

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
     * Generate the order comments based on the given Novalnet API response.
     *
     * @param array $input
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function formOrderComments(array $input, Context $context, ?string $languageId = null): string
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

        if (!empty($input['transaction']['tid'])) {
            $comments .= sprintf($this->translator->trans('NovalnetPayment.text.transactionId', [], null, $localeCode), $input['transaction']['tid']);
            if (!empty($input['transaction']['test_mode'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.testOrder', [], null, $localeCode);
            }
            if (!empty($input['transaction']['status']) && $input['transaction']['status'] === 'PENDING'
                && in_array($paymentType, ['GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                $comments .= $this->newLine . $this->translator->trans('NovalnetPayment.text.sepaGuaranteePendingMsg', [], null, $localeCode);
            }
        }

        if (!empty($input['transaction']['status']) && $input['transaction']['status'] === 'CONFIRMED' && $input['transaction']['amount'] === 0 && in_array($paymentType, ['CREDITCARD', 'DIRECT_DEBIT_SEPA', 'GOOGLEPAY', 'DIRECT_DEBIT_ACH', 'APPLEPAY']) && ((!empty($paymentdata['booking_details']['payment_action']) && $paymentdata['booking_details']['payment_action'] === 'zero_amount') || (!empty($input['event']) && !empty($input['event']['checksum'])))) {
            if (isset($input['custom']) && isset($input['custom']['input3']) && $input['custom']['input3'] === 'ZeroBooking') {
                $comments .= $this->newLine . $this->newLine . $this->translator->trans('NovalnetPayment.text.zeroAmountAlertMsg', [], null, $localeCode);
            }
        }

        if (isset($input['result']['status']) && $input['result']['status'] === 'FAILURE') {
            $comments .= $this->newLine . $this->newLine . $input['result']['status_text'];
        }

        return $comments;
    }

    /**
     * Converts the given amount in the lower currency unit to the bigger currency unit and format it according to the given currency and context.
     *
     * @param int $amount
     * @param string $currency
     * @param Context|null $context
     * @return string|null
     */
    public function amountInBiggerCurrencyUnit(int $amount, string $currency = '', ?Context $context = null): ?string
    {
        $formatedAmount = (float) sprintf('%.2f', $amount / 100);
        if (!empty($currency)) {
            if (empty($context)) {
                $context = Context::createDefaultContext();
            }
            $formatedAmount = $this->currencyFormatter->formatCurrencyByLanguage($formatedAmount, $currency, $context->getLanguageId(), $context);
        }

        return (string) $formatedAmount;
    }

    /**
     * Get the response text based on the given data.
     *
     * Checks if response text is available in the given data and returns it.
     * If not, returns the default error text.
     *
     * @param array $data
     * @return string
     */
    public function getResponseText(array $data): string
    {
        if (!empty($data['result']['status_text'])) {
            return $data['result']['status_text'];
        }

        if (!empty($data['status_text'])) {
            return $data['status_text'];
        }

        return $this->translator->trans('NovalnetPayment.text.paymentError');
    }

    /**
     * Validates the checksum of a given request.
     *
     * @param Request $request
     * @param string $accessKey
     * @param string $txnSecret
     *
     * @return bool
     */
    public function isValidChecksum(Request $request, string $accessKey, string $txnSecret): bool
    {
        if (!empty($request->get('checksum')) && !empty($request->get('tid')) && !empty($request->get('status')) && !empty($accessKey) && !empty($txnSecret)) {
            $checksum = hash('sha256', $request->get('tid') . $txnSecret . $request->get('status') . strrev($accessKey));
            if ($checksum === $request->get('checksum')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Fetches the transaction details for a given transaction id.
     *
     * @param Request $request
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
     * Updates the transaction details for a given order transaction id.
     *
     * @param array $data
     * @param Context $context
     */
    public function updateTransactionData(array $data, Context $context): void
    {
        $this->container->get('novalnet_transaction_details.repository')->upsert([$data], $context);
    }

    /**
     * Fetches the locale from the given order id.
     *
     * @param string $orderId
     * @param bool $getLanguageId
     * @return string
     */
    public function getLocaleFromOrder(string $orderId, $getLanguageId = false): string
    {
        $orderCriteria = new Criteria([$orderId]);
        $orderCriteria->addAssociation('language');
        $orderCriteria->addAssociation('language.locale');
        $order = $this->container->get('order.repository')->search($orderCriteria, Context::createDefaultContext())->first();
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
     * Validate an email address
     *
     * @param string $mail The email address to check
     * @return bool True if the email is valid, false otherwise
     */
    public function isValidEmail($mail): bool
    {
        return (bool) (new EmailValidator())->isValid($mail, new RFCValidation());
    }

    /**
     * Store the payment details for a given customer in the backend.
     *
     * @param array $paymentDetails The payment details to store
     * @param array $customer The customer data
     * @param Context $context The context
     * @return string 'success' if the data was stored successfully, empty string otherwise
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
                'id' => $customerid,
                'customFields' => $customerDetails->getCustomFields()
            ];

            $this->container->get('customer.repository')->update([$upsertData], $context);

            $result = 'success';
        }

        return $result;
    }

    /**
     * Stores the given value in the session under the given key.
     *
     * @param string $key
     * @param mixed $data
     */
    public function setSession(string $key, $data): void
    {
        $this->requestStack->getSession()->set($key, $data);
    }

    /**
     * Retrieves the value from the session under the given key.
     *
     * @param string $key The key to retrieve the value for.
     * @return mixed The retrieved value.
     */
    public function getSession(string $key)
    {
        return $this->requestStack->getSession()->get($key);
    }

    /**
     * Checks if a session variable exists under the given key.
     *
     * @param string $key The key to check for.
     * @return bool True if the session variable exists, false otherwise.
     */
    public function hasSession(string $key): bool
    {
        return $this->requestStack->getSession()->has($key);
    }

    /**
     * Removes the session variable under the given key.
     *
     * @param string $key The key of the session variable to remove.
     */
    public function removeSession(string $key): void
    {
        $this->requestStack->getSession()->remove($key);
    }

    /**
     * This function is used to get the updated payment type. If the payment type is not found in the list, it returns the given type.
     *
     * @param string $type
     * @param bool $getOldPayment
     * @return string
     */
    public function getUpdatedPaymentType(string $type, bool $getOldPayment = false): string
    {
        $types = [
            'novalnetinvoice' => 'INVOICE',
            'novalnetprepayment' => 'PREPAYMENT',
            'novalnetsepa' => 'DIRECT_DEBIT_SEPA',
            'novalnetsepaguarantee' => 'GUARANTEED_DIRECT_DEBIT_SEPA',
            'novalnetinvoiceguarantee' => 'GUARANTEED_INVOICE',
            'novalnetcreditcard' => 'CREDITCARD',
            'novalnetinvoiceinstalment' => 'INSTALMENT_INVOICE',
            'novalnetsepainstalment' => 'INSTALMENT_DIRECT_DEBIT_SEPA',
            'novalnetmultibanco' => 'MULTIBANCO',
            'novalnetideal' => 'IDEAL',
            'novalneteps' => 'EPS',
            'novalnettrustly' => 'TRUSTLY',
            'novalnetpaypal' => 'PAYPAL',
            'novalnetpostfinancecard' => 'POSTFINANCE_CARD',
            'novalnetpostfinance' => 'POSTFINANCE',
            'novalnetgooglepay' => 'GOOGLEPAY',
            'novalnetapplepay' => 'APPLEPAY',
            'novalnetwechatpay' => 'WECHATPAY',
            'novalnetalipay' => 'ALIPAY',
            'novalnetprzelewy24' => 'PRZELEWY24',
            'novalnetbancontact' => 'BANCONTACT',
            'novalnetonlinebanktransfer' => 'ONLINE_BANK_TRANSFER',
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
            $countryDetails = $this->container->get('country.repository')->search($countryCriteria, Context::createDefaultContext())->first();
            $countryCode = $countryDetails->getIso();
        }

        return $countryCode;
    }

    /**
     * Returns the translated payment name for the given type in the given locale.
     *
     * @param string $type
     * @param string $local
     *
     * @return string|null
     */
    public function getUpdatedPaymentName($type, $local): ?string
    {
        return $this->translator->trans('NovalnetPayment.text.' . strtolower($type), [], null, $local);
    }

    /**
     * Returns the translated payment description for the given type in the given locale.
     *
     * @param string $type
     * @param string $local
     *
     * @return string|null
     */
    public function getPaymentDescription($type, $local): ?string
    {
        return $this->translator->trans('NovalnetPayment.description.' . strtolower($type), [], null, $local);
    }

    /**
     * Retrieves the Novalnet error message from the session and removes it afterwards.
     *
     * @return string|null The error message, or null if it doesn't exist.
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
     * Makes a request to Novalnet to generate an iframe URL for the given payment method.
     *
     * @param string $saleschannelId The ID of the sales channel the request is for.
     * @param CustomerEntity $customer The customer the request is for.
     * @param array $requiredFields The required fields for the request (amount, currency).
     * @param Context $context The context the request is in.
     * @param string $type The type of payment method to request (default '').
     *
     * @return array
     */
    public function getNovalnetIframeResponse(string $saleschannelId, CustomerEntity $customer, array $requiredFields, Context $context, string $type = ''): array
    {
        $parameters['customer'] = $this->getCustomerData($customer);
        $session = [
            'amount' => $this->getSession('cartAmount'),
            'billing' => $this->getSession('billingAddress'),
            'shipping' => $this->getSession('shippingAddress')
        ];

        if (!empty($session['amount']) && !empty($session['billing']) && !empty($session['shipping'])) {
            if (($session['amount'] === $requiredFields['amount']) && ($session['billing'] === $parameters['customer']['billing']) && ($session['shipping'] === $parameters['customer']['shipping'])) {
                if (!empty($this->getSession('nnIframeUrl'))) {
                    $response['result'] = ['status' => 'SUCCESS', 'redirect_url' => $this->getSession('nnIframeUrl')];
                    return $response;
                }
            }
        }

        $parameters['merchant'] = $this->merchantParameter($saleschannelId);
        $parameters['custom']['lang'] = $this->getLocaleCodeFromContext($context);

        $this->setSession('cartAmount', $requiredFields['amount']);
        $this->setSession('billingAddress', $parameters['customer']['billing']);
        $this->setSession('shippingAddress', $parameters['customer']['shipping']);

        $parameters['transaction'] = [
            'amount' => $requiredFields['amount'],
            'currency' => $requiredFields['currency']
        ];

        $parameters['transaction'] = $this->systemParameter($context, $parameters['transaction']);
        $parameters['transaction']['system_version'] = $this->getVersionInfo($context) . '-NNT' . $this->getThemeName($saleschannelId, $context);
        $parameters['hosted_page'] = ['type' => 'PAYMENTFORM'];

        $getCurrentRequest = $this->requestStack->getCurrentRequest();
        $server = $getCurrentRequest ? $getCurrentRequest->server->all() : [];
        if (!empty($server) && !empty($server['REQUEST_URI']) && preg_match('/account\/payment/', $server['REQUEST_URI'])) {
            $parameters['hosted_page']['display_payments_mode'] = ['ALL'];
        } elseif (!empty($type)) {
            $parameters['hosted_page']['display_payments_mode'] = [$type];
        }

        return $this->sendPostRequest($parameters, $this->getActionEndpoint('seamless_payment'), str_replace(' ', '', $this->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $saleschannelId)));
    }

    /**
     * Return the payment request data
     *
     * @param float $amount The amount to be paid.
     * @param string $orderNumber The order number.
     * @param array $data The booking data.
     * @param SalesChannelContext $salesChannelContext The sales channel context.
     *
     * @return array The data to be sent to Novalnet.
     */
    public function getNovalnetRequestData(float $amount, string $orderNumber, array $data, SalesChannelContext $salesChannelContext): array
    {
        // Built merchant parameters.
        $parameters['merchant'] = $this->merchantParameter($salesChannelContext->getSalesChannel()->getId());

        // Built customer parameters.
        if (!empty($salesChannelContext->getCustomer())) {
            $parameters['customer'] = $this->getCustomerData($salesChannelContext->getCustomer());
        }

        // Build Transaction paramters.
        $parameters['transaction'] = [
            'amount' => $amount,
            'order_no' => $orderNumber,
            'currency' => $salesChannelContext->getCurrency()->getIsoCode() ? $salesChannelContext->getCurrency()->getIsoCode() : $salesChannelContext->getSalesChannel()->getCurrency()->getIsoCode(),
            'test_mode' => (int) $data['booking_details']['test_mode'],
            'payment_type' => $data['payment_details']['type'],
        ];

        $parameters['transaction'] = $this->systemParameter($salesChannelContext->getContext(), $parameters['transaction']);

        if ($salesChannelContext->getSalesChannel()->getDomains() !== null) {
            $domain = $salesChannelContext->getSalesChannel()->getDomains()->first();
            $hookUrl = $domain->getUrl() . '/novalnet/callback';
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

        if (!empty($data['booking_details']['payment_action']) && $data['booking_details']['payment_action'] === 'zero_amount') {
            $parameters['transaction']['amount'] = 0;
            $parameters['transaction']['create_token'] = 1;
        }

        if (!empty($data['booking_details']['enforce_3d'])) {
            $parameters['transaction']['enforce_3d'] = $data['booking_details']['enforce_3d'];
        }

        if (!empty($data['booking_details']['create_token'])) {
            $parameters['transaction']['create_token'] = $data['booking_details']['create_token'];
        }

        // Build custom paramters.
        $parameters['custom'] = [
            'lang' => $this->getLocaleCodeFromContext($salesChannelContext->getContext())
        ];

        return $parameters;
    }

    /**
     * Gets the address id for the given address object.
     *
     * @param AddressEntity $address
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
     * Check if the given novalnet host ip is present in the server request.
     *
     * @param string $novalnetHostIp Novalnet host ip to check in the server request.
     *
     * @return bool True if ip matches, otherwise false.
     */
    public function checkWebhookIp(string $novalnetHostIp): bool
    {
        $ipKeys = ['HTTP_X_FORWARDED_HOST', 'HTTP_CLIENT_IP', 'HTTP_X_REAL_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_X_CLUSTER_CLIENT_IP', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];

        foreach ($ipKeys as $key) {
            if (array_key_exists($key, $_SERVER) === true) {
                if (in_array($key, ['HTTP_X_FORWARDED_HOST', 'HTTP_X_FORWARDED_FOR'])) {
                    $forwardedIps = (!empty($_SERVER[$key])) ? explode(',', $_SERVER[$key]) : [];
                    if (in_array($novalnetHostIp, $forwardedIps)) {
                        return true;
                    }
                }

                if ($_SERVER[$key] === $novalnetHostIp) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Gets the system parameter data.
     *
     * @param Context $context Context to get the system version.
     * @param array $transaction The transaction data.
     *
     * @return array The system parameter data.
     */
    public function systemParameter(Context $context, array $transaction): array
    {
        $transaction['system_name'] = 'shopware6';
        $transaction['system_ip'] = $this->getIp('SYSTEM');
        $transaction['system_version'] = $this->getVersionInfo($context);
        if (!empty($this->requestStack->getCurrentRequest())) {
            $requested = $this->requestStack->getCurrentRequest();
            $systemUrl = $requested->attributes->get(RequestTransformer::SALES_CHANNEL_ABSOLUTE_BASE_URL)
            . $requested->attributes->get(RequestTransformer::SALES_CHANNEL_BASE_URL);

            if (!empty($systemUrl)) {
                $transaction['system_url'] = $systemUrl;
            }
        }

        return $transaction;
    }

    /**
     * Retrieves the merchant parameter data.
     *
     * @param string $saleschannelId The sales channel ID to retrieve the data for.
     *
     * @return array The merchant parameter data.
     */
    public function merchantParameter(string $saleschannelId): array
    {
        return [
            'signature' => $this->getNovalnetPaymentSettings('NovalnetPayment.settings.clientId', $saleschannelId),
            'tariff' => $this->getNovalnetPaymentSettings('NovalnetPayment.settings.tariff', $saleschannelId)
        ];
    }

    /**
     * Retrieves the ID of the Novalnet payment method in the given context.
     *
     * @param Context $context The context to retrieve the payment method ID for.
     *
     * @return string|null The ID of the Novalnet payment method, or null if it does not exist.
     */
    public function getNovalnetPaymentId(Context $context): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', 'Novalnet\NovalnetPayment\Service\NovalnetPayment'));

        $paymentMethod = $this->container->get('payment_method.repository')->search($criteria, $context)->first();

        return $paymentMethod?->getId();
    }

    /**
     * Retrieves the order number for a given ABO ID in the given context.
     *
     * @param string $aboId The ABO ID to retrieve the order number for.
     * @param Context $context The context to retrieve the order number in.
     *
     * @return string|null The order number, or null if it does not exist.
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
