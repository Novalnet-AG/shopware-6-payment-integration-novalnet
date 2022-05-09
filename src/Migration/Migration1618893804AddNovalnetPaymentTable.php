<?php declare(strict_types=1);

namespace Novalnet\NovalnetPayment\Migration;

use Doctrine\DBAL\Connection;
use Shopware\Core\Framework\Migration\MigrationStep;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\Defaults;

class Migration1618893804AddNovalnetPaymentTable extends MigrationStep
{
    public function getCreationTimestamp(): int
    {
        return 1618893804;
    }

    public function update(Connection $connection): void
    {
		 $this->createMailEvents($connection);
        $connection->executeUpdate('
            CREATE TABLE IF NOT EXISTS `novalnet_transaction_details` (
              `id` binary(16) NOT NULL,
              `tid` BIGINT(20) UNSIGNED DEFAULT NULL COMMENT "Novalnet Transaction Reference ID",
              `payment_type` VARCHAR(50) DEFAULT NULL COMMENT "Executed Payment type of this order",
              `amount` INT(11) UNSIGNED DEFAULT 0 COMMENT "Transaction amount",
              `currency` VARCHAR(11) DEFAULT NULL COMMENT "Transaction currency",
              `paid_amount` INT(11)  UNSIGNED DEFAULT 0 COMMENT "Paid amount",
              `refunded_amount` INT(11) UNSIGNED DEFAULT 0 COMMENT "Refunded amount",
              `gateway_status` VARCHAR(30) DEFAULT NULL COMMENT "Novalnet transaction status",
              `order_no` VARCHAR(64) DEFAULT NULL COMMENT "Order ID from shop",
              `customer_no` VARCHAR(255) COMMENT "Customer Number from shop",
              `additional_details` LONGTEXT DEFAULT NULL COMMENT "Additional details",
              `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Created date",
              `updated_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Updated date",
              PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Novalnet Transaction History"
        ');
        
        $connection->executeUpdate('
            CREATE TABLE IF NOT EXISTS `novalnet_payment_token` (
			  `id` binary(16) NOT NULL,
			  `customer_id` binary(16) DEFAULT NULL COMMENT "Customer ID",
			  `payment_type` varchar(255) DEFAULT NULL COMMENT "Payment Type",
			  `account_data` varchar(255) DEFAULT NULL COMMENT "Account information",
			  `type` varchar(32) DEFAULT NULL COMMENT "token type",
			  `token` varchar(256) DEFAULT NULL COMMENT "token",
			  `tid` bigint(20) DEFAULT NULL COMMENT "tid",
			  `expiry_date` datetime DEFAULT NULL COMMENT "Expiry Date",
			  `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Created date",
			  `updated_at` datetime DEFAULT NULL COMMENT "Updated date",
			  PRIMARY KEY (`id`),
			  KEY `customer_id` (`customer_id`),
			  KEY `payment_type` (`payment_type`),
			  KEY `account_data` (`account_data`),
			  KEY `type` (`type`),
			  KEY `expiry_date` (`expiry_date`)
			) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT="Novalnet Payment Token"
        ');
    }

    public function updateDestructive(Connection $connection): void
    {
        // implement update destructive
    }
    
    public function createMailEvents(Connection $connection): void
    {
        $orderCofirmationTemplateId = Uuid::randomBytes();
        $mailTypeId = $this->getMailTypeMapping()['novalnet_order_confirmation_mail']['id'];
        $deLangId = $enLangId = '';
        
        if ($this->fetchLanguageId('de-DE', $connection) != '') {
            $deLangId = Uuid::fromBytesToHex($this->fetchLanguageId('de-DE', $connection));
        }
        
        if ($this->fetchLanguageId('en-GB', $connection) != '') {
            $enLangId = Uuid::fromBytesToHex($this->fetchLanguageId('en-GB', $connection));
        }
            
        if (!$this->checkMailType($connection)) {
            $connection->insert(
                'mail_template_type',
                [
                'id' => Uuid::fromHexToBytes($mailTypeId),
                'technical_name' => 'novalnet_order_confirmation_mail',
                'available_entities' => json_encode($this->getMailTypeMapping()['novalnet_order_confirmation_mail']['availableEntities']),
                'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            );

            $connection->insert(
                'mail_template',
                [
                    'id' => $orderCofirmationTemplateId,
                    'mail_template_type_id' => Uuid::fromHexToBytes($mailTypeId),
                    'system_default' => 1,
                    'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                ]
            );

            if ($enLangId != '') {
                $connection->insert(
                    'mail_template_translation',
                    [
                        'mail_template_id' => $orderCofirmationTemplateId,
                        'language_id' => Uuid::fromHexToBytes($enLangId),
                        'subject' => 'Order confirmation',
                        'description' => 'Novalnet Order confirmation',
                        'sender_name' => '{{ salesChannel.name }}',
                        'content_html' => $this->getHtmlTemplateEn(),
                        'content_plain' => $this->getPlainTemplateEn(),
                        'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                );
                
                $connection->insert(
                    'mail_template_type_translation',
                    [
                        'mail_template_type_id' => Uuid::fromHexToBytes($mailTypeId),
                        'language_id' => Uuid::fromHexToBytes($enLangId),
                        'name' => 'Order confirmation',
                        'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                );
            }
            
            if ($deLangId != '') {
                $connection->insert(
                    'mail_template_translation',
                    [
                        'mail_template_id' => $orderCofirmationTemplateId,
                        'language_id' => Uuid::fromHexToBytes($deLangId),
                        'subject' => 'Bestellbestätigung',
                        'description' => 'Novalnet Bestellbestätigung',
                        'sender_name' => '{{ salesChannel.name }}',
                        'content_html' => $this->getHtmlTemplateDe(),
                        'content_plain' => $this->getPlainTemplateDe(),
                        'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                );

                $connection->insert(
                    'mail_template_type_translation',
                    [
                        'mail_template_type_id' => Uuid::fromHexToBytes($mailTypeId),
                        'language_id' => Uuid::fromHexToBytes($deLangId),
                        'name' => 'Bestellbestätigung',
                        'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                );
            }
            
            if (!in_array(Defaults::LANGUAGE_SYSTEM, [$enLangId, $deLangId])) {
                $connection->insert(
                    'mail_template_translation',
                    [
                        'mail_template_id' => $orderCofirmationTemplateId,
                        'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                        'subject' => 'Order confirmation',
                        'description' => 'Novalnet Order confirmation',
                        'sender_name' => '{{ salesChannel.name }}',
                        'content_html' => $this->getHtmlTemplateEn(),
                        'content_plain' => $this->getPlainTemplateEn(),
                        'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                );
                
                $connection->insert(
                    'mail_template_type_translation',
                    [
                        'mail_template_type_id' => Uuid::fromHexToBytes($mailTypeId),
                        'language_id' => Uuid::fromHexToBytes(Defaults::LANGUAGE_SYSTEM),
                        'name' => 'Order confirmation',
                        'created_at' => (new \DateTime())->format(Defaults::STORAGE_DATE_TIME_FORMAT),
                    ]
                );
            }
        }
    }

    private function getMailTypeMapping(): array
    {
        return[
            'novalnet_order_confirmation_mail' => [
                'id' => Uuid::randomHex(),
                'name' => 'Order confirmation',
                'nameDe' => 'Bestellbestätigung',
                'availableEntities' => ['order' => 'order', 'salesChannel' => 'sales_channel'],
            ],
        ];
    }

    private function getHtmlTemplateEn(): string
    {
        return '<div style="font-family:Arial, Helvetica, sans-serif; font-size:12px;">
    
{% set currencyIsoCode = order.currency.isoCode %}
{{order.orderCustomer.salutation.letterName }} {{order.orderCustomer.firstName}} {{order.orderCustomer.lastName}},<br>
<br>
{% if instalment == false %}
Thank you for your order at {{ salesChannel.name }} (Number: {{order.orderNumber}}) on {{ order.orderDateTime|date }}.<br>
{% else %}
The next instalment cycle have arrived for the instalment order (OrderNumber: {{order.orderNumber}}) placed at the store {{ salesChannel.name }} on {{ order.orderDateTime|date }}.<br>
{% endif %}
<br>
<strong>Information on your order:</strong><br>
<br>

<table width="80%" border="0" style="font-family:Arial, Helvetica, sans-serif; font-size:12px;">
    <tr>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Pos.</strong></td>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Description</strong></td>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Quantities</strong></td>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Price</strong></td>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Total</strong></td>
    </tr>

    {% for lineItem in order.lineItems %}
    <tr>
        <td style="border-bottom:1px solid #cccccc;">{{ loop.index }} </td>
        <td style="border-bottom:1px solid #cccccc;">
          {{ lineItem.label|u.wordwrap(80) }}<br>
          Art. No.: {{ lineItem.payload.productNumber|u.wordwrap(80) }}
        </td>
        <td style="border-bottom:1px solid #cccccc;">{{ lineItem.quantity }}</td>
        <td style="border-bottom:1px solid #cccccc;">{{ lineItem.unitPrice|currency(currencyIsoCode) }}</td>
        <td style="border-bottom:1px solid #cccccc;">{{ lineItem.totalPrice|currency(currencyIsoCode) }}</td>
    </tr>
    {% endfor %}
</table>

{% set delivery =order.deliveries.first %}
<p>
    <br>
    <br>
    Shipping costs: {{order.deliveries.first.shippingCosts.totalPrice|currency(currencyIsoCode) }}<br>
    Net total: {{ order.amountNet|currency(currencyIsoCode) }}<br>
        {% for calculatedTax in order.price.calculatedTaxes %}
			{% if order.taxStatus is same as(\'net\') %}plus{% else %}including{% endif %} {{ calculatedTax.taxRate }}% VAT. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
		{% endfor %}
        <strong>Total gross: {{ order.amountTotal|currency(currencyIsoCode) }}</strong><br>
    <br>
    
    <strong>Selected payment type:</strong> {{ order.transactions.first.paymentMethod.name }}<br>
    {{ order.transactions.first.paymentMethod.description }}<br>
    <br>
    
	<strong>Comments:</strong><br>
    {{ note|replace({"/ ": "<br>"}) | raw }}<br>
	<br>
	
	{% if "NovalnetInvoiceInstalment" in order.transactions.first.paymentMethod.handlerIdentifier or "NovalnetSepaInstalment" in order.transactions.first.paymentMethod.handlerIdentifier %}
			{% if instalmentInfo is not empty %}
				<table width="40%" style="font-family:Arial, Helvetica, sans-serif; border: 1px solid;border-color: #bcc1c7;text-align: center;font-size:12px;">
					<thead style="font-weight: bold;">
						<tr>
							<td style="border-bottom:1px solid #cccccc;">S.No</td>
							<td style="border-bottom:1px solid #cccccc;">Date</td>
							<td style="border-bottom:1px solid #cccccc;">Novalnet Transaction ID</td>
							<td style="border-bottom:1px solid #cccccc;">Amount</td>
						<tr>
					</thead>
					<tbody>
						{% for info in instalmentInfo.InstalmentDetails %}
							{%set amount = info.amount/100 %}
							<tr>
								<td style="border-bottom:1px solid #cccccc;">{{ loop.index }}</td>
								<td style="border-bottom:1px solid #cccccc;">{{ info.cycleDate ? info.cycleDate|date("d/m/Y"): "-" }}</td>
								<td style="border-bottom:1px solid #cccccc;">{{ info.reference ? info.reference : "-" }}</td>
								<td style="border-bottom:1px solid #cccccc;">{{ amount ? amount|currency(currencyIsoCode): "-" }}</td>
							<tr>
						{% endfor %}
					</tbody>
				</table>
				<br>
			{% endif %}
	{% endif %}
    
    <strong>Selected shipping type:</strong> {{ delivery.shippingMethod.name }}<br>
    {{ delivery.shippingMethod.description }}<br>
    <br>
    
    {% set billingAddress = order.addresses.get(order.billingAddressId) %}
    <strong>Billing address:</strong><br>
    {{ billingAddress.company }}<br>
    {{ billingAddress.firstName }} {{ billingAddress.lastName }}<br>
    {{ billingAddress.street }} <br>
    {{ billingAddress.zipcode }} {{ billingAddress.city }}<br>
    {{ billingAddress.country.name }}<br>
    <br>
    
    <strong>Shipping address:</strong><br>
    {{ delivery.shippingOrderAddress.company }}<br>
    {{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}<br>
    {{ delivery.shippingOrderAddress.street }} <br>
    {{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}<br>
    {{ delivery.shippingOrderAddress.country.name }}<br>
    <br>
    {% if billingAddress.vatId %}

      Your VAT-ID: {{ billingAddress.vatId }}
      In case of a successful order and if you are based in one of the EU countries, you will receive your goods exempt from turnover tax.<br>
    {% endif %}
    <br>
    
    If you have any questions, do not hesitate to contact us.

</p>
<br>
</div>';
    }

    private function getPlainTemplateEn(): string
    {
        return '{% set currencyIsoCode = order.currency.isoCode %}
{{ order.orderCustomer.salutation.letterName }} {{order.orderCustomer.firstName}} {{order.orderCustomer.lastName}},

{% if instalment == false %}
Thank you for your order at {{ salesChannel.name }} (Number: {{order.orderNumber}}) on {{ order.orderDateTime|date }}.
{% else %}
The next instalment cycle have arrived for the instalment order (OrderNumber: {{order.orderNumber}}) placed at the store {{ salesChannel.name }} on {{ order.orderDateTime|date }}.
{% endif %}

Information on your order:

Pos.   Art.No.			Description			Quantities			Price			Total 

{% for lineItem in order.lineItems %}
{{ loop.index }}      {{ lineItem.payload.productNumber|u.wordwrap(80) }}				{{ lineItem.label|u.wordwrap(80) }}			{{ lineItem.quantity }}			{{ lineItem.unitPrice|currency(currencyIsoCode) }}			{{ lineItem.totalPrice|currency(currencyIsoCode) }}
{% endfor %}

{% set delivery = order.deliveries.first %}

Shipping costs: {{order.deliveries.first.shippingCosts.totalPrice|currency(currencyIsoCode) }}
Net total: {{ order.amountNet|currency(currencyIsoCode) }}
	{% for calculatedTax in order.price.calculatedTaxes %}
        {% if order.taxStatus is same as(\'net\') %}plus{% else %}including{% endif %} {{ calculatedTax.taxRate }}% VAT. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
     {% endfor %}
	Total gross: {{ order.amountTotal|currency(currencyIsoCode) }}

Selected payment type: {{ order.transactions.first.paymentMethod.name }}
{{ order.transactions.first.paymentMethod.description }}

Comments:
{{ note|replace({"/ ": "<br>"}) | raw }}

{% if "NovalnetInvoiceInstalment" in order.transactions.first.paymentMethod.handlerIdentifier or "NovalnetSepaInstalment" in order.transactions.first.paymentMethod.handlerIdentifier %}
		{% if instalmentInfo is not empty %}
				S.No.   Date			Novalnet Transaction ID			Amount
					{% for info in instalmentInfo.InstalmentDetails %}
						{%set amount = info.amount/100 %}
							{{ loop.index }} {{ info.cycleDate ? info.cycleDate|date("d/m/Y"): "-" }} {{ info.reference ? info.reference : "-" }} {{ amount ? amount|currency(currencyIsoCode): "-" }}
					{% endfor %}
		{% endif %}
{% endif %}

Selected shipping type: {{ delivery.shippingMethod.name }}
{{ delivery.shippingMethod.description }}

{% set billingAddress = order.addresses.get(order.billingAddressId) %}
Billing address:
{{ billingAddress.company }}
{{ billingAddress.firstName }} {{ billingAddress.lastName }}
{{ billingAddress.street }}
{{ billingAddress.zipcode }} {{ billingAddress.city }}
{{ billingAddress.country.name }}

Shipping address:
{{ delivery.shippingOrderAddress.company }}
{{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}
{{ delivery.shippingOrderAddress.street }} 
{{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}
{{ delivery.shippingOrderAddress.country.name }}

{% if billingAddress.vatId %}
Your VAT-ID: {{ billingAddress.vatId }}
In case of a successful order and if you are based in one of the EU countries, you will receive your goods exempt from turnover tax.
{% endif %}

If you have any questions, do not hesitate to contact us.

';
    }

    private function getHtmlTemplateDe(): string
    {
        return '<div style="font-family:Arial, Helvetica, sans-serif; font-size:12px;">
    
{% set currencyIsoCode = order.currency.isoCode %}
{{order.orderCustomer.salutation.letterName }} {{order.orderCustomer.firstName}} {{order.orderCustomer.lastName}},<br>
<br>
{% if instalment == false %}
vielen Dank für Ihre Bestellung im {{ salesChannel.name }} (Nummer: {{order.orderNumber}}) am {{ order.orderDateTime|date }}.<br>
{% else %}
Für Ihre (Bestellung Nr: {{order.orderNumber}}) bei {{ salesChannel.name }}, ist die nächste Rate fällig. Bitte beachten Sie weitere Details unten am {{ order.orderDateTime|date }}.<br>
{% endif %}
<br>
<strong>Informationen zu Ihrer Bestellung:</strong><br>
<br>

<table width="80%" border="0" style="font-family:Arial, Helvetica, sans-serif; font-size:12px;">
    <tr>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Pos.</strong></td>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Bezeichnung</strong></td>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Menge</strong></td>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Preis</strong></td>
        <td bgcolor="#F7F7F2" style="border-bottom:1px solid #cccccc;"><strong>Summe</strong></td>
    </tr>

    {% for lineItem in order.lineItems %}
    <tr>
        <td style="border-bottom:1px solid #cccccc;">{{ loop.index }} </td>
        <td style="border-bottom:1px solid #cccccc;">
          {{ lineItem.label|u.wordwrap(80) }}<br>
          Artikel-Nr: {{ lineItem.payload.productNumber|u.wordwrap(80) }}
        </td>
        <td style="border-bottom:1px solid #cccccc;">{{ lineItem.quantity }}</td>
        <td style="border-bottom:1px solid #cccccc;">{{ lineItem.unitPrice|currency(currencyIsoCode) }}</td>
        <td style="border-bottom:1px solid #cccccc;">{{ lineItem.totalPrice|currency(currencyIsoCode) }}</td>
    </tr>
    {% endfor %}
</table>

{% set delivery =order.deliveries.first %}
<p>
    <br>
    <br>
    Versandkosten: {{order.deliveries.first.shippingCosts.totalPrice|currency(currencyIsoCode) }}<br>
    Gesamtkosten Netto: {{ order.amountNet|currency(currencyIsoCode) }}<br>
        {% for calculatedTax in order.price.calculatedTaxes %}
			{% if order.taxStatus is same as(\'net\') %}plus{% else %}including{% endif %} {{ calculatedTax.taxRate }}% VAT. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
		{% endfor %}
        <strong>Gesamtkosten Brutto: {{ order.amountTotal|currency(currencyIsoCode) }}</strong><br>
    <br>
    
    <strong>Gewählte Zahlungsart:</strong> {{ order.transactions.first.paymentMethod.name }}<br>
    {{ order.transactions.first.paymentMethod.description }}<br>
    <br>
    
    <strong>Kommentare:</strong><br>
    {{ note|replace({"/ ": "<br>"}) | raw }}<br>
    <br>
    
    {% if "NovalnetInvoiceInstalment" in order.transactions.first.paymentMethod.handlerIdentifier or "NovalnetSepaInstalment" in order.transactions.first.paymentMethod.handlerIdentifier %}
			{% if instalmentInfo is not empty %}
				<table width="40%" style="font-family:Arial, Helvetica, sans-serif; border: 1px solid;border-color: #bcc1c7;text-align: center;font-size:12px;">
					<thead style="font-weight: bold;">
						<tr>
							<td style="border-bottom:1px solid #cccccc;">S.Nr</td>
							<td style="border-bottom:1px solid #cccccc;">Datum</td>
							<td style="border-bottom:1px solid #cccccc;">Novalnet-Transaktions-ID</td>
							<td style="border-bottom:1px solid #cccccc;">Betrag</td>
						<tr>
					</thead>
					<tbody>
						{% for info in instalmentInfo.InstalmentDetails %}
							{%set amount = info.amount/100 %}
							<tr>
								<td style="border-bottom:1px solid #cccccc;">{{ loop.index }}</td>
								<td style="border-bottom:1px solid #cccccc;">{{ info.cycleDate ? info.cycleDate|date("d/m/Y"): "-" }}</td>
								<td style="border-bottom:1px solid #cccccc;">{{ info.reference ? info.reference : "-" }}</td>
								<td style="border-bottom:1px solid #cccccc;">{{ amount ? amount|currency(currencyIsoCode): "-" }}</td>
							<tr>
						{% endfor %}
					</tbody>
				</table>
				<br>
			{% endif %}
	{% endif %}
    
    <strong>Gewählte Versandtart:</strong> {{ delivery.shippingMethod.name }}<br>
    {{ delivery.shippingMethod.description }}<br>
    <br>
    
    {% set billingAddress = order.addresses.get(order.billingAddressId) %}
    <strong>Rechnungsaddresse:</strong><br>
    {{ billingAddress.company }}<br>
    {{ billingAddress.firstName }} {{ billingAddress.lastName }}<br>
    {{ billingAddress.street }} <br>
    {{ billingAddress.zipcode }} {{ billingAddress.city }}<br>
    {{ billingAddress.country.name }}<br>
    <br>
    
    <strong>Lieferadresse:</strong><br>
    {{ delivery.shippingOrderAddress.company }}<br>
    {{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}<br>
    {{ delivery.shippingOrderAddress.street }} <br>
    {{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}<br>
    {{ delivery.shippingOrderAddress.country.name }}<br>
    <br>
    
    {% if billingAddress.vatId %}
        Ihre Umsatzsteuer-ID: {{ billingAddress.vatId }}
        Bei erfolgreicher Prüfung und sofern Sie aus dem EU-Ausland
        bestellen, erhalten Sie Ihre Ware umsatzsteuerbefreit. <br>
    {% endif %}
    <br>
    
    Für Rückfragen stehen wir Ihnen jederzeit gerne zur Verfügung.

</p>
<br>
</div>';
    }

    private function getPlainTemplateDe(): string
    {
        return '{% set currencyIsoCode = order.currency.isoCode %}
{{order.orderCustomer.salutation.letterName }} {{order.orderCustomer.firstName}} {{order.orderCustomer.lastName}},

{% if instalment == false %}
vielen Dank für Ihre Bestellung im {{ salesChannel.name }} (Nummer: {{order.orderNumber}}) am {{ order.orderDateTime|date }}.
{% else %}
Für Ihre (Bestellung Nr: {{order.orderNumber}}) bei {{ salesChannel.name }}, ist die nächste Rate fällig. Bitte beachten Sie weitere Details unten am {{ order.orderDateTime|date }}.
{% endif %}

Informationen zu Ihrer Bestellung:

Pos.   Artikel-Nr.			Beschreibung			Menge			Preis			Summe
{% for lineItem in order.lineItems %}
{{ loop.index }}     {{ lineItem.payload.productNumber|u.wordwrap(80) }}				{{ lineItem.label|u.wordwrap(80) }}			{{ lineItem.quantity }}			{{ lineItem.unitPrice|currency(currencyIsoCode) }}			{{ lineItem.totalPrice|currency(currencyIsoCode) }}
{% endfor %}

{% set delivery =order.deliveries.first %}

Versandtkosten: {{order.deliveries.first.shippingCosts.totalPrice|currency(currencyIsoCode) }}
Gesamtkosten Netto: {{ order.amountNet|currency(currencyIsoCode) }}
	 {% for calculatedTax in order.price.calculatedTaxes %}
        {% if order.taxStatus is same as(\'net\') %}plus{% else %}including{% endif %} {{ calculatedTax.taxRate }}% VAT. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
     {% endfor %}
	Gesamtkosten Brutto: {{ order.amountTotal|currency(currencyIsoCode) }}

Gewählte Zahlungsart: {{ order.transactions.first.paymentMethod.name }}
{{ order.transactions.first.paymentMethod.description }}

Kommentare:
{{ note|replace({"/ ": "<br>"}) | raw }}

{% if "NovalnetInvoiceInstalment" in order.transactions.first.paymentMethod.handlerIdentifier or "NovalnetSepaInstalment" in order.transactions.first.paymentMethod.handlerIdentifier %}
		{% if instalmentInfo is not empty %}
						S.Nr     Datum     Novalnet-Transaktions-ID    Betrag
					{% for info in instalmentInfo.InstalmentDetails %}
						{%set amount = info.amount/100 %}
						{{ loop.index }}     {{ info.cycleDate ? info.cycleDate|date("d/m/Y"): "-" }}    {{ info.reference ? info.reference : "-" }}     {{ amount ? amount|currency(currencyIsoCode): "-" }}
					{% endfor %}
		{% endif %}
{% endif %}

Gewählte Versandtart: {{ delivery.shippingMethod.name }}
{{ delivery.shippingMethod.description }}

{% set billingAddress = order.addresses.get(order.billingAddressId) %}
Rechnungsadresse:
{{ billingAddress.company }}
{{ billingAddress.firstName }} {{ billingAddress.lastName }}
{{ billingAddress.street }}
{{ billingAddress.zipcode }} {{ billingAddress.city }}
{{ billingAddress.country.name }}

Lieferadresse:
{{ delivery.shippingOrderAddress.company }}
{{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}
{{ delivery.shippingOrderAddress.street }} 
{{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}
{{ delivery.shippingOrderAddress.country.name }}

{% if billingAddress.vatId %}
Ihre Umsatzsteuer-ID: {{ billingAddress.vatId }}
Bei erfolgreicher Prüfung und sofern Sie aus dem EU-Ausland
bestellen, erhalten Sie Ihre Ware umsatzsteuerbefreit.
{% endif %}
    
Für Rückfragen stehen wir Ihnen jederzeit gerne zur Verfügung.

';
    }

    private function fetchLanguageId(string $code, Connection $connection): ?string
    {
        /** @var string|null $langId */
        $langId = $connection->fetchColumn('
        SELECT `language`.`id` FROM `language` INNER JOIN `locale` ON `language`.`locale_id` = `locale`.`id` WHERE `code` = :code LIMIT 1
        ', ['code' => $code]);

        if (!$langId) {
            return null;
        }

        return $langId;
    }

    private function checkMailType(Connection $connection): ?bool
    {
        $mailTypeId = $connection->fetchColumn('
        SELECT `id` FROM `mail_template_type` WHERE `technical_name` = :technical_name LIMIT 1
        ', ['technical_name' => 'novalnet_order_confirmation_mail']);

        if (!$mailTypeId) {
            return false;
        }

        return true;
    }
}
