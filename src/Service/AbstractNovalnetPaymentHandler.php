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
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\Request;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AbstractPaymentHandler;

abstract class AbstractNovalnetPaymentHandler extends AbstractPaymentHandler
{
    /**
     * @var OrderTransactionStateHandler|null
     */
    protected $orderTransactionStateHandler;

    /**
     * @var NovalnetValidator|null
     */
    protected $validator;

    /**
     * @var NovalnetOrderTransactionHelper|null
     */
    protected $transactionHelper;

    /**
     * @var NovalnetHelper|null
     */
    protected $helper;

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
     * @var string
     */
    protected $novalnetPaymentType;

    /**
     * @var string
     */
    protected $paymentHandler;

    /**
     * @var EntityRepository|null
     */
    protected $orderTransactionRepository;

    /**
     * Constructs a `AbstractNovalnetPaymentHandler`
     *
     * @param NovalnetHelper $helper
     * @param NovalnetOrderTransactionHelper $transactionHelper
     * @param NovalnetValidator $validator
     * @param OrderTransactionStateHandler $orderTransactionStateHandler
     * @param EntityRepository $orderTransactionRepository
     */
    public function __construct(
        NovalnetHelper $helper = null,
        NovalnetOrderTransactionHelper $transactionHelper = null,
        NovalnetValidator $validator = null,
        OrderTransactionStateHandler $orderTransactionStateHandler = null,
        EntityRepository $orderTransactionRepository = null
    ) {
        $this->helper = $helper;
        $this->transactionHelper = $transactionHelper;
        $this->validator = $validator;
        $this->orderTransactionStateHandler = $orderTransactionStateHandler;
        $this->orderTransactionRepository = $orderTransactionRepository;
    }

    /**
     * Prepare payment related parameters
     *
     * @param mixed $transaction
     * @param Request|null $data
     * @param array $parameters
     * @param string $salesChannelId
     * @param string $returnUrl
     */
    abstract public function generatePaymentParameters($transaction, Request $data = null, array &$parameters, string $salesChannelId, string $returnUrl): void;

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
        if (! empty($translations[$locale]['name'])) {
            return $translations[$locale]['name'];
        }
        return $translations['de-DE']['name'];
    }

    /**
     * Get payment description
     *
     * @return string
     */
    public function getDescription(string $locale): string
    {
        $translations = $this->getTranslations();
        if (! empty($translations[$locale]['description'])) {
            return $translations[$locale]['description'];
        }
        return $translations['de-DE']['description'];
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
     * @param Context $context
     * @param OrderTransactionEntity $transaction
     * @param Request|null $request
     * @param string $returnUrl
     *
     * @return array
     */
    public function handlePaymentProcess(Context $context, OrderTransactionEntity $transaction, Request $request = null, string $returnUrl): array
    {
        $parameters = $this->generateBasicParameters($context, $transaction);
        $this->generatePaymentParameters($transaction, $request, $parameters, $transaction->getOrder()->getSalesChannel()->getId(), $returnUrl);
		$paymentMethod  = $this->paymentCode;
        if (!empty($request->get('isBackendOrderCreation'))) {
            $parameters ['custom']['input1'] = 'BackendOrder';
            $parameters ['custom']['inputval1'] = '1';
        } elseif (!empty($request->get('ExpressCheckout'))) {
            $parameters ['custom']['input1'] = 'orderId';
            $parameters ['custom']['inputval1'] = $transaction->getOrder()->getId();
        } elseif (!empty($request->get('isSubscriptionOrder'))) {
            $parameters ['custom']['input1'] = 'shop_subs';
            $parameters ['custom']['inputval1'] = '1';
            if(!empty($request->get('changePayment')) && !empty($request->get('subParentOrderNumber'))){
				$parameters['custom']['input2'] = 'subParentOrderNumber';
				$parameters['custom']['inputval2'] = $request->get('subParentOrderNumber');
			}
        } elseif (!empty($request->get('isRecurringOrder'))) {
            $parameters ['custom']['input1'] = 'isRecurringOrder';
            $parameters ['custom']['inputval1'] = '1';
        }

        if (!empty($request->get('isRecurringOrder')) && !empty($request->get('parentOrderNumber'))) {
            

            if (in_array($paymentMethod, ['novalnetinvoiceguarantee', 'novalnetsepaguarantee', 'novalnetgooglepay' , 'novalnetapplepay', 'novalnetcreditcard', 'novalnetsepa', 'novalnetpaypal', 'novalnetdirectdebitach'])) {
                $data = $this->transactionHelper->fetchNovalnetTransactionData((string) $request->get('parentOrderNumber'), $context, null, true);
                $addtionalDetails = $this->helper->unserializeData($data->getadditionalDetails());

                if (in_array($paymentMethod, ['novalnetgooglepay' , 'novalnetapplepay', 'novalnetcreditcard', 'novalnetsepaguarantee', 'novalnetsepa', 'novalnetpaypal', 'novalnetdirectdebitach'])) {
					
					if (!empty($addtionalDetails['token'])){
                       $parameters ['transaction'] ['payment_data'] ['token'] = $addtionalDetails['token'];
                    } else {
						$parameters ['transaction'] ['payment_data'] ['payment_ref'] = $data->getTid();
					}
					
                    unset($parameters ['transaction'] ['return_url'], $parameters ['transaction'] ['error_return_url']);
                    
                } elseif (!empty($addtionalDetails['dob'])) {
                    $parameters ['customer'] ['birth_date'] =  $addtionalDetails['dob'];
                }
            }
        }
        
        return $this->helper->sendPostRequest($parameters, $this->getPaymentEndpoint($parameters, $transaction->getOrder()->getSalesChannel()->getId()), $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $transaction->getOrder()->getSalesChannel()->getId()));
    }

    /**
     * Built redirect parameters
     *
     * @param string $returnUrl
     * @param array $parameters
     */
    public function redirectParameters(string $returnUrl, array &$parameters): void
    {
        $parameters ['transaction'] ['return_url']  = $parameters ['transaction'] ['error_return_url']  = $returnUrl;
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
        foreach ($transaction->getOrder()->getLineItems()->getElements() as $lineItem) {
            $totalAmount += $lineItem->getPrice()->getTotalPrice();
            $parameters['cart_info']['line_items'][] = array( 'name' => $lineItem->getLabel(), 'price' => round((float) sprintf('%0.2f', $lineItem->getPrice()->getUnitPrice()) * 100), 'quantity' => $lineItem->getQuantity(), 'description' => $lineItem->getDescription(), 'category' => 'physical' );
        }

        foreach ($transaction->getOrder()->getDeliveries()->getElements() as $delivery) {
            $totalAmount += $delivery->getShippingCosts()->getTotalPrice();
            $parameters['cart_info']['items_shipping_price'] = round((float) sprintf('%0.2f', $delivery->getShippingCosts()->getTotalPrice()) * 100);
        }

        if ($transaction->getOrder()->getPrice()->getTotalPrice() > $totalAmount) {
            foreach ($transaction->getOrder()->getPrice()->getCalculatedTaxes()->getElements() as $tax) {
                $parameters['cart_info']['items_tax_price'] = round((float) sprintf('%0.2f', $tax->getTax()) * 100);
            }
        }
    }

    /**
     * Built basic parameters
     *
     * @param Context $context
     * @param mixed $transaction
     *
     * @return array
     */
    public function generateBasicParameters(Context $context, $transaction): array
    {
        // Start to built basic parameters.
        $parameters = [];

		$order = $transaction->getOrder();
        $paymentCode = $this->helper->formatString($this->paymentCode);
        $onHold   = $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $paymentCode ."OnHold", $order->getSalesChannel()->getId());
        $allowB2B = $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $paymentCode ."AllowB2B", $order->getSalesChannel()->getId());

        // Built merchant parameters.
        $parameters['merchant'] = [
            'signature' => str_replace(' ', '', $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.clientId', $order->getSalesChannel()->getId())),
            'tariff'    => $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.tariff', $order->getSalesChannel()->getId())
        ];
		
		$parameters['customer'] = $this->transactionHelper->bookAmountRequest($order, $context);
		$parameters['customer']['email'] = $order->getOrderCustomer()->getEmail();
		$parameters['customer']['customer_ip'] = $this->helper->getIp();
		$parameters['customer']['customer_no'] = $order->getOrderCustomer()->getCustomerNumber();

        // Built transaction parameters.
        $parameters['transaction'] = [
            'amount'         => $this->helper->amountInLowerCurrencyUnit($order->getPrice()->getTotalPrice()),
            'order_no'       => $order->getOrderNumber(),
            'test_mode'      => (int) $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $paymentCode ."TestMode", $order->getSalesChannel()->getId()),
            'currency'       => $order->getCurrency()->getIsoCode(),
            'payment_type'   => $this->getNovalnetPaymentType(),
            'system_name'    => 'Shopware6',
            'system_ip'      => $this->helper->getIp('SYSTEM'),
            'system_version' => $this->helper->getVersionInfo($context),
        ];
        
        if(!empty($this->helper->systemUrl())) {
			$parameters['transaction']['system_url'] = $this->helper->systemUrl();
		}

        // Built custom parameters.
        $parameters['custom'] = [
            'lang' => $this->helper->getLocaleCodeFromContext($context)
        ];

        // Check for Zero amount booking payments
        if (in_array($this->paymentCode, ['novalnetcreditcard', 'novalnetsepa', 'novalnetgooglepay', 'novalnetapplepay', 'novalnetdirectdebitach']) && $onHold == 'zero_amount') {
            $parameters['transaction']['amount'] = 0;
        }

        // Check for Zero amount booking payments
        if (in_array($this->paymentCode, ['novalnetsepaguarantee', 'novalnetinvoiceguarantee', 'novalnetinvoiceinstalment', 'novalnetsepainstalment']) && empty($allowB2B)) {
            unset($parameters['customer']['billing']['company']);
        }
        
        if ($onHold == 'zero_amount' && $this->helper->amountInLowerCurrencyUnit($order->getPrice()->getTotalPrice()) > 0) {
            $parameters ['custom']['input4'] = 'ZeroBooking';
            $parameters ['custom']['inputval4'] = $this->helper->amountInLowerCurrencyUnit($order->getPrice()->getTotalPrice());
        }
       
        return $parameters;
    }

    /**
     * Set payment token in payment request
     *
     * @param string $paymentType
     * @param Request $request
     * @param array $sessionData
     * @param array $parameters
     */
    public function setPaymentToken(string $paymentType, Request $request = null, array $sessionData, array &$parameters): void
    {
        if (!is_null($request->get($paymentType . 'FormData'))) {
            $formData = $request->get($paymentType . 'FormData');
        }
        

        if (!empty($formData) && isset($formData['paymentToken']) && !empty($formData['paymentToken'])) {
            $sessionData['paymentToken'] = $formData['paymentToken'];
        }

        if (! empty($sessionData['paymentToken']) && $sessionData['paymentToken'] !== 'new') {
            $parameters ['transaction']['payment_data']['token'] = $sessionData['paymentToken'];
        } elseif ((! empty($sessionData['saveData']) && $sessionData['saveData'] == 'on') || (!empty($formData) && isset($formData['saveData']) && $formData['saveData'] == 'on') || !empty($request->get('isSubscriptionOrder'))) {
            $parameters ['transaction']['create_token'] = '1' ;
        }
    }

    /**
     * Get Novalnet endpoint to send the payment request
     *
     * @param array $parameters
     * @param string $saleschannelId
     *
     * @return string
     */
    public function getPaymentEndpoint(array $parameters, string $saleschannelId): string
    {
        $action = 'payment';
        if ($this->helper->getSupports('authorize', $this->paymentCode) && $this->validator->isAuthorize($saleschannelId, $this->paymentCode, $parameters)) {
            $action = 'authorize';
        }
        return $this->helper->getActionEndpoint($action);
    }

    /**
     * Check the response parameters for transaction status
     */
    public function checkTransactionStatus(OrderTransactionEntity $orderTransaction, array $response, Context $context, $transaction = null, string $isExpress = null): void
    {
        if ($this->validator->isSuccessStatus($response)) {
            $this->transactionSuccess($orderTransaction, $response, $context, $transaction);
        } else {
            $this->transactionFailure($orderTransaction, $response, $context, $transaction, $isExpress);
        }
    }

    /**
     * Handle transaction success process
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param array $response
     * @param Context $context
     *
     * @return void
     */
    public function transactionSuccess(OrderTransactionEntity $orderTransaction, array $response, Context $context, $transaction = null): void
    {
        try {
            // Get stored current novalnet payment method.
            $paymentMethod = $this->paymentCode;
            // Get current payment method value and store it in session for future reference.
            if ( !is_null($orderTransaction->getPaymentMethod()) && empty($paymentMethod)) {
                $paymentMethod = $this->helper->getPaymentMethodName($orderTransaction->getPaymentMethod());
            }

            $insertData = [
                'id'          => Uuid::randomHex(),
                'paymentType' => $paymentMethod
            ];

            $paymentStatus = '';
            $insertData['paidAmount'] = 0;
            if ($response['transaction']['status'] === 'CONFIRMED' && (!empty($response['transaction']['amount']) || $orderTransaction->getAmount()->getTotalPrice() == 0)) {
                $paymentStatus = 'PAID';
                if (!empty($response['transaction']['amount'])) {
                    $insertData['paidAmount'] = $response['transaction']['amount'];
                }
            } elseif ($response['transaction']['status'] === 'PENDING') {
                $paymentStatus = 'PENDING';
                if ($this->helper->getSupports('payLater', $paymentMethod)) {
                    $paymentStatus = 'PAYLATER';
                }
            } elseif (($response['transaction']['status'] === 'ON_HOLD') || ($response['transaction']['status'] === 'CONFIRMED' && $response['transaction']['amount'] == 0)) {
                $paymentStatus = 'AUTHORIZED';
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

            if (! empty($response['transaction']['bank_details'])) {
                $insertData['additionalDetails'] = $response['transaction']['bank_details'];
            } elseif (!empty($response['transaction']['payment_data']['token'])) {
                $insertData['additionalDetails']['token'] = $response['transaction']['payment_data']['token'];
            }
            
            if($response['transaction']['payment_type'] == 'CASHPAYMENT'){
				
				$insertData['additionalDetails'] ['cashpayment'] = [
					'payment_type' => $response['transaction']['payment_type'],
					'checkout_token' => $response['transaction']['checkout_token'],
					'checkout_js' => $response['transaction']['checkout_js'] 
				];
			}

            if (in_array($paymentMethod, ['novalnetinvoiceguarantee' , 'novalnetsepaguarantee'])  &&  !empty($response['customer']['birth_date'])) {
                $insertData ['additionalDetails'] ['dob'] = date('Y-m-d', strtotime($response['customer']['birth_date']));
            }

            if (! empty($response['instalment']['cycles_executed'])) {
                $locale = $this->helper->getLocaleCodeFromContext($context, true,  $orderTransaction->getOrder()->getLanguageId());
                $insertData['additionalDetails']= $this->transactionHelper->getInstalmentInformation($response, $locale);
            }

            if (!empty($response['custom']['change_payment']) || (!empty($response['custom']['input3']) && $response['custom']['input3'] == 'change_payment')) {
                $insertData['additionalDetails']['change_payment'] = 1;
            }

            if (!empty($insertData['additionalDetails'])) {
                $insertData['additionalDetails'] = $this->helper->serializeData($insertData['additionalDetails']);
            }


            // Upsert data into novalnet_transaction_details.repository
            $this->helper->upsertTransactionData($insertData, $context);

            // Save Novalnet payment token
            if (!in_array($paymentMethod, ['novalnetgooglepay','novalnetapplepay', 'novalnetpaypal']) && !empty($response['transaction']['payment_data']['token']) && empty($response['custom']['isRecurringOrder'])) {
                $this->savePaymentToken($response, $paymentMethod, $orderTransaction->getOrder()->getOrderCustomer()->getCustomer()->getId(), $context);
            }

            // Prepare order comments
            $orderComments = $this->prepareComments($response, $context, $orderTransaction->getOrder() != null ? $orderTransaction->getOrder()->getLanguageId() : null);

            $customFields = [
                'novalnet_comments' => $orderComments,
            ];

            // Update Novalnet comments in Order transaction Repository.
            $this->orderTransactionRepository->upsert([[
                'id' => $orderTransaction->getId(),
                'customFields' => $customFields
            ]], $context);

            // Update novalnet custom fields in order field
            if (!is_null($orderTransaction->getOrder())) {
                $orderTransaction->getOrder()->setCustomFields($customFields);
            }

            $orderTransaction->setCustomFields($customFields);

            if (!empty($paymentStatus) && (empty($response['custom']['input3']) || $response['custom']['input3'] != 'change_payment') && (empty($response['custom']['input1']) || $response['custom']['input1'] != 'BackendOrder')) {
                if ($paymentStatus == 'PAID') {
                    // Payment completed, set transaction status to "PAID"
                    $this->orderTransactionStateHandler->paid($orderTransaction->getId(), $context);
                } elseif ($paymentStatus == 'AUTHORIZED') {
                    $this->orderTransactionStateHandler->authorize($orderTransaction->getId(), $context);
                } elseif (empty($response['custom']['inputval3'])) {
                    $this->orderTransactionStateHandler->process($orderTransaction->getId(), $context);
                }
            } elseif(!empty($paymentStatus) && ( isset($response['custom']['input1']) && $response['custom']['input1'] == 'BackendOrder' )){
				$this->orderTransactionStateHandler->process($orderTransaction->getId(), $context);
			}

            // Send order email with Novalnet transaction comments.
            if (($this->helper->getSupports('payLater', $paymentMethod) || (in_array($paymentMethod, ['novalnetinvoiceguarantee','novalnetsepaguarantee', 'novalnetinvoiceinstalment', 'novalnetsepainstalment']) && in_array($response['transaction']['status'], ['CONFIRMED', 'ON_HOLD', 'PENDING'])))) {
                $this->transactionHelper->prepareMailContent($orderTransaction->getOrder(), $context, $orderComments);
            }
            
            if(isset($response['custom']['input2']) && $response['custom']['input2'] == 'subParentOrderNumber'){
				if(isset($response['custom']['inputval2']) && !empty($response['custom']['inputval2'])){
					$response ['custom']['paymentMethodId'] = $orderTransaction->getPaymentMethodId();
					$this->helper->updateChangePayment($response, $orderTransaction->getOrderId(), $context, true);
				}
			}
            
        } catch (\Exception $e) {
            echo 'Caught exception: ',  $e->getMessage(), "\n";
        }

        if (empty($response['custom']['isRecurringOrder'])) {
            // Unset Novalnet Session
            $this->unsetSession();
        }
        return;
    }

    /**
     * Handle transaction failure process
     *
     * @param OrderTransactionEntity $orderTransaction
     * @param array $response
     * @param Context $context
     * @param mixed $transaction
     * @param string|null $isExpress
     *
     */
    public function transactionFailure(OrderTransactionEntity $orderTransaction, array $response, Context $context, $transaction = null, string $isExpress = null)
    {
        $errorMessage = $this->helper->getResponseText($response);

        if (empty($response['custom']['isRecurringOrder'])) {
            $this->helper->setSession('novalnetErrorMessage', $errorMessage);
            $this->unsetSession();
        }

        // Prepare order comments
        $orderComments = $this->prepareComments($response, $context, $orderTransaction->getOrder() != null ? $orderTransaction->getOrder()->getLanguageId() : null);
        $customFields = [
            'novalnet_comments' => $orderComments,
        ];

        // Update Novalnet comments in Order transaction Repository.
        $this->orderTransactionRepository->upsert([[
            'id' => $orderTransaction->getId(),
            'customFields' => $customFields
        ]], $context);

        if (!is_null($transaction) && empty($isExpress)) {
            throw PaymentException::customerCanceled($orderTransaction->getId(), $errorMessage);
        } else {
            // Payment cancelled, set transaction status to "CANCEL"
            $this->orderTransactionStateHandler->cancel($orderTransaction->getId(), $context);
            return null;
        }
    }

    /**
     * Save payment token
     *
     * @param array $data
     * @param string $paymentMethod
     * @param string $customerId
     * @param Context $context
     */
    public function savePaymentToken(array $data, string $paymentMethod, string $customerId, Context $context): void
    {
        $tokenData = [];
        $keys      = [];

        if (!empty($data['transaction']['payment_data']['token'])) {
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
            } elseif ($this->validator->checkString($paymentMethod, 'novalnetdirectdebitach')) {
                $keys = [
                    'token'       => 'token',
                    'accountData' => 'account_number',
                ];
                $tokenData['type'] = 'ACCOUNT NUMBER';
            }

            $tokenData['paymentType'] = $this->helper->formatString($paymentMethod, 'guarantee');

            if ($this->validator->checkString($paymentMethod, 'instalment')) {
                $tokenData['paymentType'] = $this->helper->formatString($paymentMethod, 'instalment');
            }
            $tokenData['tid'] = $data['transaction']['tid'];

            foreach ($keys as $key => $value) {
                if (! empty($paymentData[$value])) {
                    $tokenData[$key] = $paymentData[$value];
                }
            }

            if (!empty($this->helper->getSession('isSubscriptionOrder'))) {
                $tokenData['subscription'] = (int) $this->helper->getSession('isSubscriptionOrder');
            }

            $this->helper->paymentTokenRepository->savePaymentToken($customerId, $context, $tokenData);
        }
    }

    /**
     * Handle redirect response
     *
     * @param Request $request
     * @param Context $context
     */
    public function handleRedirectResponse(Request $request, Context $context, OrderTransactionEntity $orderTransaction): array
    {
        $accessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $orderTransaction->getOrder()->getSalesChannel()->getId());
        $response = [];
		$paymentCode = $this->helper->formatString($this->paymentCode);
        $txnSecert  = $this->helper->getSession('novalnetTxnSecret');

        if ($request->query->get('status') == 'SUCCESS') {
            if (!empty($txnSecert) && $this->validator->isValidChecksum($request, trim($accessKey), $txnSecert)) {
				$salesChannelContext = $this->helper->getSalesChannelDetails($orderTransaction->getOrder());
                $response = $this->helper->retrieveTransactionDetails($request, $salesChannelContext);
            } else {
                $response = $this->formatQuerystring($request);
                $response['result']['status_text'] = 'Please note some data has been changed while redirecting';
            }
        } else {
            $response = $this->formatQuerystring($request);
            $response['transaction']['test_mode'] = (int) $this->helper->getNovalnetPaymentSettings("NovalnetPayment.settings.". $paymentCode ."TestMode", $orderTransaction->getOrder()->getSalesChannel()->getId());
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
            $this->paymentCode . 'Response',
            $this->paymentCode . 'FormData',
            'novalnetTxnSecret',
            'isSubscriptionOrder',
        ] as $sessionKey) {
            if ($this->helper->hasSession($sessionKey)) {
                $this->helper->removeSession($sessionKey);
            }
        }
    }
}
