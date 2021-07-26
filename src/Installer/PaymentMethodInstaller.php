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

use Novalnet\NovalnetPayment\Service\NovalnetBancontact;
use Novalnet\NovalnetPayment\Service\NovalnetCashpayment;
use Novalnet\NovalnetPayment\Service\NovalnetCreditCard;
use Novalnet\NovalnetPayment\Service\NovalnetEps;
use Novalnet\NovalnetPayment\Service\NovalnetGiropay;
use Novalnet\NovalnetPayment\Service\NovalnetIdeal;
use Novalnet\NovalnetPayment\Service\NovalnetInvoice;
use Novalnet\NovalnetPayment\Service\NovalnetInvoiceGuarantee;
use Novalnet\NovalnetPayment\Service\NovalnetInvoiceInstalment;
use Novalnet\NovalnetPayment\Service\NovalnetMultibanco;
use Novalnet\NovalnetPayment\Service\NovalnetPaypal;
use Novalnet\NovalnetPayment\Service\NovalnetPostfinance;
use Novalnet\NovalnetPayment\Service\NovalnetPostfinanceCard;
use Novalnet\NovalnetPayment\Service\NovalnetPrepayment;
use Novalnet\NovalnetPayment\Service\NovalnetPrzelewy24;
use Novalnet\NovalnetPayment\Service\NovalnetSepa;
use Novalnet\NovalnetPayment\Service\NovalnetSepaGuarantee;
use Novalnet\NovalnetPayment\Service\NovalnetSepaInstalment;
use Novalnet\NovalnetPayment\Service\NovalnetSofort;
use Novalnet\NovalnetPayment\Installer\MediaProvider;
use Doctrine\DBAL\Connection;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\ContainsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\Plugin\Context\ActivateContext;
use Shopware\Core\Framework\Plugin\Context\DeactivateContext;
use Shopware\Core\Framework\Plugin\Context\InstallContext;
use Shopware\Core\Framework\Plugin\Context\UninstallContext;
use Shopware\Core\Framework\Plugin\Context\UpdateContext;
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
        NovalnetBancontact::class,
        NovalnetCashpayment::class,
        NovalnetCreditCard::class,
        NovalnetEps::class,
        NovalnetGiropay::class,
        NovalnetIdeal::class,
        NovalnetInvoice::class,
        NovalnetInvoiceGuarantee::class,
        NovalnetMultibanco::class,
        NovalnetPaypal::class,
        NovalnetPostfinance::class,
        NovalnetPostfinanceCard::class,
        NovalnetPrepayment::class,
        NovalnetPrzelewy24::class,
        NovalnetSepa::class,
        NovalnetSepaGuarantee::class,
        NovalnetSofort::class,
        NovalnetInvoiceInstalment::class,
        NovalnetSepaInstalment::class,
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
     * @var array
     */
    private $customFields = [
        [
            'name' => 'novalnet',
            'config' => [
                'label' => [
                    'en-GB' => 'Novalnet',
                    'de-DE' => 'Novalnet',
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
        'creditcard.css'              	=> 'body{color: #8798a9;font-family:Helvetica,Arial,sans-serif;font-weight: 500;}input{border-radius: 3px;background-clip: padding-box;box-sizing: border-box;line-height: 1.1875rem;padding: .625rem .625rem .5625rem .625rem;box-shadow: inset 0 1px 1px #dadae5;background: #f8f8fa;border: 1px solid #dadae5;border-top-color: #cbcbdb;color: #8798a9;text-align: left;font: inherit;letter-spacing: normal;margin: 0;word-spacing: normal;text-transform: none;text-indent: 0px;text-shadow: none;display: inline-block;height:40px;font-family:Helvetica,Arial,sans-serif;font-weight: 500;}input:focus{background-color: white;font-family:Helvetica,Arial,sans-serif;font-weight: 500;}',
        'creditcard.inline'            	=> true,
        'creditcard.oneclick'          	=> true,
        'sepa.oneclick'                	=> true,
        'sepaguarantee.oneclick'       	=> true,
        'sepainstalment.oneclick'       => true,
        'sepaguarantee.allowB2B'       	=> true,
        'invoiceguarantee.allowB2B'    	=> true,
        'invoiceinstalment.allowB2B'	=> true,
        'invoiceinstalment.productPageInfo'	=> true,
        'sepainstalment.productPageInfo'	=> true,
        'sepainstalment.allowB2B'		=> true,
        'invoiceguarantee.minimumOrderAmount'	=> 999,
        'sepaguarantee.minimumOrderAmount'		=> 999,
        'invoiceinstalment.minimumOrderAmount'	=> 1998,
        'sepainstalment.minimumOrderAmount'		=> 1998,
        'invoiceinstalment.cycles'              => [
			'2',
			'3',
			'4',
			'5',
			'6',
			'7',
			'8',
			'9',
			'10',
			'11',
			'12'
		],
		'sepainstalment.cycles'					=> [
			'2',
			'3',
			'4',
			'5',
			'6',
			'7',
			'8',
			'9',
			'10',
			'11',
			'12'
		]
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
    }

    /**
     * Add Payment Methods on plugin installation
     *
     */
    public function install(): void
    {
        $this->addPaymentMethods();
    }

    /**
     * Add Payment Methods on plugin update process
     *
     */
    public function update(): void
    {
        $this->addPaymentMethods();
        $this->updateMediaData();
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
     *
     */
    public function uninstall(): void
    {
        $this->deactivatePaymentMethods();
    }

    /**
     * // Delete plugin related system configurations.
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
     * Add Novalnet Payment methods
     *
     */
    private function addPaymentMethods(): void
    {
        $paymentMethods = $this->getPaymentMethods();

        if ($paymentMethods) {
            $defaultLocale = $this->getDefaultLocaleCode();
            $context = $this->context;

            foreach ($paymentMethods as $paymentMethod) {
                $paymentMethodId = $this->getHandlerIdentifier($paymentMethod->getPaymentHandler());

                // Skip insertion if the payment already exists.
                if (empty($paymentMethodId)) {
					$translations = $paymentMethod->getTranslations();
					$paymentData  = [
						[
							'name'              => $paymentMethod->getName($defaultLocale),
							'description'       => $paymentMethod->getDescription($defaultLocale),
							'position'          => $paymentMethod->getPosition(),
							'handlerIdentifier' => $paymentMethod->getPaymentHandler(),
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
					
					$paymentMethodId = $this->getHandlerIdentifier($paymentMethod->getPaymentHandler());
					$channels        = $this->container->get('sales_channel.repository')
													   ->searchIds(new Criteria(), $this->context);

					// Enable payment method on available channels.
					if (!is_null($this->container->get('sales_channel_payment_method.repository'))) {
						foreach ($channels->getIds() as $channel) {
							$data = [
								'salesChannelId'  => $channel,
								'paymentMethodId' => $paymentMethodId,
							];
							$this->container->get('sales_channel_payment_method.repository')->upsert([$data], $this->context);
						}
					}
				}

				$this->updateCustomFieldsForTranslations($paymentMethod, $paymentMethodId);
            }
        }

        // Set default configurations value for payment methods
        $customFields = $this->customFields;
        $customFieldExistsId = $this->checkCustomField('novalnet');
        
        if (!$customFieldExistsId && !is_null($this->container->get('custom_field_set.repository'))) {
            $this->context->scope(Context::SYSTEM_SCOPE, function (Context $context) use ($customFields): void {
                $this->container->get('custom_field_set.repository')->upsert($customFields, $context);
            });
        }

        $systemConfig = $this->container->get(SystemConfigService::class);
        
        if (!is_null($systemConfig)) {
            foreach ($this->defaultConfiguration as $key => $value) {
                if (!empty($value)) {
                    $systemConfig->set(self::SYSTEM_CONFIG_DOMAIN . $key, $value);
                }
            }
        }
    }

    /**
     * Check custom field
     *
     * @param string $customFieldName
     *
     * @return string
     */
    private function checkCustomField(string $customFieldName): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('name', $customFieldName));

        // Return null on given customField non existance case.
        if (is_null($this->container->get('custom_field_set.repository'))) {
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
     * Get Payment handler identifier
     *
     * @param string $handlerIdentifier
     *
     * @retrun string|null
     */
    private function getHandlerIdentifier(string $handlerIdentifier): ?string
    {
        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('handlerIdentifier', $handlerIdentifier));

        return $this->container->get('payment_method.repository')
            ->searchIds($criteria, $this->context)
            ->firstId();
    }

    /**
     * Deactivate Payment methods
     *
     */
    private function deactivatePaymentMethods(): void
    {
        $paymentMethods = $this->getPaymentMethods();
        foreach ($paymentMethods as $paymentMethod) {
            $paymentMethodId = $this->getHandlerIdentifier($paymentMethod->getPaymentHandler());
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
     * @return string
     */
    private function getDefaultLocaleCode(): ?string
    {
        $criteria = new Criteria([Defaults::LANGUAGE_SYSTEM]);
        $criteria->addAssociation('locale');

        $systemDefaultLanguage = $this->container->get('language.repository')
                                      ->search($criteria, $this->context)
                                      ->first();
        $locale = $systemDefaultLanguage->getLocale();
        if (!$locale) {
            return null;
        }

        return $locale->getCode();
    }

    /**
     * Prepare and update media files on plugin activation
     *
     */
    private function updateMediaData(): void
    {
        $paymentMethods = $this->getPaymentMethods();
        foreach ($paymentMethods as $paymentMethod) {
            $paymentMethodId = $this->getHandlerIdentifier($paymentMethod->getPaymentHandler());
            if (!$paymentMethodId) {
                continue;
            }

            // Initiate MediaProvider.
            $mediaProvider = $this->container->get(MediaProvider::class);
            
            if (!is_null($mediaProvider)) {
                $this->container->get('payment_method.repository')->update([
                    [
                        'id'       => $paymentMethodId,
                        'mediaId' => $mediaProvider->getMediaId($paymentMethod->getPaymentCode(), $this->context),
                    ],
                ], $this->context);
            }
        }
    }
    
    private function updateCustomFieldsForTranslations($paymentMethod, $paymentMethodId): void
    {
        $customFields['novalnet_payment_method_name'] = $paymentMethod->getPaymentCode();
        $customFields = json_encode($customFields, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        
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
}
