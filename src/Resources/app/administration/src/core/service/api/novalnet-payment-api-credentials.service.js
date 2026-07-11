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
                orderNumber,
                refundAmount,
                reason,
                instalmentCycleTid,
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
                orderNumber,
                cancelType,
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
                orderId,
                bookedAmount
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
                orderNumber,
                status,
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
                orderNumber,
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
}

export default NovalPaymentApiCredentialsService;
