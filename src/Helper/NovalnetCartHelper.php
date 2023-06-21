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

namespace Novalnet\NovalnetPayment\Helper;

use Novalnet\NovalnetPayment\Helper\NovalnetHelper;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Content\Newsletter\Exception\SalesChannelDomainNotFoundException;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\MultiFilter;
use Shopware\Core\Framework\Validation\DataBag\DataBag;
use Shopware\Core\Framework\Validation\DataBag\RequestDataBag;
use Shopware\Core\Framework\Uuid\Uuid;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextService;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceInterface;
use Shopware\Core\System\SalesChannel\Context\SalesChannelContextServiceParameters;
use Shopware\Core\System\SalesChannel\SalesChannel\SalesChannelContextSwitcher;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Core\System\SystemConfig\SystemConfigService;

/**
 * NovalnetHelper Class.
 */
class NovalnetCartHelper
{
    /**
     * @var NovalnetHelper
     */
    private $helper;
    
    /**
     * @var SalesChannelContextSwitcher
     */
    private $contextSwitcher;
    
    /**
     * @var SalesChannelContextServiceInterface
     */
    private $contextService;
    
    /**
     * @var CartService
     */
    private $cartService;
    
    /**
     * @var AbstractRegisterRoute
     */
    private $registerRoute;
    
    /**
     * @var EntityRepository
     */
    private $customerRepository;
    
    /**
     * @var AccountService
     */
    private $accountService;
    
    /**
     * @var string
     */
    private $swVersion;
    
    /**
     * @var SystemConfigService
     */
    private $systemConfigService;
    
    public function __construct(
        NovalnetHelper $helper,
        SalesChannelContextSwitcher $contextSwitcher,
        SalesChannelContextServiceInterface $contextService,
        CartService $cartService,
        AbstractRegisterRoute $registerRoute,
        AccountService $accountService,
        EntityRepository $customerRepository,
        SystemConfigService $systemConfigService,
        string $swVersion
    ) {
        $this->helper              = $helper;
        $this->contextSwitcher     = $contextSwitcher;
        $this->contextService      = $contextService;
        $this->cartService         = $cartService;
        $this->registerRoute       = $registerRoute;
        $this->customerRepository  = $customerRepository;
        $this->accountService      = $accountService;
        $this->systemConfigService = $systemConfigService;
        $this->swVersion           = $swVersion;
    }
    
    /**
     * Load and return the cart value
     *
     * @param array $data
     * @param SalesChannelContext $salesChannelContext
     *
     * @return Cart|null
     */
    public function addToCart(array $data, Cart $cart, SalesChannelContext $salesChannelContext): ?Cart
    {
        if (!empty($data)) {
            $items = [];
            /** @var LineItem $lineItem */
            $lineItem = new LineItem(
                $data['productId'],
                $data['type'],
                $data['productId'],
                (int) $data['quantity']
            );

            $lineItem->setStackable(true);
            $lineItem->setRemovable(true);

            $items[] = $lineItem;
            
            $cart = $this->cartService->add($cart, $items, $salesChannelContext);
            return $cart;
        }
        return null;
    }
    
    /**
     * Update the shipping and payment data
     *
     * @param string $shippingMethodId
     * @param SalesChannelContext $salesChannelContext
     *
     * @return SalesChannelContext
     */
    public function updateShippingMethod(string $shippingMethodId, SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        $dataBag = new DataBag();
        $dataBag->add([
            SalesChannelContextService::SHIPPING_METHOD_ID => $shippingMethodId,
            SalesChannelContextService::LANGUAGE_ID => $salesChannelContext->getSalesChannel()->getLanguageId() ?? $salesChannelContext->getContext()->getLanguageId(),
            SalesChannelContextService::CURRENCY_ID => $salesChannelContext->getCurrency()->getId()
        ]);
        $this->contextSwitcher->update($dataBag, $salesChannelContext);
        $salesChannelID = $this->getSalesChannelID($salesChannelContext);
        return $this->getSalesChannelContext($salesChannelID, $salesChannelContext->getToken());
    }
    
    /**
     * Update the sheet countryID.
     *
     * @param string $countryID
     * @param SalesChannelContext $salesChannelContext
     *
     * @return SalesChannelContext
     */
    public function updateCountryId(string $countryID, SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        $dataBag = new DataBag();
        $dataBag->add([
            SalesChannelContextService::COUNTRY_ID => $countryID,
            SalesChannelContextService::LANGUAGE_ID => $salesChannelContext->getSalesChannel()->getLanguageId() ?? $salesChannelContext->getContext()->getLanguageId(),
            SalesChannelContextService::CURRENCY_ID => $salesChannelContext->getCurrency()->getId()
        ]);
        $this->contextSwitcher->update($dataBag, $salesChannelContext);
        $salesChannelID = $this->getSalesChannelID($salesChannelContext);
        return $this->getSalesChannelContext($salesChannelID, $salesChannelContext->getToken());
    }
    
    /**
     * Update the sheet customerId.
     *
     * @param string $customerId
     * @param SalesChannelContext $salesChannelContext
     *
     * @return SalesChannelContext
     */
    public function updateCustomerSalesChannel(string $customerId, SalesChannelContext $salesChannelContext): SalesChannelContext
    {
        $dataBag = new DataBag();
        $dataBag->add([
            SalesChannelContextService::CUSTOMER_ID => $customerId,
            SalesChannelContextService::LANGUAGE_ID => $salesChannelContext->getContext()->getLanguageId() ?? $salesChannelContext->getSalesChannel()->getLanguageId(),
        ]);
        $this->contextSwitcher->update($dataBag, $salesChannelContext);
        $salesChannelID = $this->getSalesChannelID($salesChannelContext);
        return $this->getSalesChannelContext($salesChannelID, $salesChannelContext->getToken());
    }
    
    /**
     * Update the payment method ID.
     *
     * @param string $paymentMethodId
     * @param SalesChannelContext $salesChannelContext
     * @param string|null $currencyId
     *
     * @return SalesChannelContext
     */
    public function updatePaymentMethodId(string $paymentMethodId, SalesChannelContext $salesChannelContext, string $currencyId = null): SalesChannelContext
    {
        $dataBag = new DataBag();
        $dataBag->add([
            SalesChannelContextService::PAYMENT_METHOD_ID => $paymentMethodId,
            SalesChannelContextService::LANGUAGE_ID => $salesChannelContext->getContext()->getLanguageId() ?? $salesChannelContext->getSalesChannel()->getLanguageId(),
            SalesChannelContextService::CURRENCY_ID => $currencyId ?? $salesChannelContext->getCurrency()->getId()
        ]);
        $this->contextSwitcher->update($dataBag, $salesChannelContext);
        $salesChannelID = $this->getSalesChannelID($salesChannelContext);
        return $this->getSalesChannelContext($salesChannelID, $salesChannelContext->getToken());
    }
    
    /**
     * return the available shipping method.
     *
     * @param string $countryCode
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array|null
     */
    public function getAvailableShippingMethod(string $countryCode, SalesChannelContext $salesChannelContext): ?array
    {
        $dataBag = new DataBag();
        $countryID  = $this->helper->getCountryIdFromCode($countryCode, $salesChannelContext->getContext());
        
        // wallet sheet country code updated in saleschannel
        $salesChannelContext = $this->updateCountryId($countryID, $salesChannelContext);
        
        if (!is_null($salesChannelContext->getCustomer())) {
            $criteria = new Criteria([$salesChannelContext->getCustomer()->getId()]);
            $customer = $this->customerRepository->search($criteria, $salesChannelContext->getContext())->first();
            $data = [
                'id' => $customer->getDefaultShippingAddressId(),
                'countryId' => $countryID
            ];
            $this->helper->updateCustomerShippingAddress($data, $salesChannelContext);
            $salesChannelContext = $this->updateCustomerSalesChannel($salesChannelContext->getCustomer()->getId(), $salesChannelContext);
        }
        
        $activeShippingMethods = $this->helper->getActiveShippingMethods($salesChannelContext);
        $defaultShippingId     = $salesChannelContext->getShippingMethod()->getId();
        $shippingDetails       = $allShippingDetails = [];
        $selectedShippingMethod = null;
        
        foreach ($activeShippingMethods as $shippingMethod) {
            $context = $this->updateShippingMethod($shippingMethod->getId(), $salesChannelContext);
            $cart    = $this->getCalculatedCart($context);
            $value = $this->helper->getShippingCosts($cart);

            if ($defaultShippingId == $shippingMethod->getId()) {
                $selectedShippingMethod = ['label' => $shippingMethod->getName(), 'amount' => round((float) sprintf('%0.2f', $value) * 100), 'identifier' => $shippingMethod->getId(), 'detail' => $shippingMethod->getDescription()];
            } else {
                $allShippingDetails[] = ['label' => $shippingMethod->getName(), 'amount' => round((float) sprintf('%0.2f', $value) * 100), 'identifier' => $shippingMethod->getId(), 'detail' => $shippingMethod->getDescription()];
            }
        }
        
        if ($selectedShippingMethod != null) {
            $shippingDetails[] = $selectedShippingMethod;
        }
        
        foreach ($allShippingDetails as $method) {
            $shippingDetails[] = $method;
        }
        
        # restore our previously used shipping method
        # this is very important to avoid accidental changes in the context
        $this->updateShippingMethod($defaultShippingId, $salesChannelContext);
        return $shippingDetails;
    }
    
    /**
     * Get the formatted cart.
     *
     * @param Cart $cart
     * @param SalesChannelContext $salesChannelContext
     *
     * @return array|null
     */
    public function getFormattedCart(Cart $cart, SalesChannelContext $salesChannelContext): ?array
    {
        $orderDetails = [];
        foreach ($cart->getLineItems() as $lineItem) {
            $label = $lineItem->getLabel(). ' ('. $lineItem->getQuantity(). ' x '. html_entity_decode($salesChannelContext->getCurrency()->getSymbol()). sprintf('%0.2f', $lineItem->getPrice()->getUnitPrice()).')';

            $orderDetails[] = array('label' => $label, 'type' => 'SUBTOTAL', 'amount' => round((float) sprintf('%0.2f', $lineItem->getPrice()->getTotalPrice()) * 100));
        }
        
        foreach ($cart->getDeliveries() as $delivery) {
            $label = $delivery->getShippingMethod()->getName(). ' ('. $delivery->getShippingCosts()->getQuantity(). ' x '. html_entity_decode($salesChannelContext->getCurrency()->getSymbol()). sprintf('%0.2f', $delivery->getShippingCosts()->getUnitPrice()).')';

            $orderDetails[] = array('label' => $label, 'type' => 'SUBTOTAL', 'amount' => round((float) sprintf('%0.2f', $delivery->getShippingCosts()->getTotalPrice()) * 100));
        }
        
        foreach ($cart->getPrice()->getCalculatedTaxes() as $tax) {
            $label = $this->helper->getVatLabel($tax->getTaxRate(), $salesChannelContext->getContext());
            $orderDetails[] = array('label' => $label, 'type' => 'SUBTOTAL', 'amount' => round((float) sprintf('%0.2f', $tax->getTax()) * 100));
        }
        return $orderDetails;
    }
    
    /**
     * Return the calculated cart.
     *
     * @param SalesChannelContext $salesChannelContext
     *
     * @return Cart
     */
    public function getCalculatedCart(SalesChannelContext $salesChannelContext): Cart
    {
        $cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
        return $this->cartService->recalculate($cart, $salesChannelContext);
    }
    
    /**
     * Return the current salechannel ID
     *
     * @param SalesChannelContext $context
     * @return string
     */
    public function getSalesChannelID(SalesChannelContext $context): string
    {
        return $context->getSalesChannel()->getId();
    }
    
    /**
     * Create saleschannel context from saleschannelID and token
     *
     * @param string $salesChannelID
     * @param string $token
     * @return SalesChannelContext
     */
    public function getSalesChannelContext(string $salesChannelID, string $token): SalesChannelContext
    {
        if (version_compare($this->swVersion, '6.4', '>=')) {
            $params = new SalesChannelContextServiceParameters($salesChannelID, $token);
            return $this->contextService->get($params);
        }

        /* @phpstan-ignore-next-line */
        $context = $this->contextService->get($salesChannelID, $token, null);

        return $context;
    }
    
    /**
     * Create the guest account for end-user not logged in
     *
     * @param array $response
     * @param SalesChannelContext $salesChannelContext
     *
     */
    public function createNewCustomer(array $response, SalesChannelContext $salesChannelContext): string
    {
        $token = $this->findExistingCustomer($response, $salesChannelContext);
        if ($token !== null) {
            return $token;
        }
        
        return $this->registerNewCustomer($response, $salesChannelContext);
    }

    /**
     * Check the mail Id already registered (or) not
     *
     * @param array $response
     * @param SalesChannelContext $salesChannelContext
     *
     */
    public function registerNewCustomer(array $response, SalesChannelContext $salesChannelContext): string
    {
        $customerDataBag = $this->getCustomerDataBag($response, $salesChannelContext);
        
        $response = $this->registerRoute->register($customerDataBag, $salesChannelContext, false);
        
        $newToken = $response->headers->get(PlatformRequest::HEADER_CONTEXT_TOKEN);

        if ($newToken === null || $newToken === '') {
            return $salesChannelContext->getToken();
        }

        return $newToken;
    }
    
    public function getCustomerDataBag(array $response, SalesChannelContext $salesChannelContext): RequestDataBag
    {
        return new RequestDataBag([
            'guest' => true,
            'accountType' => CustomerEntity::ACCOUNT_TYPE_PRIVATE,
            'email' => $response['order']['billing']['contact']['email'] ?? $response['order']['shipping']['contact']['email'],
            'storefrontUrl' => $this->getStorefrontUrl($salesChannelContext),
            'salutationId' => $this->helper->getSalutationId($salesChannelContext->getContext()),
            'firstName' => $response['order']['billing']['contact']['firstName'] ?? $response['order']['shipping']['contact']['firstName'],
            'lastName' => $response['order']['billing']['contact']['lastName'] ?? $response['order']['shipping']['contact']['lastName'],
            'billingAddress' => $this->getAddressData($response, $salesChannelContext),
            'shippingAddress' => $this->getAddressData($response, $salesChannelContext, 'shipping'),
            'acceptedDataProtection' => true,
        ]);
    }
    
    private function getStorefrontUrl(SalesChannelContext $salesChannelContext): string
    {
        $salesChannel = $salesChannelContext->getSalesChannel();
        $domainUrl = $this->systemConfigService->get('core.loginRegistration.doubleOptInDomain', $salesChannel->getId());

        if (\is_string($domainUrl) && $domainUrl !== '') {
            return $domainUrl;
        }

        $domains = $salesChannel->getDomains();
        if ($domains === null) {
            throw new SalesChannelDomainNotFoundException($salesChannel);
        }

        $domain = $domains->first();
        if ($domain === null) {
            throw new SalesChannelDomainNotFoundException($salesChannel);
        }

        return $domain->getUrl();
    }
    
    /**
     * Check the mail Id already registered (or) not
     *
     * @param array $response
     * @param SalesChannelContext $salesChannelContext
     *
     */
    public function findExistingCustomer(array $response, SalesChannelContext $salesChannelContext): ?string
    {
        $email = $response ['order']['billing']['contact']['email'] ?? $response ['order']['shipping']['contact']['email'];
        $criteria = new Criteria();
            $criteria->addAssociation('addresses');
            $criteria->addFilter(new EqualsFilter('guest', true));
            $criteria->addFilter(new EqualsFilter('email', $email));
            $criteria->addFilter(new MultiFilter(MultiFilter::CONNECTION_OR, [
                new EqualsFilter('boundSalesChannelId', null),
                new EqualsFilter('boundSalesChannelId', $salesChannelContext->getSalesChannel()->getId()),
            ]));

        /** @var CustomerEntity|null $customer */
        $customer = $this->customerRepository->search($criteria, $salesChannelContext->getContext())->first();
        if ($customer === null) {
            return null;
        }
        
        // update customer addresses
        $this->updateCustomer($customer, $response, $salesChannelContext);
        
        if (!\method_exists($this->accountService, 'loginById')) {
            return $this->accountService->login($customer->getEmail(), $salesChannelContext, true);
        }
        
        return $this->accountService->loginById($customer->getId(), $salesChannelContext);
    }
    
    public function updateCustomer(CustomerEntity $customer, array $response, SalesChannelContext $salesChannelContext): void
    {
        $billingAddress  = $this->getAddressData($response, $salesChannelContext);
        $shippingAddress = $this->getAddressData($response, $salesChannelContext, 'shipping');
        
        $matchedBillingAddress = $matchedShippingAddress = null;
        $addresses = $customer->getAddresses();
        
        if ($addresses == null)
        {
			$criteria = new Criteria([$customer->getId()]);
			$criteria->addAssociation('addresses');
			$customerAddress = $this->customerRepository->search($criteria, $salesChannelContext->getContext())->first();
			$addresses = $customerAddress->getAddresses();
		}
		
        if ($addresses !== null) {
            foreach ($addresses as $address) {
                if ($this->isIdenticalAddress($address, $billingAddress)) {
                    $matchedBillingAddress = $address;
                }
                
                if ($this->isIdenticalAddress($address, $shippingAddress)) {
                    $matchedShippingAddress = $address;
                }
            }
        }
        
        $billingAddressId = $matchedBillingAddress === null ? Uuid::randomHex() : $matchedBillingAddress->getId();
        $shippingAddressId = $matchedShippingAddress === null ? Uuid::randomHex() : $matchedShippingAddress->getId();
        $salutationId = $this->helper->getSalutationId($salesChannelContext->getContext());
        
        $customerData = [
            'id' => $customer->getId(),
            'defaultShippingAddressId' => $shippingAddressId,
            'defaultBillingAddressId' => $billingAddressId,
            'firstName' => $response['order']['billing']['contact']['firstName'],
            'lastName' => $response['order']['billing']['contact']['lastName'],
            'salutationId' => $salutationId,
            'addresses' => [
                array_merge($billingAddress, [
                    'id' => $billingAddressId,
                    'salutationId' => $salutationId,
                ]),
                array_merge($shippingAddress, [
                    'id' => $shippingAddressId,
                    'salutationId' => $salutationId,
                ]),
            ],
        ];
        
        $this->customerRepository->update([$customerData], $salesChannelContext->getContext());
    }
    
    private function getAddressData(array $response, SalesChannelContext $salesChannelContext, $type = 'billing'): array
    {
        $serverData = $response['order'][$type]['contact'];
        $countryId  = $this->helper->getCountryIdFromCode($serverData['countryCode'], $salesChannelContext->getContext());
        $countryStateId  = $serverData['administrativeArea'] ? $this->helper->getCountryStateIdFromCode($serverData['countryCode'].'-'.$serverData['administrativeArea'], $salesChannelContext->getContext()) : null;
        return [
            'firstName' => $serverData['firstName'],
            'lastName' => $serverData['lastName'],
            'salutationId' => $this->helper->getSalutationId($salesChannelContext->getContext()),
            'street' => $serverData['addressLines'],
            'zipcode' => $serverData['postalCode'],
            'phoneNumber' => $serverData['phoneNumber'],
            'countryId' => $countryId,
            'countryStateId' => $countryStateId,
            'city' => $serverData['locality']
        ];
    }
    
    private function isIdenticalAddress(CustomerAddressEntity $address, array $addressData): bool
    {
        foreach (['street', 'zipcode', 'countryId', 'city' ] as $key) {
            if ($address->get($key) !== ($addressData[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
