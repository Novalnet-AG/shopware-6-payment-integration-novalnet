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

class NovalnetApplePay extends AbstractNovalnetPaymentHandler implements SynchronousPaymentHandlerInterface
{
    
    /** @var string */
    protected $novalnetPaymentType = 'APPLEPAY';
    
    /** @var string */
    protected $paymentCode         = 'novalnetapplepay';

    /** @var string */
    protected $paymentHandler      = NovalnetApplePay::class;
    
    /** @var int */
    protected $position            = -1022;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Apple Pay',
            'description' => 'Der Betrag wird nach erfolgreicher Authentifizierung von Ihrer Karte abgebucht',
        ],
        'en-GB' => [
            'name'        => 'Apple Pay',
            'description' => 'Amount will be booked from your card after successful authentication',
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
        // Method that sends the return URL to the external gateway and gets a redirect URL back
        try {
            $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
            if (version_compare($this->helper->getShopVersion(), '6.4.2.0', '>=')) {
				$this->contextPersister->save($transaction->getOrderTransaction()->getId(), ['novalnetResponse' => $response], $salesChannelContext->getSalesChannel()->getId());
			}
            $this->sessionInterface->set('novalnetResponse', $response);
            $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
        } catch (\Exception $e) {
            throw new SyncPaymentProcessException($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
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
        if ($this->sessionInterface->has('novalnetapplepayFormData') && \version_compare($this->helper->getShopVersion(), '6.4.0', '<')) {
            $sessionData = $this->sessionInterface->get('novalnetapplepayFormData');
        } elseif (!empty($data->get('novalnetapplepayFormData'))) {
			$sessionData = $data->get('novalnetapplepayFormData')->all();
		} elseif (!empty($_REQUEST['novalnetapplepayFormData'])) {
			$sessionData = $_REQUEST['novalnetapplepayFormData'];
		}
		
        if (! empty($sessionData['walletToken'])) {
            $parameters['transaction'] ['payment_data']['wallet_token'] = $sessionData['walletToken'];
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
