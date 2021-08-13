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

class NovalnetPrzelewy24 extends AbstractNovalnetPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    
    /** @var string */
    protected $novalnetPaymentType = 'PRZELEWY24';
    
    /** @var string */
    protected $paymentCode         = 'novalnetprzelewy24';

    /** @var string */
    protected $paymentHandler      = NovalnetPrzelewy24::class;
    
    /** @var int */
    protected $position            = -1009;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Przelewy24',
            'description' => 'Sie werden zu Przelewy24 weitergeleitet. Um eine erfolgreiche Zahlung zu gewährleisten, darf die Seite nicht geschlossen oder neu geladen werden, bis die Bezahlung abgeschlossen ist.',
        ],
        'en-GB' => [
            'name'        => 'Przelewy24',
            'description' => 'You will be redirected to Przelewy24. Please don’t close or refresh the browser until the payment is completed',
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
        // Method that sends the return URL to the external gateway and gets a redirect URL back
        try {
            $redirectUrl = '';
            $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
            if (!empty($response['result']['redirect_url'])) {
				if (version_compare($this->helper->getShopVersion(), '6.4.2.0', '>=')) {
					$this->contextPersister->save($transaction->getOrderTransaction()->getId(), ['novalnetTxnSecret' => $response['transaction']['txn_secret']], $salesChannelContext->getSalesChannel()->getId());
				}
                $this->sessionInterface->set('novalnetTxnSecret', $response['transaction']['txn_secret']);
                $redirectUrl = $response['result']['redirect_url'];
            }
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }
        
        // Redirect to external gateway
        return new RedirectResponse($redirectUrl);
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
        $response = $this->handleRedirectResponse($request, $salesChannelContext, $transaction->getOrderTransaction());
        $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
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
        $this->redirectParameters($transaction, $parameters);
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
