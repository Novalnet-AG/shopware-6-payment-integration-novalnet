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
 * @license     https://www.novalnet.com/payment-plugins/free/license
 */
declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Controller;

use Doctrine\DBAL\Connection;
use Novalnet\NovalnetPayment\Components\NovalnetPaymentTokenRepository;
use Novalnet\NovalnetPayment\Content\PaymentTransaction\NovalnetPaymentTransactionEntity;
use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetValidator;
use Shopware\Core\Content\MailTemplate\Service\MailServiceInterface;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionDefinition;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Routing\Annotation\RouteScope;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\StateMachine\Aggregation\StateMachineTransition\StateMachineTransitionActions;
use Shopware\Core\System\StateMachine\StateMachineRegistry;
use Shopware\Core\System\StateMachine\Transition;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\SessionInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

/**
 * @RouteScope(scopes={"storefront"})
 */
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
     * @var string
     */
    private $message;

    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var NovalnetValidator
     */
    private $validator;

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
     * @var SessionInterface
     */
    private $sessionInterface;
    
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
     * @var NovalnetPaymentTransactionEntity
     */
    private $orderReference;
    
    /**
     * @var int
     */
    private $eventTid;
    
    /**
     * @var MailServiceInterface
     */
    private $mailService;
    /**
     * @var array
     */
    private $response;
    
    /**
     * @var array
     */
    private $paymentSettings;
    
    /**
     * @var NovalnetPaymentTokenRepository
     */
    private $paymentTokenRepository;
    
    /**
     * @var Connection
     */
    private $connection;
    
    /**
     * @var EntityRepositoryInterface
     */
    private $orderTransactionRepository;

    /**
     * @var OrderEntity
     */
    private $order;
    
    /**
     * @var array
     */
    private $mandatory = [
        'event' => [
            'type',
            'checksum',
            'tid'
        ],
        'result' => [
            'status'
        ],
    ];

    /**
     * {@inheritdoc}
     */
    public function __construct(
        Connection $connection,
        ContainerInterface $container,
        NovalnetHelper $helper,
        NovalnetOrderTransactionHelper $transactionHelper,
        NovalnetPaymentTokenRepository $paymentTokenRepository,
        NovalnetValidator $validator,
        TranslatorInterface $translator,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        SessionInterface $sessionInterface,
        StateMachineRegistry $stateMachineRegistry,
        EntityRepositoryInterface $orderTransactionRepository,
        MailServiceInterface $mailService
    ) {
        $this->connection                    = $connection;
        $this->container                     = $container;
        $this->helper                        = $helper;
        $this->transactionHelper             = $transactionHelper;
        $this->paymentTokenRepository        = $paymentTokenRepository;
        $this->validator                     = $validator;
        $this->translator                    = $translator;
        $this->orderTransactionStateHandler  = $orderTransactionStateHandler;
        $this->sessionInterface              = $sessionInterface;
        $this->stateMachineRegistry          = $stateMachineRegistry;
        $this->orderTransactionRepository    = $orderTransactionRepository;
        $this->mailService                   = $mailService;
    }

    /**
     * @Route("/novalnet/callback", name="api.action.novalnetpayment.status-action", defaults={"csrf_protected"=false}, methods={"GET","POST"})
     */
    public function statusAction(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->salesChannelContext = $salesChannelContext;
        
        $this->eventData = $this->helper->unserializeData((string) $request->getContent());
        if (!$this->eventData) {
            $this->response = ['message' => "Received data is not in the JSON format"];
            return $this->debugMessage();
        }
        
        $this->paymentSettings = $this->helper->getNovalnetPaymentSettings($this->salesChannelContext->getSalesChannel()->getId());
        
        if (!$this->authenticateEventData() || !$this->validateEventData() || !$this->validateChecksum()) {
            return $this->debugMessage();
        }
        
        // Set Event data.
        $this->eventType = $this->eventData ['event'] ['type'];
        $this->eventTid  = $this->eventData ['event'] ['tid'];
        $this->parentTid = $this->eventTid;
        
        if (! empty($this->eventData ['event'] ['parent_tid'])) {
            $this->parentTid = $this->eventData ['event'] ['parent_tid'];
        }
        
        // Get order reference.
        if (!$this->getOrderReference()) {
            return $this->debugMessage();
        }
        
        if (! empty($this->eventData ['transaction'] ['amount'])) {
            $this->formattedAmount = $this->helper->amountInBiggerCurrencyUnit((int) $this->eventData ['transaction'] ['amount'], $this->eventData ['transaction'] ['currency'], $this->salesChannelContext->getContext());
        }
        
        $this->response ['message'] = 'Notification received from Novalnet for this order. ';
        
        if ($this->validator->isSuccessStatus($this->eventData)) {
            switch ($this->eventType) {
                case "PAYMENT":
                
                    $this->response ['message'] .= 'The Payment has been received';
                    break;
                    
                case "TRANSACTION_CAPTURE":
                case "TRANSACTION_CANCEL":
                    $callbackComments = $this->transactionCaptureVoid();
                    break;
                
                case "TRANSACTION_REFUND":
                    $callbackComments = $this->transactionRefund();
                    break;
                    
                case "TRANSACTION_UPDATE":
                    $callbackComments = $this->transactionUpdate();
                    break;
                    
                case "CREDIT":
                    $callbackComments = $this->creditProcess();
                    break;
                
                case "CHARGEBACK":
                    $callbackComments = $this->chargebackProcess();
                    break;
                    
                default:
                    $this->response ['message'] .= "The webhook notification has been received for the unhandled EVENT type($this->eventType)";
            }
        }
        if (!empty($callbackComments)) {
            $this->response['message'] .= $callbackComments;
            $this->sendNotificationEmail();
        }
        return $this->debugMessage();
    }
    
    /**
     * Validate eventData
     *
     * @return bool
     */
    public function validateEventData(): bool
    {
        if (! empty($this->eventData ['custom'] ['shop_invoked'])) {
            $this->response = [ 'message' => 'Process already handled in the shop.' ];
            return false;
        }

        // Validate request parameters.
        foreach ($this->mandatory as $category => $parameters) {
            if (empty($this->eventData [ $category ])) {

                // Could be a possible manipulation in the notification data.
                $this->response = [ 'message' => "Required parameter category($category) not received" ];
                return false;
            } elseif (! empty($parameters)) {
                foreach ($parameters as $parameter) {
                    if (empty($this->eventData [ $category ] [ $parameter ])) {

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
    public function validateChecksum(): bool
    {
        $tokenString = $this->eventData ['event'] ['tid'] . $this->eventData ['event'] ['type'] . $this->eventData ['result'] ['status'];

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
     * Authenticate Event data
     *
     * @return bool
     */
    public function authenticateEventData(): bool
    {
        
        // Host based validation.
        if (! empty($this->novalnetHostName)) {
            $novalnetHostIp = gethostbyname($this->novalnetHostName);

            // Authenticating the server request based on IP.
            $requestReceivedIp = $this->helper->getIp();
            
            if (! empty($novalnetHostIp) && ! empty($requestReceivedIp)) {
                if ($novalnetHostIp !== $requestReceivedIp && empty($this->paymentSettings ['NovalnetPayment.settings.deactivateIp'])) {
                    $this->response = ['message' => "Unauthorised access from the IP $requestReceivedIp"];
                    return false;
                }
            } else {
                $this->response = [ 'message' => 'Unauthorised access from the IP. Host/recieved IP is empty' ];
                return false;
            }
        } else {
            $this->response = [ 'message' => 'Unauthorised access from the IP. Novalnet Host name is empty' ];
            return false;
        }
        return true;
    }

    /**
     * Display/print the message.
     *
     * @return Response
     */
    public function debugMessage(): Response
    {
        return new Response($this->helper->serializeData($this->response));
    }

    /**
     * Get order reference from the novalnet_transaction_detail table on shop database.
     *
     * @return bool
     */
    private function getOrderReference(): bool
    {
        $orderNumber   = '';
        $paymentMethod = '';
        if (! empty($this->eventData ['transaction'] ['order_no']) || ! empty($this->parentTid)) {
            if (! empty($this->eventData ['transaction'] ['order_no'])) {
                $orderNumber = $this->eventData ['transaction'] ['order_no'];
            }
            
            $this->orderTransaction = $this->transactionHelper->getOrder($orderNumber, $this->salesChannelContext->getContext());
            
            $this->order = $this->transactionHelper->getOrderEntity($orderNumber, $this->salesChannelContext->getContext());
            
            if (!is_null($this->orderTransaction)) {
                $paymentMethod = $this->transactionHelper->getPaymentMethodById($this->orderTransaction->getPaymentMethodId(), $this->salesChannelContext->getContext());
                if (!is_null($paymentMethod)) {
                    $this->paymentMethodName = $this->helper->getPaymentMethodName($paymentMethod);
                }
            }
            
            if (!$this->validator->checkString($this->paymentMethodName)) {
                $this->response = ['message' => 'Order Reference not exist in Database!'];
                return false;
            }
            
            $this->orderReference = $this->transactionHelper->fetchNovalnetTransactionData($orderNumber, $this->salesChannelContext->getContext(), $this->parentTid);
        }
        
        if(is_null($this->orderReference)) {
            if ($this->eventData ['transaction'] ['payment_type'] === 'ONLINE_TRANSFER_CREDIT') {
                if (! empty($this->parentTid)) {
                    $this->eventData ['transaction'] ['tid'] = $this->parentTid;
                    $this->updateInitialPayment($paymentMethod, false);
                    $this->orderReference = $this->transactionHelper->fetchNovalnetTransactionData($orderNumber, $this->salesChannelContext->getContext());
                }
            } else {
                $this->updateInitialPayment($paymentMethod, true);
            }
        }
        return true;
    }
    
    /**
     * Handle communication failure
     *
     * @param PaymentMethodEntity $paymentMethod
     * @param bool $communicationFailure
     */
    public function updateInitialPayment(PaymentMethodEntity $paymentMethod, $communicationFailure): void
    {
        $handlerIdentifier = $paymentMethod->getHandlerIdentifier();
        $this->sessionInterface->set('currentNovalnetPaymentmethod', $this->paymentMethodName);
        $paymentMethodInstance = new $handlerIdentifier(
            $this->connection,
            $this->container,
            $this->helper,
            $this->transactionHelper,
            $this->paymentTokenRepository,
            $this->validator,
            $this->orderTransactionStateHandler,
            $this->sessionInterface,
            $this->stateMachineRegistry,
            $this->orderTransactionRepository
        );

        if (method_exists($paymentMethodInstance, 'checkTransactionStatus')) {
            $paymentMethodInstance->checkTransactionStatus($this->orderTransaction, $this->eventData, $this->salesChannelContext);
            $this->response = [ 'message' => 'Communication failure has been handled successfully. The transaction details has been updated' ];
        } else {
            $this->response = [ 'message' => 'Payment not found in the order' ];
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
            $mailSubject .= 'in the ' . $this->salesChannelContext->getSalesChannel()->getName();
            
            foreach ($toEmail as $email) {
                if ($this->validator->isValidEmail($email, $this->paymentSettings)) {
                    $data->set(
                        'recipients',
                        [
                            $email => $email,
                        ]
                    );
                }
            }
            
            $data->set('senderName', 'Novalnet');
            $data->set('salesChannelId', null);
            $data->set('contentHtml', $this->response['message']);
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
    
    /**
     * Handle CAPTURE/VOID transaction
     *
     * @return string
     */
    private function transactionCaptureVoid(): string
    {
        $callbackComments = '';
        $appendComments = true;
        if (in_array($this->orderReference->getGatewayStatus(), ['ON_HOLD'])) {
            $upsertData = [
                'id'            => $this->orderReference->getId(),
                'gatewayStatus' => $this->eventData['transaction']['status']
            ];
            if ($this->eventType === 'TRANSACTION_CAPTURE') {
                $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.confirmMessage'), date('d-m-Y H:i:s'));
                if (in_array($this->paymentMethodName, ['novalnetinvoice', 'novalnetinvoiceGuarantee']) && $this->eventData['transaction']['due_date']) {
                    $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.amountTransferWithDueDate'), $this->formattedAmount, date('d/m/Y', strtotime($this->eventData['transaction']['due_date'])));
                }
                if (in_array($this->eventData['transaction']['status'], ['CONFIRMED', 'PENDING'])) {
					if(!empty($this->orderReference->getAdditionalDetails() && !empty($this->orderReference->getPaymentType()) && in_array($this->orderReference->getPaymentType(), ['novalnetinvoice', 'novalnetinvoiceguarantee', 'novalnetprepayment']))) {
						$appendComments = false;
                        $this->eventData['transaction']['bank_details'] = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
                        $callbackComments .= $this->newLine . $this->newLine . $this->helper->formBankDetails($this->eventData);
                    }
					
                    if ($this->eventData['transaction']['status'] == 'CONFIRMED') {
						$upsertData['paidAmount'] = $this->orderReference->getAmount();
                        try {
							$this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
						} catch (IllegalTransitionException $exception) {
						
						}
                    } elseif ($this->eventData['transaction']['status'] == 'PENDING') {
                        $this->orderTransactionStateHandler->process($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
                        if (in_array($this->paymentMethodName, ['novalnetinvoice', 'novalnetinvoiceGuarantee'])) {
							$this->transactionHelper->prepareMailContent($this->order, $this->salesChannelContext, $callbackComments);
						}
                    }
                    
                    if ($this->eventData['transaction']['status'] == 'CONFIRMED') {
						if (in_array($this->paymentMethodName, ['novalnetinvoice', 'novalnetinvoiceGuarantee'])) {
							$this->transactionHelper->prepareMailContent($this->order, $this->salesChannelContext, $callbackComments);
						}
						$upsertData['paidAmount'] = $this->orderReference->getAmount();
					}
                }
            } else {
                $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage'), date('d/m/Y H:i:s'));
                $this->orderTransactionStateHandler->cancel($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
            }
            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, $upsertData, $appendComments);
        } else {
            
            // transaction already captured or transaction not been authorized.
            $this->response ['message'] = 'Order already processed.';
        }
        return $callbackComments;
    }
    
    /**
     * Check transaction cancellation
     *
     * @return string
     */
    private function transactionRefund(): string
    {
        $callbackComments = '';
        if (! empty($this->eventData['transaction']['refund']['amount'])) {
            $refundAmount = $this->eventData['transaction']['refund']['amount'];
        } else {
            $refundAmount = (int) $this->orderReference->getAmount() - (int) $this->orderReference->getRefundedAmount();
        }
        
        if (! empty($refundAmount)) {
            $formattedAmountRefund = $this->helper->amountInBiggerCurrencyUnit((int) $refundAmount, $this->eventData ['transaction'] ['currency'], $this->salesChannelContext->getContext());
            
            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.refundComment'), $this->parentTid, $formattedAmountRefund);
            
            if (!empty($this->eventData['transaction']['refund']['tid'])) {
                $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid'), $this->eventData['transaction']['refund']['tid']);
            }
            
            $totalRefundedAmount = (int) $this->orderReference->getRefundedAmount() + (int) $refundAmount;
            
            if ($totalRefundedAmount >= $this->orderReference->getAmount()) {
                try {
					$this->orderTransactionStateHandler->refund($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
				} catch (IllegalTransitionException $exception) {
					
					// we can not ensure that the refund or refund partially status change is allowed
					$this->orderTransactionStateHandler->cancel($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
				}
            }
            
            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, [
                'id'             => $this->orderReference->getId(),
                'refundedAmount' => $totalRefundedAmount,
                'gatewayStatus'  => $this->eventData['transaction']['status'],
            ]);
        }
        return $callbackComments;
    }
    
    /**
     * Handle payment CHARGEBACK/RETURN_DEBIT/REVERSAL process
     *
     * @return string
     */
    private function chargebackProcess(): string
    {
        $callbackComments = '';
        
        if (in_array($this->orderReference->getGatewayStatus(), ['CONFIRMED']) && ! empty($this->eventData ['transaction'] ['amount'])) {
            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.chargebackComments'), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->eventTid);
            
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
        $upsertData = [
            'id' => $this->orderReference->getId(),
            'gatewayStatus' => $this->eventData['transaction']['status'],
        ];
        
        $callbackComments = '';
        if (in_array($this->eventData['transaction']['status'], [ 'PENDING', 'ON_HOLD', 'CONFIRMED', 'DEACTIVATED' ])) {
			
            if ($this->eventData['transaction']['status'] === 'DEACTIVATED') {
                $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage'), date('d/m/Y H:i:s'));
                $this->orderTransactionStateHandler->cancel($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
            } elseif ($this->orderReference->getGatewayStatus() === 'PENDING') {
				$callbackComments .= sprintf($this->newLine. $this->translator->trans('NovalnetPayment.text.amountUpdateComment'), $this->formattedAmount, date('d/m/Y H:i:s'));
                
                if ($this->eventData['transaction']['status'] === 'ON_HOLD') {
                    // Payment not yet completed, set transaction status to "AUTHORIZE"
                    $this->orderTransactionStateHandler->reopen($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
                    
                    $callbackComments .= sprintf($this->newLine . $this->translator->trans('NovalnetPayment.text.updateOnholdComments'), $this->eventTid, date('d/m/Y H:i:s'));
                } elseif ($this->eventData['transaction']['status'] === 'CONFIRMED') {
                    $upsertData['paidAmount'] = $this->eventData['transaction']['amount'];
                    
                    if ($this->eventData['transaction']['payment_type'] === 'GUARANTEED_INVOICE' && !empty($this->orderReference->getAdditionalDetails())) {
						$appendComments = false;
						$this->eventData['transaction']['bank_details'] = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
						$callbackComments .= $this->helper->formBankDetails($this->eventData);
					}
					try {
						$this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
					} catch (IllegalTransitionException $exception) {
					
					}
                    
                }
            } elseif ($this->orderReference->getGatewayStatus() === 'ON_HOLD' && $this->eventData['transaction']['status'] === 'ON_HOLD' &&
            in_array($this->eventData['transaction']['payment_type'], ['DIRECT_DEBIT_SEPA', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'GUARANTEED_INVOICE', 'PREPAYMENT'])) {
                $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.updateComments1'), $this->eventTid, $this->formattedAmount, date('d/m/Y H:i:s'));
            }
            
            
            if (!empty($this->eventData['transaction']['due_date'])) {
                $dueDate = date('d/m/Y', strtotime($this->eventData['transaction']['due_date']));
                
                if ($this->eventData['transaction']['payment_type'] === 'CASHPAYMENT') {
                    $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.cashDueDateComments'), $this->formattedAmount, $dueDate);
                }
            }
            
            if (in_array($this->paymentMethodName, ['novalnetinvoice', 'novalnetinvoiceGuarantee']) && in_array($this->eventData['transaction']['status'], ['CONFIRMED', 'PENDING'])) {
				$this->transactionHelper->prepareMailContent($this->order, $this->salesChannelContext, $callbackComments);
			}
        }
        $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, $upsertData);
        return $callbackComments;
    }
    
    /**
     * Handle payformattedAmountment credit process
     *
     * return string
     */
    private function creditProcess(): string
    {
        $upsertData       = [];
        $callbackComments = '';
        if ($this->eventData['transaction']['payment_type'] === 'ONLINE_TRANSFER_CREDIT') {
            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.creditMessage'), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->parentTid);
        } else {
            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.creditMessage'), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->eventTid);
            if (in_array($this->eventData['transaction']['payment_type'], [ 'INVOICE_CREDIT', 'CASHPAYMENT_CREDIT', 'MULTIBANCO_CREDIT' ])) {
                if ((int) $this->orderReference->getPaidAmount() < (int) $this->orderReference->getAmount()) {
                    
                    // Calculate total amount.
                    $paidAmount = (int) $this->orderReference->getPaidAmount() + (int) $this->eventData['transaction']['amount'];
                    
                    // Calculate including refunded amount.
                    $amountToBePaid = (int) $this->orderReference->getAmount() - (int) $this->orderReference->getRefundedAmount();
                    
                    $upsertData['id']            = $this->orderReference->getId();
                    $upsertData['gatewayStatus'] = $this->eventData['transaction']['status'];
                    $upsertData['paidAmount']    = $paidAmount;
                    
                    if (((int) $paidAmount >= (int) $amountToBePaid)) {
                        try {
							$this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
						} catch (IllegalTransitionException $exception) {
						
						}
                    }
                }
            }
        }
        $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, $upsertData);
        return $callbackComments;
    }
}
