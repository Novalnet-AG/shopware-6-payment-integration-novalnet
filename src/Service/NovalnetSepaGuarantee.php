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

use Shopware\Core\Checkout\Payment\Cart\SyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\SynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\SyncPaymentProcessException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NovalnetSepaGuarantee extends AbstractNovalnetPaymentHandler implements SynchronousPaymentHandlerInterface
{
    /** @var string */
    protected $novalnetPaymentType = 'GUARANTEED_DIRECT_DEBIT_SEPA';
    
    /** @var string */
    protected $paymentCode         = 'novalnetsepaguarantee';
    
    /** @var string */
    protected $paymentHandler      = NovalnetSepaGuarantee::class;
    
    /** @var int */
    protected $position            = -1018;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'SEPA-Lastschrift mit Zahlungsgarantie',
            'description' => 'Der Betrag wird durch Novalnet von Ihrem Konto abgebucht',
        ],
        'en-GB' => [
            'name'        => 'Direct Debit SEPA with payment guarantee',
            'description' => 'The amount will be debited from your account by Novalnet',
        ],
    ];
    
    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * Throw a @see SyncPaymentProcessException exception if an error ocurres while processing the payment
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws SyncPaymentProcessException
     */
    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        try {
            $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
            $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
        } catch (\Exception $e) {
            throw new SyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway'.PHP_EOL.$e->getMessage());
        }
    }
    
    /**
     * The recurring function will be called during recurring payment.
     * Allows to process the order and store additional information.
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws SyncPaymentProcessException
     */
    public function recurring(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): bool
    {
		$response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
		$this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction, '1');
		if ($this->validator->isSuccessStatus($response)) {
			return true;
        } else {
			return false;
        }
	}
        
    /**
     * Prepare payment related parameters
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $data
     * @param array $parameters
     * @param array $paymentSettings
     */
    public function generatePaymentParameters($transaction, RequestDataBag $data = null, array &$parameters, array $paymentSettings): void
    {
        $sessionData = [];
        if ($this->sessionInterface->has($this->paymentCode . 'FormData') && \version_compare($this->helper->getShopVersion(), '6.4.0', '<')) {
            $sessionData = $this->sessionInterface->get($this->paymentCode . 'FormData');
        } elseif (!empty($data->get($this->paymentCode . 'FormData'))) {
			$sessionData = $data->get($this->paymentCode . 'FormData')->all();
		} elseif (!empty($_REQUEST[$this->paymentCode . 'FormData'])) {
			$sessionData = $_REQUEST[$this->paymentCode . 'FormData'];
		}
		
		if(!empty($sessionData['doForceSepaPayment']))
		{
			$this->paymentCode = 'novalnetsepa';
			$parameters['transaction']['payment_type'] = 'DIRECT_DEBIT_SEPA';
		}
		
        $this->setPaymentToken($this->paymentCode, $data, $sessionData, $parameters);
        
		if (! empty($sessionData['dob']) || !empty($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday())) {
			$parameters['customer']['birth_date'] = !empty($sessionData['dob']) ? date('Y-m-d', strtotime($sessionData['dob'])) : date('Y-m-d', strtotime($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday()->format('Y-m-d')));
		}
		
        if (empty($parameters ['transaction'] ['payment_data'] ['token'])) {
            
            foreach ([
                'accountData' => 'iban',
                'accountBic' => 'bic',
            ] as $key => $value) {
                if (! empty($sessionData[$key])) {
                    $parameters['transaction'] ['payment_data'][$value] = $sessionData[$key] ? strtoupper(str_replace(' ', '', $sessionData[$key])) : '';
                }
            }
        }
        
        if (!empty($this->paymentSettings['NovalnetPayment.settings.sepaguarantee.dueDate']) && $this->paymentSettings['NovalnetPayment.settings.sepaguarantee.dueDate'] >= 2 && $this->paymentSettings['NovalnetPayment.settings.sepaguarantee.dueDate'] <= 14) {
            $parameters['transaction'] ['due_date']	= $this->helper->formatDueDate($this->paymentSettings['NovalnetPayment.settings.sepaguarantee.dueDate']);
        }
    }
    
    /**
     * Prepare transaction comments
     *
     * @param array $response
     * @param Context $context
     *
     * @return string
     */
    public function prepareComments(array $response, $context): string
    {
        return $this->helper->formBasicComments($response, $context);
    }
}
