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

use Shopware\Core\Checkout\Payment\Cart\AsyncPaymentTransactionStruct;
use Shopware\Core\Checkout\Payment\Cart\PaymentHandler\AsynchronousPaymentHandlerInterface;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentProcessException;
use Shopware\Core\Checkout\Payment\Exception\AsyncPaymentFinalizeException;
use Shopware\Core\Checkout\Payment\Exception\CustomerCanceledAsyncPaymentException;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class NovalnetSepa extends AbstractNovalnetPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /** @var string */
    protected $novalnetPaymentType = 'DIRECT_DEBIT_SEPA';
    
    /** @var string */
    protected $paymentCode         = 'novalnetsepa';
    
    /** @var string */
    protected $paymentHandler      = NovalnetSepa::class;
    
    /** @var int */
    protected $position            = -1019;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'SEPA-Lastschrift',
            'description' => 'Der Betrag wird durch Novalnet von Ihrem Konto abgebucht',
        ],
        'en-GB' => [
            'name'        => 'Direct Debit SEPA',
            'description' => 'The amount will be debited from your account by Novalnet',
        ],
    ];

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
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws AsyncPaymentProcessException
     */
    public function pay(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): RedirectResponse
    {
        try {
            $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
            if (version_compare($this->helper->getShopVersion(), '6.4.2.0', '>=')) {
				$this->contextPersister->save($transaction->getOrderTransaction()->getId(), ['nn_data' => base64_encode(json_encode($response))], $salesChannelContext->getSalesChannel()->getId());
			}
            $this->sessionInterface->set($this->paymentCode . 'Response', $response);
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway'.PHP_EOL.$e->getMessage());
        }
        
        return new RedirectResponse($transaction->getreturnUrl(), 302, []);
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
        $response = $this->sessionInterface->get($this->paymentCode . 'Response');
        
        if (version_compare($this->helper->getShopVersion(), '6.4.2.0', '>=') && empty($response)) {
			$responseData	= $this->contextPersister->load($transaction->getOrderTransaction()->getId(), $salesChannelContext->getSalesChannel()->getId());
			$response		= !empty($responseData['nn_data']) ? json_decode(base64_decode($responseData['nn_data']), true) : '';
		}
		
        if (!empty($response)) {
            $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
        }
    }
        
    /**
     * Prepare payment related parameters
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $data
     * @param array $parameters
     * @param array $paymentSettings
     */
    public function generatePaymentParameters(AsyncPaymentTransactionStruct $transaction, RequestDataBag $data = null, array &$parameters, array $paymentSettings): void
    {
        $sessionData = [];
        
        if ($this->sessionInterface->has('novalnetsepaFormData') && \version_compare($this->helper->getShopVersion(), '6.4.0', '<')) {
            $sessionData = $this->sessionInterface->get('novalnetsepaFormData');
        } elseif (!empty($data->get('novalnetsepaFormData'))) {
			$sessionData = $data->get('novalnetsepaFormData')->all();
		} elseif (!empty($_REQUEST['novalnetsepaFormData'])) {
			$sessionData = $_REQUEST['novalnetsepaFormData'];
		}
		
        $this->setPaymentToken('novalnetsepa', $data, $sessionData, $parameters);
        
		if(!empty($sessionData) && empty($parameters ['transaction'] ['payment_data'] ['token']))
		{
			foreach ([
				'accountData' => 'iban',
			] as $key => $value) {
				if (! empty($sessionData[$key])) {
					$parameters['transaction'] ['payment_data'][$value] = strtoupper(str_replace(' ', '', $sessionData[$key]));
				}
			}
        }
        if (!empty($this->paymentSettings['NovalnetPayment.settings.sepa.dueDate']) && $this->paymentSettings['NovalnetPayment.settings.sepa.dueDate'] >= 2 && $this->paymentSettings['NovalnetPayment.settings.sepa.dueDate'] <= 14) {
            $parameters['transaction'] ['due_date']= $this->helper->formatDueDate($this->paymentSettings['NovalnetPayment.settings.sepa.dueDate']);
        }
    }
    
    /**
     * Prepare transaction comments
     *
     * @param array $response
     *
     * @return string
     */
    public function prepareComments(array $response): string
    {
        return $this->helper->formBasicComments($response);
    }
}
