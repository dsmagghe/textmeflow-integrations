import template from './textmeflow-index.html.twig';
import './textmeflow-index.scss';

const { Component, Mixin } = Shopware;

const POLL_INTERVAL_MS = 3000;
const MAX_AUTO_RESTARTS = 3;

Component.register('textmeflow-index', {
    template,

    inject: ['textmeflowApiService'],

    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: true,
            status: { connected: false, tokenInvalid: false, email: null, plan: null, phone: null, apiError: null },
            pair: { id: null, qr: null, status: null },
            pollTimer: null,
            restarts: 0,
            pairExpiredFinally: false,
            deviceLimit: false,
            pairError: false,
            isConnecting: false,
            testPhone: '',
            isSendingTest: false,
            showDisconnectConfirm: false,
        };
    },

    computed: {
        locale() {
            return Shopware.State.get('session').currentLocale || 'en-GB';
        },

        isWhatsappConnected() {
            return this.status.connected && this.status.phone !== null;
        },

        pricingUrl() {
            return 'https://textmeflow.eu/pricing';
        },
    },

    created() {
        this.showReturnNotice();
        this.loadStatus();
    },

    beforeDestroy() {
        this.stopPolling();
    },

    beforeUnmount() {
        this.stopPolling();
    },

    methods: {
        /** Message after the browser came back from textmeflow.eu. */
        showReturnNotice() {
            const result = this.$route.query.tmf;
            if (result === 'connected') {
                this.createNotificationSuccess({ message: this.$tc('textmeflow.notice.connected') });
            } else if (result === 'denied') {
                this.createNotificationWarning({ message: this.$tc('textmeflow.notice.denied') });
            } else if (result === 'failed') {
                this.createNotificationError({ message: this.$tc('textmeflow.notice.failed') });
            }
        },

        loadStatus() {
            this.isLoading = true;

            return this.textmeflowApiService.status().then((status) => {
                this.status = status;
                if (status.connected && status.phone === null && !status.apiError) {
                    return this.startPair();
                }
                return null;
            }).catch(() => {
                this.createNotificationError({ message: this.$tc('textmeflow.notice.statusFailed') });
            }).finally(() => {
                this.isLoading = false;
            });
        },

        onConnect() {
            this.isConnecting = true;
            this.textmeflowApiService.startConnect(this.locale.substring(0, 2)).then(({ url }) => {
                // Leave the administration for textmeflow.eu; it sends the browser back to the callback.
                window.location.href = url;
            }).catch((error) => {
                this.isConnecting = false;
                const code = error?.response?.data?.error;
                this.createNotificationError({
                    message: code === 'https_required'
                        ? this.$tc('textmeflow.notice.httpsRequired')
                        : this.$tc('textmeflow.notice.failed'),
                });
            });
        },

        startPair() {
            this.stopPolling();
            this.pairError = false;
            this.deviceLimit = false;

            return this.textmeflowApiService.startPair().then((pair) => {
                this.pair = { id: pair.pairId, qr: pair.qr, status: pair.status };
                this.pairExpiredFinally = false;
                this.startPolling();
            }).catch((error) => {
                if (error?.response?.data?.error === 'device_limit_reached') {
                    this.deviceLimit = true;
                } else {
                    this.pairError = true;
                }
            });
        },

        onNewCode() {
            this.restarts = 0;
            this.startPair();
        },

        startPolling() {
            this.stopPolling();
            this.pollTimer = window.setInterval(() => this.pollPair(), POLL_INTERVAL_MS);
        },

        stopPolling() {
            if (this.pollTimer !== null) {
                window.clearInterval(this.pollTimer);
                this.pollTimer = null;
            }
        },

        pollPair() {
            if (!this.pair.id) {
                return;
            }

            this.textmeflowApiService.pairStatus(this.pair.id).then((pair) => {
                if (pair.status === 'connected') {
                    this.stopPolling();
                    this.pair = { id: null, qr: null, status: null };
                    this.loadStatus();
                } else if (pair.status === 'expired') {
                    this.stopPolling();
                    if (this.restarts < MAX_AUTO_RESTARTS) {
                        this.restarts += 1;
                        this.startPair();
                    } else {
                        this.pairExpiredFinally = true;
                    }
                } else if (pair.qr) {
                    // The QR code rotates; always show the latest one.
                    this.pair = { ...this.pair, qr: pair.qr, status: pair.status };
                }
            }).catch(() => {
                // A failed poll is retried on the next tick.
            });
        },

        onSendTest() {
            this.isSendingTest = true;
            this.textmeflowApiService.sendTest(this.testPhone, this.locale.substring(0, 2)).then(() => {
                this.createNotificationSuccess({ message: this.$tc('textmeflow.notice.testSent') });
            }).catch((error) => {
                const code = error?.response?.data?.error;
                this.createNotificationError({
                    message: code === 'invalid_number'
                        ? this.$tc('textmeflow.notice.invalidNumber')
                        : this.$tc('textmeflow.notice.testFailed'),
                });
            }).finally(() => {
                this.isSendingTest = false;
            });
        },

        onDisconnect() {
            this.showDisconnectConfirm = false;
            this.stopPolling();
            this.textmeflowApiService.disconnect().then(() => {
                this.pair = { id: null, qr: null, status: null };
                this.status = {
                    connected: false, tokenInvalid: false, email: null, plan: null, phone: null, apiError: null,
                };
                this.createNotificationSuccess({ message: this.$tc('textmeflow.notice.disconnected') });
            }).catch(() => {
                this.createNotificationError({ message: this.$tc('textmeflow.notice.failed') });
            });
        },

        openMessageSettings() {
            this.$router.push({ name: 'sw.extension.config', params: { namespace: 'TextMeFlow' } });
        },
    },
});
