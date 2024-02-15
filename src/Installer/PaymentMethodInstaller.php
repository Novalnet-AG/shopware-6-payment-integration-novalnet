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

namespace Novalnet\NovalnetPayment\Installer;

use Novalnet\NovalnetPayment\Service\NovalnetPayment;
use Doctrine\DBAL\Connection;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsAnyFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\PrefixFilter;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldDefinition;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSet\CustomFieldSetCollection;
use Shopware\Core\System\CustomField\Aggregate\CustomFieldSetRelation\CustomFieldSetRelationEntity;
use Shopware\Core\System\CustomField\CustomFieldCollection;
use Shopware\Core\System\CustomField\CustomFieldEntity;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\Framework\DataAbstractionLayer\Search\IdSearchResult;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Novalnet\NovalnetPayment\Installer\MediaProvider;

/**
 * PaymentMethodInstaller Class.
 */
class PaymentMethodInstaller
{
    /**
     * @var
     */
    private $paymentMethods = NovalnetPayment::class;
      
     /** @var array */
    protected $translations        = [
        'de-DE' => [
            'name'        => 'Novalnet Zahlung',
            'description' => 'Bieten Sie Ihren Kunden auf sichere und vertrauenswürdige Weise alle weltweit unterstützten Zahlungsarten. Mit Novalnet können Sie Ihre Verkäufe steigern und Ihren Kunden ein ansprechendes Zahlungserlebnis aus einem Guss bieten.',
        ],
        'en-GB' => [
            'name'        => 'Novalnet Payment',
            'description' => 'Secured and trusted means of accepting all payment methods supported worldwide. Novalnet provides the most convenient way to increase your sales and deliver seamless checkout experience for your customers.',
        ],
    ];
    
     /**
     * @var array
     */
    
    private $customFields= [
            'name' => 'novalnet',
            'config' => [
                'label' => [
                    'en-GB' => 'Novalnet Comments',
                    'de-DE' => 'Novalnet Comments',
                ],
            ],
            'customFields' => [
                [
                    'name' => 'novalnet_comments',
                    'active' => true,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'componentName' => 'sw-field',
                        'customFieldType' => 'text',
                        'customFieldPosition' => 1,
                        'label' => [
                            'en-GB' => 'Novalnet Coments',
                            'de-DE' => 'Novalnet Kommentare',
                        ],
                    ],
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'order_transaction',
                ]
            ]
        ];
        
        
        private $customFieldsPaymentName= [
            'name' => 'novalnetPaymentName',
            'config' => [
                'label' => [
                    'en-GB' => 'Novalnet Payment',
                    'de-DE' => 'Novalnet Payment',
                ],
            ],
            'customFields' => [
                [
                    'name' => 'novalnet_payment_name',
                    'active' => true,
                    'type' => CustomFieldTypes::TEXT,
                    'config' => [
                        'componentName' => 'sw-field',
                        'customFieldType' => 'text',
                        'customFieldPosition' => 1,
                        'label' => [
                            'en-GB' => 'Novalnet Payment',
                            'de-DE' => 'Novalnet Zahlung',
                        ],
                    ],
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'order_transaction',
                ]
            ]
        ];
        
    /**
    * @var array
    */
        protected $defaultConfiguration = [
        'emailMode'                     => true,
        'onHoldStatus'                  => 'authorized',
        'completeStatus'                => 'paid'
        ];
        
        /**
     * @var Context
     */
        private $context;

    /**
     * @var ContainerInterface
     */
        private $container;

    /**
     * @constant string
     */
        protected const SYSTEM_CONFIG_DOMAIN = 'NovalnetPayment.settings.';

    
    /**
     * Constructs a `PaymentMethodInstaller`
     *
     * @param ContainerInterface $container
     * @param Context $context
     */
        public function __construct(ContainerInterface $container, Context $context)
        {
            $this->context   = $context;
            $this->container = $container;
            $this->mailTemplateRepo = $this->container->get('mail_template.repository');
            $this->mailTemplateTypeRepo = $this->container->get('mail_template_type.repository');
        }
    
    /**
     * Get payment name
     *
     * @param string $locale
     *
     * @return string
     */
        public function getName(string $locale): string
        {
            $translations = $this->getTranslations();
            $name = '';
            if (! empty($translations[$locale]['name'])) {
                $name = $translations[$locale]['name'];
            }
            return $name;
        }
    
    /**
     * Get payment description
     *
     * @param string $locale
     *
     * @return string
     */
        public function getDescription(string $locale): string
        {
            $translations = $this->getTranslations();
            $description = '';
            if (! empty($translations[$locale]['description'])) {
                $description = $translations[$locale]['description'];
            }
            return $description;
        }
    
    /**
     * Get payment translations
     *
     * @return array
     */
        public function getTranslations(): array
        {
            return $this->translations;
        }
    
    /**
     * Add Payment Methods on plugin installation
     *
     */
        public function install(): void
        {
            $this->addPaymentMethods();
            $this->createMailEvents();
            $this->alterPaymentTokenTable();
        }

    /**
     * Add Payment Methods on plugin update process
     *
     */
        public function update(): void
        {
            $this->addPaymentMethods();
            $this->updateMediaData();
            $this->alterPaymentTokenTable();
        }

    /**
     * Add payment logo into media on plugin activation
     *
     */
        public function activate(): void
        {
            $this->updateMediaData();
        }

    /**
     * Deactivate Payment Methods
     *
     */
        public function deactivate(): void
        {
            $this->deactivatePaymentMethods();
        }

    /**
     * Deactivate Payment Methods
     *
     */
        public function uninstall(): void
        {
            $this->deactivatePaymentMethods();
        }

   /**
     * Delete plugin related system configurations.
     *
     */
        public function removeConfiguration(): void
        {
            $systemConfigRepository = $this->container->get('system_config.repository');
            $criteria = (new Criteria())
            ->addFilter(new ContainsFilter('configurationKey', self::SYSTEM_CONFIG_DOMAIN));
            
            $idSearchResult = $systemConfigRepository->searchIds($criteria, $this->context);

            $ids = array_map(static function ($id) {
                return ['id' => $id];
            }, $idSearchResult->getIds());

            $systemConfigRepository->delete($ids, $this->context);
        }
    
    /**
     * Add plugin related Payment Method.
     *
    */
    
        private function addPaymentMethods(): void
        {
            $defaultLocale = (in_array($this->getDefaultLocaleCode(), ['de-DE', 'en-GB'])) ? $this->getDefaultLocaleCode() : 'en-GB';
            $context = $this->context;
            $this->getPaymentMethodEntity();
            $paymentMethodId = $this->getPaymentMethods();
        
            // Skip insertion if the payment already exists.
            if (empty($paymentMethodId)) {
                $translations = $this->getTranslations();
                $paymentData  = [
                [
                    'name'              => $this->getName($defaultLocale),
                    'description'       => $this->getDescription($defaultLocale),
                    'position'          => -1001,
                    'handlerIdentifier' => NovalnetPayment::class,
                    'translations'      => $translations,
                    'afterOrderEnabled' => true,
                    'customFields'      => [
                        'novalnet_payment_method_name' => 'novalnetpay',
                    ],
                ]
                ];

                $this->context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($paymentData): void {
                    $this->container->get('payment_method.repository')->upsert($paymentData, $context);
                });

                $paymentMethodId = $this->getPaymentMethods();
                $channels  = $this->container->get('sales_channel.repository')->searchIds(new Criteria(), $this->context);

                // Enable payment method on available channels.
                if (!empty($this->container->get('sales_channel_payment_method.repository'))) {
                    foreach ($channels->getIds() as $channel) {
                        $data = [
                            'salesChannelId'  => $channel,
                            'paymentMethodId' => $paymentMethodId,
                        ];
                        $this->container->get('sales_channel_payment_method.repository')->upsert([$data], $this->context);
                    }
                }
            }

            $this->updateCustomFieldsForTranslations('novalnetpay', $paymentMethodId);
        
            // Set default configurations value for payment methods
            $customFields = $this->customFields;
            $customFieldExistsId = $this->customFieldsExist('novalnet', $this->context);

            if (!$customFieldExistsId && !empty($this->container->get('custom_field_set.repository'))) {
                $this->container->get('custom_field_set.repository')->upsert([$customFields], $this->context);
            }
        
        
            $customFieldsPaymentName = $this->customFieldsPaymentName;
            $customFieldsPaymentExistsId = $this->customFieldsExist('novalnetPaymentName', $this->context);

            if (!$customFieldsPaymentExistsId && !empty($this->container->get('custom_field_set.repository'))) {
                $this->container->get('custom_field_set.repository')->upsert([$customFieldsPaymentName], $this->context);
            }
              
            $systemConfig = $this->container->get(SystemConfigService::class);

            if (!empty($systemConfig)) {
                foreach ($this->defaultConfiguration as $key => $value) {
                    if (!empty($value)) {
                        $systemConfig->set(self::SYSTEM_CONFIG_DOMAIN . $key, $value);
                    }
                }
            }
        }

    
    /**
     * Get Novalnet Payment method instance
     *
     * @return string
     */
        private function getPaymentMethods(): ?string
        {
             /** @var EntityRepository $paymentRepository */
            $paymentRepository = $this->container->get('payment_method.repository');
            
            // Fetch ID for update
            $paymentCriteria = (new Criteria())->addFilter(new EqualsFilter('handlerIdentifier', NovalnetPayment::class));
            $paymentmethodId = $paymentRepository->searchIds($paymentCriteria, $this->context)->firstId();
  
            return $paymentmethodId;
        }

    /**
     * Deactivate Payment methods
     */
        private function deactivatePaymentMethods(): void
        {
            $paymentMethodId = $this->getPaymentMethods();

            if (!$paymentMethodId) {
                return;
            }

            // Deactivate the payment methods.
            $this->container->get('payment_method.repository')->update([
            [
                'id' => $paymentMethodId,
                'active' => false,
            ],
            ], $this->context);
  
            // Deactivate the custom fields.
            $customFieldExistsId = $this->customFieldsExist('novalnet', $this->context);
            if (!$customFieldExistsId) {
                return;
            }

            $customField = [
            'id' => $customFieldExistsId,
            'active' => false,
            ];
            $this->container->get('custom_field_set.repository')->upsert([$customField], $this->context);
            $customFieldPaymentExistsId = $this->customFieldsExist('novalnetPaymentName', $this->context);
            if (!$customFieldPaymentExistsId) {
                return;
            }

            $customFieldPayment = [
            'id' => $customFieldPaymentExistsId,
            'active' => false,
            ];
            $this->container->get('custom_field_set.repository')->upsert([$customFieldPayment], $this->context);
        }

    /**
     * Get default langauge during plugin installation
     *
     * @return string|null
     */
        private function getDefaultLocaleCode(): ?string
        {
            $criteria = new Criteria([Defaults::LANGUAGE_SYSTEM]);
            $criteria->addAssociation('locale');

            $systemDefaultLanguage = $this->container->get('language.repository')->search($criteria, $this->context)->first();

            $locale = $systemDefaultLanguage->getLocale();
            if (!$locale) {
                return null;
            }
            return $locale->getCode();
        }

    
    /**
     * Update payment custom fields
     *
     * @param string $paymentcode
     * @param string $paymentMethodId
     *
     */
        private function updateCustomFieldsForTranslations(string $paymentcode, string $paymentMethodId): void
        {
            $customFields['novalnet_payment_method_name'] = $paymentcode;
            if (! empty($customFields)) {
                $customFields = json_encode($customFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            /** @var Connection $connection */
            $connection = $this->container->get(Connection::class);

            $connection->exec(sprintf("
            UPDATE `payment_method_translation`
            SET
                `custom_fields` = '%s'
            WHERE
                `custom_fields` IS NULL AND
                `payment_method_id` = UNHEX('%s');
         ", $customFields, $paymentMethodId));
        }
    
    /**
     * payment custom fields Exist
     *
     * @param string $customFieldName
     * @param Context $context
     *
     * @return string|null
     */
   
        private function customFieldsExist(string $customFieldName, Context $context): ?string
        {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('name', $customFieldName));
        
            if (empty($this->container->get('custom_field_set.repository'))) {
                return null;
            }
            $result = $this->container->get('custom_field_set.repository')->searchIds($criteria, $this->context);

            if (!$result->getTotal()) {
                return null;
            }

            $customeFields = $result->getIds();
            return array_shift($customeFields);
        }
    
    /**
     * Delete plugin related mail configuration.
     *
     */
        public function deleteMailSettings(): void
        {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', 'novalnet_order_confirmation_mail'));

            $mailData = $this->mailTemplateRepo->search($criteria, $this->context)->first();

            if ($mailData) {
                // delete subscription mail template
                $this->mailTemplateRepo->delete([['id' => $mailData->getId()]], $this->context);
                $this->mailTemplateTypeRepo->delete([['id' => $mailData->getMailTemplateTypeId()]], $this->context);
            }
        }
    
    /**
     * Prepare and update media files on plugin activation
     *
     * @return void
     */
        private function updateMediaData(): void
        {
            $paymentMethodId = $this->getPaymentMethods();

            if (!$paymentMethodId) {
                return;
            }
            $paymentMethodEntity = $this->getPaymentMethodMedia();
            // Initiate MediaProvider.
            $mediaProvider = $this->container->get(MediaProvider::class);

            if (!is_null($mediaProvider)) {
                if (is_null($paymentMethodEntity->getMediaId())) {
                    $mediaId = $mediaProvider->getMediaId('novalnetpay', $this->context);
                    $this->container->get('payment_method.repository')->update([
                    [
                        'id'       => $paymentMethodId,
                        'mediaId'  => $mediaId,
                    ],
                    ], $this->context);
                }
            }
        }
    
    /**
     * Create/Update novalnet order confirmation mail template
     *
     * @return void
     */
        public function createMailEvents(): void
        {
            $mailType = $this->getMailTemplateType();
            $mailTemplateTypeId = Uuid::randomHex();
            $mailTemplateId = Uuid::randomHex();

            if (!empty($mailType)) {
                $mailTemplateId = $mailType->getId();
                $mailTemplateTypeId = $mailType->getMailTemplateTypeId();
            }

            $this->mailTemplateRepo->upsert([
            [
                'id' => $mailTemplateId,
                'translations' => [
                    'de-DE' => [
                        'subject' => 'Bestellbestätigung',
                        'contentHtml' => $this->getHtmlTemplateDe(),
                        'contentPlain'=> $this->getPlainTemplateDe(),
                        'description' => 'Novalnet Bestellbestätigung',
                        'senderName'  => '{{ salesChannel.name }}',
                    ],
                    'en-GB' => [
                        'subject' => 'Order confirmation',
                        'contentHtml' => $this->getHtmlTemplateEn(),
                        'contentPlain'=> $this->getPlainTemplateEn(),
                        'description' => 'Novalnet Order confirmation',
                        'senderName'  => '{{ salesChannel.name }}',
                    ],
                ],
                'mailTemplateType' => [
                    'id' => $mailTemplateTypeId,
                    'technicalName' => 'novalnet_order_confirmation_mail',
                    'translations'  => [
                        'de-DE' => [
                            'name' => 'Bestellbestätigung',
                        ],
                        'en-GB' => [
                            'name' => 'Order Confirmation',
                        ],
                    ],
                    'availableEntities' => [
                        'order' => 'order',
                        'salesChannel' => 'sales_channel',
                    ],
                ],
            ]
            ], $this->context);
        }
    
    /**
     * Get Payment method entity
     *
     */
        private function getPaymentMethodEntity(): void
        {
            $criteria = new Criteria();
            $criteria->addFilter(new PrefixFilter('payment_method.handlerIdentifier', 'Novalnet\NovalnetPayment\Service'));
            $paymentListed = $this->container->get('payment_method.repository')->search($criteria, $this->context);
            $paymentLists = $paymentListed->getelements();
      
            if (!empty($paymentLists)) {
                foreach ($paymentLists as $payment) {
                    if ($payment->gethandlerIdentifier() != 'Novalnet\NovalnetPayment\Service\NovalnetPayment') {
                        if (!$payment->getId()) {
                            continue;
                        }

                        // Deactivate the payment methods.
                        $this->container->get('payment_method.repository')->update([
                        [
                            'id' => $payment->getId(),
                            'active' => false,
                        ],
                        ], $this->context);
                    
                        $channels  = $this->container->get('sales_channel.repository')->searchIds(new Criteria(), $this->context);

                        // Enable payment method on available channels.
                        if (!empty($this->container->get('sales_channel_payment_method.repository'))) {
                            foreach ($channels->getIds() as $channel) {
                                $data = [
                                    'salesChannelId'  => $channel,
                                    'paymentMethodId' => $payment->getId(),
                                ];
                                $this->container->get('sales_channel_payment_method.repository')->delete([$data], $this->context);
                            }
                        }
                    }
                }
            }
        }
    
    /**
     * Get Payment method entity
     *
     * @param string $handlerIdentifier
     *
     * @retrun PaymentMethodEntity|null
     */
        private function getPaymentMethodMedia(): ?PaymentMethodEntity
        {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('handlerIdentifier', 'Novalnet\NovalnetPayment\Service\NovalnetPayment'));

            return $this->container->get('payment_method.repository')->search($criteria, $this->context)->first();
        }
    
    
    /**
     * Alter Payment Token Table
     */
        public function alterPaymentTokenTable(): void
        {
            /** @var Connection $connection */
            $connection = $this->container->get(Connection::class);
        
            $isTableExists  = $connection->executeQuery('
            SELECT COUNT(*) as exists_tbl
            FROM information_schema.tables
            WHERE table_name = "novalnet_transaction_details"
            AND table_schema = database()
        ')->fetch();

            if (!empty($isTableExists['exists_tbl'])) {
                $isColumnExists = $connection->fetchOne('SHOW COLUMNS FROM `novalnet_transaction_details` LIKE "token_info"');

                if (empty($isColumnExists)) {
                    $connection->exec('
                    ALTER TABLE `novalnet_transaction_details`
                    ADD `token_info` varchar(255) DEFAULT NULL COMMENT "Transaction Token" AFTER `additional_details`;
                ');
                }
            }
        }
    
    /**
     * Get English HTML Template
     *
     * @return string
     */
        private function getHtmlTemplateEn(): string
        {
            return '<div style="font-family:Arial, Helvetica, sans-serif; font-size:12px;">

		{% set currencyIsoCode = order.currency.isoCode %}
		{{order.orderCustomer.salutation.letterName }} {{order.orderCustomer.firstName}} {{order.orderCustomer.lastName}},<br>
		<br>
		{% if instalment == false %}
			Thank you for your order at {{ salesChannel.name }} (Number: {{order.orderNumber}}) on {{ order.orderDateTime|format_datetime("medium", "short", locale="en-GB") }}.<br>
		{% else %}
			The next instalment cycle have arrived for the instalment order (OrderNumber: {{order.orderNumber}}) placed at the store {{ salesChannel.name }} on {{ order.orderDateTime|format_datetime("medium", "short", locale="en-GB") }}.<br>
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

			{% for lineItem in order.lineItems|reverse %}
			<tr>
				<td style="border-bottom:1px solid #cccccc;">{{ loop.index }} </td>
				<td style="border-bottom:1px solid #cccccc;">
				  {{ lineItem.label|u.wordwrap(80) }}<br>
				  {% if lineItem.payload.productNumber is defined %} Art. No.: {{ lineItem.payload.productNumber|u.wordwrap(80) }} {% endif %}
				</td>
				<td style="border-bottom:1px solid #cccccc;">{{ lineItem.quantity }}</td>
				<td style="border-bottom:1px solid #cccccc;">{{ lineItem.unitPrice|currency(currencyIsoCode) }}</td>
				<td style="border-bottom:1px solid #cccccc;">{{ lineItem.totalPrice|currency(currencyIsoCode) }}</td>
			</tr>
			{% endfor %}
		</table>

		{% set delivery =order.deliveries.first %}


		{% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
		{% set decimals = order.totalRounding.decimals %}
		{% set total = order.price.totalPrice %}
		{% if displayRounded %}
			{% set total = order.price.rawTotal %}
			{% set decimals = order.itemRounding.decimals %}
		{% endif %}
		<p>
			<br>
			<br>
			{% if delivery is not null %}
				Shipping costs: {{order.deliveries.first.shippingCosts.totalPrice|currency(currencyIsoCode) }}<br>
			{% endif %}
			Net total: {{ order.amountNet|currency(currencyIsoCode) }}<br>
			
			{% for calculatedTax in order.price.calculatedTaxes %}
				{% if order.taxStatus is same as(\'net\') %}plus{% else %}including{% endif %} {{ calculatedTax.taxRate }}% VAT. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
			{% endfor %}
			{% if not displayRounded %}<strong>{% endif %}Total gross: {{ order.amountTotal|currency(currencyIsoCode,decimals=decimals) }}{% if not displayRounded %}</strong>{% endif %}<br>
			{% if displayRounded %}
				<strong>Rounded total gross: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}</strong><br>
			{% endif %}

			<br>

			<strong>Selected payment type:</strong> {% if paymentName is not empty %} {{ paymentName }} {% else %} {{ order.transactions|last.paymentMethod.name }} {% endif %} <br>
			{{ order.transactions|last.paymentMethod.description }}<br>
			<br>

			<strong>Comments:</strong><br>
			{{ note|replace({"/ ": "<br>"}) | raw }}<br>
			<br>

			{% if "INSTALMENT_INVOICE" in novalnetDetails.paymentType or "INSTALMENT_DIRECT_DEBIT_SEPA" in novalnetDetails.paymentType %}
				{% if instalmentInfo is not empty %}
					<table width="40%" style="font-family:Arial, Helvetica, sans-serif; border: 1px solid;border-color: #bcc1c7;text-align: center;font-size:12px;">
						<thead style="font-weight: bold;">
							<tr>
								<td style="border-bottom:1px solid #cccccc;">S.No</td>
								<td style="border-bottom:1px solid #cccccc;">Novalnet Transaction ID</td>
								<td style="border-bottom:1px solid #cccccc;">Amount</td>
								<td style="border-bottom:1px solid #cccccc;">Next Instalment Date</td>
							<tr>
						</thead>
						<tbody>
							{% for info in instalmentInfo.InstalmentDetails %}
								{%set amount = info.amount/100 %}
								<tr>
									<td style="border-bottom:1px solid #cccccc;">{{ loop.index }}</td>
									<td style="border-bottom:1px solid #cccccc;">{{ info.reference ? info.reference : "-" }}</td>
									<td style="border-bottom:1px solid #cccccc;">{{ amount ? amount|currency(currencyIsoCode): "-" }}</td>
									<td style="border-bottom:1px solid #cccccc;">{{ info.cycleDate ? info.cycleDate|date("d/m/Y"): "-" }}</td>
								<tr>
							{% endfor %}
						</tbody>
					</table>
					<br>
				{% endif %}
			{% endif %}

			{% if delivery is not null %}
				<strong>Selected shipping type:</strong> {{ delivery.shippingMethod.translated.name }}<br>
				{{ delivery.shippingMethod.translated.description }}<br>
				<br>
			{% endif %}

			{% set billingAddress = order.addresses.get(order.billingAddressId) %}
			<strong>Billing address:</strong><br>
			{{ billingAddress.company }}<br>
			{{ billingAddress.firstName }} {{ billingAddress.lastName }}<br>
			{{ billingAddress.street }} <br>
			{{ billingAddress.zipcode }} {{ billingAddress.city }}<br>
			{{ billingAddress.country.name }}<br>
			<br>
			{% if delivery is not null %}
				<strong>Shipping address:</strong><br>
				{{ delivery.shippingOrderAddress.company }}<br>
				{{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}<br>
				{{ delivery.shippingOrderAddress.street }} <br>
				{{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}<br>
				{{ delivery.shippingOrderAddress.country.name }}<br>
				<br>
			{% endif %}	
			{% if billingAddress.vatId %}

			Your VAT-ID: {{ billingAddress.vatId }}
			In case of a successful order and if you are based in one of the EU countries, you will receive your goods exempt from turnover tax.<br>
			{% endif %}
			<br/>
			You can check the current status of your order on our website under "My account" - "My orders" anytime: {{ rawUrl("frontend.account.order.single.page", { "deepLinkCode": order.deepLinkCode }, salesChannel.domains|first.url) }}
			</br>
			If you have any questions, do not hesitate to contact us.

		</p>
		<br>
	</div>';
        }

    /**
     * Get English Plain Template
     *
     * @return string
     */
        private function getPlainTemplateEn(): string
        {
            return '{% set currencyIsoCode = order.currency.isoCode %}
		{{ order.orderCustomer.salutation.letterName }} {{order.orderCustomer.firstName}} {{order.orderCustomer.lastName}},

		{% if instalment == false %}
			Thank you for your order at {{ salesChannel.name }} (Number: {{order.orderNumber}}) on {{ order.orderDateTime|format_datetime("medium", "short", locale="en-GB") }}.
		{% else %}
			The next instalment cycle have arrived for the instalment order (OrderNumber: {{order.orderNumber}}) placed at the store {{ salesChannel.name }} on {{ order.orderDateTime|format_datetime("medium", "short", locale="en-GB") }}.
		{% endif %}

		Information on your order:

		Pos.   Art.No.          Description         Quantities          Price           Total

		{% for lineItem in order.lineItems|reverse %}
			{{ loop.index }}       {% if lineItem.payload.productNumber is defined %}{{ lineItem.payload.productNumber|u.wordwrap(80) }}{% endif %}                {{ lineItem.label|u.wordwrap(80) }}         {{ lineItem.quantity }}         {{ lineItem.unitPrice|currency(currencyIsoCode) }}          {{ lineItem.totalPrice|currency(currencyIsoCode) }}
		{% endfor %}

		{% set delivery = order.deliveries.first %}

		{% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
		{% set decimals = order.totalRounding.decimals %}
		{% set total = order.price.totalPrice %}
		{% if displayRounded %}
			{% set total = order.price.rawTotal %}
			{% set decimals = order.itemRounding.decimals %}
		{% endif %}

		{% if delivery is not null %}
			Shipping costs: {{order.deliveries.first.shippingCosts.totalPrice|currency(currencyIsoCode) }}
		{% endif %}
		Net total: {{ order.amountNet|currency(currencyIsoCode) }}
		{% for calculatedTax in order.price.calculatedTaxes %}
			{% if order.taxStatus is same as(\'net\') %}plus{% else %}including{% endif %} {{ calculatedTax.taxRate }}% VAT. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
		{% endfor %}
		Total gross: {{ order.amountTotal|currency(currencyIsoCode,decimals=decimals) }}
		{% if displayRounded %}
			Rounded total gross: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}
		{% endif %}

		Selected payment type: {% if paymentName is not empty %} {{ paymentName }} {% else %} {{ order.transactions|last.paymentMethod.name }} {% endif %}
		{{ order.transactions|last.paymentMethod.description }}

		Comments:
		{{ note|replace({"/ ": "<br>"}) | raw }}

		{% if "INSTALMENT_INVOICE" in novalnetDetails.paymentType or "INSTALMENT_DIRECT_DEBIT_SEPA" in novalnetDetails.paymentType  %}
			{% if instalmentInfo is not empty %}
				S.No.  Novalnet Transaction ID         Amount          Next Instalment Date
				{% for info in instalmentInfo.InstalmentDetails %}
					{%set amount = info.amount/100 %}
					{{ loop.index }} {{ info.reference ? info.reference : "-" }} {{ amount ? amount|currency(currencyIsoCode): "-" }}  {{ info.cycleDate ? info.cycleDate|date("d/m/Y"): "-" }}
				{% endfor %}
			{% endif %}
		{% endif %}
		
		{% if delivery is not null %}
			Selected shipping type: {{ delivery.shippingMethod.translated.name }}
			{{ delivery.shippingMethod.translated.description }}
		{% endif %}

		{% set billingAddress = order.addresses.get(order.billingAddressId) %}
		Billing address:
		{{ billingAddress.company }}
		{{ billingAddress.firstName }} {{ billingAddress.lastName }}
		{{ billingAddress.street }}
		{{ billingAddress.zipcode }} {{ billingAddress.city }}
		{{ billingAddress.country.name }}

		{% if delivery is not null %}
			Shipping address:
			{{ delivery.shippingOrderAddress.company }}
			{{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}
			{{ delivery.shippingOrderAddress.street }}
			{{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}
			{{ delivery.shippingOrderAddress.country.name }}
			
		{% endif %}

		{% if billingAddress.vatId %}
		Your VAT-ID: {{ billingAddress.vatId }}
		In case of a successful order and if you are based in one of the EU countries, you will receive your goods exempt from turnover tax.
		{% endif %}

		You can check the current status of your order on our website under "My account" - "My orders" anytime: {{ rawUrl("frontend.account.order.single.page", { "deepLinkCode": order.deepLinkCode }, salesChannel.domains|first.url) }}
		If you have any questions, do not hesitate to contact us.
		';
        }

    /**
     * Get German HTML Template
     *
     * @return string
     */
        private function getHtmlTemplateDe(): string
        {
            return '<div style="font-family:Arial, Helvetica, sans-serif; font-size:12px;">

			{% set currencyIsoCode = order.currency.isoCode %}
			{{order.orderCustomer.salutation.letterName }} {{order.orderCustomer.firstName}} {{order.orderCustomer.lastName}},<br>
			<br>
			{% if instalment == false %}
				vielen Dank für Ihre Bestellung im {{ salesChannel.name }} (Nummer: {{order.orderNumber}}) am {{ order.orderDateTime|format_datetime("medium", "short", locale="de-DE") }}.<br>
			{% else %}
				Für Ihre (Bestellung Nr: {{order.orderNumber}}) bei {{ salesChannel.name }}, ist die nächste Rate fällig. Bitte beachten Sie weitere Details unten am {{ order.orderDateTime|format_datetime("medium", "short", locale="de-DE") }}.<br>
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

				{% for lineItem in order.lineItems |reverse %}
					<tr>
						<td style="border-bottom:1px solid #cccccc;">{{ loop.index }} </td>
						<td style="border-bottom:1px solid #cccccc;">
						  {{ lineItem.label|u.wordwrap(80) }}<br>
						  {% if lineItem.payload.productNumber is defined %} Artikel-Nr: {{ lineItem.payload.productNumber|u.wordwrap(80) }} {% endif %}
						</td>
						<td style="border-bottom:1px solid #cccccc;">{{ lineItem.quantity }}</td>
						<td style="border-bottom:1px solid #cccccc;">{{ lineItem.unitPrice|currency(currencyIsoCode) }}</td>
						<td style="border-bottom:1px solid #cccccc;">{{ lineItem.totalPrice|currency(currencyIsoCode) }}</td>
					</tr>
				{% endfor %}
			</table>

			{% set delivery =order.deliveries.first %}

			{% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
			{% set decimals = order.totalRounding.decimals %}
			{% set total = order.price.totalPrice %}
			{% if displayRounded %}
				{% set total = order.price.rawTotal %}
				{% set decimals = order.itemRounding.decimals %}
			{% endif %}
			<p>
				<br>
				<br>
				{% if delivery is not null %}
					Versandkosten: {{order.deliveries.first.shippingCosts.totalPrice|currency(currencyIsoCode) }}<br>
				{% endif %}
				Gesamtkosten Netto: {{ order.amountNet|currency(currencyIsoCode) }}<br>
					{% for calculatedTax in order.price.calculatedTaxes %}
						{% if order.taxStatus is same as(\'net\') %}zzgl{% else %}inkl{% endif %} {{ calculatedTax.taxRate }}% MWST. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
					{% endfor %}
					{% if not displayRounded %}<strong>{% endif %}Gesamtkosten Brutto: {{ order.amountTotal|currency(currencyIsoCode,decimals=decimals) }}{% if not displayRounded %}</strong>{% endif %}<br>
				{% if displayRounded %}
					<strong>Gesamtkosten Brutto gerundet: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}</strong><br>
				{% endif %}
				<br>
				<strong>Gewählte Zahlungsart:</strong> {% if paymentName is not empty %} {{ paymentName }} {% else %} {{ order.transactions|last.paymentMethod.name }} {% endif %} <br>
				{{ order.transactions|last.paymentMethod.description }}<br>
				<br>

				<strong>Kommentare:</strong><br>
				{{ note|replace({"/ ": "<br>"}) | raw }}<br>
				<br>

				{% if "INSTALMENT_INVOICE" in novalnetDetails.paymentType or "INSTALMENT_DIRECT_DEBIT_SEPA" in novalnetDetails.paymentType  %}
						{% if instalmentInfo is not empty %}
							<table width="40%" style="font-family:Arial, Helvetica, sans-serif; border: 1px solid;border-color: #bcc1c7;text-align: center;font-size:12px;">
								<thead style="font-weight: bold;">
									<tr>
										<td style="border-bottom:1px solid #cccccc;">S.Nr</td>
										<td style="border-bottom:1px solid #cccccc;">Novalnet-Transaktions-ID</td>
										<td style="border-bottom:1px solid #cccccc;">Betrag</td>
										<td style="border-bottom:1px solid #cccccc;">Nächste Rate fällig am</td>
									<tr>
								</thead>
								<tbody>
									{% for info in instalmentInfo.InstalmentDetails %}
										{%set amount = info.amount/100 %}
										<tr>
											<td style="border-bottom:1px solid #cccccc;">{{ loop.index }}</td>
											<td style="border-bottom:1px solid #cccccc;">{{ info.reference ? info.reference : "-" }}</td>
											<td style="border-bottom:1px solid #cccccc;">{{ amount ? amount|currency(currencyIsoCode): "-" }}</td>
											<td style="border-bottom:1px solid #cccccc;">{{ info.cycleDate ? info.cycleDate|date("d/m/Y"): "-" }}</td>
										<tr>
									{% endfor %}
								</tbody>
							</table>
							<br>
						{% endif %}
				{% endif %}

				{% if delivery is not null %}
					<strong>Gewählte Versandtart:</strong> {{ delivery.shippingMethod.translated.name }}<br>
					{{ delivery.shippingMethod.translated.description }}<br>
					<br>
				{% endif %}

				{% set billingAddress = order.addresses.get(order.billingAddressId) %}
				<strong>Rechnungsaddresse:</strong><br>
				{{ billingAddress.company }}<br>
				{{ billingAddress.firstName }} {{ billingAddress.lastName }}<br>
				{{ billingAddress.street }} <br>
				{{ billingAddress.zipcode }} {{ billingAddress.city }}<br>
				{{ billingAddress.country.name }}<br>
				<br>

				{% if delivery is not null %}
					<strong>Lieferadresse:</strong><br>
					{{ delivery.shippingOrderAddress.company }}<br>
					{{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}<br>
					{{ delivery.shippingOrderAddress.street }} <br>
					{{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}<br>
					{{ delivery.shippingOrderAddress.country.name }}<br>
					<br>
					
				{% endif %}

				{% if billingAddress.vatId %}
					Ihre Umsatzsteuer-ID: {{ billingAddress.vatId }}
					Bei erfolgreicher Prüfung und sofern Sie aus dem EU-Ausland
					bestellen, erhalten Sie Ihre Ware umsatzsteuerbefreit. <br>
				{% endif %}
				<br>
				<br/>
				Den aktuellen Status Ihrer Bestellung können Sie auch jederzeit auf unserer Webseite im  Bereich "Mein Konto" - "Meine Bestellungen" abrufen: {{ rawUrl("frontend.account.order.single.page", { "deepLinkCode": order.deepLinkCode }, salesChannel.domains|first.url) }}
				</br>
				Für Rückfragen stehen wir Ihnen jederzeit gerne zur Verfügung.

			</p>
			<br>
		</div>';
        }

    /**
     * Get German Plain Template
     *
     * @return string
     */
        private function getPlainTemplateDe(): string
        {
            return '{% set currencyIsoCode = order.currency.isoCode %}
			{{order.orderCustomer.salutation.letterName }} {{order.orderCustomer.firstName}} {{order.orderCustomer.lastName}},

			{% if instalment == false %}
				vielen Dank für Ihre Bestellung im {{ salesChannel.name }} (Nummer: {{order.orderNumber}}) am {{ order.orderDateTime|format_datetime("medium", "short", locale="de-DE") }}.
			{% else %}
				Für Ihre (Bestellung Nr: {{order.orderNumber}}) bei {{ salesChannel.name }}, ist die nächste Rate fällig. Bitte beachten Sie weitere Details unten am {{ order.orderDateTime|format_datetime("medium", "short", locale="de-DE")}}.
			{% endif %}

			Informationen zu Ihrer Bestellung:

			Pos.   Artikel-Nr.          Beschreibung            Menge           Preis           Summe
			{% for lineItem in order.lineItems |reverse %}
				{{ loop.index }}     {% if lineItem.payload.productNumber is defined %}{{ lineItem.payload.productNumber|u.wordwrap(80) }}{% endif %} {{ lineItem.label|u.wordwrap(80) }}         {{ lineItem.quantity }}         {{ lineItem.unitPrice|currency(currencyIsoCode) }}          {{ lineItem.totalPrice|currency(currencyIsoCode) }}
			{% endfor %}

			{% set delivery =order.deliveries.first %}

			{% set displayRounded = order.totalRounding.interval != 0.01 or order.totalRounding.decimals != order.itemRounding.decimals %}
			{% set decimals = order.totalRounding.decimals %}
			{% set total = order.price.totalPrice %}
			{% if displayRounded %}
				{% set total = order.price.rawTotal %}
				{% set decimals = order.itemRounding.decimals %}
			{% endif %}

			{% if delivery is not null %}
				Versandtkosten: {{order.deliveries.first.shippingCosts.totalPrice|currency(currencyIsoCode) }}
			{% endif %}
			Gesamtkosten Netto: {{ order.amountNet|currency(currencyIsoCode) }}
			{% for calculatedTax in order.price.calculatedTaxes %}
				{% if order.taxStatus is same as(\'net\') %}zzgl{% else %}inkl{% endif %} {{ calculatedTax.taxRate }}% MWST. {{ calculatedTax.tax|currency(currencyIsoCode) }}<br>
			{% endfor %}
			Gesamtkosten Brutto: {{ order.amountTotal|currency(currencyIsoCode,decimals=decimals) }}
			{% if displayRounded %}
				Gesamtkosten Brutto gerundet: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}
			{% endif %}

			Gewählte Zahlungsart: {% if paymentName is not empty %} {{ paymentName }} {% else %} {{ order.transactions|last.paymentMethod.name }} {% endif %}
			{{ order.transactions|last.paymentMethod.description }}

			Kommentare:
			{{ note|replace({"/ ": "<br>"}) | raw }}

			{% if "INSTALMENT_INVOICE" in novalnetDetails.paymentType or "INSTALMENT_DIRECT_DEBIT_SEPA" in novalnetDetails.paymentType %}
					{% if instalmentInfo is not empty %}
									S.Nr   Novalnet-Transaktions-ID    Betrag   Nächste Rate fällig am
								{% for info in instalmentInfo.InstalmentDetails %}
									{%set amount = info.amount/100 %}
									{{ loop.index }}    {{ info.reference ? info.reference : "-" }}     {{ amount ? amount|currency(currencyIsoCode): "-" }}     {{ info.cycleDate ? info.cycleDate|date("d/m/Y"): "-" }}
								{% endfor %}
					{% endif %}
			{% endif %}

			{% if delivery is not null %}
				Gewählte Versandtart: {{ delivery.shippingMethod.translated.name }}
				{{ delivery.shippingMethod.translated.description }}
			{% endif %}

			{% set billingAddress = order.addresses.get(order.billingAddressId) %}
			Rechnungsadresse:
			{{ billingAddress.company }}
			{{ billingAddress.firstName }} {{ billingAddress.lastName }}
			{{ billingAddress.street }}
			{{ billingAddress.zipcode }} {{ billingAddress.city }}
			{{ billingAddress.country.name }}

			{% if delivery is not null %}
				Lieferadresse:
				{{ delivery.shippingOrderAddress.company }}
				{{ delivery.shippingOrderAddress.firstName }} {{ delivery.shippingOrderAddress.lastName }}
				{{ delivery.shippingOrderAddress.street }}
				{{ delivery.shippingOrderAddress.zipcode}} {{ delivery.shippingOrderAddress.city }}
				{{ delivery.shippingOrderAddress.country.name }}
				
			{% endif %}

			{% if billingAddress.vatId %}
				Ihre Umsatzsteuer-ID: {{ billingAddress.vatId }}
				Bei erfolgreicher Prüfung und sofern Sie aus dem EU-Ausland
				bestellen, erhalten Sie Ihre Ware umsatzsteuerbefreit.
			{% endif %}

			Den aktuellen Status Ihrer Bestellung können Sie auch jederzeit auf unserer Webseite im  Bereich "Mein Konto" - "Meine Bestellungen" abrufen: {{ rawUrl("frontend.account.order.single.page", { "deepLinkCode": order.deepLinkCode }, salesChannel.domains|first.url) }}
			Für Rückfragen stehen wir Ihnen jederzeit gerne zur Verfügung.

		';
        }

    /**
     * Get Mail template entity
     *
     * @return MailTemplateEntity|null
     */
        private function getMailTemplateType(): ?MailTemplateEntity
        {
            $criteria = new Criteria();
            $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', 'novalnet_order_confirmation_mail'));
            return $this->mailTemplateRepo->search($criteria, $this->context)->first();
        }
}
