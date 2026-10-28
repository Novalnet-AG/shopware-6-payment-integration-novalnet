<?php

declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Subscriber\Administration;

use Shopware\Core\Checkout\Document\Zugferd\ZugferdInvoiceGeneratedEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final class ZugferdSubscriber implements EventSubscriberInterface
{
    public static function getSubscribedEvents(): array
    {
        return [
            ZugferdInvoiceGeneratedEvent::class => 'generateInvoice',
        ];
    }

    public function generateInvoice( ZugferdInvoiceGeneratedEvent $event ): void {

        $transaction = $event->order->getTransactions()?->last();

        if ($transaction === null) {
            return;
        }
        $paymentMethod = $transaction->getPaymentMethod();

        if ($paymentMethod === null) {
            return;
        }

        $novalnetZugferdInvoiceComments = $transaction->getCustomFields()['novalnet_zugferd_invoice_comments'] ?? null;

        if (empty($novalnetZugferdInvoiceComments)) {
            return;
        }

        $paymentMeans = is_array($novalnetZugferdInvoiceComments)
            ? $novalnetZugferdInvoiceComments
            : json_decode((string) $novalnetZugferdInvoiceComments, true);

        if (!is_array($paymentMeans) || empty($paymentMeans['typeCode'])) {
            return;
        }

        $event->document->getBuilder()->addDocumentPaymentMean(...$paymentMeans);
    }

}