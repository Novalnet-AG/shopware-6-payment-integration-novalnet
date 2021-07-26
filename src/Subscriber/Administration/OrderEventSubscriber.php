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

namespace Novalnet\NovalnetPayment\Subscriber\Administration;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetOrderTransactionHelper;
use Novalnet\NovalnetPayment\Helper\NovalnetValidator;
use Shopware\Core\Checkout\Order\OrderEvents;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Event\EntityWrittenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * OrderEventSubscriber Class.
 */
class OrderEventSubscriber implements EventSubscriberInterface
{
    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var NovalnetOrderTransactionHelper
     */
    private $transactionHelper;

    /**
     * @var NovalnetValidator
     */
    private $validator;

    /**
     * Constructs a `OrderEventSubscriber`
     *
     * @param NovalnetHelper $helper
     * @param NovalnetOrderTransactionHelper $transactionHelper
     * @param NovalnetValidator $validator
     */
    public function __construct(NovalnetHelper $helper, NovalnetOrderTransactionHelper $transactionHelper, NovalnetValidator $validator)
    {
        $this->helper            = $helper;
        $this->transactionHelper = $transactionHelper;
        $this->validator         = $validator;
    }

    /**
     * Get subscribed events
     *
     * return array
     */
    public static function getSubscribedEvents(): array
    {
        return [
            OrderEvents::ORDER_TRANSACTION_WRITTEN_EVENT => 'onPaymentStateChange'
        ];
    }

    /**
     * Handle API calls on payment status change.
     *
     * @params EntityWrittenEvent $event
     */
    public function onPaymentStateChange(EntityWrittenEvent $event): void
    {
        foreach ($event->getPayloads() as $payload) {
            $transaction = $this->transactionHelper->getTransactionById($payload['id']);
            $paymentMethodName = '';
            if (!is_null($transaction)) {
                $paymentMethod     = $this->transactionHelper->getPaymentMethodById($transaction->getPaymentMethodId());
                if (!is_null($paymentMethod)) {
                    $paymentMethodName = $this->helper->getPaymentMethodName($paymentMethod);
                }

                if (!is_null($paymentMethodName) && $this->validator->checkString($paymentMethodName)) {
                    if ($transaction !== null && $transaction->getStateMachineState() !== null && $transaction->getOrder() !== null) {
                        $context = Context::createDefaultContext();
                        if ($transaction->getOrder()->getOrderNumber() !== null) {
                            $transactionData = $this->transactionHelper->fetchNovalnetTransactionData($transaction->getOrder()->getOrderNumber());

                            if (!is_null($transactionData)) {
                                if (($transactionData->getGatewayStatus() === 'ON_HOLD' || in_array($transactionData->getGatewayStatus(), ['91', '98', '99', '85'])) && in_array($transaction->getStateMachineState()->getTechnicalName(), ['paid', 'cancelled', 'in_progress'])) {
                                    if (in_array($transaction->getStateMachineState()->getTechnicalName(), ['paid', 'in_progress'])) {
                                        if (($transaction->getStateMachineState()->getTechnicalName() === 'in_progress' && $this->helper->getSupports('payLater', $paymentMethodName)) || $transaction->getStateMachineState()->getTechnicalName() === 'paid') {
                                            $response = $this->transactionHelper->manageTransaction($transactionData, $transaction, $context, 'transaction_capture');
                                        }
                                    } elseif ($transaction->getStateMachineState()->getTechnicalName() === 'cancelled') {
                                        $response = $this->transactionHelper->manageTransaction($transactionData, $transaction, $context, 'transaction_cancel');
                                    }
                                } elseif ($transactionData->getRefundedAmount() < $transactionData->getAmount() && ($transactionData->getGatewayStatus() === 'CONFIRMED' || ($transactionData->getGatewayStatus() === 'PENDING' && $this->helper->getSupports('payLater', $paymentMethodName)) || $transactionData->getGatewayStatus() == '100') && in_array($transaction->getStateMachineState()->getTechnicalName(), ['refunded', 'cancelled'])) {
                                    if($this->helper->getSupports('instalment', $paymentMethodName))
                                    {
										$this->transactionHelper->refundTransaction($transactionData, $transaction, $context, 0, null, true);
									} else {
										$this->transactionHelper->refundTransaction($transactionData, $transaction, $context);
									}
										
                                }
                            }
                        }
                    }
                }
                
                return;
            }
        }
    }
}
