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

use Novalnet\NovalnetPayment\Content\PaymentTransaction\NovalnetPaymentTransactionEntity;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Content\Mail\Service\AbstractMailService;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\OrFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\AndFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionEntity;
use Shopware\Core\Checkout\Order\Aggregate\OrderTransaction\OrderTransactionStateHandler;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\StateMachine\Exception\IllegalTransitionException;
use Symfony\Component\HttpFoundation\ParameterBag;
use Symfony\Contracts\Translation\TranslatorInterface;
use Symfony\Component\HttpFoundation\Request;

class NovalnetOrderTransactionHelper
{
    /**
     * @var AbstractMailService
     */
    private $mailService;

    /**
     * @var TranslatorInterface
     */
    private $translator;

    /**
     * @var NovalnetHelper
     */
    private $helper;

    /**
     * @var NovalnetValidator
     */
    private $validator;

    /**
     * @var OrderTransactionStateHandler
     */
    private $orderTransactionState;

    /**
     * @var string
     */
    public $newLine = '/ ';

    /**
     * @var EntityRepository
     */
    public $orderRepository;

    /**
     * @var EntityRepository
     */
    public $orderTransactionRepository;

    /**
     * @var EntityRepository
     */
    public $novalnetTransactionRepository;

    /**
     * @var EntityRepository
     */
    private $mailTemplateRepository;

    public function __construct(
        NovalnetHelper $helper,
        NovalnetValidator $validator,
        OrderTransactionStateHandler $orderTransactionState,
        TranslatorInterface $translator,
        EntityRepository $orderRepository,
        EntityRepository $orderTransactionRepository,
        EntityRepository $novalnetTransactionRepository,
        AbstractMailService $mailService,
        EntityRepository $mailTemplateRepository
    ) {
        $this->helper                        = $helper;
        $this->validator                     = $validator;
        $this->orderTransactionState         = $orderTransactionState;
        $this->translator                    = $translator;
        $this->orderRepository               = $orderRepository;
        $this->orderTransactionRepository    = $orderTransactionRepository;
        $this->novalnetTransactionRepository = $novalnetTransactionRepository;
        $this->mailService                   = $mailService;
        $this->mailTemplateRepository        = $mailTemplateRepository;
    }

    /**
     * send novalnet order mail.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param OrderEntity $order
     * @param string $note
     * @param boolean $instalmentRecurring
     */
    public function sendMail(SalesChannelContext $salesChannelContext, OrderEntity $order, string $note, bool $instalmentRecurring = false): void
    {
        $customer = $order->getOrderCustomer();
        if (null === $customer) {
            return;
        }

        $transaction = $order->getTransactions()->last();

        $instalmentInfo = [];

        if (strpos($transaction->getPaymentMethod()->getHandlerIdentifier(), 'Instalment') !== false) {
            $instalmentInfo = $this->getNovalnetInstalmentInfo($salesChannelContext, $order->getOrderNumber());
        }

        $mailTemplate =  $this->getMailTemplate($salesChannelContext->getContext(), 'novalnet_order_confirmation_mail');

        if (is_null($mailTemplate)) {
            return;
        }
        $data = new ParameterBag();
        $data->set(
            'recipients',
            [
                $customer->getEmail() => $customer->getFirstName().' '.$customer->getLastName(),
            ]
        );
        $data->set('senderName', $mailTemplate->getTranslation('senderName'));
        $data->set('salesChannelId', $order->getSalesChannelId());

        $data->set('contentHtml', $mailTemplate->getTranslation('contentHtml'));
        $data->set('contentPlain', $mailTemplate->getTranslation('contentPlain'));

        if ($instalmentRecurring) {
            $data->set('subject', sprintf($this->translator->trans('NovalnetPayment.text.instalmentMailSubject'), $mailTemplate->getTranslation('senderName'), $order->getOrderNumber()));
        } else {
            $data->set('subject', $mailTemplate->getTranslation('subject'));
        }

        try {
            $this->mailService->send(
                $data->all(),
                $salesChannelContext->getContext(),
                [
                    'order' => $order,
                    'note' => $note,
                    'instalment' => $instalmentRecurring,
                    'salesChannel' => $salesChannelContext->getSalesChannel(),
                    'context' => $salesChannelContext,
                    'instalmentInfo' => $instalmentInfo,
                ]
            );
        } catch (\RuntimeException $e) {
            //Ignore
        }
    }

    /**
     * get the order mail template.
     *
     * @param Context $context
     * @param string $technicalName
     *
     * @return MailTemplateEntity|null
     */
    public function getMailTemplate(Context $context, string $technicalName): ? MailTemplateEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', $technicalName));
        $criteria->setLimit(1);

        /** @var MailTemplateEntity|null $mailTemplate */
        $mailTemplate = $this->mailTemplateRepository->search($criteria, $context)->first();

        return $mailTemplate;
    }

    /**
     * Finds a transaction by id.
     *
     * @param string $transactionId
     * @param Context|null $context
     *
     * @return OrderTransactionEntity|null
     */
    public function getTransactionById(string $transactionId, Context $context = null): ?OrderTransactionEntity
    {
        $transactionCriteria = new Criteria();
        $transactionCriteria->addFilter(new EqualsFilter('id', $transactionId));

        $transactionCriteria->addAssociation('order');

        $transactions = $this->orderTransactionRepository->search(
            $transactionCriteria,
            $context ?? Context::createDefaultContext()
        );

        if ($transactions->count() === 0) {
            return null;
        }

        return $transactions->first();
    }

    /**
     * send novalnet mail.
     *
     * @param OrderEntity $order
     * @param SalesChannelContext $salesChannelContext
     * @param string $note
     * @param boolean $instalmentRecurring
     *
     */
    public function prepareMailContent(OrderEntity $order, SalesChannelContext $salesChannelContext, string $note, $instalmentRecurring = false): void
    {
        if (!is_null($order->getOrderCustomer())) {
            $orderReference = $this->helper->getOrderCriteria($order->getId(), $salesChannelContext->getContext(), $order->getOrderCustomer()->getCustomerId());
            try {
                $emailMode = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.emailMode', $salesChannelContext->getSalesChannel()->getId());
                if (!empty($emailMode)) {
                    $this->sendMail($salesChannelContext, $orderReference, $note, $instalmentRecurring);
                }
            } catch (\RuntimeException $e) {
                //Ignore
            }
        }
    }

    /**
     * Fetch Novalnet transaction data.
     *
     * @param string $orderNumber
     * @param Context|null $context
     * @param string $tid
     *
     * @return NovalnetPaymentTransactionEntity
     */
    public function fetchNovalnetTransactionData(string $orderNumber = null, Context $context = null, string $tid = null): ?NovalnetPaymentTransactionEntity
    {
        $criteria = new Criteria();

        if (!is_null($tid)) {
            $criteria->addFilter(new OrFilter([
                new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber),
                new EqualsFilter('novalnet_transaction_details.tid', $tid)
            ]));
        } else {
            $criteria->addFilter(new EqualsFilter('novalnet_transaction_details.orderNo', $orderNumber));
        }

        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::DESCENDING)
        );

        return $this->novalnetTransactionRepository->search($criteria, $context ?? Context::createDefaultContext())->first();
    }

    /**
     * @throws \RuntimeException
     */
    private function getSalesChannelIdByOrderId(string $orderId, Context $context): string
    {
        $order = $this->orderRepository->search(new Criteria([$orderId]), $context)->first();

        if ($order === null) {
            throw new \RuntimeException('Order not founded');
        }

        return $order->getSalesChannelId();
    }

    /**
     * Instalment cancel
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param Request $request
     *
     * @return array
     */
    public function cancelInstalmentPayment(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, Request $request): array
    {
        // set the shop locale to display the message
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());

        $endPoint   = $this->helper->getActionEndpoint('instalment_cancel');

        $parameters = [
            'instalment' => [
                'tid'    => $transactionData->getTid(),
                'cancel_type' => $request->request->get('cancelType')
            ],
            'custom' => [
                'shop_invoked' => 1,
                'lang' => strtoupper(substr($localeCode, 0, 2))
            ]
        ];

        $accessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

        $response = $this->helper->sendPostRequest($parameters, $endPoint, $accessKey);

        if ($this->validator->isSuccessStatus($response)) {
            $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());
            if (isset($response['transaction']['refund'])) {
                $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction']['refund']['amount'], $transactionData->getCurrency(), $context);
                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentRefundComment', [], null, $localeCode), $transactionData->getTid(), date('Y-m-d H:i:s'), $refundedAmountInBiggerUnit);
                $totalRefundedAmount = $transactionData->getAmount();
            } else {
                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.instalmentRemainRefundComment', [], null, $localeCode), $transactionData->getTid(), date('Y-m-d H:i:s'));
                $totalRefundedAmount = $transactionData->getRefundedAmount();
                foreach ($additionalDetails['InstalmentDetails'] as $instalment) {
                    $totalRefundedAmount += empty($instalment['reference']) ? $instalment['amount'] : 0;
                }
            }

            $additionalDetails['InstalmentDetails'] = $this->updateInstalmentCancel($additionalDetails['InstalmentDetails'], $request->request->get('cancelType'), $localeCode);
            $additionalDetails['cancelType'] = $request->request->get('cancelType');

            $this->postProcess($transaction, $context, $message, [
                'id'             => $transactionData->getId(),
                'refundedAmount' => $totalRefundedAmount,
                'gatewayStatus'  => $response['transaction']['status'],
                'additionalDetails'  => $this->helper->serializeData($additionalDetails),
            ]);

            if ($totalRefundedAmount >= $transactionData->getAmount()) {
                try {
                    $this->orderTransactionState->cancel($transaction->getId(), $context);
                } catch (IllegalTransitionException $exception) {
                }
            }
        }

        return $response;
    }

    /**
     * Manage transaction
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $type
     *
     * @return array
     */
    public function manageTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, string $type = 'transaction_capture'): array
    {
        // set the shop locale to display the message
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());

        $response = [];
        if ($type) {
            $parameters = [
                'transaction' => [
                    'tid' => $transactionData->getTid()
                ],
                'custom' => [
                    'shop_invoked' => 1,
                    'lang' => strtoupper(substr($localeCode, 0, 2))
                ]
            ];
            $endPoint   = $this->helper->getActionEndpoint($type);

            $accessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

            $response = $this->helper->sendPostRequest($parameters, $endPoint, $accessKey);

            if ($this->validator->isSuccessStatus($response)) {
                $message        = '';
                $appendComments = true;

                if (! empty($response['transaction']['status'])) {
                    $upsertData = [
                        'id'            => $transactionData->getId(),
                        'gatewayStatus' => $response['transaction']['status']
                    ];
                    if (in_array($response['transaction']['status'], ['CONFIRMED', 'PENDING'])) {
                        if ($response['transaction']['status'] === 'CONFIRMED') {
                            $upsertData['paidAmount'] = $transactionData->getAmount();
                        }
                        if (!empty($transactionData->getAdditionalDetails()) && !empty($transactionData->getPaymentType()) && in_array($transactionData->getPaymentType(), ['novalnetinvoice', 'novalnetinvoiceguarantee', 'novalnetprepayment', 'novalnetinvoiceinstalment'])) {
                            $appendComments = false;
                            $response['transaction']['bank_details'] = $this->helper->unserializeData($transactionData->getAdditionalDetails());
                            $message .=  $this->helper->formBankDetails($response, $context) . $this->newLine;
                        }

                        if (! empty($response['instalment']['cycles_executed']) && !empty($transactionData->getPaymentType()) && in_array($transactionData->getPaymentType(), ['novalnetsepainstalment', 'novalnetinvoiceinstalment'])) {
                            $response['transaction']['amount'] = $transactionData->getAmount();
                            $upsertData['additionalDetails'] = $this->getInstalmentInformation($response, $localeCode);
                            $upsertData['additionalDetails'] = $this->helper->serializeData($upsertData['additionalDetails']);
                        }

                        $message .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.confirmMessage', [], null, $localeCode), date('d/m/Y H:i:s'));
                    } elseif ($response['transaction']['status'] === 'DEACTIVATED') {
                        $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.faliureMessage', [], null, $localeCode), date('d/m/Y H:i:s'));
                    }

                    $this->postProcess($transaction, $context, $message, $upsertData, $appendComments);

                    if ($response['transaction']['status'] === 'CONFIRMED') {
                        $this->orderTransactionState->paid($transaction->getId(), $context);
                    } elseif ($response['transaction']['status'] === 'PENDING') {
                        $this->orderTransactionState->process($transaction->getId(), $context);
                    } elseif ($response['transaction']['status'] !== 'PENDING') {
                        $this->orderTransactionState->cancel($transaction->getId(), $context);
                    }
                }
            }
        }
        return $response;
    }

    /**
     * Book Amount
     *
     * @param integer $amount
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderEntity $order
     * @param Context $context
     *
     * @return array
     */
    public function bookOrderAmount(int $amount, NovalnetPaymentTransactionEntity $transactionData, OrderEntity $order, Context $context): array
    {
        $response = [];
        $message = '';
        // set the shop locale to display the message
        $localeCode = $this->helper->getLocaleFromOrder($order->getId());

        if (!is_null($transactionData->getAdditionalDetails())) {
            $accessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $order->getSalesChannelId());

            $serverRequest = $this->helper->unserializeData($transactionData->getAdditionalDetails());
            $serverRequest ['transaction']['amount'] = $amount;
            // Built Payment token
            $serverRequest['transaction'] ['payment_data'] = ['token' => $serverRequest['token']];
            unset($serverRequest['token'], $serverRequest['transaction']['create_token'], $serverRequest['transaction']['return_url'], $serverRequest['transaction']['error_return_url']);
            // send request to server
            $response = $this->helper->sendPostRequest($serverRequest, $this->helper->getActionEndpoint('payment'), $accessKey);
            if ($this->validator->isSuccessStatus($response)) {
                $bookedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction'] ['amount'], $response['transaction'] ['currency'], $context);

                $message .= $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.bookedComment', [], null, $localeCode), $bookedAmountInBiggerUnit, $response['transaction'] ['tid']);

                $transaction = $this->getOrder($order->getOrderNumber(), $context);

                $this->postProcess($transaction, $context, $message, [
                    'id'      => $transactionData->getId(),
                    'tid'     => $response['transaction']['tid'],
                    'amount'  => $response['transaction']['amount'],
                    'paidAmount'  => $response['transaction']['amount'],
                    'gatewayStatus'  => $response['transaction']['status'],
                ]);

                try {
                    $this->orderTransactionState->paid($transaction->getId(), $context);
                } catch (IllegalTransitionException $exception) {
                }
            }
        }
        return $response;
    }

    /**
     * Refund transaction
     *
     * @param NovalnetPaymentTransactionEntity $transactionData
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param int $refundAmount
     * @param Request $request
     *
     * @return array
     */
    public function refundTransaction(NovalnetPaymentTransactionEntity $transactionData, OrderTransactionEntity $transaction, Context $context, int $refundAmount, Request $request) : array
    {
        // set the shop locale to display the message
        $localeCode = $this->helper->getLocaleFromOrder($transaction->getOrderId());

        $endPoint   = $this->helper->getActionEndpoint('transaction_refund');

        $parameters = [
            'transaction' => [
                'tid'    => !empty($request->request->get('instalmentCycleTid')) ? $request->request->get('instalmentCycleTid') : $transactionData->getTid()
            ],
            'custom' => [
                'shop_invoked' => 1,
                'lang' => strtoupper(substr($localeCode, 0, 2))
            ]
        ];

        if ($request->request->get('reason')) {
            $parameters['transaction']['reason'] = $request->request->get('reason');
        }

        if (!empty($refundAmount)) {
            $parameters['transaction']['amount'] = $refundAmount;
        }
        
        $accessKey = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.accessKey', $this->getSalesChannelIdByOrderId($transaction->getOrderId(), $context));

        $response = $this->helper->sendPostRequest($parameters, $endPoint, $accessKey);

        if ($this->validator->isSuccessStatus($response)) {
            if (! empty($response['transaction']['status'])) {
                $currency = !empty($response['transaction'] ['currency']) ? $response['transaction'] ['currency'] : $response ['transaction'] ['refund'] ['currency'];
                $additionalDetails = $this->helper->unserializeData($transactionData->getAdditionalDetails());

                if (!empty($response['transaction']['refund']['amount'])) {
                    $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($response['transaction']['refund']['amount'], $currency, $context);
                } else {
                    $refundedAmountInBiggerUnit = $this->helper->amountInBiggerCurrencyUnit($refundAmount, $transactionData->getCurrency(), $context);
                }

                $message = $this->newLine . sprintf($this->translator->trans('NovalnetPayment.text.refundComment', [], null, $localeCode), $transactionData->getTid(), $refundedAmountInBiggerUnit);

                if (! empty($response['transaction']['refund']['tid'])) {
                    $message .= sprintf($this->translator->trans('NovalnetPayment.text.refundCommentForNewTid', [], null, $localeCode), $response ['transaction']['refund']['tid']);
                }

                if (in_array($transactionData->getPaymentType(), ['novalnetinvoiceinstalment', 'novalnetsepainstalment'])) {
                    $additionalDetails['InstalmentDetails'] = $this->updateInstalmentCycle($additionalDetails['InstalmentDetails'], $refundAmount, $request->request->get('instalmentCycleTid'), $localeCode);
                }
                $totalRefundedAmount = (int) $transactionData->getRefundedAmount() + (int) $refundAmount;

                $this->postProcess($transaction, $context, $message, [
                    'id'             => $transactionData->getId(),
                    'refundedAmount' => $totalRefundedAmount,
                    'gatewayStatus'  => $response['transaction']['status'],
                    'additionalDetails'  => $this->helper->serializeData($additionalDetails),
                ]);

                if ($totalRefundedAmount >= $transactionData->getAmount()) {
                    try {
                        $this->orderTransactionState->refund($transaction->getId(), $context);
                    } catch (IllegalTransitionException $exception) {
                        // we can not ensure that the refund or refund partially status change is allowed
                        $this->orderTransactionState->cancel($transaction->getId(), $context);
                    }
                }
            }
        }
        return $response;
    }

    /**
     * Post payment process
     *
     * @param OrderTransactionEntity $transaction
     * @param Context $context
     * @param string $comments
     * @param array $upsertData
     * @param bool $append
     */
    public function postProcess(OrderTransactionEntity $transaction, Context $context, string $comments, array $upsertData = [], bool $append = true) : void
    {
        if (!empty($upsertData)) {
            $this->novalnetTransactionRepository->update([$upsertData], $context);
        }

        if (!is_null($transaction->getCustomFields()) && !empty($comments)) {
            $oldComments = $transaction->getCustomFields()['novalnet_comments'];

            if (!empty($oldComments) && !empty($append)) {
                $comments = $oldComments . $this->newLine . $comments;
            }

            $data = [
                'id' => $transaction->getId(),
                'customFields' => [
                    'novalnet_comments' => $comments,
                ],
            ];
            $this->orderTransactionRepository->update([$data], $context);
        }
    }

    /**
     * Get order.
     *
     * @param string $orderNumber
     * @param Context $context
     *
     * @return OrderTransactionEntity|null
     */
    public function getOrder(string $orderNumber, Context $context): ?OrderTransactionEntity
    {
        $order = $this->getOrderEntity($orderNumber, $context);

        if (null === $order) {
            return null;
        }

        $transactionCollection = $order->getTransactions();

        if (null === $transactionCollection) {
            return null;
        }

        $firstTransaction = $transactionCollection->first();
        $lastTransaction = $transactionCollection->last();
        if ($firstTransaction->getCreatedAt()->format('Y-m-d H:i:s') > $lastTransaction->getCreatedAt()->format('Y-m-d H:i:s')) {
            $transaction = $firstTransaction;
        } else {
            $transaction = $lastTransaction;
        }

        if (null === $transaction) {
            return null;
        }

        return $transaction;
    }

    /**
     * Get order.
     *
     * @param string $orderNumber
     * @param Context $context
     *
     * @return OrderEntity|null
     */
    public function getOrderEntity(string $orderNumber, Context $context): ?OrderEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderNumber', $orderNumber));
        $criteria->addAssociation('transactions');
        $criteria->addAssociation('transactions.paymentMethod');
        $criteria->addSorting(
            new FieldSorting('transactions.createdAt', FieldSorting::ASCENDING)
        );
        $order = $this->orderRepository->search($criteria, $context)->first();

        if (null === $order) {
            return null;
        }

        return $order;
    }

    /**
     * Form instalment information.
     *
     * @param array $response
     * @param string $locale
     *
     * @return array
     */
    public function getInstalmentInformation(array $response, string $locale = 'de-DE'): array
    {
        $instalmentData = $response['instalment'];
        $additionalDetails = [];
        
        if (!empty($instalmentData['cycle_dates']))
        {
			sort($instalmentData['cycle_dates']);
			foreach ($instalmentData['cycle_dates'] as $cycle => $futureInstalmentDate) {
				$cycle = $cycle + 1;
				$additionalDetails['InstalmentDetails'][$cycle] = [
					'amount'        => $instalmentData['cycle_amount'],
					'cycleDate'     => $futureInstalmentDate ? date('Y-m-d', strtotime($futureInstalmentDate)) : '',
					'cycleExecuted' => '',
					'dueCycles'     => '',
					'paidDate'      => '',
					'status'        => $this->translator->trans('NovalnetPayment.text.pendingMsg', [], null, $locale),
					'reference'     => '',
					'refundAmount'  => 0,
				];

				if ($cycle == count($instalmentData['cycle_dates'])) {
					$amount = $response['transaction']['amount'] - ($instalmentData['cycle_amount'] * ($cycle - 1));
					$additionalDetails['InstalmentDetails'][$cycle] = array_merge($additionalDetails['InstalmentDetails'][$cycle], [
					   'amount'    => $amount
					]);
				}

				if ($cycle == 1) {
					$additionalDetails['InstalmentDetails'][$cycle] = array_merge($additionalDetails['InstalmentDetails'][$cycle], [
						'cycleExecuted' => !empty($instalmentData['cycles_executed']) ? $instalmentData['cycles_executed'] : '',
						'dueCycles'     => !empty($instalmentData['pending_cycles']) ? $instalmentData['pending_cycles'] : '',
						'paidDate'      => date('Y-m-d'),
						'status'        => $this->translator->trans('NovalnetPayment.text.paidMsg', [], null, $locale),
						'reference'     => (string) $response['transaction']['tid'],
						'refundAmount'  => 0,
					]);
				}
			}
		}
		
        return $additionalDetails;
    }

    /**
     * Return the novalnet instalment information.
     *
     * @param SalesChannelContext $salesChannelContext
     * @param string $orderNumber
     *
     * @return array
     */
    public function getNovalnetInstalmentInfo(SalesChannelContext $salesChannelContext, $orderNumber): array
    {
        $transactionData = $this->fetchNovalnetTransactionData((string) $orderNumber, $salesChannelContext->getContext());

        if ($transactionData->getGatewayStatus() === 'CONFIRMED') {
            return $this->helper->unserializeData($transactionData->getAdditionalDetails());
        }

        return [];
    }

    /**
     * Fetch Novalnet last transaction data by customer id .
     *
     * @param string $customerNumber
     * @param string $paymentName
     * @param Context|null $context
     *
     * @return NovalnetPaymentTransactionEntity
     */
    public function fetchNovalnetReferenceData(string $customerNumber, string $paymentName, Context $context = null): ? NovalnetPaymentTransactionEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new AndFilter([
            new EqualsFilter('novalnet_transaction_details.customerNo', $customerNumber),
            new EqualsFilter('novalnet_transaction_details.paymentType', $paymentName),
        ]));

        if ($paymentName == 'novalnetinvoiceguarantee') {
            $criteria->addFilter(new ContainsFilter('novalnet_transaction_details.additionalDetails', 'dob'));
        } elseif (in_array($paymentName, ['novalnetgooglepay', 'novalnetapplepay', 'novalnetcreditcard','novalnetsepaguarantee', 'novalnetsepa'])) {
            $criteria->addFilter(new ContainsFilter('novalnet_transaction_details.additionalDetails', 'token'));
        }
        
        $criteria->addSorting(
            new FieldSorting('createdAt', FieldSorting::DESCENDING)
        );

        return $this->novalnetTransactionRepository->search($criteria, $context ?? Context::createDefaultContext())->first();
    }

    /**
     * Update Novalnet instalment cycles
     *
     * @param array $instalmentDetails
     * @param int $amount
     * @param string $referenceTid
     * @param string $locale
     *
     * @return array
     */
    public function updateInstalmentCycle(array $instalmentDetails, int $amount, string $referenceTid, string $locale = 'de-DE'): array
    {
        foreach ($instalmentDetails as $key => $values)
        {
            if ($values['reference'] == $referenceTid)
            {
                $instalmentDetails[$key]['refundAmount'] = (int) $values['refundAmount'] + $amount;
                if ($instalmentDetails[$key]['refundAmount'] >= $values['amount'])
                {
                    $instalmentDetails[$key]['status'] = $this->translator->trans('NovalnetPayment.text.refundedMsg', [], null, $locale);
                }
            }
        }
        return $instalmentDetails;
    }

    /**
     * Update Novalnet instalment cycles
     *
     * @param array $instalmentDetails
     * @param string|null $cycleType
     * @param string $locale
     *
     * @return array
     */
    public function updateInstalmentCancel(array $instalmentDetails, ?string $cycleType, string $locale = 'de-DE'): array
    {
        foreach ($instalmentDetails as $key => $values)
        {
            if (empty($cycleType) || false !== strpos($cycleType, 'ALL_CYCLES'))
            {
                $instalmentDetails[$key]['refundAmount'] = !empty($values['reference']) ? $values['amount'] : 0;
                $instalmentDetails[$key]['status'] = !empty($values['reference']) ? $this->translator->trans('NovalnetPayment.text.refundedMsg', [], null, $locale) : $this->translator->trans('NovalnetPayment.text.cancelMsg', [], null, $locale);
            } elseif (false !== strpos($cycleType, 'REMAINING_CYCLES') && empty($values['reference']))
            {
                $instalmentDetails[$key]['status'] = $this->translator->trans('NovalnetPayment.text.cancelMsg', [], null, $locale);
            }
        }

        return $instalmentDetails;
    }
}
