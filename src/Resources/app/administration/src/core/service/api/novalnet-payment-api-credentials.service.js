const ApiService = Shopware.Classes.ApiService;

class NovalPaymentApiCredentialsService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'novalnet-payment') {
        super(httpClient, loginService, apiEndpoint);
    }

    validateApiCredentials(clientId, accessKey) {
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
    
    refundPayment(orderNumber, refundAmount, reason, instalmentCycleTid) {
        const apiRoute = `_action/${this.getApiBasePath()}/refund-payment`;

        return this.httpClient.post(
            apiRoute,
            {
				orderNumber: orderNumber,
                refundAmount: refundAmount,
                reason: reason,
                instalmentCycleTid: instalmentCycleTid,
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    instalmentCancel(orderNumber, cancelType) {
        const apiRoute = `_action/${this.getApiBasePath()}/instalment-cancel`;

        return this.httpClient.post(
            apiRoute,
            {
				orderNumber: orderNumber,
                cancelType: cancelType,
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    BookOrderAmount(orderId, bookedAmount) {
        const apiRoute = `_action/${this.getApiBasePath()}/book-amount`;

        return this.httpClient.post(
            apiRoute,
            {
				orderId: orderId,
                bookedAmount: bookedAmount
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    managePayment(orderNumber, status) {
        const apiRoute = `_action/${this.getApiBasePath()}/manage-payment`;

        return this.httpClient.post(
            apiRoute,
            {
				orderNumber: orderNumber,
                status: status,                
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    getNovalnetAmount(orderNumber) {
        const apiRoute = `_action/${this.getApiBasePath()}/transaction-amount`;

        return this.httpClient.post(
            apiRoute,
            {
				orderNumber: orderNumber,
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
				url: url,
				productActivationKey: productActivationKey,
				paymentAccessKey: paymentAccessKey
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
