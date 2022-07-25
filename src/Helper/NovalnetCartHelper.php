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
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressEntity;
use Shopware\Core\Checkout\Customer\CustomerEntity;
use Shopware\Core\Checkout\Customer\SalesChannel\AbstractRegisterRoute;
use Shopware\Core\Checkout\Customer\SalesChannel\AccountService;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepositoryInterface;
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
     * @var EntityRepositoryInterface
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
    
    public function __construct(
        NovalnetHelper $helper,
        SalesChannelContextSwitcher $contextSwitcher,
        SalesChannelContextServiceInterface $contextService,
        CartService $cartService,
        AbstractRegisterRoute $registerRoute,
        AccountService $accountService,
        EntityRepositoryInterface $customerRepository,
        string $swVersion
    ) {
		$this->helper              = $helper;
		$this->contextSwitcher     = $contextSwitcher;
		$this->contextService      = $contextService;
		$this->cartService         = $cartService;
		$this->registerRoute       = $registerRoute;
		$this->customerRepository  = $customerRepository;
		$this->accountService      = $accountService;
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
		if(!empty($data))
		{
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
     * Load and return the cart value
     * 
     * @param array $data
     * @param SalesChannelContext $salesChannelContext
     *
     * @return Cart|null
     */
    public function getCart(array $data, SalesChannelContext $salesChannelContext): ?Cart
    {
		if(!empty($data))
		{
			$dataBag = new DataBag();
			$paymentID  = $this->helper->getApplePayPaymentId($salesChannelContext->getContext());
			
			$dataBag->add([
				SalesChannelContextService::PAYMENT_METHOD_ID => $paymentID
			]);
				
			if(!empty($data['countryCode']))
			{
				$countryID  = $this->helper->getCountryIdFromCode($data['countryCode'], $salesChannelContext->getContext());
				$dataBag->add([
					SalesChannelContextService::COUNTRY_ID => $countryID
				]);
			} else if(!empty($data['shippingInfo']['identifier']))
			{
				$dataBag->add([
					SalesChannelContextService::SHIPPING_METHOD_ID => $data['shippingInfo']['identifier']
				]);
			}
			
			$this->contextSwitcher->update($dataBag, $salesChannelContext);
			$salesChannelID = $this->getSalesChannelID($salesChannelContext);
			$salesChannelContext = $this->getSalesChannelContext($salesChannelID, $salesChannelContext->getToken());
			$cart = $this->cartService->getCart($salesChannelContext->getToken(), $salesChannelContext);
			$cart = $this->cartService->recalculate($cart, $salesChannelContext);
			return $cart;
		}
		return null;
    }
    
    /**
     * Load and return the cart value
     * 
     * @param array $data
     * @param SalesChannelContext $salesChannelContext
     *
     * @return void
     */
    public function updateSalesChannel(array $data, SalesChannelContext $salesChannelContext): void
    {
		$dataBag = new DataBag();
		$paymentID  = $this->helper->getApplePayPaymentId($salesChannelContext->getContext());
		
		$dataBag->add([
			SalesChannelContextService::PAYMENT_METHOD_ID => $paymentID
		]);
		
		if(!empty($data ['wallet']['chosen_shipping_method']))
		{
			$dataBag->add([
				SalesChannelContextService::SHIPPING_METHOD_ID => $data ['wallet']['chosen_shipping_method']['identifier']
			]);
		}
		
		$this->contextSwitcher->update($dataBag, $salesChannelContext);
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
            'salutationId' => $this->helper->getSalutationId($salesChannelContext->getContext()),
            'email' => $response ['wallet']['shipping']['emailAddress'],
            'firstName' => $response ['wallet']['billing']['givenName'],
            'lastName' => $response ['wallet']['billing']['familyName'],
            'billingAddress' => $this->getAddressData($response, $salesChannelContext),
            'shippingAddress' => $this->getAddressData($response, $salesChannelContext, 'shipping'),
            'acceptedDataProtection' => true,
        ]);
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
		$criteria = new Criteria();
        $criteria->addAssociation('addresses');
        $criteria->addFilter(new EqualsFilter('guest', true));
        $criteria->addFilter(new EqualsFilter('email', $response ['wallet']['shipping']['emailAddress']));
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
        if ($addresses !== null) {
            foreach ($addresses as $address) {
				if($this->isIdenticalAddress($address, $billingAddress))
				{
					$matchedBillingAddress = $address;
				}
				
				if($this->isIdenticalAddress($address, $shippingAddress))
				{
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
            'firstName' => $response['wallet']['billing']['givenName'],
            'lastName' => $response['wallet']['billing']['familyName'],
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
		$serverData = $response['wallet'][$type];
		$countryId  = $this->helper->getCountryIdFromCode($serverData['countryCode'], $salesChannelContext->getContext());
		$countryStateId  = $serverData['administrativeArea'] ? $this->helper->getCountryStateIdFromCode($serverData['countryCode'].'-'.$serverData['administrativeArea'], $salesChannelContext->getContext()) : null;
		return [
            'firstName' => $serverData['givenName'],
            'lastName' => $serverData['familyName'],
            'salutationId' => $this->helper->getSalutationId($salesChannelContext->getContext()),
            'street' => ($serverData['addressLines'][0] ?? '') . ' ' . ($serverData['addressLines'][1] ?? ''),
            'zipcode' => $serverData['postalCode'],
            'countryId' => $countryId,
            'countryStateId' => $countryStateId,
            'city' => $serverData['locality']
        ];
	}
	
	private function isIdenticalAddress(CustomerAddressEntity $address, array $addressData): bool
    {
        foreach (['firstName', 'lastName', 'street', 'zipcode', 'countryId', 'city' ] as $key) {
            if ($address->get($key) !== ($addressData[$key] ?? null)) {
                return false;
            }
        }

        return true;
    }
}
