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
    
    refundPayment(orderId, orderNumber, refundAmount, reason, instalmentCycleTid, instalmentCancel) {
        const apiRoute = `_action/${this.getApiBasePath()}/refund-payment`;

        return this.httpClient.post(
            apiRoute,
            {
				orderId: orderId,
				orderNumber: orderNumber,
                refundAmount: refundAmount,
                reason: reason,
                instalmentCycleTid: instalmentCycleTid,
                instalmentCancel: instalmentCancel,
            },
            {
                headers: this.getBasicHeaders()
            }
        ).then((response) => {
            return ApiService.handleResponse(response);
        });
    }
    
    managePayment(orderId, orderNumber, status) {
        const apiRoute = `_action/${this.getApiBasePath()}/manage-payment`;

        return this.httpClient.post(
            apiRoute,
            {
				orderId: orderId,
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
    
    updateAmount(orderId, orderNumber, amount) {
        const apiRoute = `_action/${this.getApiBasePath()}/update-amount`;

        return this.httpClient.post(
            apiRoute,
            {
				orderId: orderId,
				orderNumber: orderNumber,
                amount: amount,                
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
