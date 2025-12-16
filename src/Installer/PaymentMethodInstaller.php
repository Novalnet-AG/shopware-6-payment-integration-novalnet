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

use Doctrine\DBAL\Connection;
use Novalnet\NovalnetPayment\Installer\MediaProvider;
use Novalnet\NovalnetPayment\Service\NovalnetAliPay;
use Novalnet\NovalnetPayment\Service\NovalnetApplePay;
use Novalnet\NovalnetPayment\Service\NovalnetBancontact;
use Novalnet\NovalnetPayment\Service\NovalnetBlik;
use Novalnet\NovalnetPayment\Service\NovalnetCreditCard;
use Novalnet\NovalnetPayment\Service\NovalnetDirectDebitACH;
use Novalnet\NovalnetPayment\Service\NovalnetEps;
use Novalnet\NovalnetPayment\Service\NovalnetGooglePay;
use Novalnet\NovalnetPayment\Service\NovalnetIdeal;
use Novalnet\NovalnetPayment\Service\NovalnetInvoice;
use Novalnet\NovalnetPayment\Service\NovalnetInvoiceGuarantee;
use Novalnet\NovalnetPayment\Service\NovalnetInvoiceInstalment;
use Novalnet\NovalnetPayment\Service\NovalnetMbway;
use Novalnet\NovalnetPayment\Service\NovalnetMultibanco;
use Novalnet\NovalnetPayment\Service\NovalnetOnlineBankTransfer;
use Novalnet\NovalnetPayment\Service\NovalnetPayconiq;
use Novalnet\NovalnetPayment\Service\NovalnetPaypal;
use Novalnet\NovalnetPayment\Service\NovalnetPostfinance;
use Novalnet\NovalnetPayment\Service\NovalnetPostfinanceCard;
use Novalnet\NovalnetPayment\Service\NovalnetPrepayment;
use Novalnet\NovalnetPayment\Service\NovalnetPrzelewy24;
use Novalnet\NovalnetPayment\Service\NovalnetSepa;
use Novalnet\NovalnetPayment\Service\NovalnetSepaGuarantee;
use Novalnet\NovalnetPayment\Service\NovalnetSepaInstalment;
use Novalnet\NovalnetPayment\Service\NovalnetTrustly;
use Novalnet\NovalnetPayment\Service\NovalnetTwint;
use Novalnet\NovalnetPayment\Service\NovalnetWeChatPay;
use Shopware\Core\Checkout\Payment\PaymentMethodEntity;
use Shopware\Core\Content\MailTemplate\MailTemplateEntity;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\System\CustomField\CustomFieldTypes;
use Shopware\Core\System\SystemConfig\SystemConfigService;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * PaymentMethodInstaller Class.
 */
class PaymentMethodInstaller
{
    /**
     * @var array
     */
    private $paymentMethods = [
        NovalnetGooglePay::class,
        NovalnetApplePay::class,
        NovalnetBancontact::class,
        NovalnetCreditCard::class,
        NovalnetEps::class,
        NovalnetIdeal::class,
        NovalnetTrustly::class,
        NovalnetWeChatPay::class,
        NovalnetAliPay::class,
        NovalnetMbway::class,
        NovalnetInvoice::class,
        NovalnetInvoiceGuarantee::class,
        NovalnetMultibanco::class,
        NovalnetPaypal::class,
        NovalnetPostfinance::class,
        NovalnetPostfinanceCard::class,
        NovalnetPrepayment::class,
        NovalnetPrzelewy24::class,
        NovalnetSepa::class,
        NovalnetDirectDebitACH::class,
        NovalnetSepaGuarantee::class,
        NovalnetBlik::class,
        NovalnetPayconiq::class,
        NovalnetOnlineBankTransfer::class,
        NovalnetInvoiceInstalment::class,
        NovalnetSepaInstalment::class,
        NovalnetTwint::class,
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
     * @var EntityRepository
     */
    private $mailTemplateRepo;

    /**
     * @var EntityRepository
     */
    private $mailTemplateTypeRepo;

    /**
     * @var array
     */
    private $customFields = [
        [
            'name' => 'novalnet',
            'config' => [
                'label' => [
                    'en-GB' => 'Novalnet',
                    'de-DE' => 'Novalnet',
                    Defaults::LANGUAGE_SYSTEM => 'Novalnet',
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
                            Defaults::LANGUAGE_SYSTEM => 'Novalnet Coments',
                        ],
                    ],
                ],
            ],
            'relations' => [
                [
                    'entityName' => 'order_transaction',
                ]
            ]
        ]
    ];

    /**
     * @constant string
     */
    protected const SYSTEM_CONFIG_DOMAIN = 'NovalnetPayment.settings.';

    /**
     * @var array
     */
    protected $defaultConfiguration = [
        'creditcardCss'                => 'body{color: #8798a9;font-family:Helvetica,Arial,sans-serif;font-weight: 500;}input{border-radius: 3px;background-clip: padding-box;box-sizing: border-box;line-height: 1.1875rem;padding: .625rem .625rem .5625rem .625rem;box-shadow: inset 0 1px 1px #dadae5;background: #f8f8fa;border: 1px solid #dadae5;border-top-color: #cbcbdb;color: #8798a9;text-align: left;font: inherit;letter-spacing: normal;margin: 0;word-spacing: normal;text-transform: none;text-indent: 0px;text-shadow: none;display: inline-block;height:40px;font-family:Helvetica,Arial,sans-serif;font-weight: 500;}input:focus{background-color: white;font-family:Helvetica,Arial,sans-serif;font-weight: 500;}',
        'creditcardInline'             => true,
        'creditcardOneclick'           => true,
        'sepaOneclick'                 => true,
        'directdebitachOneclick'       => true,
        'sepaguaranteeOneclick'        => true,
        'sepainstalmentOneclick'       => true,
        'sepaguaranteeAllowB2B'        => true,
        'invoiceguaranteeAllowB2B'     => true,
        'invoiceinstalmentAllowB2B'    => true,
        'invoiceinstalmentProductPageInfo' => true,
        'sepainstalmentProductPageInfo'    => true,
        'sepainstalmentAllowB2B'        => true,
        'emailMode'                     => true,
        'applepayButtonType'   => 'plain',
        'applepayButtonTheme'  => 'black',
        'applepayButtonHeight' => 40,
        'applepayButtonRadius' => 2,
        'applepayDisplayFields'    => ['cart', 'register', 'ajaxCart', 'productDetailPage', 'productListingPage'],
        'googlepayButtonType'  => 'book',
        'googlepayButtonTheme' => 'default',
        'googlepayButtonHeight'    => 50,
        'googlepayDisplayFields'   => ['cart', 'register', 'ajaxCart', 'productDetailPage', 'productListingPage'],
        'invoiceguaranteeMinimumOrderAmount'  => 999,
        'sepaguaranteeMinimumOrderAmount'     => 999,
        'invoiceinstalmentMinimumOrderAmount' => 1998,
        'sepainstalmentMinimumOrderAmount'    => 1998,
        'invoiceinstalmentCycles'             => ['2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12'],
        'sepainstalmentCycles' => ['2', '3', '4', '5', '6', '7', '8', '9', '10', '11', '12']
    ];

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
     * Add Payment Methods on plugin installation
     *
     */
    public function install(): void
    {
        $this->addPaymentMethods();
        $this->alterPaymentTokenTable();
        $this->createMailEvents();
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
        $this->createMailEvents();
    }

    /**
     * Add payment logo into media on plugin activation
     *
     */
    public function activate(): void
    {
        #Update icon to the media repository
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
     */
    public function uninstall(): void
    {
        $this->deactivatePaymentMethods();
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
            WHERE table_name IN ("novalnet_transaction_details")
            AND table_schema = database()
        ')->fetchAllAssociative();

        if (!empty($isTableExists)) {
            $isColumnExists = $connection->fetchOne('SHOW COLUMNS FROM `novalnet_transaction_details` LIKE "lang"');

            if ($isColumnExists) {
                $connection->executeQuery('
                    ALTER TABLE `novalnet_transaction_details`
                    ADD `currency` VARCHAR(11) DEFAULT NULL COMMENT "Transaction currency",
                    ADD `refunded_amount` INT(11) UNSIGNED DEFAULT 0 COMMENT "Refunded amount",
                    ADD `created_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Created date",
                    ADD `updated_at` datetime DEFAULT CURRENT_TIMESTAMP COMMENT "Updated date",
                    DROP COLUMN `lang`;
                ');
            }
        }

        $istokenTableExists = $connection->executeQuery('
            SELECT COUNT(*) as exists_tbl
            FROM information_schema.tables
            WHERE table_name IN ("novalnet_payment_token")
            AND table_schema = database()
        ')->fetchAllAssociative();

        if (!empty($istokenTableExists)) {
            $istokenColumnExists    = $connection->fetchOne('SHOW COLUMNS FROM `novalnet_payment_token` LIKE "subscription"');

            if (!$istokenColumnExists) {
                $connection->executeQuery('ALTER TABLE `novalnet_payment_token` ADD `subscription` INT(1) DEFAULT 0 COMMENT "Subscription Token" AFTER `expiry_date`;');
            }
        }
    }

    /**
     * Delete plugin related system configurations.
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
     * Delete plugin related mail configuration.
     *
     */
    public function deleteMailSettings(): void
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('mailTemplateType.technicalName', 'novalnet_order_confirmation_mail'));

        /** @var MailTemplateEntity|null $mailData */
        $mailData = $this->mailTemplateRepo->search($criteria, $this->context)->first();

        if ($mailData) {
            // delete subscription mail template
            $this->mailTemplateRepo->delete([['id' => $mailData->getId()]], $this->context);
            $this->mailTemplateTypeRepo->delete([['id' => $mailData->getMailTemplateTypeId()]], $this->context);
        }
    }

    /**
     * Add Novalnet Payment methods
     *
     */
    private function addPaymentMethods(): void
    {
        $paymentMethods = $this->getPaymentMethods();

        if ($paymentMethods) {
            $defaultLocale = (in_array($this->getDefaultLocaleCode(), ['de-DE', 'en-GB'])) ? $this->getDefaultLocaleCode() : 'en-GB';
            $context = $this->context;

            foreach ($paymentMethods as $paymentMethod) {
                $paymentMethodId = $this->getPaymentMethodEntity($paymentMethod->getPaymentHandler())?->getId();

                // Skip insertion if the payment already exists.
                if (empty($paymentMethodId)) {
                    $translations = $paymentMethod->getTranslations();
                    $paymentData  = [
                        [
                            'name'              => $paymentMethod->getName($defaultLocale),
                            'description'       => $paymentMethod->getDescription($defaultLocale),
                            'position'          => $paymentMethod->getPosition(),
                            'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
                            'technicalName'     => $paymentMethod->getPaymentCode(),
                            'translations'      => $translations,
                            'afterOrderEnabled' => true,
                            'customFields'      => [
                                'novalnet_payment_method_name' => $paymentMethod->getPaymentCode(),
                            ],
                        ]
                    ];

                    $this->context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($paymentData): void {
                        $this->container->get('payment_method.repository')->upsert($paymentData, $context);
                    });

                    $paymentMethodId = $this->getPaymentMethodEntity($paymentMethod->getPaymentHandler())?->getId();
                    $channels        = $this->container->get('sales_channel.repository')->searchIds(new Criteria(), $this->context);
                    /** @var EntityRepository|null $salesChannelPaymentRepo */
                    $salesChannelPaymentRepo = $this->container->get('sales_channel_payment_method.repository');

                    // Enable payment method on available channels.
                    if ($salesChannelPaymentRepo != null) {
                        foreach ($channels->getIds() as $channel) {
                            $data = [
                                'salesChannelId'  => $channel,
                                'paymentMethodId' => $paymentMethodId,
                            ];
                            $salesChannelPaymentRepo->upsert([$data], $this->context);
                        }
                    }
                } else {
                    $paymentDetails = $this->getPaymentMethodEntity($paymentMethod->getPaymentHandler());
                    $technicalName = $paymentDetails->getTechnicalName();
                    if (!empty($paymentDetails) && (empty($technicalName) || $technicalName != $paymentMethod->getPaymentCode())) {
                        $this->container->get('payment_method.repository')->update([
                           [
                               'id' => $paymentDetails->getId(),
                               'technicalName'     => $paymentMethod->getPaymentCode()
                           ],
                        ], $context);
                    }
                }

                $this->updateCustomFieldsForTranslations($paymentMethod, $paymentMethodId);
            }
        }

        // Set default configurations value for payment methods
        $customFields = $this->customFields;
        $customFieldExistsId = $this->checkCustomField('novalnet');

        if (!$customFieldExistsId) {
            $this->context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($customFields): void {
                $this->container->get('custom_field_set.repository')->upsert($customFields, $context);
            });
        }

        foreach ($this->defaultConfiguration as $key => $value) {
            if (!empty($value)) {
                $this->container->get(SystemConfigService::class)->set(self::SYSTEM_CONFIG_DOMAIN . $key, $value);
            }
        }
    }

    /**
     * Check custom field
     *
     * @param string $customFieldName
     *
     * @return string|null
     */
    private function checkCustomField(string $customFieldName): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $customFieldName));

        /** @var EntityRepository|null $customFieldSetRepo */
        $customFieldSetRepo = $this->container->get('custom_field_set.repository');

        // Return null on given customField non existance case.
        if (is_null($customFieldSetRepo)) {
            return null;
        }
        $result = $customFieldSetRepo->searchIds($criteria, $this->context);

        if (!$result->getTotal()) {
            return null;
        }

        $customeFields = $result->getIds();
        return array_shift($customeFields);
    }

    /**
     * Get Novalnet Payment method instance
     *
     * @return array
     */
    private function getPaymentMethods(): array
    {
        $paymentMethods = [];

        // Get Novalnet payment methods and initiate the corresponding instance
        foreach ($this->paymentMethods as $paymentMethod) {
            $paymentMethods[] = new $paymentMethod();
        }

        return $paymentMethods;
    }

    /**
     * Get Payment method entity
     *
     * @param string $handlerIdentifier
     *
     * @return PaymentMethodEntity|null
     */
    private function getPaymentMethodEntity(string $handlerIdentifier): ?PaymentMethodEntity
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', $handlerIdentifier));

        return $this->container->get('payment_method.repository')->search($criteria, $this->context)->first();
    }

    /**
     * Deactivate Payment methods
     *
     * @return void
     */
    private function deactivatePaymentMethods(): void
    {
        $paymentMethods = $this->getPaymentMethods();
        foreach ($paymentMethods as $paymentMethod) {
            $paymentMethodId = $this->getPaymentMethodEntity($paymentMethod->getPaymentHandler())?->getId();

            if (!$paymentMethodId) {
                continue;
            }

            // Deactivate the payment methods.
            $this->container->get('payment_method.repository')->update([
                [
                    'id' => $paymentMethodId,
                    'active' => false,
                ],
            ], $this->context);
        }

        // Deactivate the custom fields.
        $customFieldExistsId = $this->checkCustomField('novalnet');

        if (!$customFieldExistsId) {
            return;
        }

        $customField = [
                'id' => $customFieldExistsId,
                'active' => false,
        ];
        $context = $this->context;

        $this->context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($customField): void {
            $this->container->get('custom_field_set.repository')->upsert([$customField], $context);
        });
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
     * Prepare and update media files on plugin activation
     *
     * @return void
     */
    private function updateMediaData(): void
    {
        $paymentMethods = $this->getPaymentMethods();
        foreach ($paymentMethods as $paymentMethod) {
            $paymentMethodEntity = $this->getPaymentMethodEntity($paymentMethod->getPaymentHandler());
            $paymentMethodId = $paymentMethodEntity?->getId();

            if (!$paymentMethodId) {
                continue;
            }

            /** @var MediaProvider|null $mediaProvider */
            $mediaProvider = $this->container->get(MediaProvider::class, ContainerInterface::NULL_ON_INVALID_REFERENCE);

            if (is_null($paymentMethodEntity->getMediaId()) && $mediaProvider != null) {
                $mediaId = $mediaProvider->getMediaId($paymentMethod->getPaymentCode(), $this->context);
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
     * Update payment custom fields
     *
     * @return void
     */
    private function updateCustomFieldsForTranslations($paymentMethod, string $paymentMethodId): void
    {
        $customFields['novalnet_payment_method_name'] = $paymentMethod->getPaymentCode();
        $customFields = json_encode($customFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        /** @var Connection $connection */
        $connection = $this->container->get(Connection::class);

        $connection->executeQuery(sprintf("
            UPDATE `payment_method_translation`
            SET
                `custom_fields` = '%s'
            WHERE
                `custom_fields` IS NULL AND
                `payment_method_id` = UNHEX('%s');
         ", $customFields, $paymentMethodId));
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

        if (!is_null($mailType)) {
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
                        'contentPlain' => $this->getPlainTemplateDe(),
                        'description' => 'Novalnet Bestellbestätigung',
                        'senderName'  => '{{ salesChannel.name }}',
                    ],
                    'en-GB' => [
                        'subject' => 'Order confirmation',
                        'contentHtml' => $this->getHtmlTemplateEn(),
                        'contentPlain' => $this->getPlainTemplateEn(),
                        'description' => 'Novalnet Order confirmation',
                        'senderName'  => '{{ salesChannel.name }}',
                    ],
                    Defaults::LANGUAGE_SYSTEM => [
                        'subject' => 'Order confirmation',
                        'contentHtml' => $this->getHtmlTemplateEn(),
                        'contentPlain' => $this->getPlainTemplateEn(),
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
                        Defaults::LANGUAGE_SYSTEM => [
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
        {% for shippingCost in order.deliveries %}
            Shipping costs: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}<br>
        {% endfor %}
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

    {% set lastTransaction = "" %}

    {% for transaction in order.transactions|sort((a, b) => a.createdAt <=> b.createdAt) %}
        {% set lastTransaction = transaction %}
    {% endfor %}

    <strong>Selected payment type:</strong> {{ lastTransaction.paymentMethod.translated.name }}<br>
    {{ lastTransaction.paymentMethod.translated.description }}<br>
    <br>

    <strong>Comments:</strong><br>
    {{ note|replace({"/ ": "<br>"}) | raw }}
    {% if qrImage is not empty %}
        <br>
        <div class="qr-code-text">
        {{ "NovalnetPayment.text.epcQrCodeDesc"|trans|sw_sanitize }}
        </div>
        <div class="qr-code-image">
            <img src="{{ qrImage }}" alt="QR Code" title="QR Code" />
        </div>
    {% endif %}
    <br>
    <br>

    {% if "NovalnetInvoiceInstalment" in lastTransaction.paymentMethod.handlerIdentifier or "NovalnetSepaInstalment" in lastTransaction.paymentMethod.handlerIdentifier %}
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
							{% for info in instalmentInfo %}
								{%set amount = info.amount/100 %}
								<tr>
									<td style="border-bottom:1px solid #cccccc;">{{ loop.index }}</td>
									<td style="border-bottom:1px solid #cccccc;">{{ info.reference ? info.reference : "-" }}</td>
									<td style="border-bottom:1px solid #cccccc;">{{ amount ? amount|currency(): "-" }}</td>
									{% if instalmentInfo[loop.index + 1] is defined %}
										<td style="border-bottom:1px solid #cccccc;">{{ instalmentInfo[loop.index + 1].cycleDate ? instalmentInfo[loop.index + 1].cycleDate|date("Y-m-d") : "-" }}</td>
									{% else %}
										<td style="border-bottom:1px solid #cccccc;">{{ "-" }}</td>
									{% endif %}
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
{% for shippingCost in order.deliveries %}
Shipping costs: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}
{% endfor %}
{% endif %}
Net total: {{ order.amountNet|currency(currencyIsoCode) }}
{% for calculatedTax in order.price.calculatedTaxes %}
{% if order.taxStatus is same as(\'net\') %}plus{% else %}including{% endif %} {{ calculatedTax.taxRate }}% VAT. {{ calculatedTax.tax|currency(currencyIsoCode) }}
{% endfor %}
Total gross: {{ order.amountTotal|currency(currencyIsoCode,decimals=decimals) }}
{% if displayRounded %}
Rounded total gross: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}
{% endif %}

{% set lastTransaction = "" %}

{% for transaction in order.transactions|sort((a, b) => a.createdAt <=> b.createdAt) %}
    {% set lastTransaction = transaction %}
{% endfor %}

Selected payment type: {{ lastTransaction.paymentMethod.translated.name }}
{{ lastTransaction.paymentMethod.translated.description }}

Comments:
{{ note|replace({"/ ": "\n"}) | raw }}
{% if qrImage is not empty %}
    {{ "NovalnetPayment.text.epcQrCodeDesc"|trans|sw_sanitize }}
    Kindly use the link to scan the QR code: {{ qrImage }}
{% endif %}

{% if "NovalnetInvoiceInstalment" in lastTransaction.paymentMethod.handlerIdentifier or "NovalnetSepaInstalment" in lastTransaction.paymentMethod.handlerIdentifier %}
{% if instalmentInfo is not empty %}
S.No.   Novalnet Transaction ID        Amount        Next Instalment Date
{% for info in instalmentInfo %}
{% set amount = info.amount/100 %}
{{ loop.index }}   {{ info.reference ? info.reference : "-          " }}       {{ amount ? amount|currency(currencyIsoCode): "-" }}           {% if instalmentInfo[loop.index + 1] is defined %} {{ instalmentInfo[loop.index + 1].cycleDate ? instalmentInfo[loop.index + 1].cycleDate|date("Y-m-d") : "-" }}{% else %}{{ "-" }}
{% endif %}

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

{% set delivery = order.deliveries.first %}

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
        {% for shippingCost in order.deliveries %}
            Versandkosten: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}<br>
        {% endfor %}
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

    {% set lastTransaction = "" %}

    {% for transaction in order.transactions|sort((a, b) => a.createdAt <=> b.createdAt) %}
        {% set lastTransaction = transaction %}
    {% endfor %}

    <strong>Gewählte Zahlungsart:</strong> {{ lastTransaction.paymentMethod.translated.name }}<br>
    {{ lastTransaction.paymentMethod.translated.description }}<br>

    <br>

    <strong>Kommentare:</strong><br>
    {{ note|replace({"/ ": "<br>"}) | raw }}
    {% if qrImage is not empty %}
        <br>
        <div class="qr-code-text">
        {{ "NovalnetPayment.text.epcQrCodeDesc"|trans|sw_sanitize }}
        </div>
        <div class="qr-code-image">
            <img src="{{ qrImage }}" alt="QR Code" title="QR Code" />
        </div>
    {% endif %}
    <br>
    <br>

    {% if "NovalnetInvoiceInstalment" in lastTransaction.paymentMethod.handlerIdentifier or "NovalnetSepaInstalment" in lastTransaction.paymentMethod.handlerIdentifier %}
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
							{% for info in instalmentInfo %}
								{%set amount = info.amount/100 %}
								<tr>
									<td style="border-bottom:1px solid #cccccc;">{{ loop.index }}</td>
									<td style="border-bottom:1px solid #cccccc;">{{ info.reference ? info.reference : "-" }}</td>
									<td style="border-bottom:1px solid #cccccc;">{{ amount ? amount|currency(): "-" }}</td>
									{% if instalmentInfo[loop.index + 1] is defined %}
										<td style="border-bottom:1px solid #cccccc;">{{ instalmentInfo[loop.index + 1].cycleDate ? instalmentInfo[loop.index + 1].cycleDate|date("Y-m-d") : "-" }}</td>
									{% else %}
										<td style="border-bottom:1px solid #cccccc;">{{ "-" }}</td>
									{% endif %}
								<tr>
							{% endfor %}
						</tbody>
                </table>
                <br>
            {% endif %}
    {% endif %}

    {% if delivery is not null %}
        <strong>Gewählte Versandart:</strong> {{ delivery.shippingMethod.translated.name }}<br>
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
{% for shippingCost in order.deliveries %}
Versandkosten: {{ shippingCost.shippingCosts.totalPrice|currency(currencyIsoCode) }}
{% endfor %}
{% endif %}
Gesamtkosten Netto: {{ order.amountNet|currency(currencyIsoCode) }}
{% for calculatedTax in order.price.calculatedTaxes %}
{% if order.taxStatus is same as(\'net\') %}zzgl{% else %}inkl{% endif %} {{ calculatedTax.taxRate }}% MWST. {{ calculatedTax.tax|currency(currencyIsoCode) }}
{% endfor %}
Gesamtkosten Brutto: {{ order.amountTotal|currency(currencyIsoCode,decimals=decimals) }}
{% if displayRounded %}
Gesamtkosten Brutto gerundet: {{ order.price.totalPrice|currency(currencyIsoCode,decimals=order.totalRounding.decimals) }}
{% endif %}

{% set lastTransaction = "" %}

{% for transaction in order.transactions|sort((a, b) => a.createdAt <=> b.createdAt) %}
{% set lastTransaction = transaction %}
{% endfor %}

Gewählte Zahlungsart: {{ lastTransaction.paymentMethod.translated.name }}
{{ lastTransaction.paymentMethod.translated.description }}

Kommentare:
{{ note|replace({"/ ": "\n"}) | raw }}
{% if qrImage is not empty %}
    {{ "NovalnetPayment.text.epcQrCodeDesc"|trans|sw_sanitize }}
    Bitte verwenden Sie den Link, um den QR-Code zu scannen: {{ qrImage }}
{% endif %}

{% if "NovalnetInvoiceInstalment" in lastTransaction.paymentMethod.handlerIdentifier or "NovalnetSepaInstalment" in lastTransaction.paymentMethod.handlerIdentifier %}
{% if instalmentInfo is not empty %}
S.Nr   Novalnet-Transaktions-ID          Betrag       Nächste Rate fällig am
{% for info in instalmentInfo %}
{% set amount = info.amount/100 %}
{{ loop.index }}   {{ info.reference ? info.reference : "-          " }}       {{ amount ? amount|currency(currencyIsoCode): "-" }}           {% if instalmentInfo[loop.index + 1] is defined %} {{ instalmentInfo[loop.index + 1].cycleDate ? instalmentInfo[loop.index + 1].cycleDate|date("Y-m-d") : "-" }}{% else %}{{ "-" }}{% endif %}

{% endfor %}
{% endif %}
{% endif %}

{% if delivery is not null %}
Gewählte Versandart: {{ delivery.shippingMethod.translated.name }}
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

        /** @var MailTemplateEntity|null */
        return $this->mailTemplateRepo->search($criteria, $this->context)->first();
    }
}
