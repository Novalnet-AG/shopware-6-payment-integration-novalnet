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
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

class NovalnetMultibanco extends AbstractNovalnetPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    
    /** @var string */
    protected $novalnetPaymentType = 'MULTIBANCO';
    
    /** @var string */
    protected $paymentCode         = 'novalnetmultibanco';

    /** @var string */
    protected $paymentHandler      = NovalnetMultibanco::class;
    
    /** @var int */
    protected $position            = -1001;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Multibanco',
            'description' => 'Nach erfolgreichem Bestellabschluss erhalten Sie eine Zahlungsreferenz. Damit können Sie entweder an einem Multibanco-Geldautomaten oder im Onlinebanking bezahlen.',
        ],
        'en-GB' => [
            'name'        => 'Multibanco',
            'description' => 'On successful checkout, you will receive a payment reference. Using this payment reference, you can either pay in the Multibanco ATM or through your online bank account',
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
             if(!empty($dataBag->get('isBackendOrderCreation')))
			{
				$this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
			}
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
        return $this->helper->formMultibancoDetails($response, $context);
    }
}
