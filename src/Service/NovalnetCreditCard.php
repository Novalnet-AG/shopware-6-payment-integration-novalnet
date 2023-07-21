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

class NovalnetCreditCard extends AbstractNovalnetPaymentHandler implements AsynchronousPaymentHandlerInterface
{
    protected $novalnetPaymentType = 'CREDITCARD';
    
    /** @var string */
    protected $paymentCode = 'novalnetcreditcard';
    
    /** @var string */
    protected $paymentHandler = NovalnetCreditCard::class;

    /** @var array */
    protected $translations = [
        'de-DE' => [
            'name'        => 'Kredit- / Debitkarte',
            'description' => 'Ihre Karte wird nach Bestellabschluss sofort belastet',
        ],
        'en-GB' => [
            'name'        => 'Credit/Debit Cards',
            'description' => 'Your credit/debit card will be charged immediately after the order is completed',
        ],
    ];

    /** @var int */
    protected $position = -1023;
    
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
        } catch (\Exception $e) {
            throw new AsyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway'.PHP_EOL.$e->getMessage());
        }
        
        if (!empty($response['result']['redirect_url'])) {
            $this->helper->setSession('novalnetTxnSecret', $response['transaction']['txn_secret']);
            
            // Redirect to external gateway
            return new RedirectResponse($response['result']['redirect_url']);
        }
        
        $this->helper->setSession($this->paymentCode . 'Response', $response);
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
        try {
            $response = $this->helper->getSession($this->paymentCode . 'Response');
            if (empty($response)) {
                $response = $this->handleRedirectResponse($request, $salesChannelContext, $transaction->getOrderTransaction());
            }
            $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
        } catch (\Exception $e) {
            throw new AsyncPaymentFinalizeException($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
        }
    }
    
    /**
     * The recurring function will be called during recurring payment.
     * Allows to process the order and store additional information.
     *
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws AsyncPaymentProcessException
     */
    public function recurring(AsyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): bool
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
     * @param AsyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $data
     * @param array $parameters
     * @param string $salesChannelId
     */
    public function generatePaymentParameters($transaction, RequestDataBag $data = null, array &$parameters, string $salesChannelId): void
    {
        $sessionData = [];
        if ($this->helper->hasSession('novalnetcreditcardFormData') && \version_compare($this->helper->getShopVersion(), '6.4.0', '<')) {
            $sessionData = $this->helper->getSession('novalnetcreditcardFormData');
        } elseif (!empty($data->get('novalnetcreditcardFormData'))) {
            $sessionData = $data->get('novalnetcreditcardFormData')->all();
        } elseif (!empty($_REQUEST['novalnetcreditcardFormData'])) {
            $sessionData = $_REQUEST['novalnetcreditcardFormData'];
        }
        
        if (!empty($data->get('isSubscriptionOrder'))) {
            $this->helper->setSession('isSubscriptionOrder', true);
        };
        
        $this->setPaymentToken('novalnetcreditcard', $data, $sessionData, $parameters);
        
        if (! empty($sessionData) && empty($parameters ['transaction'] ['payment_data'] ['token'])) {
            foreach ([
                'panhash' => 'pan_hash',
                'uniqueid' => 'unique_id',
            ] as $key => $value) {
                if (! empty($sessionData[$key])) {
                    $parameters['transaction'] ['payment_data'][$value] = $sessionData[$key];
                }
            }
        }
        
        $enforce3D  = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.creditcardEnforcecc3D', $salesChannelId);
        
        if (!empty($sessionData['doRedirect'])) {
            if (!empty($enforce3D)) {
                $parameters['transaction']['enforce_3d'] = 1;
            }
            $this->redirectParameters($transaction, $parameters);
        }
    }
    
    /**
     * Prepare transaction comments
     *
     * @param array $response
     * @param Context $context
     * @param string|null $languageId
     *
     * @return string
     */
    public function prepareComments(array $response, Context $context, string $languageId = null): string
    {
        return $this->helper->formBasicComments($response, $context, $languageId);
    }
}
