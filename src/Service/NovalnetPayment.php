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
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\PaymentHandlerType;
use Shopware\Core\Checkout\Payment\Cart\PaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Struct\Struct;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\SalesChannelRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class NovalnetPayment extends AbstractPaymentHandler
{
    /**
     * @var NovalnetHelper
     */
    protected $helper;

    /**
     * @var NovalnetOrderTransactionHelper
     */
    protected $transactionHelper;

    /**
     * Constructs a `AbstractPaymentHandler`
     */
    public function __construct(
        NovalnetHelper $helper,
        NovalnetOrderTransactionHelper $transactionHelper
    ) {
        $this->helper = $helper;
        $this->transactionHelper = $transactionHelper;
    }

    /**
     * Payment handler which handles the payment process of Novalnet.
     *
     * @throws PaymentException
     */
    public function pay(Request $request, PaymentTransactionStruct $transaction, Context $context, ?Struct $validateStruct): ?RedirectResponse
    {
        try {
            $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
            $salesChannelContext = $this->helper->getSalesChannelDetails($transactionDetails->getOrder());
            $response = $this->handlePaymentProcess($salesChannelContext, $transactionDetails, $transactionDetails->getOrder()->getId(), $transaction->getReturnUrl(), $request);
        } catch (\Exception $e) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransactionId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }

        if (!empty($response['result']['redirect_url'])) {
            $this->helper->setSession('novalnetTxnSecret', $response['transaction']['txn_secret']);
            // Redirect to external gateway
            return new RedirectResponse($response['result']['redirect_url']);
        }

        $this->checkTransactionStatus($transactionDetails, $response, $salesChannelContext);

        // Redirect to external gateway
        return new RedirectResponse($transaction->getreturnUrl());
    }

    /**
     * Indicates whether this payment handler supports the given payment method.
     */
    public function supports(PaymentHandlerType $type, string $paymentMethodId, Context $context): bool
    {
        return $type === PaymentHandlerType::RECURRING;
    }

    /**
     * The finalize function will be called when the user is redirected back to shop from the payment gateway.
     *
     * Throw a @see PaymentException::PAYMENT_ASYNC_FINALIZE_INTERRUPTED exception if an error ocurres while calling an external payment API
     */
    public function finalize(Request $request, PaymentTransactionStruct $transaction, Context $context): void
    {
        try {
            if ($request->query->get('status') !== null && $request->query->get('tid') !== null) {
                $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
                $salesChannelContext = $this->helper->getSalesChannelDetails($transactionDetails->getOrder());
                $response = $this->handleRedirectResponse($request, $salesChannelContext);
                $this->checkTransactionStatus($transactionDetails, $response, $salesChannelContext);
            }
        } catch (\Exception $e) {
            throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransactionId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }
    }

    /**
     * Recurring payment process.
     *
     * @throws PaymentException
     */
    public function recurring(PaymentTransactionStruct $transaction, Context $context): void
    {
        $transactionDetails = $this->transactionHelper->getOrderTransactionDetails($transaction->getOrderTransactionId(), $context);
        $salesChannelContext = $this->helper->getSalesChannelDetails($transactionDetails->getOrder());
        $orderNumber = $this->transactionHelper->getSubParentOrderNumber($transaction->getRecurring()->getSubscriptionId(), $context);
        $dataBag = new Request();
        $dataBag->request->set('isRecurringOrder', true);
        $dataBag->request->set('parentOrderNumber', $orderNumber);
        if (!empty($orderNumber)) {
            $subscription = $this->transactionHelper->fetchNovalnetTransactionData($orderNumber, $context, null, true);
            $subsupportedPayments = ['INVOICE', 'PREPAYMENT', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'DIRECT_DEBIT_SEPA', 'CREDITCARD', 'GUARANTEED_INVOICE', 'PAYPAL', 'GOOGLEPAY', 'APPLEPAY', 'DIRECT_DEBIT_ACH'];
            if (!empty($subscription) && !empty($subscription->getPaymentType()) && in_array($subscription->getPaymentType(), $subsupportedPayments)) {
                $response = $this->handlePaymentProcess($salesChannelContext, $transactionDetails, $transactionDetails->getOrder()->getId(), $transaction->getReturnUrl(), $dataBag);
                $this->checkTransactionStatus($transactionDetails, $response, $salesChannelContext, '1');
            }
        }
    }

    /**
     * Handles the redirect response after payment process.
     */
    public function handleRedirectResponse(Request $request, SalesChannelContext $salesChannelContext): array
    {
        $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $salesChannelContext->getSalesChannel()->getId());
        $response = [];
        $txnsecert = $this->helper->getSession('novalnetTxnSecret');

        if ($request->query->get('status') === 'SUCCESS') {
            if (!empty($txnsecert) && $this->helper->isValidChecksum($request, $paymentAccessKey, $txnsecert)) {
                $response = $this->helper->fetchTransactionDetails($request, $salesChannelContext);
            } else {
                $response = $this->formatQuerystring($request);
                $response['result']['status_text'] = 'Please note some data has been changed while redirecting';
                $response['transaction']['test_mode'] = 0;
            }
        } else {
            $response = $this->formatQuerystring($request);
            $response['transaction']['test_mode'] = 0;
        }

        return $response;
    }

    /**
     * Handle the payment process based on the request data.
     */
    public function handlePaymentProcess(SalesChannelContext $context, OrderTransactionEntity $transaction, string $orderId, string $returnUrl, ?Request $request = null): array
    {
        $paymentData = $response = [];
        if (!empty($request->get('novalnetpaymentFormData'))) {
            $data = $request->get('novalnetpaymentFormData');
            $paymentData = $this->helper->unserializeData($data['paymentData']);
        } elseif (!empty($request->get('isBackendOrderCreation'))) {
            $customerDetails = $this->helper->getCustomerDetails($request->get('BackendPaymentDetails'), $context->getContext());
            $paymentData = (!empty($customerDetails) && !empty($customerDetails->getCustomFields()) && !empty($customerDetails->getCustomFields()['novalnetOrderBackendParameters'])) ? $customerDetails->getCustomFields()['novalnetOrderBackendParameters'] : [];
        } elseif (!empty($request->get('isRecurringOrder'))) {
            $parentOrderNo = $request->get('parentOrderNumber');
            if (!empty($parentOrderNo)) {
                $subscription = $this->transactionHelper->getBookingInfoForRecurring($context->getContext(), $parentOrderNo, $orderId);
                $paymentData = !empty($subscription) ? $subscription : [];
            }
        } else {
            $session = $this->helper->getSession('novalnetpaymentFormData');
            $paymentData = $this->helper->unserializeData($session['paymentData']);
        }

        $locale = $this->helper->getLocaleFromOrder($orderId);

        $customFields = ['novalnet_payment_name' => (isset($paymentData['payment_details']['name']) && !empty($paymentData['payment_details']['name'])) ? $paymentData['payment_details']['name'] : $this->helper->getUpdatedPaymentName('NOVALNET_PAYMENT', $locale), 'novalnet_payment_description' => $this->helper->getPaymentDescription($paymentData['payment_details']['type'], $locale)];

        $data = ['id' => $transaction->getId(), 'customFields' => $customFields];

        $this->transactionHelper->orderTransactionUpsert($data, $context->getContext());

        $parameters = $this->generateBasicParameters($context, $transaction, $paymentData, $request);

        if ($paymentData['payment_details']['process_mode'] === 'redirect' || (isset($paymentData['booking_details']['do_redirect']) && ((bool) $paymentData['booking_details']['do_redirect'] === true))) {
            $parameters['transaction']['return_url'] = $parameters['transaction']['error_return_url'] = $returnUrl;
        }

        if (empty($request->get('isRecurringOrder'))) {
            $this->helper->setSession('novalnetPaymentdata', $paymentData);
        }
        $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $context->getSalesChannel()->getId());
        $paymentAction = (isset($paymentData['booking_details']['payment_action']) && $parameters['transaction']['amount'] > 0) ? $paymentData['booking_details']['payment_action'] : 'payment';
        $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint($paymentAction === 'authorized' ? 'authorize' : 'payment'), $paymentAccessKey);
        if (!empty($request->get('isRecurringOrder'))) {
            $response['isRecurringOrder'] = 1;
            $response['paymentData'] = $paymentData;
        }

        if (isset($parameters['transaction']['payment_data']) && isset($parameters['transaction']['payment_data']['token']) && !empty($parameters['transaction']['payment_data']['token'])) {
            $response['transaction']['payment_data']['token'] = $parameters['transaction']['payment_data']['token'];
        }

        return $response;
    }

    /**
     * Generates the basic parameters required for the Novalnet payment request.
     */
    public function generateBasicParameters(SalesChannelContext $salesChannelContext, $transaction, array $paymentData, ?Request $request = null): array
    {
        // Start to built basic parameters.
        $parameters = $this->helper->getNovalnetRequestData($this->helper->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice()), $transaction->getOrder()->getOrderNumber(), $paymentData, $salesChannelContext);

        if (!empty($paymentData['booking_details']['cycle'])) {
            $parameters['instalment'] = [
                'interval' => '1m',
                'cycles' => $paymentData['booking_details']['cycle']
            ];
        }
        
        // Set the hook URL
        $salesChannel = $transaction->getOrder()->getSalesChannel();
        if (!is_null($salesChannel) && !is_null($salesChannel->getDomains()) && !is_null($request)) {
            $domainId  = $request->attributes->get(SalesChannelRequest::ATTRIBUTE_DOMAIN_ID, '');
            if (!empty($domainId)) {
                foreach ($salesChannel->getDomains() as $domain) {
                    if ($domain->getId() == $domainId) {
                        $parameters['transaction']['hook_url'] = $domain->getUrl() . '/novalnet/callback';
                    }
                }
            }
        }

        if ($paymentData['payment_details']['type'] === 'PAYPAL' && empty($request->get('isRecurringOrder'))) {
            $parameters['cart_info'] = $this->paypalSheetDetails($transaction);
        }

        if (!empty($request->get('isRecurringOrder'))) {
            $paymentMethod = $paymentData['payment_details']['type'];
            if (!in_array($paymentMethod, ['INVOICE', 'PREPAYMENT', 'MULTIBANCO'])) {
                $data = $this->transactionHelper->fetchNovalnetReferenceData($transaction->getOrder()->getOrderCustomer()->getCustomerNumber(), $request->get('parentOrderNumber'), $paymentMethod, $salesChannelContext->getContext());
                if ($data !== null) {
                    $addtionalDetails = $this->helper->unserializeData($data->getAdditionalDetails());
                    if ($paymentMethod !== 'GUARANTEED_INVOICE') {
                        unset($parameters['transaction']['payment_data']);
                        if (!empty($data->getTokenInfo())) {
                            $parameters['transaction']['payment_data']['token'] = $data->getTokenInfo();
                        } else {
                            $parameters['transaction']['payment_data']['payment_ref'] = $data->getTid();
                        }
                    }

                    if (empty($parameters['customer']['billing']['company']) && in_array($paymentMethod, ['GUARANTEED_DIRECT_DEBIT_SEPA', 'GUARANTEED_INVOICE'])) {
                        $parameters['customer']['birth_date'] = $addtionalDetails['dob'] ?? '';
                    }
                }
            }

            if (isset($parameters['transaction']['return_url']) && isset($parameters['transaction']['error_return_url'])) {
                unset($parameters['transaction']['return_url'], $parameters['transaction']['error_return_url']);
            }
        } elseif (!empty($request->get('isSubscriptionOrder'))) {
            $parameters['custom']['input1'] = 'shop_subs';
            $parameters['custom']['inputval1'] = '1';
            if (!empty($request->get('changePayment')) && !empty($request->get('subParentOrderNumber'))) {
                $parameters['custom']['input2'] = 'subParentOrderNumber';
                $parameters['custom']['inputval2'] = $request->get('subParentOrderNumber');
                if (!empty($request->get('subscriptionId'))) {
                    $parameters['custom']['input4'] = 'subscriptionId';
                    $parameters['custom']['inputval4'] = $request->get('subscriptionId');
                    $parameters['custom']['paymentMethodId'] = $transaction->getPaymentMethodId();
                }
                if (!empty($paymentData['payment_details']['name'])) {
                    $parameters['custom']['input5'] = 'Paymentname';
                    $parameters['custom']['inputval5'] = $paymentData['payment_details']['name'];
                }
            }

            if (!isset($parameters['transaction']['create_token'])) {
                $parameters['transaction']['create_token'] = 1;
            }
        } elseif (!empty($request->get('isBackendOrderCreation'))) {
            $parameters['custom']['input1'] = 'BackendOrder';
            $parameters['custom']['inputval1'] = '1';
        }

        if (!empty($paymentData['booking_details']['payment_action']) && $paymentData['booking_details']['payment_action'] === 'zero_amount' && $this->helper->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice()) > 0) {
            $parameters['custom']['input3'] = 'ZeroBooking';
            $parameters['custom']['inputval3'] = $this->helper->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice());
        }

        return $parameters;
    }

    /**
     * Builds an array of cart information for PayPal sheet.
     *
     * @param  mixed $transaction
     * @return array
     */
    public function paypalSheetDetails($transaction): array
    {
        $totalAmount = 0;
        $cartinfo = [];

        foreach ($transaction->getOrder()->getLineItems()->getElements() as $lineItem) {
            if ($lineItem->getPayload() && isset($lineItem->getPayload()['discountScope']) && $lineItem->getPayload()['discountScope'] === 'delivery') {
                foreach ($transaction->getOrder()->getDeliveries()->getElements() as $delivery) {
                    if ($delivery->getShippingCosts()->getTotalPrice() < 0) {
                        $totalAmount += $delivery->getShippingCosts()->getTotalPrice();
                        $cartinfo['line_items'][] = ['name' => $lineItem->getLabel(), 'price' => round((float) sprintf('%0.2f', $delivery->getShippingCosts()->getTotalPrice()) * 100), 'quantity' => $lineItem->getQuantity(), 'description' => $lineItem->getDescription(), 'category' => 'physical'];
                    }
                }
            } else {
                $totalAmount += $lineItem->getPrice()->getTotalPrice();
                $cartinfo['line_items'][] = ['name' => $lineItem->getLabel(), 'price' => round((float) sprintf('%0.2f', $lineItem->getPrice()->getUnitPrice()) * 100), 'quantity' => $lineItem->getQuantity(), 'description' => $lineItem->getDescription(), 'category' => 'physical'];
            }
        }

        foreach ($transaction->getOrder()->getDeliveries()->getElements() as $delivery) {
            if ($delivery->getShippingCosts()->getTotalPrice() > 0) {
                $totalAmount += $delivery->getShippingCosts()->getTotalPrice();
                $cartinfo['items_shipping_price'] = round((float) sprintf('%0.2f', $delivery->getShippingCosts()->getTotalPrice()) * 100);
            }
        }

        if ($transaction->getOrder()->getPrice()->getTotalPrice() > $totalAmount) {
            foreach ($transaction->getOrder()->getPrice()->getCalculatedTaxes()->getElements() as $tax) {
                $cartinfo['items_tax_price'] = round((float) sprintf('%0.2f', $tax->getTax()) * 100);
            }
        }

        return $cartinfo;
    }

    /**
     * Check the transaction status from the response and trigger the success or failure transaction actions.
     */
    public function checkTransactionStatus(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, ?string $isAdmin = null): void
    {
        if ($this->helper->isSuccessStatus($response)) {
            $this->transactionSuccess($orderTransaction, $response, $salesChannelContext);
        } else {
            $this->transactionFailure($orderTransaction, $response, $salesChannelContext, $isAdmin);
        }
    }

    /**
     * This function is used to handle the transaction success scenarios
     * with the updated status from the Novalnet payment gateway.
     */
    public function transactionSuccess(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext): void
    {
        try {
            $paymentStatus = '';
            $paymentData = [];
            $locale = $this->helper->getLocaleFromOrder($orderTransaction->getOrder()->getId());
            if (!empty($response['event'])) {
                $order = $this->transactionHelper->getOrderCriteria($orderTransaction->getOrderId(), $salesChannelContext->getContext());
                $completeStatus = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.completeStatus', $order->getSalesChannel()->getId());
                $onholdStatus = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.onHoldStatus', $order->getSalesChannel()->getId());
            } else {
                $completeStatus = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.completeStatus', $salesChannelContext->getSalesChannel()->getId());
                $onholdStatus = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.onHoldStatus', $salesChannelContext->getSalesChannel()->getId());
            }

            if (!empty($response['isRecurringOrder']) && !empty($response['paymentData'])) {
                $paymentData = $response['paymentData'];
            } else {
                $paymentData = $this->helper->getSession('novalnetPaymentdata');
            }

            $insertData = [
                'id' => Uuid::randomHex(),
                'paymentType' => $response['transaction']['payment_type'] ?? 'NOVALNET_PAYMENT',
                'paidAmount' => 0,
                'refundedAmount' => 0,
                'tid' => $response['transaction']['tid'],
                'gatewayStatus' => $response['transaction']['status'],
                'amount' => $response['transaction']['amount'],
                'currency' => $response['transaction']['currency'],
                'orderNo' => $response['transaction']['order_no'],
                'customerNo' => !empty($response['customer']['customer_no']) ? $response['customer']['customer_no'] : '',
                'additionalDetails' => null
            ];

            if (empty($response['isRecurringOrder']) && isset($response['custom']['input1']) && ($response['custom']['input1'] === 'shop_subs')) {
                $data['payment_details'] = !empty($paymentData['payment_details']) ? $paymentData['payment_details'] : ['name' => $this->helper->getUpdatedPaymentName($response['transaction']['payment_type'], $locale), 'type' => $response['transaction']['payment_type']];
                if (isset($paymentData['booking_details']) && isset($paymentData['booking_details']['test_mode'])) {
                    $data['booking_details']['test_mode'] = $paymentData['booking_details']['test_mode'];
                } else {
                    $data['booking_details']['test_mode'] = $response['transaction']['test_mode'];
                }
                $insertData['additionalDetails']['subscription'] = $data;
            }

            if ($response['transaction']['status'] === 'CONFIRMED' && (!empty($response['transaction']['amount']) || empty($orderTransaction->getAmount()->getTotalPrice()))) {
                $insertData['paidAmount'] = $response['transaction']['amount'];
                if (!empty($completeStatus)) {
                    $paymentStatus = strtoupper((string) $completeStatus);
                } else {
                    $paymentStatus = 'PAID';
                }
            } elseif ($response['transaction']['status'] === 'PENDING') {
                $paymentStatus = 'PENDING';
            } elseif ($response['transaction']['status'] === 'ON_HOLD') {
                if (!empty($onholdStatus)) {
                    $paymentStatus = strtoupper((string) $onholdStatus);
                } else {
                    $paymentStatus = 'AUTHORIZED';
                }
            } elseif ($response['transaction']['status'] === 'CONFIRMED' && $response['transaction']['amount'] === 0 && !empty($orderTransaction->getAmount()->getTotalPrice())) {
                $paymentStatus = 'AUTHORIZED';
            }

            if (!empty($response['transaction']['bank_details'])) {
                $insertData['additionalDetails']['bankDetails'] = $response['transaction']['bank_details'];
            }

            if (!empty($response['transaction']['payment_data']['token'])) {
                $insertData['tokenInfo'] = $response['transaction']['payment_data']['token'];
            } elseif (!empty($paymentData['booking_details']['payment_ref']['token'])) {
                $insertData['tokenInfo'] = $paymentData['booking_details']['payment_ref']['token'];
            }

            if (!empty($response['instalment']['cycles_executed'])) {
                $insertData['additionalDetails']['InstalmentDetails'] = $this->transactionHelper->getInstalmentInformation($response, $this->helper->getLocaleCodeFromContext($salesChannelContext->getContext(), true, $orderTransaction->getOrder()->getLanguageId()));
            }

            if (!empty($response['customer']['birth_date']) && in_array($response['transaction']['payment_type'], ['GUARANTEED_DIRECT_DEBIT_SEPA', 'GUARANTEED_INVOICE'])) {
                $insertData['additionalDetails']['dob'] = date('Y-m-d', strtotime((string) $response['customer']['birth_date']));
            }

            if (!empty($insertData['additionalDetails'])) {
                $insertData['additionalDetails'] = $this->helper->serializeData($insertData['additionalDetails']);
            }

            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData((string) $response['transaction']['order_no'], $salesChannelContext->getContext());

            if (!empty($transactionData)) {
                $insertData['id'] = $transactionData->getId();
            }

            // Insert (or) Update data into novalnet_transaction_details.repository
            $this->helper->updateTransactionData($insertData, $salesChannelContext->getContext());

            // novalnet order comments
            $orderComments = $this->helper->formBankDetails($response, $salesChannelContext->getContext(), $orderTransaction->getOrder()->getLanguageId());

            if (!empty($orderTransaction->getCustomFields()['novalnet_comments']) && preg_match('/' . $response['transaction']['tid'] . '/', (string) $orderTransaction->getCustomFields()['novalnet_comments'])) {
                $orderComments = $orderTransaction->getCustomFields()['novalnet_comments'];
            } else {
                $orderComments .= !empty($orderTransaction->getCustomFields()['novalnet_comments']) ? '&&' . $orderTransaction->getCustomFields()['novalnet_comments'] : '';
            }

            $customFields = ['novalnet_comments' => $orderComments, 'swag_paypal_resource_id' => (string) $response['transaction']['tid']];

            $data = ['id' => $orderTransaction->getId(), 'customFields' => $customFields];

            $this->transactionHelper->orderTransactionUpsert($data, $salesChannelContext->getContext());

            if (!empty($paymentStatus)) {
                $this->transactionHelper->managePaymentStatus('open', $orderTransaction->getId(), $salesChannelContext->getContext());
                $this->transactionHelper->managePaymentStatus($paymentStatus, $orderTransaction->getId(), $salesChannelContext->getContext());
            }

            // Send order email with Novalnet transaction comments.
            if (in_array($response['transaction']['payment_type'], ['INVOICE', 'GUARANTEED_INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA', 'PREPAYMENT', 'MULTIBANCO']) && in_array($response['transaction']['status'], ['CONFIRMED', 'ON_HOLD', 'PENDING'])) {
                $this->transactionHelper->prepareMailContent($orderTransaction->getOrder(), $salesChannelContext, $orderComments);
            }

            if (isset($response['custom']['input2']) && $response['custom']['input2'] === 'subParentOrderNumber') {
                if (isset($response['custom']['inputval2']) && !empty($response['custom']['inputval2'])) {
                    $response['custom']['paymentMethodId'] = $orderTransaction->getPaymentMethodId();

                    if (empty($paymentData)) {
                        $response['paymentData'] = [
                            'payment_details' => [
                                'type' => $response['transaction']['payment_type'] ?? 'NOVALNET_PAYMENT',
                                'name' => $this->helper->getUpdatedPaymentName($response['transaction']['payment_type'], $locale),
                            ],
                            'booking_details' => [
                                'test_mode' => $response['transaction']['test_mode']
                            ]
                        ];
                    } else {
                        $response['paymentData'] = $paymentData;
                    }

                    $this->transactionHelper->updateChangePayment($response, $orderTransaction->getOrderId(), $salesChannelContext->getContext(), true);
                }
            }
        } catch (\Exception $e) {
            $this->transactionHelper->setWarningMessage($e->getMessage());
        }

        if (empty($response['isRecurringOrder'])) {
            $this->unsetSession();
        }
        return;
    }

    /**
     * The function is used to update the transaction status to failed.
     *
     * @throws PaymentException
     */
    public function transactionFailure(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, ?string $isAdmin)
    {
        $errorMessage = $this->helper->getResponseText($response);

        if (empty($isAdmin)) {
            $this->helper->setSession('novalnetErrorMessage', $errorMessage);
            $paymentdata = $this->helper->getSession('novalnetPaymentdata');
        }

        if (empty($isAdmin)) {
            $this->unsetSession();
        }

        $orderComments = $this->helper->formBankDetails($response, $salesChannelContext->getContext(), $orderTransaction->getOrder()->getLanguageId());

        if (!empty($orderTransaction->getCustomFields()['novalnet_comments']) && preg_match('/' . $response['transaction']['tid'] . '/', (string) $orderTransaction->getCustomFields()['novalnet_comments'])) {
            $orderComments = $orderTransaction->getCustomFields()['novalnet_comments'];
        } else {
            $orderComments .= !empty($orderTransaction->getCustomFields()['novalnet_comments']) ? '&&' . $orderTransaction->getCustomFields()['novalnet_comments'] : '';
        }

        $customFields = ['novalnet_comments' => $orderComments, 'swag_paypal_resource_id' => (string) $response['transaction']['tid']];

        $data = ['id' => $orderTransaction->getId(), 'customFields' => $customFields];

        $this->transactionHelper->orderTransactionUpsert($data, $salesChannelContext->getContext());

        if (empty($isAdmin) && (!isset($response['custom']) || !\array_key_exists('BackendOrder', $response['custom']))) {
            try {
                throw PaymentException::customerCanceled($orderTransaction->getId(), $errorMessage);
            } catch (\Exception) {
                $this->transactionHelper->managePaymentStatus('open', $orderTransaction->getId(), $salesChannelContext->getContext());
                throw PaymentException::customerCanceled($orderTransaction->getId(), $errorMessage);
            }
        } else {
            // Payment cancelled, set transaction status to "CANCEL"
            $this->transactionHelper->managePaymentStatus('cancel', $orderTransaction->getId(), $salesChannelContext->getContext());

            return null;
        }
    }

    /**
     * Formats the query string from the request into an array with the following structure
     *
     * @param Request $request
     *
     * @return array
     */
    public function formatQuerystring(Request $request): array
    {
        $data = [];
        foreach ([
            'tid' => 'transaction',
            'payment_type' => 'transaction',
            'status' => 'result',
            'status_text' => 'result',
        ] as $parameter => $category) {
            $data[$category][$parameter] = $request->query->get($parameter);
        }

        return $data;
    }

    /**
     * Unset Novalnet session
     */
    public function unsetSession(): void
    {
        foreach ([
            'novalnetPaymentdata',
            'novalnetTxnSecret',
            'novalnetpaymentFormData'
        ] as $sessionKey) {
            if ($this->helper->hasSession($sessionKey)) {
                $this->helper->removeSession($sessionKey);
            }
        }
    }
}
