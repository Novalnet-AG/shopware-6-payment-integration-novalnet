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
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;

class NovalnetPayment implements AsynchronousPaymentHandlerInterface
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
     * Constructs a `AsynchronousPaymentHandlerInterface`
     *
     * @param NovalnetHelper                 $helper
     * @param NovalnetOrderTransactionHelper $transactionHelper
     */
    public function __construct(
        NovalnetHelper $helper,
        NovalnetOrderTransactionHelper $transactionHelper
    ) {
        $this->helper = $helper;
        $this->transactionHelper = $transactionHelper;
    }

    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * Throw a @see PaymentException::asyncProcessInterrupted exception if an error ocurres while processing the payment
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag                $dataBag
     * @param SalesChannelContext           $context
     *
     * @throws PaymentException
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $context): RedirectResponse
    {
        try {
            $response = $this->handlePaymentProcess($context, $transaction, $dataBag);
        } catch (\Exception $e) {
            throw PaymentException::asyncProcessInterrupted($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway'.PHP_EOL.$e->getMessage());
        }

        if (!empty($response['result']['redirect_url'])) {
            $this->helper->setSession('novalnetTxnSecret', $response['transaction']['txn_secret']);
            // Redirect to external gateway
            return new RedirectResponse($response['result']['redirect_url']);
        }

        $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $context, $transaction);

        // Redirect to external gateway
        return new RedirectResponse($transaction->getreturnUrl());
    }

    /**
     * The finalize function will be called when the user is redirected back to shop from the payment gateway.
     *
     *  Throw a @see PaymentException::PAYMENT_ASYNC_FINALIZE_INTERRUPTED exception if an error ocurres while calling an external payment API
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request                       $request
     * @param SalesChannelContext           $salesChannelContext
     *
     * @throws PaymentException
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        try {
            if ($request->query->get('status') !== null && $request->query->get('tid') !== null) {
                $response = $this->handleRedirectResponse($request, $salesChannelContext, $transaction->getOrderTransaction());
                $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
            }
        } catch (\Exception $e) {
            throw PaymentException::asyncFinalizeInterrupted($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }
    }

    /**
     * The recurring function will be called during recurring payment.
     * Allows to process the order and store additional information.
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag                $dataBag
     * @param SalesChannelContext           $salesChannelContext
     */
    public function recurring(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): bool
    {
        $parentOrderNo = $dataBag->get('parentOrderNumber');
        $result        = false;
        if (!empty($parentOrderNo)) {
            $subscription = $this->transactionHelper->fetchNovalnetTransactionData($parentOrderNo, $salesChannelContext->getContext(), null, true);
            $subsupportedPayments = ['INVOICE', 'PREPAYMENT', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'DIRECT_DEBIT_SEPA','CREDITCARD','GUARANTEED_INVOICE','PAYPAL','GOOGLEPAY','APPLEPAY','DIRECT_DEBIT_ACH'];
            if (!empty($subscription) && !empty($subscription->getPaymentType()) && in_array($subscription->getPaymentType(), $subsupportedPayments)) {
                $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
                $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction, '1');
                return $this->helper->isSuccessStatus($response);
            }
        }
        return $result;
    }

    /**
     * Handle Paymennt process
     *
     * @param SalesChannelContext           $context
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag                $dataBag
     *
     * @return array
     */
    public function handlePaymentProcess(SalesChannelContext $context, AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag = null): array
    {
        $paymentData = $response = [];
        if (!empty($dataBag->get('novalnetpaymentFormData'))) {
            $data = $dataBag->get('novalnetpaymentFormData')->all();
            $paymentData = $this->helper->unserializeData($data['paymentData']);
        } elseif (!empty($dataBag->get('isBackendOrderCreation'))) {
            $customerDetails =  $this->helper->getCustomerDetails($dataBag->get('BackendPaymentDetails'), $context->getContext());
            $paymentData = (!empty($customerDetails) && !empty($customerDetails->getCustomFields()) && !empty($customerDetails->getCustomFields()['novalnetOrderBackendParameters'])) ? $customerDetails->getCustomFields()['novalnetOrderBackendParameters'] : [];
        } elseif (!empty($dataBag->get('isRecurringOrder'))) {
            $parentOrderNo = $dataBag->get('parentOrderNumber');
            if (!empty($parentOrderNo)) {
                $subscription = $this->transactionHelper->getSubscriptionDetails($context->getContext(), $parentOrderNo);
                $paymentData  = !empty($subscription) ? $subscription : [];
            }
        } else {
            $session = $this->helper->getSession('novalnetpaymentFormData');
            $paymentData = $this->helper->unserializeData($session['paymentData']);
        }

        $locale = $this->helper->getLocaleFromOrder($transaction->getOrderTransaction()->getorderId());

        $customFields = ['novalnet_payment_name' => (isset($paymentData['payment_details']['name']) && !empty($paymentData['payment_details']['name'])) ? $paymentData['payment_details']['name'] : $this->helper->getUpdatedPaymentName('NOVALNET_PAYMENT', $locale), 'novalnet_payment_description' => $this->helper->getPaymentDescription($paymentData['payment_details']['type'], $locale)];

        $data = ['id' => $transaction->getOrderTransaction()->getId(), 'customFields' => $customFields];

        $this->transactionHelper->orderTransactionUpsert($data, $context->getContext());


        $parameters = $this->generateBasicParameters($context, $transaction, $paymentData, $dataBag);

        if (empty($dataBag->get('isRecurringOrder'))) {
            $this->helper->setSession('novalnetPaymentdata', $paymentData);
            $this->helper->setSession('novalnetRequestParameters', $parameters);
        }
        $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $context->getSalesChannel()->getId());
        $paymentaction = (isset($paymentData['booking_details']['payment_action']) && $parameters['transaction']['amount'] > 0) ? $paymentData['booking_details']['payment_action'] : 'payment';
        $response = $this->helper->sendPostRequest($parameters, $this->helper->getActionEndpoint($paymentaction == 'authorized' ? 'authorize' : 'payment'), $paymentAccessKey);
        if (!empty($dataBag->get('isRecurringOrder'))) {
            $response['isRecurringOrder'] = 1;
            $response['paymentData'] = $paymentData;
        }

        if (isset($parameters['transaction']['payment_data']) && isset($parameters['transaction']['payment_data']['token']) && !empty($parameters['transaction']['payment_data']['token'])) {
            $response['transaction']['payment_data']['token'] = $parameters['transaction']['payment_data']['token'];
        }

        return $response;
    }

    /**
     * Built basic parameters
     *
     * @param SalesChannelContext $context
     * @param mixed               $transaction
     * @param array               $paymentData
     * @param RequestDataBag      $dataBag
     *
     * @return array
     */
    public function generateBasicParameters(SalesChannelContext $context, $transaction, array $paymentData, RequestDataBag $dataBag = null): array
    {
        // Start to built basic parameters.
        $parameters = $this->helper->getNovalnetRequestData($this->helper->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice()), $transaction->getOrder()->getOrderNumber(), $paymentData, $context);

        if (!empty($paymentData['booking_details']['cycle'])) {
            $parameters['instalment'] = [
                'interval'  => '1m',
                'cycles'    => $paymentData['booking_details']['cycle']
            ];
        }

        if ($paymentData['payment_details']['process_mode'] == 'redirect' || (isset($paymentData['booking_details']['do_redirect']) && ((bool) $paymentData['booking_details']['do_redirect'] ==  true))) {
            $parameters['transaction']['return_url']  = $parameters['transaction']['error_return_url']  = $transaction->getReturnUrl();
        }

        if ($paymentData['payment_details']['type'] == 'PAYPAL' && empty($dataBag->get('isRecurringOrder'))) {
            $parameters['cart_info'] = $this->paypalSheetDetails($transaction);
        }

        if (!empty($dataBag->get('isRecurringOrder'))) {
            $paymentMethod  = $paymentData['payment_details']['type'];
            if (!in_array($paymentMethod, ['INVOICE', 'PREPAYMENT'])) {
                $data = $this->transactionHelper->fetchNovalnetReferenceData($context->getCustomer()->getCustomerNumber(), $dataBag->get('parentOrderNumber'), $paymentMethod, $context->getContext());
                if (!is_null($data)) {
                    $addtionalDetails = $this->helper->unserializeData($data->getAdditionalDetails());

                    if (!empty($data->getTokenInfo())) {
                        $parameters ['transaction'] ['payment_data'] ['token'] = $data->getTokenInfo();
                        if (in_array($paymentData['payment_details']['type'], ['GOOGLEPAY','APPLEPAY']) && !empty($parameters['transaction']['payment_data']['wallet_token'])) {
                            unset($parameters['transaction']['payment_data']['wallet_token']);
                        }
                    } else {
                        $parameters ['transaction'] ['payment_data'] ['payment_ref'] = $data->getTid();
                    }

                    if (empty($parameters['customer']['billing']['company']) && in_array($paymentMethod, ['GUARANTEED_DIRECT_DEBIT_SEPA', 'GUARANTEED_INVOICE'])) {
                        $parameters ['customer'] ['birth_date'] =  $addtionalDetails['dob'] ?? '';
                    }
                }
            }

            if (isset($parameters ['transaction'] ['return_url']) && isset($parameters ['transaction'] ['error_return_url'])) {
                unset($parameters ['transaction'] ['return_url'], $parameters ['transaction'] ['error_return_url']);
            }
        } elseif (!empty($dataBag->get('isSubscriptionOrder'))) {
            $parameters['custom']['input1'] = 'shop_subs';
            $parameters ['custom']['inputval1'] = '1';
            if (!empty($dataBag->get('changePayment')) && !empty($dataBag->get('subParentOrderNumber'))) {
                $parameters['custom']['input2'] = 'subParentOrderNumber';
                $parameters['custom']['inputval2'] = $dataBag->get('subParentOrderNumber');
                if (!empty($dataBag->get('subscriptionId'))) {
                    $parameters['custom']['input4'] = 'subscriptionId';
                    $parameters['custom']['inputval4'] = $dataBag->get('subscriptionId');
                    $parameters ['custom']['paymentMethodId'] = $transaction->getOrderTransaction()->getPaymentMethodId();
                }
                if (!empty($paymentData['payment_details']['name'])) {
                    $parameters['custom']['input5'] = 'Paymentname';
                    $parameters['custom']['inputval5'] = $paymentData['payment_details']['name'];
                }
            }

            if (!isset($parameters['transaction']['create_token'])) {
                $parameters['transaction']['create_token'] = 1;
            }

        } elseif (!empty($dataBag->get('isBackendOrderCreation'))) {
            $parameters ['custom']['input1'] = 'BackendOrder';
            $parameters ['custom']['inputval1'] = '1';
        }

        if (!empty($paymentData['booking_details']['payment_action']) && $paymentData['booking_details']['payment_action'] == 'zero_amount' && $this->helper->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice()) > 0) {
            $parameters ['custom']['input3'] = 'ZeroBooking';
            $parameters ['custom']['inputval3'] = $this->helper->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice());
        }

        if (!empty($paymentData['booking_details']['order'])) {
            $paymentOrderInfo = $paymentData['booking_details']['order'];

            $billingEmail = $paymentOrderInfo['billing']['contact']['email'] ?? '';
            $shippingEmail = $paymentOrderInfo['shipping']['contact']['email'] ?? '';

            $email = !empty($billingEmail) ? $billingEmail : (!empty($shippingEmail) ? $shippingEmail : '');

            if (!empty($email)) {
                $parameters['custom']['input6'] = 'WalletEmailId';
                $parameters['custom']['inputval6'] = $email;
            }
        }

        return $parameters;
    }

    /**
     * Built paypal lineItems to show in paypal page.
     *
     * @param  mixed $transaction
     * @return array
     */
    public function paypalSheetDetails($transaction): array
    {
        $totalAmount = 0;
        $cartinfo = [];
        foreach ($transaction->getOrder()->getLineItems()->getElements() as $lineItem) {
            $totalAmount += $lineItem->getPrice()->getTotalPrice();
            $cartinfo['line_items'][] = array( 'name' => $lineItem->getLabel(), 'price' => round((float) sprintf('%0.2f', $lineItem->getPrice()->getUnitPrice()) * 100), 'quantity' => $lineItem->getQuantity(), 'description' => $lineItem->getDescription(), 'category' => 'physical' );
        }

        foreach ($transaction->getOrder()->getDeliveries()->getElements() as $delivery) {
            $totalAmount += $delivery->getShippingCosts()->getTotalPrice();
            $cartinfo['items_shipping_price'] = round((float) sprintf('%0.2f', $delivery->getShippingCosts()->getTotalPrice()) * 100);
        }

        if ($transaction->getOrder()->getPrice()->getTotalPrice() > $totalAmount) {
            foreach ($transaction->getOrder()->getPrice()->getCalculatedTaxes()->getElements() as $tax) {
                $cartinfo['items_tax_price'] = round((float) sprintf('%0.2f', $tax->getTax()) * 100);
            }
        }
        return $cartinfo;
    }

    /**
     * Check the response parameters for transaction status

     * @param OrderTransactionEntity        $orderTransaction
     * @param array                         $response
     * @param SalesChannelContext           $salesChannelContext
     * @param string|null                   $isAdmin
     * @param AsyncPaymentTransactionStruct $transaction|null
     */
    public function checkTransactionStatus(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, $transaction = null, string $isAdmin = null): void
    {
        if ($this->helper->isSuccessStatus($response)) {
            $this->transactionSuccess($orderTransaction, $response, $salesChannelContext, $transaction);
        } else {
            $this->transactionFailure($orderTransaction, $response, $salesChannelContext, $transaction, $isAdmin);
        }
    }

    /**
     * Handle redirect response
     *
     * @param Request                $request
     * @param SalesChannelContext    $salesChannelContext
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return array
     */
    public function handleRedirectResponse(Request $request, SalesChannelContext $salesChannelContext, OrderTransactionEntity $orderTransaction): array
    {
        $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $salesChannelContext->getSalesChannel()->getId());
        $response = [];
        $txnsecert = $this->helper->getSession('novalnetTxnSecret');
        $novalnetParameter = $this->helper->getSession('novalnetRequestParameters');

        if ($request->query->get('status') == 'SUCCESS') {
            if (!empty($txnsecert) && $this->helper->isValidChecksum($request, $paymentAccessKey, $txnsecert)) {
                $response = $this->helper->fetchTransactionDetails($request, $salesChannelContext);
            } else {
                $response = $this->formatQuerystring($request);
                $response['result']['status_text'] = 'Please note some data has been changed while redirecting';
                $response['transaction']['test_mode'] = !empty($novalnetParameter['transaction']['test_mode']) ? $novalnetParameter['transaction']['test_mode'] : '';
            }
        } else {
            $response = $this->formatQuerystring($request);
            $response['transaction']['test_mode'] = !empty($novalnetParameter['transaction']['test_mode']) ? $novalnetParameter['transaction']['test_mode'] : '';
        }

        return $response;
    }
    /**
     * Handle transaction success process
     *
     * @param OrderTransactionEntity        $orderTransaction
     * @param array                         $response
     * @param SalesChannelContext           $salesChannelContext
     * @param AsyncPaymentTransactionStruct $transaction|null
     */
    public function transactionSuccess(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, AsyncPaymentTransactionStruct $transaction = null): void
    {
        try {
            $paymentStatus = '';
            $paymentdata = [];
            $locale = $this->helper->getLocaleFromOrder($orderTransaction->getOrderId());
            if (!empty($response['event'])) {
                $order = $this->transactionHelper->getOrderCriteria($orderTransaction->getOrderId(), $salesChannelContext->getContext());
                $completeStatus = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.completeStatus', $order->getSalesChannel()->getId());
                $onholdStatus   = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.onHoldStatus', $order->getSalesChannel()->getId());
            } else {
                $completeStatus = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.completeStatus', $salesChannelContext->getSalesChannel()->getId());
                $onholdStatus   = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.onHoldStatus', $salesChannelContext->getSalesChannel()->getId());
            }

            if (!empty($response['isRecurringOrder']) && !empty($response['paymentData'])) {
                $paymentdata = $response['paymentData'];
            } else {
                $paymentdata = $this->helper->getSession('novalnetPaymentdata');
            }

            $insertData = [
                'id'    => Uuid::randomHex(),
                'paymentType' => $response['transaction']['payment_type'] ?? 'NOVALNET_PAYMENT',
                'paidAmount' => 0,
                'refundedAmount' => 0,
                'tid' => $response['transaction']['tid'],
                'gatewayStatus' => $response['transaction']['status'],
                'amount' => $response['transaction']['amount'],
                'currency' => $response['transaction']['currency'],
                'orderNo' => $response['transaction']['order_no'],
                'customerNo' => !empty($response['customer']['customer_no']) ? $response['customer']['customer_no'] : ''
            ];

            if (empty($response['isRecurringOrder']) && isset($response['custom']['input1']) && ($response['custom']['input1'] == 'shop_subs')) {
                $data['payment_details'] = !empty($paymentdata['payment_details']) ? $paymentdata['payment_details'] : [];
                if(isset($paymentdata['booking_details']) && isset($paymentdata['booking_details']['test_mode'])) {
                    $data['booking_details']['test_mode'] = $paymentdata['booking_details']['test_mode'];
                }
                $insertData['additionalDetails']['subscription'] = $data;
            }

            if ($response['transaction']['payment_type'] == 'CASHPAYMENT') {

                $insertData['additionalDetails'] ['cashpayment'] = [
                    'payment_type' => $response['transaction']['payment_type'],
                    'checkout_token' => $response['transaction']['checkout_token'],
                    'checkout_js' => $response['transaction']['checkout_js']
                ];
            }

            if ($response['transaction']['status'] === 'CONFIRMED' && (!empty($response['transaction']['amount']) || $orderTransaction->getAmount()->getTotalPrice() == 0)) {
                $insertData['paidAmount'] = $response['transaction']['amount'];
                if (!empty($completeStatus)) {
                    $paymentStatus = strtoupper($completeStatus);
                } else {
                    $paymentStatus = 'PAID';
                }
            } elseif ($response['transaction']['status'] === 'PENDING') {
                $paymentStatus = 'PENDING';
            } elseif ($response['transaction']['status'] === 'ON_HOLD') {
                if (!empty($onholdStatus)) {
                    $paymentStatus = strtoupper($onholdStatus);
                } else {
                    $paymentStatus = 'AUTHORIZED';
                }
            } elseif ($response['transaction']['status'] === 'CONFIRMED' && $response['transaction']['amount'] == 0 && $orderTransaction->getAmount()->getTotalPrice() != 0) {
                $paymentStatus = 'AUTHORIZED';
            }

            if (! empty($response['transaction']['bank_details'])) {
                $insertData['additionalDetails']['bankDetails'] = $response['transaction']['bank_details'];
            }

            if (!empty($response['transaction']['payment_data']['token'])) {
                $insertData['tokenInfo'] = $response['transaction']['payment_data']['token'];
            } elseif (!empty($paymentdata['booking_details']['payment_ref']['token'])) {
                $insertData['tokenInfo'] = $paymentdata['booking_details']['payment_ref']['token'];
            }

            if (! empty($response['instalment']['cycles_executed'])) {
                $insertData['additionalDetails']['InstalmentDetails'] = $this->transactionHelper->getInstalmentInformation($response, $this->helper->getLocaleCodeFromContext($salesChannelContext->getContext(), true, $transaction != null ? $transaction->getOrder()->getLanguageId() : null));
            }

            if (!empty($response['customer']['birth_date']) && in_array($response['transaction']['payment_type'], ['GUARANTEED_DIRECT_DEBIT_SEPA', 'GUARANTEED_INVOICE'])) {
                $insertData['additionalDetails'] ['dob'] = date('Y-m-d', strtotime($response['customer']['birth_date']));
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

            //novalnet order comments
            $orderComments = $this->helper->formBankDetails($response, $salesChannelContext->getContext(), $transaction != null ? $transaction->getOrder()->getLanguageId() : null);

            if (!empty($orderTransaction->getCustomFields()['novalnet_comments']) && preg_match('/'.$response ['transaction']['tid'].'/', $orderTransaction->getCustomFields()['novalnet_comments'])) {
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
            if ((in_array($response['transaction']['payment_type'], ['INVOICE','GUARANTEED_INVOICE','GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA','PREPAYMENT','CASHPAYMENT', 'MULTIBANCO'])) && in_array($response['transaction']['status'], ['CONFIRMED', 'ON_HOLD', 'PENDING']) && !empty($transaction)) {
                $this->transactionHelper->prepareMailContent($transaction->getOrder(), $salesChannelContext, $orderComments);
            }

            if (isset($response['custom']['input2']) && $response['custom']['input2'] == 'subParentOrderNumber') {
                if (isset($response['custom']['inputval2']) && !empty($response['custom']['inputval2'])) {
                    $response ['custom']['paymentMethodId'] = $orderTransaction->getPaymentMethodId();

                    if (empty($paymentdata)) {
                        $response['paymentData'] = [
                                'payment_details' => [
                                        'type' => $response['transaction']['payment_type'] ?? 'NOVALNET_PAYMENT',
                                        'name' => $this->helper->getUpdatedPaymentName($response['transaction']['payment_type'], $locale)
                                ],
                                'booking_details' => [
                                        'test_mode' => $response ['transaction']['test_mode']
                                ]
                            ];
                    } else {
                        $response['paymentData'] = $paymentdata;
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
     * Handle transaction failure process
     *
     * @param OrderTransactionEntity        $orderTransaction
     * @param array                         $response
     * @param SalesChannelContext           $salesChannelContext
     * @param string|null                   $isAdmin
     * @param AsyncPaymentTransactionStruct $transaction|null
     */
    public function transactionFailure(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, AsyncPaymentTransactionStruct $transaction = null, string $isAdmin = null)
    {
        $errorMessage = $this->helper->getResponseText($response);
        if (empty($isAdmin)) {
            $this->helper->setSession('novalnetErrorMessage', $errorMessage);
            $paymentdata = $this->helper->getSession('novalnetPaymentdata');
        }

        if (empty($isAdmin)) {
            $this->unsetSession();
        }

        $orderComments = $this->helper->formBankDetails($response, $salesChannelContext->getContext(), $transaction != null ? $transaction->getOrder()->getLanguageId() : null);

        if (!empty($orderTransaction->getCustomFields()['novalnet_comments']) && preg_match('/'.$response ['transaction']['tid'].'/', $orderTransaction->getCustomFields()['novalnet_comments'])) {
            $orderComments = $orderTransaction->getCustomFields()['novalnet_comments'];
        } else {
            $orderComments .= !empty($orderTransaction->getCustomFields()['novalnet_comments']) ? '&&' .$orderTransaction->getCustomFields()['novalnet_comments'] : '';
        }

        $customFields = ['novalnet_comments' => $orderComments, 'swag_paypal_resource_id' => (string) $response['transaction']['tid']];

        $data = ['id' => $orderTransaction->getId(), 'customFields' => $customFields];

        $this->transactionHelper->orderTransactionUpsert($data, $salesChannelContext->getContext());

        if (!empty($transaction) && empty($isAdmin) && ((!isset($response['custom'])) || ((isset($response['custom']) && (!array_key_exists("BackendOrder", $response['custom'])))))) {
            try {
                throw PaymentException::customerCanceled($orderTransaction->getId(), $errorMessage);
            } catch (\Exception $e) {
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
            $data[ $category ][ $parameter ] = $request->query->get($parameter);
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
            'novalnetpaymentFormData',
            'novalnetRequestParameters'
        ] as $sessionKey) {
            if ($this->helper->hasSession($sessionKey)) {
                $this->helper->removeSession($sessionKey);
            }
        }
    }
}
