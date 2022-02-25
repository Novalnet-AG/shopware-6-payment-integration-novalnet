# Novalnet payment plugin for Shopware 6

Novalnet payment plugin for Shopware 6 simplifies your daily work by automating the entire payment process, from checkout till collection. The Shopware 6 payment plugin is designed to help you increase your sales by offering various international and local payment methods.


## Why Shopware 6 with Novalnet? 

Shopware 6 is the next generation shop system based on advanced hook system. With the open source and commercial versions, users can easily create Shopware 6 storefronts based on Twig and Bootstrap technologies. Novalnet’s Shopware 6 payment plugin supports international and local payments including payment guarantee and cash payment particularly for Shopware. We encrypt our end customer data (strong encryption) to free you from data protection concerns and from payment related licenses.

## Advantages

-	Easy configuration for all payment methods - international and local
-	One platform for all payment types and related services
-	Complete automation of all payment processes
-	More than 50 fraud prevention modules integrated to prevent risk in real-time
-	Effortless configuration of risk management with fraud prevention
-	Comprehensive affiliate system with automated split conversion of transaction on revenue sharing
-	No PCI DSS certification required when using our payment module
-	Real-time monitoring of the payment methods & transaction flows 
-	Multilevel claims management with integrated handover to collection and various export functions for the accounting
-	Automated e-mail notification function concerning payment status reports
-	Clear real-time overview and monitoring of payment status
-	Automated bookkeeping report in XML, SOAP, CSV, MT940
-	Seamless and fast integration of the payment plugin
-	Secure SSL- encoded gateways
-	Credit/Debit Card iframe integration
-	Custom CSS configuration for Credit/Debit Card iframe
-	One-click shopping supported for Credit/Debit Cards, Direct Debit SEPA, Direct Debit SEPA with payment guarantee, Instalment by Direct Debit SEPA & PayPal
-	Easy confirmation/cancellation of on-hold transactions for Direct Debit SEPA, Direct Debit SEPA with payment guarantee, Instalment payment for Direct Debit SEPA, Credit/Debit Card, Invoice, Invoice with payment guarantee, Instalment payment for Invoice, Prepayment & PayPal
-	Refund option for Credit/Debit Cards, Direct Debit SEPA, Direct Debit SEPA with payment guarantee, Instalment by Direct Debit SEPA, Invoice, Invoice with payment guarantee, Instalment by Invoice, Prepayment, Barzahlen/viacash, Sofort, iDEAL, eps, giropay, PayPal, Przelewy24, PostFinance Card, PostFinance E-Finance & Bancontact
-	Transaction amount update option for Direct Debit SEPA, Invoice, Prepayment & Barzahlen/viacash
-	Responsive templates

## Compatibility

Shopware 6 payment plugin is compatible with below technical capabilities. 

- [x]	Shopware versions 6.3.0.0 to Shopware 6.4.8.1
- [x]	Linux based OS with Apache 2.2 or 2.4
- [x]	PHP 7.2.0 or higher
- [x]	MySQL 5.7 or higher

## Supported payment methods

-	Direct Debit SEPA
-	Credit/Debit Cards
-	Invoice
-	Prepayment
-	Invoice with payment guarantee
-	Direct Debit SEPA with payment guarantee
-	Instalment by Invoice
-	Instalment by Direct Debit SEPA
-	iDEAL
-	Sofort
-	giropay
-	Barzahlen/viacash
-	Przelewy24
-	eps
-	PayPal
-	PostFinance Card
-	PostFinance E-Finance
-	Bancontact
-	Multibanco

## Installation via Composer

#### Follow the below steps and run each command in your terminal from the shop root directory
 ##### 1. Run the below command to upload the payment plugin
 ```
 composer require novalnet/shopware6-payment
 ```
 ##### 2. Run the below command to refresh the payment plugin
 ```
 php bin/console plugin:refresh
 ```
 ##### 3. Run the below command to install and activate the payment plugin
 ```
 php bin/console plugin:install --activate --clearCache NovalnetPayment
 ```
## Quick Installation via plugin upload

1. **Login** to shop backend, move to:
   - Extensions
     - My extensions
       - Upload extensions
       
2. **Upload** the Shopware 6 payment Apps from Novalnet to the shop

3. Click **Install** to install the Novalnet Shopware 6 payment Apps

## Documentation & Support
For more information about the integration, please get in touch with us: sales@novalnet.de or +49 89 9230683-20 or by contacting us <a href="https://www.novalnet.de/kontakt/sales"> here.</a>

Novalnet AG<br>
Zahlungsinstitut (ZAG)<br>
Feringastr. 4<br>
85774 Unterföhring<br>
Deutschland<br>
Website: <a href= "https://www.novalnet.de/"> www.novalnet.de </a>

## Licenses

As a European Payment institution, Novalnet holds all necessary payment licenses to accept and process payments across the world. We also comply with the European data protection regulations to guarantee advanced data protection across the world.  

See here for [Freeware License Agreement](https://github.com/Novalnet-AG/Shopware-6-payment-integration/blob/master/LICENSE).
