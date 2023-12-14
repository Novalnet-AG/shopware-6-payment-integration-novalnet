const ApiService = Shopware.Classes.ApiService;

class NovalPaymentApiCredentialsService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'novalnet-payment') {
        super(httpClient, loginService, apiEndpoint);
    }

    validateApiCredentials(clientId, accessKey) {
        const headers = this.getBasicHeaders();

        return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/validate-api-credentials`,
                {
					clientId,
					accessKey
				},
				{
					headers: this.getBasicHeaders()
				}
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
    }
    
    getNovalnetAmount(orderNumber){
		const headers = this.getBasicHeaders();
		
		return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/transaction-amount`,
                {
					orderNumber
				},
				{
					headers: this.getBasicHeaders()
				}
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
	}
	
	refundPayment(orderNumber, refundAmount, reason, instalmentCycleTid){
		const headers = this.getBasicHeaders();
		
		return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/refund-amount`,
                {
					orderNumber,
					refundAmount,
					reason,
					instalmentCycleTid
				},
				{
					headers: this.getBasicHeaders()
				}
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
	}
	
	managePayment(orderNumber, status){
		const headers = this.getBasicHeaders();
		
		return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/manage-payment`,
                {
					orderNumber: orderNumber,
					status: status
				},
				{
					headers: this.getBasicHeaders()
				}
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
	}
	
	instalmentCancel(orderNumber, cancelType){
		const headers = this.getBasicHeaders();
		
		return this.httpClient
            .post(
                `_action/${this.getApiBasePath()}/instalment-cancel`,
                {
					orderNumber,
					cancelType
				},
				{
					headers: this.getBasicHeaders()
				}
            )
            .then((response) => {
                return ApiService.handleResponse(response);
            });
	}
	
	    BookOrderAmount(orderNumber, bookAmount) {
        const apiRoute = `_action/${this.getApiBasePath()}/book-amount`;

        return this.httpClient.post(
            apiRoute,
            {
				orderNumber,
				bookAmount
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    getNovalnetPaymentMethod (orderNumber) {
        const apiRoute = `_action/${this.getApiBasePath()}/novalnet-paymentmethod`;

        return this.httpClient.post(
            apiRoute,
            {
				orderNumber
				
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    configureWebhookUrl(url, productActivationKey, paymentAccessKey) {
        const apiRoute = `_action/${this.getApiBasePath()}/webhook-url-configure`;

        return this.httpClient.post(
            apiRoute,
            {
				url,
				productActivationKey,
				paymentAccessKey
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    novalnetPayment(shippingaddress, billingaddress, amount,  currency, customer) {
        const apiRoute = `_action/${this.getApiBasePath()}/novalnet-payment`;

        return this.httpClient.post(
            apiRoute,
            {
				shippingaddress,
				billingaddress,
				amount,
				currency,
				customer
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    paymentDetails(paymentSelected) {
        const apiRoute = `_action/${this.getApiBasePath()}/novalnet-select-payment`;

        return this.httpClient.post(
            apiRoute,
            {
				paymentSelected
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    paymentValue(value, customer) {
        const apiRoute = `_action/${this.getApiBasePath()}/payment-value-data`;

        return this.httpClient.post(
            apiRoute,
            {
				value,
				customer
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    
}

export default NovalPaymentApiCredentialsService;
