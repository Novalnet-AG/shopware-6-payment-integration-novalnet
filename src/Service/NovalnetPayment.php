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
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextPersister;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Util\Random;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;

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
     * @var EntityRepository
     */
    protected $orderTransactionRepository;

    /**
     * @var SalesChannelContextPersister
     */
    protected $contextPersister;

     /**
     * @var OrderTransactionStateHandler
     */
    protected $orderTransactionStateHandler;
    
    /**
     * @var string
     */
    protected $newLine = '/ ';

    /**
     * Constructs a `AsynchronousPaymentHandlerInterface`
     *
     * @param NovalnetHelper $helper
     * @param NovalnetOrderTransactionHelper $transactionHelper
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param EntityRepository $orderTransactionRepository
    */
    public function __construct(
        NovalnetHelper $helper,
        NovalnetOrderTransactionHelper $transactionHelper,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        EntityRepository $orderTransactionRepository
    ) {
		$this->helper = $helper;
		$this->transactionHelper = $transactionHelper;
		$this->orderTransactionStateHandler = $orderTransactionStateHandler;
		$this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * Throw a @see AsyncPaymentProcessException exception if an error ocurres while processing the payment
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $context
     *
     * @throws AsyncPaymentProcessException
     */

    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $context): RedirectResponse
    {
        try {
            $response = $this->handlePaymentProcess($context, $transaction, $dataBag);
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway'.PHP_EOL.$e->getMessage());
        }

        if (!empty($response['result']['redirect_url'])) {
            $this->helper->setSession('novalnetTxnSecret', $response['transaction']['txn_secret']);
            // Redirect to external gateway
            return new RedirectResponse($response['result']['redirect_url']);
        }
        
        $this->helper->setSession('novalnetResponse', $response);

        if (!empty($dataBag->get('isBackendOrderCreation'))) {
            $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $context, $transaction);
        }
        
        // Redirect to external gateway
        return new RedirectResponse($transaction->getreturnUrl());
    }

    /**
     * The finalize function will be called when the user is redirected back to shop from the payment gateway.
     *
     * Throw a @see AsyncPaymentFinalizeException exception if an error ocurres while calling an external payment API
     * Throw a @see CustomerCanceledAsyncPaymentException exception if the customer canceled the payment process on
     * payment provider page
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws AsyncPaymentFinalizeException
     * @throws CustomerCanceledAsyncPaymentException
     */
    public function finalize(AsyncPaymentTransactionStruct $transaction, Request $request, SalesChannelContext $salesChannelContext): void
    {
        try {
            $response = $this->helper->getSession('novalnetResponse');
            if (empty($response)) {
                $response = $this->handleRedirectResponse($request, $salesChannelContext, $transaction->getOrderTransaction());
            }
            $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
        } catch (\Exception $e) {
            throw new AsyncPaymentFinalizeException($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }
    }

    /**
     * The recurring function will be called during recurring payment.
     * Allows to process the order and store additional information.
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws AsyncPaymentProcessException
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
     * @param SalesChannelContext $context
     * @param mixed $transaction
     * @param RequestDataBag $dataBag
     *
     * @return array
     */
    public function handlePaymentProcess(SalesChannelContext $context, $transaction, RequestDataBag $dataBag = null): array
    {
        $paymentData = $response = [];
        if (!empty($dataBag->get('novalnetpaymentFormData'))) {
            $data = $dataBag->get('novalnetpaymentFormData')->all();
            $paymentData = $this->helper->unserializeData($data['paymentData']);
        } elseif (!empty($dataBag->get('isBackendOrderCreation'))) {
            $paymentData = $dataBag->get('BackendPaymentDetails');
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

        $parameters = $this->generateBasicParameters($context, $transaction, $paymentData, $dataBag);

        if (empty($dataBag->get('isRecurringOrder'))) {
            $this->helper->setSession('novalnetPaymentdata', $paymentData);
            $this->helper->setSession('novalnetRequestParameters', $parameters);
        }

        $paymentSettings = $this->helper->getNovalnetPaymentSettings($context->getSalesChannel()->getId());
        $paymentaction = (isset($paymentData['booking_details']['payment_action']) && $parameters['transaction']['amount'] > 0) ? $paymentData['booking_details']['payment_action'] : 'payment';
        $response = $this->helper->sendPostRequest($parameters, $this->getPaymentEndpoint($paymentaction), $paymentSettings['NovalnetPayment.settings.accessKey']);

        if (!empty($dataBag->get('isRecurringOrder'))) {
            $response['isRecurringOrder'] = 1;
            $response['paymentData'] = $paymentData;
        }

        return $response;
    }

    /**
     * Built basic parameters
     *
     * @param SalesChannelContext $context
     * @param mixed $transaction
     * @param array $paymentData
     * @param RequestDataBag $dataBag
     *
     * @return array
     */
    public function generateBasicParameters(SalesChannelContext $context, $transaction, array $paymentData, RequestDataBag $dataBag = null): array
    {
        $paymentSettings = $this->helper->getNovalnetPaymentSettings($context->getSalesChannel()->getId());
         
        // Start to built basic parameters.
        $parameters = [];

        if (!empty($dataBag->get('isSubscriptionOrder'))) {
            $this->helper->setSession('isSubscriptionOrder', true);
        };

         // Built merchant parameters.
        $parameters['merchant'] = [
            'signature' => $paymentSettings['NovalnetPayment.settings.clientId'],
            'tariff'    => $paymentSettings['NovalnetPayment.settings.tariff']
        ];
        
        $customer = $context->getCustomer();
        
        // Built customer parameters.
        if (!empty($customer)) {
            $parameters['customer'] = $this->helper->getCustomerData($customer);
        }

        if (!empty($paymentData['booking_details']['birth_date'])) {
            $parameters['customer']['birth_date'] = $paymentData['booking_details']['birth_date'];
            unset($parameters['customer']['billing']['company']);
        }
        
        $parameters['transaction'] = [
            'amount'         => $this->helper->amountInLowerCurrencyUnit($transaction->getOrder()->getPrice()->getTotalPrice()),
            'order_no'       => $transaction->getOrder()->getOrderNumber(),
            'test_mode'      => (int) $paymentData['booking_details']['test_mode'],
            'payment_type'   => $paymentData['payment_details']['type'],
            'system_name'    => 'Shopware6',
            'system_ip'      => $this->helper->getIp('SYSTEM'),
            'system_version' => $this->helper->getVersionInfo($context->getContext()),
        ];
        
        if (!empty($paymentData['booking_details']['due_date'])) {
            $parameters['transaction']['due_date'] = date('Y-m-d', strtotime('+' . $paymentData['booking_details']['due_date'] . ' days'));
        }
        
        if (isset($paymentData['booking_details']['mobile']) && !empty($paymentData['booking_details']['mobile'])) {
            $parameters['customer']['mobile'] = $paymentData['booking_details']['mobile'];
        }

        if (!empty($paymentData['booking_details']['payment_action']) && $paymentData['booking_details']['payment_action'] == 'zero_amount') {
             $parameters['transaction']['amount'] = 0;
             $parameters['transaction']['create_token'] = 1;
        }

        $paymentDataKeys = ['account_holder', 'iban', 'bic', 'wallet_token', 'pan_hash', 'unique_id', 'account_number', 'routing_number'];

        foreach ($paymentDataKeys as $paymentDataKey) {
            if (!empty($paymentData['booking_details'][$paymentDataKey])) {
                $parameters['transaction']['payment_data'][$paymentDataKey] = $paymentData['booking_details'][$paymentDataKey];
            }
        }

        if (!empty($paymentData['booking_details']['payment_ref']['token'])) {
            $parameters['transaction']['payment_data']['token'] = $paymentData['booking_details']['payment_ref']['token'];
        }

        if (!empty($paymentData['booking_details']['enforce_3d'])) {
            $parameters['transaction']['enforce_3d'] = 1;
        }

        if ($paymentData['payment_details']['process_mode'] == 'redirect' || (isset($paymentData['booking_details']['do_redirect']) && ((bool) $paymentData['booking_details']['do_redirect'] ==  true))) {
            $parameters['transaction']['return_url']  = $parameters['transaction']['error_return_url']  = $transaction->getReturnUrl();
        }

        if (!empty($paymentData['booking_details']['create_token'])) {
            $parameters['transaction']['create_token'] = $paymentData['booking_details']['create_token'];
        }

        if (!empty($paymentData['booking_details']['cycle'])) {
            $parameters['instalment']= [
                'interval'  => '1m',
                'cycles'    => $paymentData['booking_details']['cycle']
            ];
        }

        if (!empty($context->getSalesChannel()->getCurrency())) {
            $parameters['transaction']['currency'] = $context->getCurrency()->getIsoCode() ? $context->getCurrency()->getIsoCode() : $context->getSalesChannel()->getCurrency()->getIsoCode();
        }
        
        if ($paymentData['payment_details']['type'] == 'PAYPAL' && empty($dataBag->get('isRecurringOrder'))) {
            $parameters['cart_info']= $this->paypalSheetDetails($transaction);
        }

        if (!empty($dataBag->get('isRecurringOrder'))) {
			
            $paymentMethod  = $paymentData['payment_details']['type'];
            if (!in_array($paymentMethod, ['INVOICE', 'PREPAYMENT'])) {
                $data = $this->transactionHelper->fetchNovalnetReferenceData($customer->getCustomerNumber(), $dataBag->get('parentOrderNumber'), $paymentMethod, $context->getContext());
                if (!is_null($data)) {
                    $addtionalDetails = $this->helper->unserializeData($data->getAdditionalDetails());

                    if (!empty($data->getTokenInfo())) {
                        $parameters ['transaction'] ['payment_data'] ['token'] = $data->getTokenInfo();
                    }

                    if (empty($parameters['customer']['billing']['company'])) {
                        $parameters ['customer'] ['birth_date'] =  $addtionalDetails['dob'] ?? '';
                    }
                }
            }
            
            if (isset($parameters ['transaction'] ['return_url']) && isset($parameters ['transaction'] ['error_return_url'])) {
                unset($parameters ['transaction'] ['return_url'], $parameters ['transaction'] ['error_return_url']);
            }
        }
        // Built custom parameters.
        $parameters['custom'] = [
            'lang'      => $this->helper->getLocaleCodeFromContext($context->getContext())
        ];

        if (!empty($dataBag->get('isBackendOrderCreation'))) {
            $parameters ['custom']['input4'] = 'BackendOrder';
            $parameters ['custom']['inputval4'] = '1';
        }
        
        return $parameters;
    }
    
    /**
     * Built paypal lineItems to show in paypal page.
     *
     * @param mixed $transaction
     * @return array
     */
    public function paypalSheetDetails($transaction): array
    {
        $totalAmount = 0;
        foreach ($transaction->getOrder()->getLineItems()->getElements() as $lineItem) {
            $totalAmount += $lineItem->getPrice()->getTotalPrice();
            $cartinfo['line_items'][] = array( 'name'=> $lineItem->getLabel(), 'price' => round((float) sprintf('%0.2f', $lineItem->getPrice()->getUnitPrice()) * 100), 'quantity' => $lineItem->getQuantity(), 'description' => $lineItem->getDescription(), 'category' => 'physical' );
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
     * Get Novalnet endpoint to send the payment request
     *
     * @param string $paymentaction
     *
     * @return string
     */
    public function getPaymentEndpoint(string $paymentaction) : string
    {
        $action = 'payment';
        if ($paymentaction == 'authorized') {
            $action = 'authorize';
        }
        return $this->helper->getActionEndpoint($action);
    }

    /**
     * Check the response parameters for transaction status

     * @param OrderTransactionEntity $orderTransaction
     * @param array $response
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $isAdmin
     *
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
     * @param Request $request
     * @param SalesChannelContext $salesChannelContext
     * @param OrderTransactionEntity $orderTransaction
     *
     * @return array
     */
    public function handleRedirectResponse(Request $request, SalesChannelContext $salesChannelContext, OrderTransactionEntity $orderTransaction): array
    {
        $paymentSettings = $this->helper->getNovalnetPaymentSettings($salesChannelContext->getSalesChannel()->getId());
        $response = [];
        $txnsecert = $this->helper->getSession('novalnetTxnSecret');
        $novalnetParameter = $this->helper->getSession('novalnetRequestParameters');
        
        if ($request->query->get('status') == 'SUCCESS') {
            if (!empty($txnsecert) && $this->helper->isValidChecksum($request, $paymentSettings['NovalnetPayment.settings.accessKey'], $txnsecert)) {
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
     * @param OrderTransactionEntity $orderTransaction
     * @param array $response
     * @param SalesChannelContext $salesChannelContext
     */
    public function transactionSuccess(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, $transaction = null): void
    {
        try {
            $paymentStatus = '';
            $paymentdata = [];
            
            $locale = $this->helper->getLocaleFromOrder($orderTransaction->getOrderId());

            if (!empty($response['event'])) {
                $order = $this->transactionHelper->getOrderCriteria($orderTransaction->getOrderId(), $salesChannelContext->getContext());
                $paymentSettings = $this->helper->getNovalnetPaymentSettings($order->getSalesChannel()->getId());
            } else {
                $paymentSettings = $this->helper->getNovalnetPaymentSettings($salesChannelContext->getSalesChannel()->getId());
            }

            if (!empty($response['isRecurringOrder']) && !empty($response['paymentData'])) {
                $paymentdata = $response['paymentData'];
            } else {
                $paymentdata = $this->helper->getSession('novalnetPaymentdata');
            }
            
            $paymentType = $response['transaction']['payment_type'] ?? 'NOVALNET_PAYMENT';
            $paymentName = $this->helper->getUpdatedPaymentName($paymentType, $locale);

            $insertData = [
                'id'    => Uuid::randomHex(),
                'paymentType' => $paymentType,
                'paidAmount' => 0,
                'tid' => $response['transaction']['tid'],
                'gatewayStatus' => $response['transaction']['status'],
                'amount' => $response['transaction']['amount'],
                'currency' => $response['transaction']['currency'],
                'orderNo' => $response['transaction']['order_no'],
                'customerNo' => !empty($response['customer']['customer_no']) ? $response['customer']['customer_no'] : '',
                 'additionalDetails' => [
                    'payment_name' => $paymentName
                ]
            ];

            if (empty($response['isRecurringOrder']) && !empty($this->helper->getSession('isSubscriptionOrder'))) {
                $insertData['additionalDetails']['subscription'] = $paymentdata;
            }

            if ($response['transaction']['status'] === 'CONFIRMED' && (!empty($response['transaction']['amount']) || $orderTransaction->getAmount()->getTotalPrice() == 0)) {
                $insertData['paidAmount'] = $response['transaction']['amount'];
                if (!empty($paymentSettings['NovalnetPayment.settings.completeStatus'])) {
                    $paymentStatus = strtoupper($paymentSettings['NovalnetPayment.settings.completeStatus']);
                } else {
                    $paymentStatus = 'PAID';
                }
            } elseif ($response['transaction']['status'] === 'PENDING') {
                $paymentStatus = 'PENDING';
            } elseif ($response['transaction']['status'] === 'ON_HOLD') {
                if (!empty($paymentSettings['NovalnetPayment.settings.onHoldStatus'])) {
                    $paymentStatus = strtoupper($paymentSettings['NovalnetPayment.settings.onHoldStatus']);
                } else {
                    $paymentStatus = 'AUTHORIZED';
                }
            } elseif (($response['transaction']['status'] === 'CONFIRMED' && $response['transaction']['amount'] == 0 && $orderTransaction->getAmount()->getTotalPrice() != 0 && !in_array($response['transaction']['payment_type'], ['PREPAYMENT']))) {
                $paymentStatus = 'AUTHORIZED';
            }
           
            if (empty($response['isRecurringOrder']) && $response['transaction']['amount'] == 0) {
                $insertData['additionalDetails']['novalnetRequestParameters'] = $this->helper->getSession('novalnetRequestParameters');
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

            if (!empty($response['customer']['birth_date'])) {
                $insertData ['additionalDetails'] ['dob'] = date('Y-m-d', strtotime($response['customer']['birth_date']));
            }
            
			if(!empty($insertData['additionalDetails'])){
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
            
            $customFields = ['novalnet_comments' => $orderComments, 'novalnet_payment_name' => $paymentName];

			$this->orderTransactionRepository->upsert([['id' => $orderTransaction->getId(), 'customFields' => $customFields]], $salesChannelContext->getContext());
			
            if (!empty($paymentStatus)) {
				
				$this->orderTransactionStateHandler->reopen($orderTransaction->getId(), $salesChannelContext->getContext());
				
                if ($paymentStatus == 'PAID') {
                    // Payment completed, set transaction status to "PAID"
                    $this->orderTransactionStateHandler->paid($orderTransaction->getId(), $salesChannelContext->getContext());
                } elseif ($paymentStatus == 'AUTHORIZED') {
                    $this->orderTransactionStateHandler->authorize($orderTransaction->getId(), $salesChannelContext->getContext());
                } elseif ($paymentStatus == 'PROCESS') {
                    $this->orderTransactionStateHandler->process($orderTransaction->getId(), $salesChannelContext->getContext());
                } elseif ($paymentStatus == 'CANCELLED' || $paymentStatus == 'CANCEL') {
                    $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $salesChannelContext->getContext());
                } elseif ($paymentStatus == 'FAILED') {
                    $this->orderTransactionStateHandler->fail($orderTransaction->getId(), $salesChannelContext->getContext());
                } elseif ($paymentStatus == 'PAIDPARTIALLY') {
                    $this->orderTransactionStateHandler->payPartially($orderTransaction->getId(), $salesChannelContext->getContext());
                } elseif (empty($response['custom']['inputval4'])) {
                    $this->orderTransactionStateHandler->process($orderTransaction->getId(), $salesChannelContext->getContext());
                }
            }

             // Send order email with Novalnet transaction comments.
            if ((in_array($response['transaction']['payment_type'], ['INVOICE','GUARANTEED_INVOICE','GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA','PREPAYMENT','CASHPAYMENT', 'MULTIBANCO'])) && in_array($response['transaction']['status'], ['CONFIRMED', 'ON_HOLD', 'PENDING']) && !empty($transaction)) {
                $this->transactionHelper->prepareMailContent($transaction->getOrder(), $salesChannelContext, $orderComments);
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
     * @param OrderTransactionEntity $orderTransaction
     * @param array $response
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $isAdmin
     *
     * throws CustomerCanceledAsyncPaymentException
     */
    public function transactionFailure(OrderTransactionEntity $orderTransaction, array $response, SalesChannelContext $salesChannelContext, $transaction = null, string $isAdmin = null): ?CustomerCanceledAsyncPaymentException
    {
        $errorMessage = $this->helper->getResponseText($response);
        $local = $this->helper->getLocaleFromOrder($orderTransaction->getorderId());
        if (empty($isAdmin)) {
            $this->helper->setSession('novalnetErrorMessage', $errorMessage);
            $paymentdata = $this->helper->getSession('novalnetPaymentdata');
        }
        
        $paymentType = $response['transaction']['payment_type'] ?? 'NOVALNET_PAYMENT';
		$paymentName = $this->helper->getUpdatedPaymentName($paymentType, $local);
		
        if (empty($isAdmin)) {
            $this->unsetSession();
        }

        $orderComments = $this->helper->formBankDetails($response, $salesChannelContext->getContext(), $transaction != null ? $transaction->getOrder()->getLanguageId() : null);
        
        if (!empty($orderTransaction->getCustomFields()['novalnet_comments']) && preg_match('/'.$response ['transaction']['tid'].'/', $orderTransaction->getCustomFields()['novalnet_comments'])) {
            $orderComments = $orderTransaction->getCustomFields()['novalnet_comments'];
        } else {
            $orderComments .= !empty($orderTransaction->getCustomFields()['novalnet_comments']) ? '&&' .$orderTransaction->getCustomFields()['novalnet_comments'] : '';
        }
        
        $customFields = ['novalnet_comments' => $orderComments, 'novalnet_payment_name' => $paymentName];

		$this->orderTransactionRepository->upsert([['id' => $orderTransaction->getId(), 'customFields' => $customFields]], $salesChannelContext->getContext());

        if (!empty($transaction) && empty($isAdmin)) {
            try {
                throw new CustomerCanceledAsyncPaymentException($orderTransaction->getId(), $errorMessage);
            } catch (\Exception $e) {
                $this->orderTransactionStateHandler->reopen($orderTransaction->getId(), $salesChannelContext->getContext());
                throw new CustomerCanceledAsyncPaymentException($orderTransaction->getId(), $errorMessage);
            }
        } else {
            // Payment cancelled, set transaction status to "CANCEL"
            $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $salesChannelContext->getContext());
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
     *
     */
    public function unsetSession(): void
    {
        foreach ([
            'novalnetResponse',
            'novalnetPaymentdata',
            'novalnetTxnSecret',
            'novalnetpaymentFormData',
            'novalnetRequestParameters',
            'isSubscriptionOrder'
        ] as $sessionKey) {
            if ($this->helper->hasSession($sessionKey)) {
                $this->helper->removeSession($sessionKey);
            }
        }
    }
}
