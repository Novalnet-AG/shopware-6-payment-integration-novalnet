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

namespace Novalnet\NovalnetPayment\Service;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetValidator;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\Transition;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;

abstract class AbstractNovalnetPaymentHandler
{
    /**
     * @var OrderTransactionStateHandler
     */
    protected $orderTransactionStateHandler;

    /**
     * @var NovalnetValidator
     */
    protected $validator;

    /**
     * @var NovalnetOrderTransactionHelper
     */
    protected $transactionHelper;

    /**
     * @var NovalnetHelper
     */
    protected $helper;

    /**
     * @var SessionInterface
     */
    protected $sessionInterface;

    /**
     * @var array
     */
    protected $paymentSettings;

    /**
     * @var string
     */
    protected $paymentCode;

    /**
     * @var int
     */
    protected $position;

    /**
     * @var array
     */
    protected $translations;

    /**
     * @var SalesChannelContextPersister
     */
    protected $contextPersister;

    /**
     * @var string
     */
    protected $novalnetPaymentType;

    /**
     * @var string
     */
    protected $paymentHandler;

    /**
     * @var EntityRepositoryInterface
     */
    protected $orderTransactionRepository;

    /**
     * Constructs a `AbstractNovalnetPaymentHandler`
     *
     * @param NovalnetHelper $helper
     * @param NovalnetOrderTransactionHelper $transactionHelper
     * @param NovalnetValidator $validator
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param SessionInterface $sessionInterface
     * @param EntityRepositoryInterface $orderTransactionRepository
     * @param SalesChannelContextPersister $contextPersister
     */
    public function __construct(
        NovalnetHelper $helper = null,
        NovalnetOrderTransactionHelper $transactionHelper = null,
        NovalnetValidator $validator = null,
        OrderTransactionStateHandler $orderTransactionStateHandler = null,
        SessionInterface $sessionInterface = null,
        EntityRepositoryInterface $orderTransactionRepository = null,
        SalesChannelContextPersister $contextPersister = null
    ) {
        if (!is_null($helper)) {
            $this->helper = $helper;
        }
        if (!is_null($transactionHelper)) {
            $this->transactionHelper = $transactionHelper;
        }
        if (!is_null($validator)) {
            $this->validator = $validator;
        }
        if (!is_null($orderTransactionStateHandler)) {
            $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        }
        if (!is_null($sessionInterface)) {
            $this->sessionInterface = $sessionInterface;
        }
        if (!is_null($contextPersister)) {
            $this->contextPersister = $contextPersister;
        }
        if (!is_null($orderTransactionRepository)) {
            $this->orderTransactionRepository = $orderTransactionRepository;
        }
    }

    /**
     * Prepare payment related parameters
     *
     * @param mixed $transaction
     * @param RequestDataBag $data
     * @param array $parameters
     * @param array $paymentSettings
     */
    abstract public function generatePaymentParameters($transaction, RequestDataBag $data = null, array &$parameters, array $paymentSettings): void;

    /**
     * Prepare transaction comments
     *
     * @param array $response
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    abstract public function prepareComments(array $response, Context $context, string $languageId = null): string;

    /**
     * Get payment code
     *
     * @return string
     */
    public function getPaymentCode(): string
    {
        return $this->paymentCode;
    }

    /**
     * Get payment name
     *
     * @return string
     */
    public function getName(string $locale): string
    {
        $translations = $this->getTranslations();
        $name = '';
        if (! empty($translations[$locale]['name'])) {
            $name = $translations[$locale]['name'];
        }
        return $name;
    }

    /**
     * Get payment description
     *
     * @return string
     */
    public function getDescription(string $locale): string
    {
        $translations = $this->getTranslations();
        $description = '';
        if (! empty($translations[$locale]['description'])) {
            $description = $translations[$locale]['description'];
        }
        return $description;
    }

    /**
     * Get payment handler
     *
     * @return string
     */
    public function getPaymentHandler(): string
    {
        return $this->paymentHandler;
    }

    /**
     * Get payment translations
     *
     * @return array
     */
    public function getTranslations(): array
    {
        return $this->translations;
    }

    /**
     * Get payment position/sort order
     *
     * @return int
     */
    public function getPosition(): int
    {
        return $this->position;
    }

    /**
     * Get payment type need to send in request
     *
     * @return string
     */
    public function getNovalnetPaymentType(): string
    {
        return $this->novalnetPaymentType;
    }

    /**
     * Handle Paymennt process
     *
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $transaction
     * @param RequestDataBag $dataBag
     *
     * @return array
     */
    public function handlePaymentProcess(SalesChannelContext $salesChannelContext, $transaction, RequestDataBag $dataBag = null): array
    {
        $parameters = $this->generateBasicParameters($salesChannelContext, $transaction);
        $this->generatePaymentParameters($transaction, $dataBag, $parameters, $this->paymentSettings);

        if (!empty($dataBag->get('isBackendOrderCreation')))
        {
            $parameters ['custom']['input4'] = 'BackendOrder';
            $parameters ['custom']['inputval4'] = '1';
        } elseif (!empty($dataBag->get('ExpressCheckout')))
        {
            $parameters ['custom']['input4'] = 'orderId';
            $parameters ['custom']['inputval4'] = $transaction->getOrder()->getId();
        }

        if(!empty($dataBag->get('isRecurringOrder')))
        {
            $paymentMethod  = '';

            // Get current payment method value and store it in session for future reference.
            if (!is_null($transaction->getOrderTransaction()->getPaymentMethod())) {
                $paymentMethod = $this->helper->getPaymentMethodName($transaction->getOrderTransaction()->getPaymentMethod());
            } else {
                $paymentMethod = $this->sessionInterface->get('currentNovalnetPaymentmethod');
            }

            if (in_array($paymentMethod, ['novalnetsepa', 'novalnetsepaguarantee'])) {
                $paymentMethod = 'novalnetsepa';
            }

            $storedData = $this->helper->paymentTokenRepository->getLastPaymentToken($salesChannelContext, ['paymentType' => $paymentMethod]);
            if(!is_null($storedData))
            {
                $parameters ['transaction'] ['payment_data'] ['token'] = $storedData->getToken();
                unset($parameters ['transaction'] ['return_url'], $parameters ['transaction'] ['error_return_url']);
            } elseif ($paymentMethod === 'novalnetinvoiceguarantee') {
                $data = $this->transactionHelper->fetchNovalnetReferenceData($salesChannelContext->getCustomer()->getCustomerNumber(), 'novalnetinvoiceguarantee', $salesChannelContext->getContext());
                $parameters ['transaction'] ['payment_data'] ['payment_ref'] = $data->getTid() ?? '';
            }
        }

        $this->sessionInterface->set('requestParams', $parameters);
        return $this->helper->sendPostRequest($parameters, $this->getPaymentEndpoint($parameters), $this->paymentSettings['NovalnetPayment.settings.accessKey']);
    }

    /**
     * Built redirect parameters
     *
     * @param mixed $transaction
     * @param array $parameters
     */
    public function redirectParameters($transaction, array &$parameters): void
    {
        $parameters ['transaction'] ['return_url']  = $parameters ['transaction'] ['error_return_url']  = $transaction->getreturnUrl();
        if (! empty($transaction->getreturnUrl())) {
            $urlComponents = parse_url($transaction->getreturnUrl());
            if (! empty($urlComponents['query'])) {
                $paymentToken = [];
                parse_str($urlComponents['query'], $paymentToken);
                if (! empty($paymentToken['_sw_payment_token'])) {
                    $parameters ['custom']['input1']    = 'paymentToken';
                    $parameters ['custom']['input3']    = 'paymentName';
                    $parameters ['custom']['inputval1'] = $paymentToken['_sw_payment_token'];
                    $parameters ['custom']['inputval3'] = $this->sessionInterface->get('currentNovalnetPaymentmethod');
                }
            }
        }
    }

    /**
     * Built paypal lineItems to show in paypal page.
     *
     * @param mixed $transaction
     * @param array $parameters
     */
    public function paypalSheetDetails($transaction, array &$parameters): void
    {
        $totalAmount = 0;
        foreach($transaction->getOrder()->getLineItems()->getElements() as $lineItem)
        {
            $totalAmount += $lineItem->getPrice()->getTotalPrice();
            $parameters['cart_info']['line_items'][] = array( 'name'=> $lineItem->getLabel(), 'price' => round((float) sprintf('%0.2f', $lineItem->getPrice()->getUnitPrice()) * 100), 'quantity' => $lineItem->getQuantity(), 'description' => $lineItem->getDescription(), 'category' => 'physical' );
        }

        foreach($transaction->getOrder()->getDeliveries()->getElements() as $delivery)
        {
            $totalAmount += $delivery->getShippingCosts()->getTotalPrice();
            $parameters['cart_info']['items_shipping_price'] = round((float) sprintf('%0.2f', $delivery->getShippingCosts()->getTotalPrice()) * 100);
        }

        if($transaction->getOrder()->getPrice()->getTotalPrice() > $totalAmount)
        {
            foreach ($transaction->getOrder()->getPrice()->getCalculatedTaxes()->getElements() as $tax)
            {
                $parameters['cart_info']['items_tax_price'] = round((float) sprintf('%0.2f', $tax->getTax()) * 100);
            }
        }
    }

    /**
     * Built basic parameters
     *
     * @param SalesChannelContext $salesChannelContext
     * @param mixed $transaction
     *
     * @return array
     */
    public function generateBasicParameters(SalesChannelContext $salesChannelContext, $transaction): array
    {
        $this->paymentSettings = $this->helper->getNovalnetPaymentSettings($salesChannelContext->getSalesChannel()->getId());
        $currentPaymentMethod  = '';

        // Get current payment method value and store it in session for future reference.
        if (!is_null($transaction->getOrderTransaction()->getPaymentMethod())) {
            $currentPaymentMethod = $this->helper->getPaymentMethodName($transaction->getOrderTransaction()->getPaymentMethod());
        }
        $this->sessionInterface->set('currentNovalnetPaymentmethod', $currentPaymentMethod);

        // Start to built basic parameters.
        $parameters = [];

        $paymentCode = $this->helper->formatString($currentPaymentMethod);

        // Built merchant parameters.
        $parameters['merchant'] = [
            'signature' => str_replace(' ', '', $this->paymentSettings['NovalnetPayment.settings.clientId']),
            'tariff'    => $this->paymentSettings['NovalnetPayment.settings.tariff']
        ];

        // Built customer parameters.
        if (!is_null($salesChannelContext->getCustomer())) {
            $parameters['customer'] = $this->helper->getCustomerData($salesChannelContext->getCustomer());
        }

        // Built transaction parameters.
        $parameters['transaction'] = [
            'amount'         => $this->helper->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice()),
            'order_no'       => $transaction->getOrder()->getOrderNumber(),
            'test_mode'      => (int) !empty($this->paymentSettings["NovalnetPayment.settings.$paymentCode.testMode"]),
            'payment_type'   => $this->getNovalnetPaymentType(),
            'system_name'    => 'Shopware',
            'system_ip'      => $this->helper->getIp('SYSTEM'),
            'system_version' => $this->helper->getVersionInfo($salesChannelContext->getContext()),
        ];

        if (method_exists($transaction, 'getreturnUrl') && ! empty($transaction->getreturnUrl())) {
            $hookUrl = substr($transaction->getreturnUrl(), 0, strpos($transaction->getreturnUrl(), "payment"));
            $hookUrl = $hookUrl. 'novalnet/callback';
            $parameters['transaction']['hook_url'] = $hookUrl;
        } elseif ( !empty($salesChannelContext->getSalesChannel()) && !empty($salesChannelContext->getSalesChannel()->getDomains()) ) {
            $elements  = $salesChannelContext->getSalesChannel()->getDomains()->getElements();
            $domainId  = $salesChannelContext->getDomainId();
            if(isset($elements[$domainId]))
            {
                $hookUrl   = $elements[$domainId]->getUrl() . '/novalnet/callback';
                $parameters['transaction']['hook_url'] = $hookUrl;
            }
        }

        if (!is_null($salesChannelContext->getSalesChannel()->getCurrency())) {
            $parameters['transaction']['currency'] = $salesChannelContext->getCurrency()->getIsoCode() ? $salesChannelContext->getCurrency()->getIsoCode() : $salesChannelContext->getSalesChannel()->getCurrency()->getIsoCode();
        }

        // Built custom parameters.
        $parameters['custom'] = [
            'lang'      => $this->helper->getLocaleCodeFromContext($salesChannelContext->getContext()),
            'input2'    => 'shop_token',
            'inputval2' => Random::getAlphanumericString(32)
        ];

        // Check for Zero amount booking payments
        if(in_array($currentPaymentMethod, ['novalnetcreditcard', 'novalnetsepa']) && $this->paymentSettings["NovalnetPayment.settings.$paymentCode.onHold"] == 'zero_amount')
        {
            $parameters['transaction']['amount'] = 0;
        }

        // Check for Zero amount booking payments
        if(in_array($currentPaymentMethod, ['novalnetsepaguarantee', 'novalnetinvoiceguarantee']) && empty($this->paymentSettings["NovalnetPayment.settings.$paymentCode.allowB2B"]))
        {
            unset($parameters['customer']['billing']['company']);
        }

        return $parameters;
    }

    /**
     * Set payment token in payment request
     *
     * @param string $paymentType
     * @param RequestDataBag $dataBag
     * @param array $sessionData
     * @param array $parameters
     */
    public function setPaymentToken(string $paymentType, RequestDataBag $dataBag = null, array $sessionData, array &$parameters): void
    {
        if (!is_null($dataBag->get($paymentType . 'FormData'))) {
            $formData = $dataBag->get($paymentType . 'FormData');
        }

        if (! empty($formData) && $formData->get('paymentToken')) {
            $sessionData['paymentToken'] = $formData->get('paymentToken');
        }

        if (! empty($sessionData['paymentToken']) && $sessionData['paymentToken'] !== 'new') {
            $parameters ['transaction']['payment_data']['token'] = $sessionData['paymentToken'];
        } elseif ( (! empty($sessionData['saveData']) && $sessionData['saveData'] == 'on') || (! empty($formData) && $formData->get('saveData') == 'on') || $parameters['transaction']['amount'] <= 0 || !empty($dataBag->get('isSubscriptionOrder'))) {
            $parameters ['transaction']['create_token'] = '1' ;
        }
    }

    /**
     * Get Novalnet endpoint to send the payment request
     *
     * @param array $parameters
     *
     * @return string
     */
    public function getPaymentEndpoint(array $parameters) : string
    {
        $action = 'payment';
        if ($this->helper->getSupports('authorize', $this->paymentCode) && $this->validator->isAuthorize($this->paymentSettings, $this->paymentCode, $parameters)) {
            $action = 'authorize';
        }

        return $this->helper->getActionEndpoint($action);
    }

    /**
     * Check the response parameters for transaction status
     */
    public function checkTransactionStatus(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, $transaction = null, string $isExpress = null): void
    {
        if ($this->validator->isSuccessStatus($response)) {
            $this->transactionSuccess($orderTransaction, $response, $salesChannelContext, $transaction);
        } else {
            $this->transactionFailure($orderTransaction, $response, $salesChannelContext, $transaction, $isExpress);
        }
    }

    /**
     * Handle transaction success process
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param array $response
     * @param SalesChannelContext $salesChannelContext
     */
    public function transactionSuccess(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, $transaction = null): void
    {
        try {
            // Get stored current novalnet payment method.
            $paymentMethod = $this->sessionInterface->get('currentNovalnetPaymentmethod');

            // Get current payment method value and store it in session for future reference.
            if (!is_null($transaction) && !is_null($transaction->getOrderTransaction()->getPaymentMethod()) && empty($paymentMethod)) {
                $paymentMethod = $this->helper->getPaymentMethodName($transaction->getOrderTransaction()->getPaymentMethod());
            }

            $insertData = [
                'id'          => Uuid::randomHex(),
                'paymentType' => $paymentMethod
            ];
            $paymentStatus = '';
            $insertData['paidAmount'] = 0;
            if ($response['transaction']['status'] === 'CONFIRMED' && $response['transaction']['amount'] != 0) {
                $paymentStatus = 'PAID';
                if (! empty($response['transaction']['amount'])) {
                    $insertData['paidAmount'] = $response['transaction']['amount'];
                }
            } elseif ($response['transaction']['status'] === 'PENDING') {
                $paymentStatus = 'PENDING';
                if ($this->helper->getSupports('payLater', $paymentMethod)) {
                    $paymentStatus = 'PAYLATER';
                }
            }

            foreach ([
                'tid'           => 'tid',
                'gatewayStatus' => 'status',
                'amount'        => 'amount',
                'orderNo'       => 'order_no',
                'customerNo'    => 'customer_no',
                'currency'      => 'currency',
            ] as $key => $value) {
                if (! empty($response['transaction'][$value])) {
                    $insertData[$key] = $response['transaction'][$value];
                }
            }

            $insertData['customerNo'] = !empty($response['customer']['customer_no']) ? $response['customer']['customer_no'] : '';

            if (in_array($paymentMethod, ['novalnetcreditcard', 'novalnetsepa']) && $response['transaction']['amount'] == 0)
            {
                $insertData['additionalDetails'] = $this->sessionInterface->get('requestParams');
            }

            if (! empty($response['transaction']['bank_details'])) {
                $insertData['additionalDetails'] = $response['transaction']['bank_details'];
            } elseif (!empty($response['transaction']['payment_data']['token'])) {
                $insertData['additionalDetails']['token'] = $response['transaction']['payment_data']['token'];
            }

            if(! empty($response['instalment']['cycles_executed']))
            {
                $insertData['additionalDetails'] = $this->transactionHelper->getInstalmentInformation($response);
            }

            if (! empty($insertData['additionalDetails'])) {
                $insertData['additionalDetails'] = $this->helper->serializeData($insertData['additionalDetails']);
            }

            // Upsert data into novalnet_transaction_details.repository
            $this->helper->upsertTransactionData($insertData, $salesChannelContext->getContext());

            // Save Novalnet payment token
            $this->savePaymentToken($response, $paymentMethod, $salesChannelContext);

            // Prepare order comments
            $orderComments = $this->prepareComments($response, $salesChannelContext->getContext(), $transaction != null ? $transaction->getOrder()->getLanguageId() : null);

            $customFields = [
                'novalnet_comments' => $orderComments,
            ];
            // Update Novalnet comments in Order transaction Repository.
            $this->orderTransactionRepository->upsert([[
                'id' => $orderTransaction->getId(),
                'customFields' => $customFields
            ]], $salesChannelContext->getContext());

            // Update novalnet custom fields in order field
            if (!is_null($orderTransaction->getOrder())) {
                $orderTransaction->getOrder()->setCustomFields($customFields);
            }
            $orderTransaction->setCustomFields($customFields);

            if(!empty($paymentStatus))
            {
                if ($paymentStatus == 'PAID') {
                    // Payment completed, set transaction status to "PAID"
                    $this->orderTransactionStateHandler->paid($orderTransaction->getId(), $salesChannelContext->getContext());
                } elseif (empty($response['custom']['inputval4'])) {
                    $this->orderTransactionStateHandler->process($orderTransaction->getId(), $salesChannelContext->getContext());
                }

            }

            // Send order email with Novalnet transaction comments.
            if ( ($this->helper->getSupports('payLater', $paymentMethod) || (in_array($paymentMethod, ['novalnetinvoiceguarantee','novalnetsepaguarantee', 'novalnetinvoiceinstalment', 'novalnetsepainstalment']) && in_array($response['transaction']['status'], ['CONFIRMED', 'ON_HOLD', 'PENDING']))) && !is_null($transaction))
            {
                $this->transactionHelper->prepareMailContent($transaction->getOrder(), $salesChannelContext, $orderComments);
            }

        } catch (\Exception $e) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
        }
        // Unset Novalnet Session
        $this->unsetSession();
        return;
    }

    /**
     * Handle transaction failure process
     *
     * throws CustomerCanceledAsyncPaymentException
     */
    public function transactionFailure(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, $transaction = null, string $isExpress = null): ?CustomerCanceledAsyncPaymentException
    {
        $errorMessage = $this->helper->getResponseText($response);
        $this->sessionInterface->set('novalnetErrorMessage', $errorMessage);
        $this->unsetSession();

        // Prepare order comments
        $orderComments = $this->prepareComments($response, $salesChannelContext->getContext(), $transaction != null ? $transaction->getOrder()->getLanguageId() : null);
        $customFields = [
            'novalnet_comments' => $orderComments,
        ];
        // Update Novalnet comments in Order transaction Repository.
        $this->orderTransactionRepository->upsert([[
            'id' => $orderTransaction->getId(),
            'customFields' => $customFields
        ]], $salesChannelContext->getContext());

        if(!is_null($transaction) && empty($isExpress))
        {
            if(!empty($this->contextPersister))
            {
                $this->contextPersister->delete($transaction->getOrderTransaction()->getId());
                $this->contextPersister->save($transaction->getOrderTransaction()->getId(), ['novalnetErrorMessage' => $errorMessage], $salesChannelContext->getSalesChannel()->getId());
            }
            throw new CustomerCanceledAsyncPaymentException($orderTransaction->getId(), $errorMessage);
        } else
        {
            // Payment cancelled, set transaction status to "CANCEL"
            $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $salesChannelContext->getContext());
            return null;
        }
    }

    /**
     * Save payment token
     *
     * @param array $data
     * @param string $paymentMethod
     * @param SalesChannelContext $salesChannelContext
     */
    public function savePaymentToken(array $data, string $paymentMethod, SalesChannelContext $salesChannelContext): void
    {
        $tokenData = [];
        $keys      = [];

        if (! empty($data['transaction']['payment_data']['token'])) {
            $paymentData = $data['transaction']['payment_data'];
            if ($paymentMethod === 'novalnetcreditcard') {
                if (! empty($paymentData['card_expiry_month']) && ! empty($paymentData['card_expiry_year'])) {
                    $tokenData['expiryDate'] = date("Y-m-t", (int) strtotime($paymentData['card_expiry_year'].'-'.$paymentData['card_expiry_month']));
                }
                $keys = [
                    'type'        => 'card_brand',
                    'token'       => 'token',
                    'accountData' => 'card_number',
                ];
            } elseif ($this->validator->checkString($paymentMethod, 'novalnetsepa')) {
                $keys = [
                    'token'       => 'token',
                    'accountData' => 'iban',
                ];
                $tokenData['type'] = 'IBAN';
            }

            $tokenData['paymentType'] = $this->helper->formatString($paymentMethod, 'guarantee');

            if($this->validator->checkString($paymentMethod, 'instalment'))
            {
                $tokenData['paymentType'] = $this->helper->formatString($paymentMethod, 'instalment');
            }
            $tokenData['tid'] = $data['transaction']['tid'];

            foreach ($keys as $key => $value) {
                if (! empty($paymentData[$value])) {
                    $tokenData[$key] = $paymentData[$value];
                }
            }

            $this->helper->paymentTokenRepository->savePaymentToken($salesChannelContext, $tokenData);
        }
    }

    /**
     * Handle redirect response
     *
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     */
    public function handleRedirectResponse(Request $request, SalesChannelContext $salesChannelContext, OrderTransactionEntity $orderTransaction): array
    {
        $this->paymentSettings = $this->helper->getNovalnetPaymentSettings($salesChannelContext->getSalesChannel()->getId());
        $response = [];

        $txnSecert  = $this->sessionInterface->get('novalnetTxnSecret');

        if (version_compare($this->helper->getShopVersion(), '6.4.2.0', '>=') && empty($txnSecert)) {
            $responseData = $this->contextPersister->load($orderTransaction->getId(), $salesChannelContext->getSalesChannel()->getId());
            $txnSecert    = $responseData['novalnetTxnSecret'];
        }

        if (!empty($txnSecert) && $this->validator->isValidChecksum($request, trim($this->paymentSettings['NovalnetPayment.settings.accessKey']), $txnSecert)) {
            $response = $this->helper->retrieveTransactionDetails($request, $salesChannelContext);
        } else {
            $response = $this->formatQuerystring($request);
            $response['result']['status_text'] = 'Please note some data has been changed while redirecting';
        }
        return $response;
    }

    /**
     * Form payment comments.
     *
     * @param Request $request
     *
     * @return array
     */
    public function formatQuerystring(Request $request): array
    {
        $data = [];
        foreach ([
            'tid'          => 'transaction',
            'payment_type' => 'transaction',
            'status'       => 'result',
            'status_text'  => 'result',
        ] as $parameter => $category) {
            $data[ $category ][ $parameter ] = $request->get($parameter);
        }
        return $data;
    }

    /**
     * Unset Novalnet session
     *
     */
    public function unsetSession(): void
    {
        foreach ([
            $this->paymentCode . 'Response',
            $this->paymentCode . 'FormData',
            'currentNovalnetPaymentmethod',
            'novalnetTxnSecret',
            'requestParams',
        ] as $sessionKey) {
            if ($this->sessionInterface->has($sessionKey)) {
                $this->sessionInterface->remove($sessionKey);
            }
        }
    }
}
