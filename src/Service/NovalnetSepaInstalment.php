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

class NovalnetSepaInstalment extends AbstractNovalnetPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    /** @var string */
    protected $novalnetPaymentType = 'INSTALMENT_DIRECT_DEBIT_SEPA';
    
    /** @var string */
    protected $paymentCode         = 'novalnetsepainstalment';
    
    /** @var string */
    protected $paymentHandler      = NovalnetSepaInstalment::class;
    
    /** @var int */
    protected $position            = -1007;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Ratenzahlung per SEPA-Lastschrift',
            'description' => 'Der Betrag wird durch Novalnet von Ihrem Konto abgebucht',
        ],
        'en-GB' => [
            'name'        => 'Instalment by Direct Debit SEPA',
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
        return;
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
        
        if ($this->sessionInterface->has($this->paymentCode . 'FormData') && \version_compare($this->helper->getShopVersion(), '6.4.0', '<')) {
            $sessionData = $this->sessionInterface->get($this->paymentCode . 'FormData');
        } elseif (!empty($data->get($this->paymentCode . 'FormData'))) {
			$sessionData = $data->get($this->paymentCode . 'FormData')->all();
		} elseif (!empty($_REQUEST[$this->paymentCode . 'FormData'])) {
			$sessionData = $_REQUEST[$this->paymentCode . 'FormData'];
		}
		
        $this->setPaymentToken($this->paymentCode, $data, $sessionData, $parameters);
        
		if (! empty($sessionData['dob']) || !empty($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday())) {
			$parameters['customer']['birth_date'] = !empty($sessionData['dob']) ? date('Y-m-d', strtotime($sessionData['dob'])) : date('Y-m-d', strtotime($transaction->getOrder()->getOrderCustomer()->getCustomer()->getBirthday()->format('Y-m-d')));
		}
		
		// Instalment data
		if (! empty($sessionData['duration']) || !empty($paymentSettings['NovalnetPayment.settings.sepainstalment.cycles'])) {
			$parameters['instalment']['interval']	= '1m';
			$parameters['instalment']['cycles']		= !empty($sessionData['duration']) ? $sessionData['duration'] : $paymentSettings['NovalnetPayment.settings.sepainstalment.cycles'][0];
		}
		
        if (empty($parameters ['transaction'] ['payment_data'] ['token'])) {
            
            foreach ([
                'accountData' => 'iban',
            ] as $key => $value) {
                if (! empty($sessionData[$key])) {
                    $parameters['transaction'] ['payment_data'][$value] = strtoupper(str_replace(' ', '', $sessionData[$key]));
                }
            }
        }
        
        if (!empty($this->paymentSettings['NovalnetPayment.settings.sepainstalment.dueDate']) && $this->paymentSettings['NovalnetPayment.settings.sepainstalment.dueDate'] >= 2 && $this->paymentSettings['NovalnetPayment.settings.sepainstalment.dueDate'] <= 14) {
            $parameters['transaction'] ['due_date']= $this->helper->formatDueDate($this->paymentSettings['NovalnetPayment.settings.sepainstalment.dueDate']);
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
