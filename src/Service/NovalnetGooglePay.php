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
use Shopware\Core\Checkout\Payment\PaymentException;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

class NovalnetGooglePay extends AbstractNovalnetPaymentHandler implements SynchronousPaymentHandlerInterface
{
    /** @var string */
    protected $novalnetPaymentType = 'GOOGLEPAY';

    /** @var string */
    protected $paymentCode         = 'novalnetgooglepay';

    /** @var string */
    protected $paymentHandler      = NovalnetGooglePay::class;

    /** @var int */
    protected $position            = -1021;

    /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Google Pay',
            'description' => 'Der Betrag wird nach erfolgreicher Authentifizierung von Ihrer Karte abgebucht',
        ],
        'en-GB' => [
            'name'        => 'Google Pay',
            'description' => 'Amount will be booked from your card after successful authentication',
        ],
    ];


    /**
     * The pay function will be called after the customer completed the order.
     * Allows to process the order and store additional information.
     *
     * A redirect to the url will be performed
     *
     * Throw a @see PaymentException::PAYMENT_SYNC_PROCESS_INTERRUPTED exception if an error ocurres while processing the payment
     *
     * @param SyncPaymentTransactionStruct $transaction
     * @param RequestDataBag $dataBag
     * @param SalesChannelContext $salesChannelContext
     *
     * @throws PaymentException
     */
    public function pay(SyncPaymentTransactionStruct $transaction, RequestDataBag $dataBag, SalesChannelContext $salesChannelContext): void
    {
        // Method that sends the return URL to the external gateway and gets a redirect URL back
        try {
            $response = $this->handlePaymentProcess($salesChannelContext, $transaction, $dataBag);
            $this->helper->setSession('novalnetResponse', $response);
            if (empty($response['result']['redirect_url'])) {
                $this->checkTransactionStatus($transaction->getOrderTransaction(), $response, $salesChannelContext, $transaction);
            } elseif (!empty($response['transaction']['txn_secret'])) {
                $this->helper->setSession('novalnetTxnSecret', $response['transaction']['txn_secret']);
            }
        } catch (\Exception $e) {
            throw PaymentException::syncProcessInterrupted($transaction->getOrderTransaction()->getId(), 'An error occurred during the communication with external payment gateway' . PHP_EOL . $e->getMessage());
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
     * @return boolean
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
     * @param string $salesChannelId
     *
     * @return void
     */
    public function generatePaymentParameters($transaction, RequestDataBag $data = null, array &$parameters, string $salesChannelId): void
    {
        $sessionData = [];

        if (!empty($data->get('novalnetgooglepayFormData'))) {
            $sessionData = $data->get('novalnetgooglepayFormData')->all();
        } elseif (!empty($_REQUEST['novalnetgooglepayFormData'])) {
            $sessionData = $_REQUEST['novalnetgooglepayFormData'];
        }

        $enforce3D  = $this->helper->getNovalnetPaymentSettings('NovalnetPayment.settings.googlepayEnforcecc3D', $salesChannelId);

        if ($data->get('isRecurringOrder') == null) {
            if (! empty($sessionData['walletToken'])) {
                $parameters['transaction'] ['payment_data']['wallet_token'] = $sessionData['walletToken'];
            }

            if ($data->get('isSubscriptionOrder') == 1) {
                $parameters['transaction']['create_token'] = 1;
            }

            if (!empty($sessionData['doRedirect']) && $sessionData['doRedirect'] == 'true') {
                $this->helper->getRedirectParams($parameters);
            }

            if (!empty($enforce3D)) {
                $parameters['transaction']['enforce_3d'] = 1;
            }
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
