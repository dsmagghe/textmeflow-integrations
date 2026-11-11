const { ApiService } = Shopware.Classes;

/**
 * Talks to the plugin's own admin-API routes. The TextMeFlow token is never sent to the
 * browser: these routes run on the server and proxy the calls.
 */
class TextMeFlowApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = '_action/textmeflow') {
        super(httpClient, loginService, apiEndpoint);
        this.name = 'textmeflowApiService';
    }

    status() {
        return this.httpClient
            .get(`${this.getApiBasePath()}/status`, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    startConnect(locale) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/connect/start`, { locale }, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    startPair() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/pair`, {}, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    pairStatus(pairId) {
        return this.httpClient
            .get(`${this.getApiBasePath()}/pair/${encodeURIComponent(pairId)}`, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    sendTest(phone, locale) {
        return this.httpClient
            .post(`${this.getApiBasePath()}/test`, { phone, locale }, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }

    disconnect() {
        return this.httpClient
            .post(`${this.getApiBasePath()}/disconnect`, {}, { headers: this.getBasicHeaders() })
            .then(ApiService.handleResponse);
    }
}

Shopware.Service().register('textmeflowApiService', () => {
    return new TextMeFlowApiService(
        Shopware.Application.getContainer('init').httpClient,
        Shopware.Service('loginService'),
    );
});
