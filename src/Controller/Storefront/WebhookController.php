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
use Novalnet\NovalnetPayment\Helper\NovalnetValidator;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Contracts\Translation\TranslatorInterface;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;

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
     * @var OrderTransactionEntity|null
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
     * @var NovalnetPaymentTransactionEntity|null
     */
    private $orderReference;

    /**
     * @var int
     */
    private $eventTid;

    /**
     * @var AbstractMailService
     */
    private $mailService;

    /**
     * @var array
     */
    private $response;

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
        NovalnetHelper $helper,
        NovalnetOrderTransactionHelper $transactionHelper,
        NovalnetValidator $validator,
        TranslatorInterface $translator,
        OrderTransactionStateHandler $orderTransactionStateHandler,
        EntityRepository $orderTransactionRepository,
        AbstractMailService $mailService
    ) {
        $this->helper                        = $helper;
        $this->transactionHelper             = $transactionHelper;
        $this->validator                     = $validator;
        $this->translator                    = $translator;
        $this->orderTransactionStateHandler  = $orderTransactionStateHandler;
        $this->orderTransactionRepository    = $orderTransactionRepository;
        $this->mailService                   = $mailService;
    }

    #[Route(path: '/novalnet/callback', name: 'api.action.novalnetpayment.status-action', methods: ['GET', 'POST'], defaults: ['csrf_protected' => false])]
    public function statusAction(Request $request, SalesChannelContext $salesChannelContext): Response
    {
        $this->salesChannelContext  = $salesChannelContext;
        $this->eventData = $this->helper->unserializeData((string) $request->getContent());
        if (!$this->eventData) {
            $this->response = ['message' => "Received data is not in the JSON format"];
            return $this->debugMessage();
        }

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

        if (! empty($this->eventData ['instalment']['cycle_amount'])) {
            $this->formattedAmount = $this->helper->amountInBiggerCurrencyUnit((int) $this->eventData ['instalment']['cycle_amount'], $this->eventData ['transaction'] ['currency'], $this->salesChannelContext->getContext());
        } elseif (!empty($this->eventData ['transaction'] ['amount'])) {
            $this->formattedAmount = $this->helper->amountInBiggerCurrencyUnit((int) $this->eventData ['transaction'] ['amount'], $this->eventData ['transaction'] ['currency'], $this->salesChannelContext->getContext());
        }

        // Get order reference.
        if (!$this->getOrderReference()) {
            return $this->debugMessage();
        }


        $this->response ['message'] = '';

        if ($this->validator->isSuccessStatus($this->eventData)) {
            switch ($this->eventType) {
                case "PAYMENT":
                    $this->response ['message'] .= 'Novalnet Callback executed. The Transaction ID already existed';
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

                case "INSTALMENT":
                    $callbackComments = $this->instalmentProcess();
                    break;

                case "INSTALMENT_CANCEL":
                    $callbackComments = $this->instalmentCancelProcess();
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

        $paymentAccessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $this->salesChannelContext->getSalesChannel()->getId());
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
	
            // Authenticating the server request based on IP.
            $requestReceivedIp = $this->helper->checkWebhookIp(gethostbyname($this->novalnetHostName));
			$deactivateIp = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.deactivateIp', $this->salesChannelContext->getSalesChannel()->getId());
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

            if (!$this->order) {
                $this->response = ['message' => 'Order Reference not exist in Database!'];
                return false;
            }

            $this->locale = $this->helper->getLocaleCodeFromContext($this->salesChannelContext->getContext(), true, $this->order->getLanguageId());

            if (!is_null($this->orderTransaction->getPaymentMethod())) {
                $paymentMethod = $this->orderTransaction->getPaymentMethod();
                $this->paymentMethodName = $this->helper->getPaymentMethodName($paymentMethod);
            }

            if (!$this->validator->checkString($this->paymentMethodName)) {
                $this->response = ['message' => 'Order Reference not exist in Database!'];
                return false;
            }

            if (isset($this->eventData ['custom']['change_payment']) && !empty($this->eventData ['custom']['change_payment'])) {
                if ($this->validator->isSuccessStatus($this->eventData)) {
                    $orderData = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderNumber, $this->salesChannelContext->getContext(), (string) $this->eventData ['transaction']['tid'], true);
                    if (empty($orderData)) {
                        $this->updateInitialPayment($paymentMethod);
                        $this->response = [ 'message' => 'Communication failure has been handled successfully. The transaction details has been updated' ];
                    } else {
                        $this->response = [ 'message' => 'Novalnet Callback executed. The Transaction ID already existed' ];
                    }
                } else {
                    $this->response = [ 'message' => 'The payment failure has  been handled in the shop.' ];
                }
                return false;
            }


            $this->orderReference = $this->transactionHelper->fetchNovalnetTransactionData((string) $orderNumber, $this->salesChannelContext->getContext(), (string) $this->parentTid);
        }

        if (empty($this->orderReference)) {
            if ($this->eventData ['transaction'] ['payment_type'] === 'ONLINE_TRANSFER_CREDIT' && !empty($this->parentTid)) {
				$this->eventData ['transaction'] ['tid'] = $this->parentTid;
				$this->updateInitialPayment($paymentMethod);
				return false;
			} else {
				$this->updateInitialPayment($paymentMethod);
				return false;
			}
        }

        if ($this->eventType == "PAYMENT" && $this->validator->isSuccessStatus($this->eventData) && !empty($this->eventData['transaction']['amount']) && $this->orderReference->getAmount() == 0 && $this->eventData['transaction']['tid'] != $this->orderReference->getTid()) {
            $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.bookedComment', [], null, $this->locale), $this->formattedAmount, $this->eventData['transaction'] ['tid']);

            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $message, [
                'id'      => $this->orderReference->getId(),
                'tid'     => $this->eventData['transaction']['tid'],
                'amount'  => $this->eventData['transaction']['amount'],
                'paidAmount'  => $this->eventData['transaction']['amount'],
                'gatewayStatus'  => $this->eventData['transaction']['status'],
            ]);

            try {
                $this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
            } catch (IllegalTransitionException $exception) {
            }

            $this->response = ['message' => $message];
            return false;
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

        $paymentMethodInstance = new $handlerIdentifier(
            $this->helper,
            $this->transactionHelper,
            $this->validator,
            $this->orderTransactionStateHandler,
            $this->orderTransactionRepository
        );

        if ($paymentMethodInstance instanceof SynchronousPaymentHandlerInterface) {
            $paymentTransaction = new SyncPaymentTransactionStruct($this->orderTransaction, $this->order);
        } else {
            $paymentTransaction = new AsyncPaymentTransactionStruct($this->orderTransaction, $this->order, $this->generateUrl('frontend.checkout.cart.page'));
        }

        if (method_exists($paymentMethodInstance, 'checkTransactionStatus')) {
            $this->helper->updateSubscriptionStatus($this->eventData['transaction']['status'], $this->orderTransaction->getPaymentMethodId(), $this->order->getId());
            $paymentMethodInstance->checkTransactionStatus($this->orderTransaction, $this->eventData, $this->salesChannelContext, $paymentTransaction, '1');
            if (!empty($this->orderTransaction) && !empty($this->orderTransaction->getCustomFields()['novalnet_comments'])) {
                $this->response = [ 'message' => 'The transaction details has been updated successfully' ];
            } else {
                $this->response = [ 'message' => 'Communication failure has been handled successfully. The transaction details has been updated' ];
            }
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
        $mailTo = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.mailTo', $this->salesChannelContext->getSalesChannel()->getId());
        if (!empty($mailTo) && ! empty($this->response['message'])) {
            $toEmail = explode(',', $mailTo);
            $data = new DataBag();
            $mailSubject = 'Novalnet Callback Script Access Report - ';
            if (! empty($this->eventData ['transaction']['order_no'])) {
                $mailSubject .= 'Order No : ' . $this->eventData ['transaction']['order_no'];
            }
            $mailSubject .= ' in the ' . (!empty($this->salesChannelContext->getSalesChannel()->getTranslated()) ? $this->salesChannelContext->getSalesChannel()->getTranslated()['name'] : $this->salesChannelContext->getSalesChannel()->getName());

            $senderEmail = [];
            foreach ($toEmail as $email) {
                if ($this->validator->isValidEmail($email)) {
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

    /**
     * Handle CAPTURE/VOID transaction
     *
     * @return string
     */
    private function transactionCaptureVoid(): string
    {
        $callbackComments = '';
        $appendComments = true;
        if (in_array($this->orderReference->getGatewayStatus(), ['ON_HOLD', 'PENDING']) || in_array($this->orderReference->getGatewayStatus(), ['98', '99', '91', '85', '90', '86', '75'])) {
            $upsertData = [
                'id'            => $this->orderReference->getId(),
                'gatewayStatus' => $this->eventData['transaction']['status']
            ];
            if ($this->eventType === 'TRANSACTION_CAPTURE') {
                if ($this->paymentMethodName === 'novalnetinvoice') {
                    $this->eventData['transaction']['status'] = 'PENDING';
                }

                if (in_array($this->eventData['transaction']['status'], ['CONFIRMED', 'PENDING'])) {
                    if (!empty($this->orderReference->getAdditionalDetails() && !empty($this->orderReference->getPaymentType()) && in_array($this->orderReference->getPaymentType(), ['novalnetinvoice', 'novalnetinvoiceguarantee','novalnetinvoiceinstalment', 'novalnetprepayment']))) {
                        $appendComments = false;
                        $this->eventData['transaction']['bank_details'] = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
                        $callbackComments .= $this->helper->formBankDetails($this->eventData, $this->salesChannelContext->getContext(), $this->order->getLanguageId()) . $this->newLine;
                    }

                    if ($this->eventData['transaction']['status'] == 'CONFIRMED') {
                        $upsertData['paidAmount'] = $this->orderReference->getAmount();
                        if (in_array($this->eventData['transaction']['payment_type'], ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                            $upsertData['additionalDetails'] = $this->transactionHelper->getInstalmentInformation($this->eventData, $this->locale);
                            $upsertData['additionalDetails'] = $this->helper->serializeData($upsertData['additionalDetails']);
                        }
                    }

                    if (in_array($this->paymentMethodName, ['novalnetinvoice', 'novalnetinvoiceguarantee', 'novalnetinvoiceinstalment'])) {
                        $this->transactionHelper->prepareMailContent($this->order, $this->salesChannelContext, $callbackComments);
                    }
                }
                $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.confirmMessage', [], null, $this->locale), date('d/m/Y H:i:s'));
            } else {
                $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage', [], null, $this->locale), date('d/m/Y H:i:s'));
            }

            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, $upsertData, $appendComments);

            try {
                if ($this->eventData['transaction']['status'] == 'CONFIRMED') {
                    $this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
                } elseif ($this->eventData['transaction']['status'] == 'PENDING') {
                    $this->helper->setStatus($this->orderTransaction->getId(), 'in_progress', $this->salesChannelContext->getContext());
                } elseif ($this->eventType === 'TRANSACTION_CANCEL') {
                    $this->orderTransactionStateHandler->cancel($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
                }
            } catch (IllegalTransitionException $exception) {
            }
        } else {
            // transaction already captured or transaction not been authorized.
            $callbackComments = 'Order already captured.';
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

        if (!empty($refundAmount)) {
            
            $totalRefundedAmount = (int) $this->orderReference->getRefundedAmount() + (int) $refundAmount;
            
            if ($totalRefundedAmount <= $this->orderReference->getAmount())
            {
				$formattedAmountRefund = $this->helper->amountInBiggerCurrencyUnit((int) $refundAmount, $this->eventData ['transaction'] ['currency'], $this->salesChannelContext->getContext());
				$additionalDetails = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
				$callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.refundComment', [], null, $this->locale), $this->parentTid, $formattedAmountRefund);

				if (!empty($this->eventData['transaction']['refund']['tid'])) {
					$callbackComments .= sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid', [], null, $this->locale), $this->eventData['transaction']['refund']['tid']);
				}

				if (in_array($this->orderReference->getPaymentType(), ['novalnetinvoiceinstalment', 'novalnetsepainstalment'])) {
					$additionalDetails['InstalmentDetails'] = $this->transactionHelper->updateInstalmentCycle($additionalDetails['InstalmentDetails'], $refundAmount, (string) $this->parentTid, $this->locale);
				}
				
				$this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, [
					'id'             => $this->orderReference->getId(),
					'refundedAmount' => $totalRefundedAmount,
					'gatewayStatus'  => $this->eventData['transaction']['status'],
					'additionalDetails'  => $this->helper->serializeData($additionalDetails),
				]);

				if ($totalRefundedAmount >= $this->orderReference->getAmount()) {
					try {
						$this->orderTransactionStateHandler->refund($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
					} catch (IllegalTransitionException $exception) {
						// we can not ensure that the refund or refund partially status change is allowed
						$this->orderTransactionStateHandler->cancel($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
					}
				}
			} else {
				$callbackComments = 'Already full amount refunded for this TID';
			}
        } else {
            $callbackComments = 'Already full amount refunded for this TID';
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

        if (in_array($this->orderReference->getGatewayStatus(), ['CONFIRMED', '100']) && ! empty($this->eventData ['transaction'] ['amount'])) {
            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.chargebackComments', [], null, $this->locale), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->eventTid);

            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments);

            if ($this->eventData ['transaction'] ['amount'] >= $this->orderReference->getAmount()) {
                try {
                    $this->helper->setStatus($this->orderTransaction->getId(), 'chargeback', $this->salesChannelContext->getContext());
                } catch (IllegalTransitionException $exception) {
                }
            }
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

        if (in_array($this->orderReference->getGatewayStatus(), ['CONFIRMED', 'PENDING', '100'])) {
            $reminderCount = explode('_', $this->eventType);
            $reminderCount = end($reminderCount);
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

        if (in_array($this->orderReference->getGatewayStatus(), ['CONFIRMED', 'PENDING', '100'])) {
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
        $upsertData = [
            'id' => $this->orderReference->getId(),
            'gatewayStatus' => $this->eventData['transaction']['status'],
        ];

        $callbackComments = '';
        $appendComments = true;
        
        if (in_array($this->eventData['transaction']['status'], ['PENDING', 'ON_HOLD', 'CONFIRMED', 'DEACTIVATED'])) {
            if (in_array($this->eventData['transaction']['update_type'], ['DUE_DATE', 'AMOUNT_DUE_DATE'])) {
                $upsertData['amount'] = $this->eventData['transaction']['amount'];

                $dueDate = date('d/m/Y', strtotime($this->eventData['transaction']['due_date']));

                if ($this->eventData['transaction']['payment_type'] === 'CASHPAYMENT') {
                    $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.cashDueDateComments'), $this->formattedAmount, $dueDate);
                } else {
                    $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.dueDateComments', [], null, $this->locale), $this->formattedAmount, $dueDate);
                }
            } elseif ($this->eventData['transaction']['update_type'] === 'STATUS') {
                if (in_array($this->eventData['transaction']['payment_type'], ['GUARANTEED_INVOICE', 'INSTALMENT_INVOICE', 'INVOICE', 'GUARANTEED_DIRECT_DEBIT_SEPA', 'INSTALMENT_DIRECT_DEBIT_SEPA'])) {
                    $appendComments = false;
                    if (!empty($this->orderReference->getAdditionalDetails())) {
                        $this->eventData['transaction']['bank_details'] = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
                    }
                    $callbackComments = $this->helper->formBankDetails($this->eventData, $this->salesChannelContext->getContext(), $this->order->getLanguageId());

                    if (in_array($this->eventData['transaction']['payment_type'], ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA']) && $this->eventData['transaction']['status'] == 'CONFIRMED') {
                        $upsertData['additionalDetails'] = $this->helper->serializeData($this->transactionHelper->getInstalmentInformation($this->eventData, $this->locale));
                    }
                }

                if ($this->eventData['transaction']['status'] === 'DEACTIVATED') {
                    $appendComments = true;
                    $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage', [], null, $this->locale), date('d/m/Y H:i:s'));
                } elseif (in_array($this->orderReference->getGatewayStatus(), ['PENDING', 'ON_HOLD'])|| in_array($this->orderReference->getGatewayStatus(), ['75', '86', '90', '100'])) {
                    $upsertData['amount'] = $this->eventData['transaction']['amount'];

                    if ($this->eventData['transaction']['status'] === 'ON_HOLD') {
                        $callbackComments .= sprintf($this->newLine . $this->translator->trans('NovalnetPayment.text.updateOnholdComments', [], null, $this->locale), $this->eventTid, date('d/m/Y H:i:s'));
                        // Payment not yet completed, set transaction status to "AUTHORIZE"
                    } elseif ($this->eventData['transaction']['status'] === 'CONFIRMED') {
                        $callbackComments .= $this->newLine .$this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.updateComments1', [], null, $this->locale), $this->eventTid, $this->formattedAmount, date('d/m/Y H:i:s'));

                        if (in_array($this->eventData['transaction']['payment_type'], ['PAYPAL', 'PRZELEWY24'])) {
                            $callbackComments = sprintf($this->newLine. $this->translator->trans('NovalnetPayment.text.redirectUpdateComment', [], null, $this->locale), $this->eventTid, date('d/m/Y H:i:s'));
                        }

                        $upsertData['paidAmount'] = $this->eventData['transaction']['amount'];
                    }
                }
            } else {
                if (!empty($this->eventData['transaction']['amount'])) {
                    $upsertData['amount'] = $this->eventData['transaction']['amount'];
                }
                $callbackComments .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.updateComments1', [], null, $this->locale), $this->eventTid, $this->formattedAmount, date('d/m/Y H:i:s'));
            }
        }
        $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, $upsertData, $appendComments);

        try {
            if ($this->eventData['transaction']['status'] === 'DEACTIVATED') {
                $this->orderTransactionStateHandler->cancel($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
            } elseif ($this->eventData['transaction']['status'] === 'ON_HOLD') {
                $this->orderTransactionStateHandler->authorize($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
            } elseif ($this->eventData['transaction']['status'] === 'CONFIRMED') {
                $this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
            } elseif ($this->eventData['transaction']['status'] === 'PENDING') {
                $this->helper->setStatus($this->orderTransaction->getId(), 'in_progress', $this->salesChannelContext->getContext());
            }
        } catch (IllegalTransitionException $exception) {
        }

        if (in_array($this->paymentMethodName, ['novalnetinvoice', 'novalnetinvoiceguarantee', 'novalnetinvoiceinstalment']) && in_array($this->eventData['transaction']['status'], ['CONFIRMED', 'PENDING', 'ON_HOLD'])) {
            $this->transactionHelper->prepareMailContent($this->order, $this->salesChannelContext, $callbackComments);
        }

        return $callbackComments;
    }

    /**
     * Handle payment credit process
     *
     * @return string
     */
    private function creditProcess(): string
    {
        $upsertData       = [];
        if ((int) $this->orderReference->getPaidAmount() < (int) $this->orderReference->getAmount() && in_array($this->eventData['transaction']['payment_type'], [ 'INVOICE_CREDIT', 'CASHPAYMENT_CREDIT', 'MULTIBANCO_CREDIT', 'ONLINE_TRANSFER_CREDIT', 'DEBT_COLLECTION_DE'])) {
            // Calculate total amount.
            $paidAmount = (int) $this->orderReference->getPaidAmount() + (int) $this->eventData['transaction']['amount'];

            // Calculate including refunded amount.
            $amountToBePaid = (int) $this->orderReference->getAmount() - (int) $this->orderReference->getRefundedAmount();

            $upsertData['id']            = $this->orderReference->getId();
            $upsertData['gatewayStatus'] = $this->eventData['transaction']['status'];
            $upsertData['paidAmount']    = $paidAmount;

            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.creditMessage', [], null, $this->locale), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->eventTid);

            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, $upsertData);

            if (($paidAmount >= $amountToBePaid)) {
                try {
                    $this->orderTransactionStateHandler->process($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
                    $this->orderTransactionStateHandler->paid($this->orderTransaction->getId(), $this->salesChannelContext->getContext());
                } catch (IllegalTransitionException $exception) {
                }
            } elseif ($paidAmount != 0 && $paidAmount < $amountToBePaid) {
				$this->helper->setStatus($this->orderTransaction->getId(), 'paid_partially', $this->salesChannelContext->getContext());
            }
            return $callbackComments;
        } elseif (in_array($this->eventData['transaction']['payment_type'], [ 'CREDIT_ENTRY_SEPA', 'DEBT_COLLECTION_SEPA', 'CREDIT_ENTRY_CREDITCARD', 'DEBT_COLLECTION_CREDITCARD', 'CREDITCARD_REPRESENTMENT', 'BANK_TRANSFER_BY_END_CUSTOMER', 'APPLEPAY_REPRESENTMENT', 'GOOGLEPAY_REPRESENTMENT','REFUND_REVERSAL', 'CREDIT_ENTRY_DE'])) {
            $callbackComments = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.creditMessage', [], null, $this->locale), $this->parentTid, $this->formattedAmount, date('d/m/Y H:i:s'), $this->eventTid);
            $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $callbackComments, []);
            return $callbackComments;
        } else {
            return 'Novalnet webhook received. Order Already Paid';
        }
    }

    /**
     * Handle payment INSTALMENT process
     *
     * @return string
     */
    private function instalmentProcess(): string
    {
        $comments = '';
        if (in_array($this->eventData['transaction']['payment_type'], ['INSTALMENT_INVOICE', 'INSTALMENT_DIRECT_DEBIT_SEPA']) && $this->orderReference->getGatewayStatus() == 'CONFIRMED') {
            $comments = $this->helper->formBankDetails($this->eventData, $this->salesChannelContext->getContext(), $this->order->getLanguageId());
        }

        $comments .= $this->newLine . $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentPrepaidMessage', [], null, $this->locale), $this->parentTid, $this->formattedAmount, $this->eventTid);

        $upsertData['id']                = $this->orderReference->getId();
        $upsertData['additionalDetails'] = $this->updateInstalmentInfo();

        $this->transactionHelper->postProcess($this->orderTransaction, $this->salesChannelContext->getContext(), $comments, $upsertData, false);
        $this->transactionHelper->prepareMailContent($this->order, $this->salesChannelContext, $comments, true);
        return $comments;
    }

    /**
     * Handle payment INSTALMENT cancel process
     *
     * @return string
     */
    private function instalmentCancelProcess(): string
    {
        $additionalDetails = $this->helper->unserializeData($this->orderReference->getAdditionalDetails());
        $callbackComments  = '';
        if (isset($this->eventData['transaction']['refund'])) {
            $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($this->eventData['transaction']['refund']['amount'], $this->orderReference->getCurrency(), $this->salesChannelContext->getContext());
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
        $additionalDetails['cancelType'] = $this->eventData['instalment']['cancel_type'] ?? 'CANCEL_ALL_CYCLES';

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
            'cycleDate'     => !empty($configurationDetails['InstalmentDetails'][$instalmentData['cycles_executed']]['cycleDate']) ? $configurationDetails['InstalmentDetails'][$instalmentData['cycles_executed']]['cycleDate'] : date('Y-m-d'),
            'cycleExecuted' => $instalmentData['cycles_executed'],
            'dueCycles'     => $instalmentData['pending_cycles'],
            'paidDate'      => date('Y-m-d'),
            'status'        => $this->translator->trans('NovalnetPayment.text.paidMsg', [], null, $this->locale),
            'reference'     => (string) $this->eventData['transaction']['tid'],
            'refundAmount'  => 0
        ];
        return $this->helper->serializeData($configurationDetails);
    }
}
