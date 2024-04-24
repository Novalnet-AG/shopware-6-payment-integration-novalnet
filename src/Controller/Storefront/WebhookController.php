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
 * If you wish to customize Novalnet payment extension for your needs, please contact technic@novalnet.de for more information.
 *
 * @category    Novalnet
 * @package     NovalnetPayment
 * @copyright   Copyright (c) Novalnet
 * @license     https://www.novalnet.de/payment-plugins/kostenlos/lizenz
 */
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Controller\Storefront;

use Novalnet\NovalnetPayment\Content\PaymentTransaction\NovalnetPaymentTransactionEntity;
use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Shopware\Core\Content\Mail\Service\AbstractMailService;


#[Route(defaults: ['_routeScope' => ['storefront']])]

class WebhookController extends StorefrontController
{
    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionStateHandler;

    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var NovalnetOrderTransactionHelper
     */
    private $transactionHelper;

    /**
     * @var string
     */
    private $newLine = '/ ';

    /**
     * @var string
     */
    private $novalnetHostName = 'pay-nn.de';

    /**
     * @var string
     */
    private $paymentMethodName;

    /**
     * @var StateMachineRegistry
     */
    private $stateMachineRegistry;

    /**
     * @var array
     */
    private $eventData;

    /**
     * @var string
     */
    private $eventType;

    /**
     * @var string
     */
    private $formattedAmount;

    /**
     * @var OrderTransactionEntity
     */
    private $orderTransaction;

    /**
     * @var int
     */
    private $parentTid;

    /**
     * @var SalesChannelContext
     */
    private $salesChannelContext;
    
    /**
     * @var Context
     */
    private $context;

    /**
     * @var NovalnetPaymentTransactionEntity
     */
    private $orderReference;

    /**
     * @var int
     */
    private $eventTid;

    /**
     * @var array
     */
    private $response;

    /**
     * @var array
     */
    private $paymentSettings;

    /**
     * @var EntityRepository
     */
    private $orderTransactionRepository;

    /**
     * @var OrderEntity
     */
    private $order;

    /**
     * @var string
     */
    private $locale;
    
     /**
     * @var AbstractMailService
     */
    private $mailService;

    /**
     * @var array
     */
    private $mandatoryParams = [
        'event' => [
            'type',
            'checksum',
            'tid'
        ],
        'merchant' => [
            'vendor',
            'project'
        ],
        'transaction' => [
            'tid',
            'payment_type',
            'status',
        ],
        'result' => [
            'status'
        ],
    ];

    /**
     * {@inheritdoc}
     */
    public function __construct(
        NovalnetHelper $helper,
        NovalnetOrderTransactionHelper $transactionHelper,
        TranslatorInterface $translator,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        StateMachineRegistry $stateMachineRegistry,
        EntityRepository $orderTransactionRepository,
        AbstractMailService $mailService
    ) {
        $this->helper                        = $helper;
        $this->transactionHelper             = $transactionHelper;
        $this->translator                    = $translator;
        $this->orderTransactionStateHandler  = $orderTransactionStateHandler;
        $this->stateMachineRegistry          = $stateMachineRegistry;
        $this->orderTransactionRepository    = $orderTransactionRepository;
        $this->mailService                   = $mailService;
    }
    
    #[Route(path: '/novalnet/callback', name: 'api.action.novalnetpayment.status-action', defaults: ['csrf_protected' => false], methods: ['GET', 'POST'])]
    public function statusAction(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->salesChannelContext = $salesChannelContext;
        $this->context   = $this->salesChannelContext->getContext();
        $this->eventData = $this->helper->unserializeData((string) $request->getContent());
        if (!$this->eventData) {
            $this->response = ['message' => "Received data is not in the JSON format"];
            return $this->debugMessage();
        }
        
        if (! empty($this->eventData ['transaction'] ['order_no'])) {
                $orderNumber = $this->eventData ['transaction'] ['order_no'];
                $order = $this->transactionHelper->getOrderEntity($orderNumber, $this->context);
                $this->paymentSettings = $order != null ? $this->helper->getNovalnetPaymentSettings($order->getSalesChannel()->getId()) : $this->helper->getNovalnetPaymentSettings($this->salesChannelContext->getSalesChannel()->getId());
        } else {
			$this->paymentSettings = $this->helper->getNovalnetPaymentSettings($this->salesChannelContext->getSalesChannel()->getId());
		}
         
        if (!$this->authenticateRequestIp() || !$this->validateEventData() || !$this->validateChecksum()) {
            return $this->debugMessage();
        }
        
        $this->eventType = $this->eventData ['event'] ['type'];
        $this->eventTid = $this->eventData ['event'] ['tid'];
        $this->parentTid = $this->eventTid;
        
        if (! empty($this->eventData ['event'] ['parent_tid'])) {
            $this->parentTid = $this->eventData ['event'] ['parent_tid'];
        }
        
        if (! empty($this->eventData ['instalment']['cycle_amount'])) {
            $this->formattedAmount = $this->helper->amountInBiggerCurrencyUnit((int) $this->eventData ['instalment']['cycle_amount'], $this->eventData ['transaction'] ['currency'], $this->context);
        } elseif (!empty($this->eventData ['transaction'] ['amount'])) {
            $this->formattedAmount = $this->helper->amountInBiggerCurrencyUnit((int) $this->eventData ['transaction'] ['amount'], $this->eventData ['transaction'] ['currency'], $this->context);
        }
        
        // Get order reference.
        if (!$this->getOrderReference()) {
            return $this->debugMessage();
        }
        
        $this->eventData ['transaction'] ['currency'] = isset($this->eventData ['transaction'] ['currency']) ? $this->eventData ['transaction'] ['currency'] : $this->orderReference->getCurrency();
        
        $this->response ['message'] = '';
        
        if ($this->helper->isSuccessStatus($this->eventData)) {
            switch ($this->eventType) {
                case "PAYMENT":
                    $this->response ['message'] .= 'Novalnet Callback executed. The Transaction ID already existed';
                    break;
                    
                case "CREDIT":
                    $callbackComments = $this->creditProcess();
                    break;
                    
                case "TRANSACTION_CAPTURE":
                case "TRANSACTION_CANCEL":
                    $callbackComments = $this->transactionCaptureVoid();
                    break;
                    
                case "INSTALMENT":
                    $callbackComments = $this->instalmentProcess();
                    break;
                    
                case "CHARGEBACK":
                case "RETURN_DEBIT":
                case "REVERSAL":
                    $callbackComments = $this->chargebackProcess();
                    break;
                    
                case "PAYMENT_REMINDER_1":
                case "PAYMENT_REMINDER_2":
                    $callbackComments = $this->paymentReminderProcess();
                    break;
                    
                case "SUBMISSION_TO_COLLECTION_AGENCY":
                    $callbackComments = $this->collectionProcess();
                    break;
                    
                case "TRANSACTION_UPDATE":
                    $callbackComments = $this->transactionUpdate();
                    break;
                    
                case "TRANSACTION_REFUND":
                    $callbackComments = $this->transactionrefund();
                    break;
                    
                case "INSTALMENT_CANCEL":
                    $callbackComments = $this->instalmentCancelProcess();
                    break;

                default:
                    $this->response ['message'] .= "The webhook notification has been received for the unhandled EVENT type($this->eventType)";
            }
        } else {
            $this->response ['message'] .= 'The Payment has been received';
        }
        if (!empty($callbackComments)) {
            $this->response['message'] .= $callbackComments;
            $this->sendNotificationEmail();
        }
        return $this->debugMessage();
    }
    
    /**
     * Authenticate server request
     *
     * @return bool
     */
    public function authenticateRequestIp() : bool
    {
        // Host based validation.
        if (! empty($this->novalnetHostName)) {
	
            // Authenticating the server request based on IP.
            $requestReceivedIp = $this->helper->checkWebhookIp(gethostbyname($this->novalnetHostName));
			$deactivateIp = $this->paymentSettings ['NovalnetPayment.settings.deactivateIp'];
            if (empty($requestReceivedIp) && empty($deactivateIp)) {
                    $this->response = ['message' => "Unauthorised access from the IP $requestReceivedIp"];
                    return false;
            } 
        } else {
            $this->response = [ 'message' => 'Unauthorised access from the IP. Novalnet Host name is empty' ];
            return false;
        }
       
        return true;
    }
    
    /**
     * Validate EventData
     *
     * @return bool
     */
    
    public function validateEventData() : bool
    {
        if (! empty($this->eventData ['custom'] ['shop_invoked'])) {
            $this->response = [ 'message' => 'Process already handled in the shop.' ];
            return false;
        }
       
        foreach ($this->mandatoryParams as $category => $parameters) {
            if (empty($this->eventData [ $category ])) {
                // Could be a possible manipulation in the notification data.
                $this->response = [ 'message' => "Required parameter category($category) not received" ];
            } elseif (! empty($parameters)) {
                foreach ($parameters as $parameter) {
                    if (!isset($this->eventData [ $category ] [ $parameter ])) {
                        // Could be a possible manipulation in the notification data.
                        $this->response = [ 'message' => "Required parameter($parameter) in the category($category) not received" ];
                        return false;
                    } elseif (in_array($parameter, [ 'tid', 'parent_tid' ], true) && ! preg_match('/^\d{17}$/', (string) $this->eventData [ $category ] [ $parameter ])) {
                        $this->response = [ 'message' => "Invalid TID received in the category($category) not received $parameter" ];
                        return false;
                    }
                }
            }
        }
        return true;
    }
    
     /**
     * Validate checksum
     *
     * @return bool
     */
    
    public function validateChecksum() : bool
    {
        $tokenString = $this->eventData ['event'] ['tid'] . $this->eventData ['event'] ['type']. $this->eventData ['result'] ['status'];
        
        if (isset($this->eventData ['transaction'] ['amount'])) {
            $tokenString .= $this->eventData ['transaction'] ['amount'];
        }
        if (isset($this->eventData ['transaction'] ['currency'])) {
            $tokenString .= $this->eventData ['transaction'] ['currency'];
        }
        
        $paymentAccessKey = $this->paymentSettings['NovalnetPayment.settings.accessKey'];
        if (! empty($paymentAccessKey)) {
            $tokenString .= strrev($paymentAccessKey);
        }
        $generatedChecksum = hash('sha256', $tokenString);

        if ($generatedChecksum !== $this->eventData ['event'] ['checksum']) {
            $this->response = [ 'message' => 'While notifying some data has been changed. The hash check failed' ];
            return false;
        }
        
        return true;
    }
    
    /**
    * Print the Webhook messages.
    *
    *   @return Response
     */
    public function debugMessage() : Response
    {
        return new Response($this->helper->serializeData($this->response));
    }

    /**
     * Get order reference from the novalnet_transaction_detail table on shop database.
     *
     * @return bool
     */
     
    public function getOrderReference() :bool
    {
        $orderNumber = '';
        $paymentMethod = '';
      
        if (! empty($this->eventData ['transaction'] ['order_no']) || ! empty($this->parentTid)) {
            if (! empty($this->eventData ['transaction'] ['order_no'])) {
                $orderNumber = $this->eventData ['transaction'] ['order_no'];
            }
            
            $this->order = $this->transactionHelper->getOrderEntity($orderNumber, $this->context);
            
            if ($this->order == null)
            {
				$this->response = [ 'message' => 'Order Reference not exist in Database!'];
				return false;
			}
			
            $this->orderTransaction = $this->order->getTransactions()->last();
            $this->locale = !empty($this->order->getLanguageId()) ? $this->helper->getLocaleCodeFromContext($this->context, true, $this->order->getLanguageId()) : $this->helper->getLocaleCodeFromContext($this->context, true);

            if (!is_null($this->orderTransaction)) {
                $paymentMethod = $this->orderTransaction->getPaymentMethod();
                if (!empty($paymentMethod) && !empty($paymentMethod->getHandlerIdentifier())) {
					$paymentHandlerIdentifier = (explode("\\",$paymentMethod->getHandlerIdentifier()));
                    $this->paymentMethodName = end($paymentHandlerIdentifier);
                }
            }
            
            if ($this->paymentMethodName != 'NovalnetPayment' && empty($this->eventData ['custom']['change_payment'])) {
                $this->response = ['message' => 'Order Reference not exist in Database!'];
                return false;
            }
            
            $this->paymentSettings = $this->helper->getNovalnetPaymentSettings($this->order->getSalesChannel()->getId());
            $this->eventData['transaction']['currency'] = !empty($this->eventData['transaction']['currency']) ? $this->eventData['transaction']['currency'] : $this->order->getCurrency()->getIsoCode();
            $this->eventData['customer']['customer_no'] = !empty($this->eventData['customer']['customer_no']) ? $this->eventData['customer']['customer_no'] : $this->order->getOrderCustomer()->getCustomerNumber();
            
            if (isset($this->eventData ['custom']['change_payment']) && !empty($this->eventData ['custom']['change_payment'])) {
                if ($this->helper->isSuccessStatus($this->eventData)) {
                    $orderData = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderNumber, $this->context, (string) $this->eventData ['transaction']['tid'], true);
                    if (empty($orderData)) {
                        $this->transactionHelper->updateChangePayment($this->eventData, $this->order->getId(), $this->context);
                        $this->response = [ 'message' => 'Communication failure has been handled successfully. The transaction details has been updated' ];
                    } else {
                        $this->response = [ 'message' => 'Novalnet Callback executed. The Transaction ID already existed' ];
                    }
                } else {
                    $this->response = [ 'message' => 'The payment failure has  been handled in the shop.' ];
                }
                return false;
            }
            
            $this->orderReference = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderNumber, $this->context, (string) $this->parentTid);
            
            if ($this->orderReference == null) {
                if ($this->helper->isSuccessStatus($this->eventData)) {
                    if ($this->eventData ['transaction'] ['payment_type'] === 'ONLINE_TRANSFER_CREDIT' && !empty($this->parentTid)) {
						$response = $this->helper->sendPostRequest(['transaction' => ['tid' => $this->parentTid]], $this->helper->getActionEndpoint('transaction_details'), $this->paymentSettings['NovalnetPayment.settings.accessKey']);
						if (!empty($response['transaction'] ['payment_type'])) {
							$this->eventData['transaction'] ['payment_type'] = $response['transaction'] ['payment_type'];
						}
						$this->updateInitialPayment($paymentMethod);
						$callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.creditMessage', [], null, $this->locale), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->eventTid);
						$this->transactionHelper->postProcess($this->orderTransaction, $this->context, $callbackComments);
                    } else {
						$this->updateInitialPayment($paymentMethod);
					}
                } else {
                    $customFields = $this->orderTransaction->getCustomFields();
                    if (empty($customFields['novalnet_comments']) || !preg_match('/'.$this->eventData ['transaction']['tid'].'/', $customFields['novalnet_comments'])) {
                        $this->updateInitialPayment($paymentMethod);
                    } else {
                        $this->response = [ 'message' => 'Novalnet Callback executed. The Transaction ID already existed' ];
                    }
                }
                return false;
            }

            if ($this->eventType == "PAYMENT" && $this->helper->isSuccessStatus($this->eventData) && !empty($this->eventData['transaction']['amount']) && $this->orderReference->getAmount() == 0 && $this->eventData['transaction']['tid'] != $this->orderReference->getTid()) {
                $bookAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($this->eventData['transaction'] ['amount'], $this->eventData['transaction'] ['currency'], $this->context);
                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.bookedComment', [], null, $this->locale), $bookAmountInBiggerUnit, $this->eventData['transaction'] ['tid']);
                    
                $this->transactionHelper->postProcess($this->orderTransaction, $this->context, $message, [
                    'id'      => $this->orderReference->getId(),
                    'tid'     => $this->eventData['transaction']['tid'],
                    'amount'  => $this->eventData['transaction']['amount'],
                    'paidAmount'  => $this->eventData['transaction']['amount'],
                    'gatewayStatus'  => $this->eventData['transaction']['status'],
                ]);
                
                try {
                    if (!empty($this->paymentSettings['NovalnetPayment.settings.completeStatus'])) {
                        $completeStatus = $this->paymentSettings['NovalnetPayment.settings.completeStatus'];
                        $this->transactionHelper->managePaymentStatus($completeStatus, $this->orderTransaction->getId(), $this->context);
                    } else {
                        $this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $this->context);
                    }
                } catch (IllegalTransitionException $exception) {
                }
                
                $this->response = ['message' => $message];
                return false;
            }
        }
        
        
        return true;
    }
    
    /**
     * Handle communication failure
     *
     * @param PaymentMethodEntity $paymentMethod
     */
    public function updateInitialPayment(PaymentMethodEntity $paymentMethod): void
    {
        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
        
        if (strpos($handlerIdentifier, "\NovalnetPayment")) {
            $handlerIdentifier = 'Novalnet\NovalnetPayment\Service\NovalnetPayment';
        }
        
        $paymentMethodInstance = new $handlerIdentifier(
            $this->helper,
            $this->transactionHelper,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepository
        );
    
        $paymentTransaction = new AsyncPaymentTransactionStruct($this->orderTransaction, $this->order, $this->generateUrl('frontend.checkout.cart.page'));
  
        if (method_exists($paymentMethodInstance, 'checkTransactionStatus')) {
            $this->transactionHelper->updateSubscriptionData($this->eventData['transaction']['status'], $this->orderTransaction->getPaymentMethodId(), $this->order->getId());
            $paymentMethodInstance->checkTransactionStatus($this->orderTransaction, $this->eventData, $this->salesChannelContext, $paymentTransaction, '1');
            if ( !empty($this->orderTransaction->getCustomFields()['novalnet_comments'])) {
                $this->response = [ 'message' => 'The transaction details has been updated successfully' ];
            } else {
                $this->response = [ 'message' => 'Communication failure has been handled successfully. The transaction details has been updated' ];
            }
        } else {
            $this->response = [ 'message' => 'Payment not found in the order' ];
        }
    }
    
    /**
     * Handle payment credit process
     *
     * @return string
     */
    private function creditProcess(): string
    {
        $upsertData       = [];
        if (!empty($this->eventData['transaction']['amount'])) {
            if ((int) $this->orderReference->getPaidAmount() < (int) $this->orderReference->getAmount() && in_array($this->eventData['transaction']['payment_type'], ['INVOICE_CREDIT', 'CASHPAYMENT_CREDIT', 'MULTIBANCO_CREDIT', 'ONLINE_TRANSFER_CREDIT', 'DEBT_COLLECTION_DE'])) {
                $paidAmount = (int) $this->orderReference->getPaidAmount() + (int) $this->eventData['transaction']['amount'];
                $totalAmount = (int) $this->orderReference->getAmount() - (int) $this->orderReference->getRefundedAmount();

                $upsertData['id']            = $this->orderReference->getId();
                $upsertData['gatewayStatus'] = $this->eventData['transaction']['status'];
                $upsertData['paidAmount']    = $paidAmount;
                
                $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.creditMessage', [], null, $this->locale), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->eventTid);

                $this->transactionHelper->postProcess($this->orderTransaction, $this->context, $callbackComments, $upsertData);
                
                if (($paidAmount >= $totalAmount)) {
                    try {
                        if (version_compare($this->helper->getShopVersion(), '6.4.7.0', '>=')) {
                            $this->orderTransactionStateHandler->process($this->orderTransaction->getId(), $this->context);
                        }
                        if (!empty($this->paymentSettings['NovalnetPayment.settings.completeStatus'])) {
                            $completeStatus = $this->paymentSettings['NovalnetPayment.settings.completeStatus'];
                            $this->transactionHelper->managePaymentStatus($completeStatus, $this->orderTransaction->getId(), $this->context);
                        } else {
                             $this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $this->context);
                        }
                    } catch (IllegalTransitionException $exception) {
                    }
                } elseif ($paidAmount !=0 && $paidAmount < $totalAmount) {
                    $this->orderTransactionStateHandler->payPartially($this->orderTransaction->getId(), $this->context);
                }
                return $callbackComments;
            } else {
                $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.creditMessage', [], null, $this->locale), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->eventTid);
                $this->transactionHelper->postProcess($this->orderTransaction, $this->context, $callbackComments, []);
                return $callbackComments;
            }
        } else {
            $callbackComments = 'Required parameter (amount) in the category (transaction) not received';
            return $callbackComments;
        }
    }
    
    /**
     * Handle transaction Capture process
     *
     * @return string
     */
    private function transactionCaptureVoid(): string
    {
        $callbackComments = '';
        $appendComments = true;
        $upsertData =[];
        if (in_array($this->orderReference->getGatewayStatus(), ['ON_HOLD', 'PENDING'])) {
            $upsertData['id'] = $this->orderReference->getId();
            
            $transactionStatus = $this->eventData['transaction']['status'];
            $salesChannelContext = $this->salesChannelContext->getContext();
            $additionalDetails = $this->orderReference->getAdditionalDetails();
            $transactionAdditionDetails = $this->helper->unserializeData($additionalDetails);
            $paymentType = $this->orderReference->getPaymentType();
            $orderPaymentType = $this->helper->getUpdatedPaymentType($paymentType);
            $this->eventData['transaction']['amount'] = !empty($this->eventData['transaction']['amount']) ? $this->eventData['transaction']['amount'] : $this->orderReference->getAmount();

            if ($this->eventType === 'TRANSACTION_CAPTURE') {
                if ($orderPaymentType === 'INVOICE') {
                    $transactionStatus = 'PENDING';
                }
                if (in_array($transactionStatus, ['CONFIRMED', 'PENDING'])) {
                    if ($transactionStatus == 'CONFIRMED') {
                        $upsertData['paidAmount'] = $this->orderReference->getAmount();
                        if (in_array($this->eventData['transaction']['payment_type'], ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                            $upsertData['additionalDetails'] = $transactionAdditionDetails;
                            $this->eventData['transaction']['amount'] = $this->orderReference->getAmount();
                            $upsertData['additionalDetails']['InstalmentDetails'] = $this->transactionHelper->getInstalmentInformation($this->eventData, $this->locale);
                            $upsertData['additionalDetails'] = $this->helper->serializeData($upsertData['additionalDetails']);
                        }
                    }
                }
            }
            
            if (!empty($additionalDetails) && !empty($paymentType) && in_array($orderPaymentType, ['INVOICE', 'GUARANTEED_INVOICE', 'PREPAYMENT', 'INSTALMENT_INVOICE'])) {
                $appendComments = false;
                if (!empty($transactionAdditionDetails['bankDetails'])) {
                    $bankDetails = $transactionAdditionDetails['bankDetails'];
                } elseif (!empty($transactionAdditionDetails['account_holder'])) {
                    $bankDetails = $transactionAdditionDetails;
                }
                
                if (!empty($bankDetails)) {
                    $this->eventData['transaction']['bank_details'] = $bankDetails;
                }
                $callbackComments .= $this->helper->formBankDetails($this->eventData, $salesChannelContext, $this->order->getLanguageId()) . $this->newLine;
            }
            
            if (!empty($paymentType) && in_array($orderPaymentType, ['GUARANTEED_DIRECT_DEBIT_SEPA',  'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                $appendComments = false;
                $callbackComments .= $this->helper->formBankDetails($this->eventData, $salesChannelContext, $this->order->getLanguageId()) . $this->newLine;
            }
            
            $transactionMsg = ($this->eventType === 'TRANSACTION_CAPTURE') ? 'NovalnetPayment.text.confirmMessage' : 'NovalnetPayment.text.faliureMessage';
            $callbackComments .= $this->newLine . sprintf($this->translator->trans((string)$transactionMsg, [], null, $this->locale), date('d/m/Y H:i:s'));
            $upsertData['gatewayStatus'] = $transactionStatus;
            $this->transactionHelper->postProcess($this->orderTransaction, $salesChannelContext, $callbackComments, $upsertData, $appendComments);
            
            if (in_array($this->eventData['transaction']['payment_type'], ['INVOICE', 'GUARANTEED_INVOICE', 'INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                $this->transactionHelper->prepareMailContent($this->order, $this->salesChannelContext, $callbackComments);
            }
            try {
                if ($transactionStatus == 'CONFIRMED') {
                    if (!empty($this->paymentSettings['NovalnetPayment.settings.completeStatus'])) {
                        $completeStatus = $this->paymentSettings['NovalnetPayment.settings.completeStatus'];
                        $this->transactionHelper->managePaymentStatus($completeStatus, $this->orderTransaction->getId(), $salesChannelContext);
                    } else {
                         $this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $salesChannelContext);
                    }
                } elseif ($transactionStatus == 'PENDING') {
                    $this->transactionHelper->managePaymentStatus('process', $this->orderTransaction->getId(), $salesChannelContext);
                } elseif ($this->eventType === 'TRANSACTION_CANCEL') {
                    $this->orderTransactionStateHandler->cancel($this->orderTransaction->getId(), $salesChannelContext);
                }
            } catch (IllegalTransitionException $exception) {
            }
        } else {
            $this->response ['message'] = 'Order already processed.';
        }
        
        return  $callbackComments;
    }

    
    
    /**
     * Handle payment INSTALMENT process
     *
     * @return string
     */
    private function instalmentProcess(): string
    {
        $instalmentData       = $this->eventData['instalment'];
        $comments = '';
        if (!empty($instalmentData['cycle_amount']) && !empty($instalmentData['cycles_executed']) && isset($instalmentData['pending_cycles'])) {
            if (in_array($this->eventData['transaction']['payment_type'], ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA']) && $this->orderReference->getGatewayStatus() == 'CONFIRMED') {
                $comments = $this->helper->formBankDetails($this->eventData, $this->salesChannelContext->getContext(), $this->order->getLanguageId());
            }
            
            $comments .= $this->newLine . $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentPrepaidMessage', [], null, $this->locale), $this->parentTid, $this->formattedAmount, $this->eventTid).$this->newLine;
            $upsertData['id']                = $this->orderReference->getId();
            $upsertData['additionalDetails'] = $this->updateInstalmentInfo();

            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $comments, $upsertData, false);
            $this->transactionHelper->prepareMailContent($this->order, $this->salesChannelContext, $comments, true);
            
            return $comments;
        } else {
            $comments = 'Required parameter in the category (instalment) not received';
            return $comments;
        }
    }
    
    /**
     * Form the instalment data into serialize
     *
     * @return string
     */
    private function updateInstalmentInfo(): string
    {
        $configurationDetails = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
        $instalmentData       = $this->eventData['instalment'];
        $configurationDetails['InstalmentDetails'][$instalmentData['cycles_executed']] = [
            'amount'        => $instalmentData['cycle_amount'],
            'cycleDate'     =>  !empty($instalmentData['next_cycle_date']) ? date('Y-m-d', strtotime($instalmentData['next_cycle_date'])) : '',
            'cycleExecuted' => $instalmentData['cycles_executed'],
            'dueCycles'     => $instalmentData['pending_cycles'],
            'paidDate'      => date('Y-m-d'),
            'status'        => $this->translator->trans('NovalnetPayment.text.paidMsg', [], null, $this->locale),
            'reference'     => (string) $this->eventData['transaction']['tid'],
            'refundAmount'  => 0,
        ];
        return $this->helper->serializeData($configurationDetails);
    }
    
    /**
     * Handle payment CHARGEBACK/RETURN_DEBIT/REVERSAL process
     *
     * @return string
     */
    private function chargebackProcess(): string
    {
        $callbackComments = '';
        if (! empty($this->eventData ['transaction'] ['amount'])) {
            if ($this->orderReference->getGatewayStatus()=='CONFIRMED') {
                $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.chargebackComments', [], null, $this->locale), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->eventTid);

                $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments);

                if ($this->eventData ['transaction'] ['amount'] >= $this->orderReference->getAmount()) {
                    try {
                        $this->stateMachineRegistry->transition(
                            new Transition(
                                OrderTransactionDefinition::ENTITY_NAME,
                                $this->orderTransaction->getId(),
                                StateMachineTransitionActions::ACTION_CHARGEBACK,
                                'stateId'
                            ),
                            $this->salesChannelContext->getContext()
                        );
                    } catch (IllegalTransitionException $exception) {
                    }
                }
            }
        } else {
            $callbackComments = 'Required parameter (amount) in the category (transaction) not received';
            return $callbackComments;
        }
        return $callbackComments;
    }
    
    /**
     * Handle payment reminder process
     *
     * @return string
     */
    private function paymentReminderProcess(): string
    {
        $callbackComments = '';

        if (in_array($this->orderReference->getGatewayStatus(), ['CONFIRMED', 'PENDING'])) {
            $reminderCount = preg_replace('/[^0-9]/', '', $this->eventType);
            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.paymentReminder', [], null, $this->locale), $reminderCount);

            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments);
        }
        return $callbackComments;
    }
    
     /**
     * Handle collection process
     *
     * @return string
     */
    private function collectionProcess(): string
    {
        $callbackComments = '';

        if (in_array($this->orderReference->getGatewayStatus(), ['CONFIRMED', 'PENDING'])) {
            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.collectionSubmission', [], null, $this->locale), $this->eventData['collection']['reference']);
            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments);
        }
        return $callbackComments;
    }
    
     /**
     * Handle transaction update
     *
     * @return string
     */
    
    private function transactionUpdate(): string
    {
        $transactionStatus = $this->eventData['transaction']['status'];
        $salesChannelContext = $this->salesChannelContext->getContext();
        $transactionAdditionDetails = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
        
        $upsertData = [
            'id' => $this->orderReference->getId(),
            'gatewayStatus' => $transactionStatus,
        ];

        $callbackComments = '';
        $appendComments = true;
        $paymentType  = $this->eventData['transaction']['payment_type'];
        
        if (in_array($transactionStatus, ['CONFIRMED', 'PENDING', 'ON_HOLD', 'DEACTIVATED' ])) {
            if (in_array($this->eventData['transaction']['update_type'], ['DUE_DATE', 'AMOUNT_DUE_DATE'])) {
                $this->eventData['transaction']['amount'] = isset($this->eventData['transaction']['amount']) ? $this->eventData['transaction']['amount'] : $this->orderReference->getAmount();
                $upsertData['amount'] = $this->eventData['transaction']['amount'];
                $dueDate = date('d/m/Y', strtotime($this->eventData['transaction']['due_date']));
                
                if ($paymentType === 'CASHPAYMENT') {
                    $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.cashDueDateComments', [], null, $this->locale), $this->formattedAmount, $dueDate);
                } else {
                    $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.dueDateComments', [], null, $this->locale), $this->formattedAmount, $dueDate);
                }
            } elseif ($this->eventData['transaction']['update_type'] == 'STATUS') {
                $this->eventData['transaction']['amount'] = isset($this->eventData['transaction']['amount']) ? $this->eventData['transaction']['amount'] : $this->orderReference->getAmount();
                if (in_array($paymentType, ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE', 'INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                    $appendComments = false;
                    if (!empty($this->orderReference->getAdditionalDetails())) {
                        if (!empty($transactionAdditionDetails['bankDetails'])) {
                            $bankDetails = $transactionAdditionDetails['bankDetails'];
                        } elseif (!empty($transactionAdditionDetails['account_holder'])) {
                            $bankDetails = $transactionAdditionDetails;
                        }
                        if (!empty($bankDetails) && ($transactionStatus != 'DEACTIVATED')) {
                            $this->eventData['transaction']['bank_details'] = $bankDetails;
                        }
                    }
                    $callbackComments = $this->helper->formBankDetails($this->eventData, $salesChannelContext, $this->order->getLanguageId());
                }
                
                if ($transactionStatus === 'DEACTIVATED') {
                    $callbackComments .= $this->newLine . $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage', [], null, $this->locale), date('d/m/Y H:i:s'));
                } elseif (in_array($this->orderReference->getGatewayStatus(), ['PENDING','ON_HOLD'])) {
                    $upsertData['amount'] = $this->eventData['transaction']['amount'];
                    
                    if ($transactionStatus === 'ON_HOLD') {
                        $callbackComments .= sprintf($this->newLine . $this->translator->trans('NovalnetPayment.text.updateOnholdComments', [], null, $this->locale), $this->eventTid, date('d/m/Y H:i:s'));
                    } elseif ($transactionStatus === 'CONFIRMED') {
                        $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.updateComments1', [], null, $this->locale), $this->eventTid, $this->formattedAmount, date('d/m/Y H:i:s'));

                        if (in_array($paymentType, ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                            $upsertData['additionalDetails'] = $transactionAdditionDetails;
                            $this->eventData['transaction']['amount'] = $this->orderReference->getAmount();
                            $upsertData['additionalDetails']['InstalmentDetails'] = $this->transactionHelper->getInstalmentInformation($this->eventData, $this->locale);
                            $upsertData['additionalDetails'] = $this->helper->serializeData($upsertData['additionalDetails']);
                        } elseif (in_array($paymentType, ['PAYPAL', 'PRZELEWY24', 'TRUSTLY'])) {
                            $callbackComments = sprintf($this->newLine. $this->translator->trans('NovalnetPayment.text.redirectUpdateComment', [], null, $this->locale), $this->eventTid, date('d/m/Y H:i:s'));
                        }
                        
                        $upsertData['paidAmount'] = $this->eventData['transaction']['amount'];
                    }
                }
            } else {
                if (!empty($this->eventData['transaction']['amount'])) {
                    $upsertData['amount'] = $this->eventData['transaction']['amount'];
                }
                $callbackComments .= sprintf($this->newLine . $this->translator->trans('NovalnetPayment.text.updateComments1', [], null, $this->locale), $this->eventTid, $this->formattedAmount, date('d/m/Y H:i:s'));
            }
        }

        $this->transactionHelper->postProcess($this->orderTransaction, $salesChannelContext, $callbackComments, $upsertData, $appendComments);
        
        $orderTransactionId = $this->orderTransaction->getId();
        
        try {
            if ($transactionStatus === 'DEACTIVATED') {
                $this->orderTransactionStateHandler->cancel($orderTransactionId, $salesChannelContext);
            } elseif ($transactionStatus === 'CONFIRMED'  && $this->eventData['transaction']['amount'] == 0 && !in_array($paymentType, ['PREPAYMENT'])) {
                $this->orderTransactionStateHandler->authorize($orderTransactionId, $salesChannelContext);
            } elseif ($transactionStatus=== 'CONFIRMED') {
                if (!empty($this->paymentSettings['NovalnetPayment.settings.completeStatus'])) {
                    $completeStatus = $this->paymentSettings['NovalnetPayment.settings.completeStatus'];
                    $this->transactionHelper->managePaymentStatus($completeStatus, $orderTransactionId, $salesChannelContext);
                } else {
                     $this->orderTransactionStateHandler->paid($orderTransactionId, $this->context);
                }
            } elseif (($transactionStatus === 'ON_HOLD')) {
                if (!empty($this->paymentSettings['NovalnetPayment.settings.onHoldStatus'])) {
                    $onHoldStatus = $this->paymentSettings['NovalnetPayment.settings.onHoldStatus'];
                    $this->transactionHelper->managePaymentStatus($onHoldStatus, $orderTransactionId, $salesChannelContext);
                } else {
                    $this->orderTransactionStateHandler->authorize($orderTransactionId, $salesChannelContext);
                }
            } elseif (($transactionStatus === 'PENDING')) {
                $this->transactionHelper->managePaymentStatus('process', $orderTransactionId, $salesChannelContext);
            }
        } catch (IllegalTransitionException $exception) {
        }

        if (in_array($paymentType, ['INVOICE', 'GUARANTEED_INVOICE', 'INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA']) && in_array($transactionStatus, ['CONFIRMED', 'PENDING', 'ON_HOLD'])) {
            $this->transactionHelper->prepareMailContent($this->order, $this->salesChannelContext, $callbackComments);
        }
        
        return $callbackComments;
    }
        
    /**
     * Transaction refund
     *
     * @return string
     */

    private function transactionrefund(): string
    {
        $callbackComments = '';
        
        if (!empty($this->eventData['transaction']['refund']['amount'])) {
            $refundAmount = $this->eventData['transaction']['refund']['amount'];
        } else {
            $refundAmount = (int) $this->orderReference->getAmount() - (int) $this->orderReference->getRefundedAmount();
        }
       
        if (!empty($refundAmount)) {
            $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit((int) $refundAmount, $this->eventData ['transaction'] ['currency'], $this->salesChannelContext->getContext());

            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.refundComment', [], null, $this->locale), $this->parentTid, $refundedAmountInBiggerUnit);
        
            if (!empty($this->eventData['transaction']['refund']['tid'])) {
                $callbackComments .= sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid', [], null, $this->locale), $this->eventData['transaction']['refund']['tid']);
            }
            $additionalDetails = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
            
            if (in_array($this->helper->getUpdatedPaymentType($this->orderReference->getpaymentType()), ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                $additionalDetails['InstalmentDetails'] = $this->transactionHelper->updateInstalmentCycle($additionalDetails['InstalmentDetails'], (int)$refundAmount, (string) $this->parentTid, $this->locale);
            }
            
            $totalRefundedAmount = (int) $this->orderReference->getRefundedAmount() + (int) $refundAmount;
            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, [
                'id'             => $this->orderReference->getId(),
                'refundedAmount' => $totalRefundedAmount,
                'gatewayStatus'  => $this->eventData['transaction']['status'],
                'additionalDetails'  => !empty($additionalDetails) ? $this->helper->serializeData($additionalDetails) : null,
                
            ]);
                    
            if ($totalRefundedAmount >= $this->orderReference->getAmount()) {
                try {
                    $this->orderTransactionStateHandler->refund($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
                } catch (IllegalTransitionException $exception) {
                    $this->orderTransactionStateHandler->cancel($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
                }
            }
        }
        return $callbackComments;
    }
    
    
    /**
     *  instalment cancel
     *
     * @return string
     */
    
    private function instalmentCancelProcess(): string
    {
        $callbackComments = '';
        
        $additionalDetails = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
        if (!empty($this->eventData['instalment']['cancel_type'])) {
            if (isset($this->eventData['transaction']['refund'])) {
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit((int) $this->eventData['transaction']['refund']['amount'], $this->orderReference->getCurrency(), $this->salesChannelContext->getContext());
                $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentRefundComment', [], null, $this->locale), $this->parentTid, date('Y-m-d H:i:s'), $refundedAmountInBiggerUnit);
                $totalRefundedAmount = $this->orderReference->getAmount();
            } else {
                $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentRemainRefundComment', [], null, $this->locale), $this->parentTid, date('Y-m-d H:i:s'));
                $totalRefundedAmount = 0;
                foreach ($additionalDetails['InstalmentDetails'] as $instalment) {
                    $totalRefundedAmount += empty($instalment['reference']) ? $instalment['amount'] : 0;
                }
            }
            
            $additionalDetails['InstalmentDetails'] = $this->transactionHelper->updateInstalmentCancel($additionalDetails['InstalmentDetails'], $this->eventData['instalment']['cancel_type'], $this->locale);
            $additionalDetails['cancelType'] = $this->eventData['instalment']['cancel_type'];

            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, [
                'id'             => $this->orderReference->getId(),
                'refundedAmount' => $totalRefundedAmount,
                'gatewayStatus'  => $this->eventData['transaction']['status'],
                'additionalDetails' => $this->helper->serializeData($additionalDetails),
            ]);

            if ($totalRefundedAmount >= $this->orderReference->getAmount()) {
                try {
                    $this->orderTransactionStateHandler->cancel($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
                } catch (IllegalTransitionException $exception) {
                }
            }

            return $callbackComments;
        } else {
            $callbackComments = 'Required parameter (cancel type) in the category (instalment) not received';
            return $callbackComments;
        }
    }
    
    /**
     * Send notify email after callback process.
     *
     * @return void
     */
    public function sendNotificationEmail(): void
    {
        if (!empty($this->paymentSettings['NovalnetPayment.settings.mailTo']) && ! empty($this->response['message'])) {
            $toEmail = explode(',', $this->paymentSettings['NovalnetPayment.settings.mailTo']);
            $data = new DataBag();
            $mailSubject = 'Novalnet Callback Script Access Report - ';
            if (! empty($this->eventData ['transaction']['order_no'])) {
                $mailSubject .= 'Order No : ' . $this->eventData ['transaction']['order_no'];
            }
            $mailSubject .= ' in the ' . (!empty($this->salesChannelContext->getSalesChannel()->getTranslated()) ? $this->salesChannelContext->getSalesChannel()->getTranslated()['name'] : $this->salesChannelContext->getSalesChannel()->getName());

            $senderEmail = [];
            foreach ($toEmail as $email) {
                if ($this->helper->isValidEmail($email)) {
                    $senderEmail = array_merge($senderEmail, [$email => $email]);
                }
            }
            $data->set(
                'recipients',
                $senderEmail
            );
            $data->set('senderName', 'Novalnet');
            $data->set('salesChannelId', null);
            $data->set('contentHtml', str_replace('/ ', '<br />', $this->response['message']));
            $data->set('contentPlain', $this->response['message']);
            $data->set('subject', $mailSubject);
           
            try {
                $this->mailService->send(
                    $data->all(),
                    $this->salesChannelContext->getContext(),
                    []
                );
            } catch (\Exception $e) {
                throw($e);
            }
        }
    }
    
    
}
