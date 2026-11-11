/** @odoo-module **/
// QR screen: all calls go to our own Odoo server, which talks to TextMeFlow.
// The browser never sees the TextMeFlow token, only the QR picture and a status word.
import { registry } from "@web/core/registry";
import { useService } from "@web/core/utils/hooks";
import { Component, useState, onWillStart, onWillUnmount } from "@odoo/owl";

const POLL_MS = 3000;
const MAX_AUTO_RESTARTS = 3;
const QR_PATTERN = /^data:image\/png;base64,[A-Za-z0-9+/=]+$/;

export class TextMeFlowPairScreen extends Component {
    static template = "textmeflow.PairScreen";

    setup() {
        this.orm = useService("orm");
        this.state = useState({
            status: "loading",
            qr: null,
            error: null,
            upgradeUrl: null,
        });
        this.pairId = null;
        this.restarts = 0;
        this.timer = null;
        this.busy = false;
        onWillStart(() => this.start());
        onWillUnmount(() => this.stop());
    }

    stop() {
        if (this.timer) {
            clearInterval(this.timer);
            this.timer = null;
        }
    }

    apply(result) {
        this.pairId = result.pair_id || this.pairId;
        this.state.status = result.status;
        this.state.error = result.error;
        this.state.upgradeUrl = result.upgrade_url;
        if (result.qr && QR_PATTERN.test(result.qr)) {
            this.state.qr = result.qr;
        }
    }

    async start() {
        this.stop();
        this.state.qr = null;
        this.state.status = "loading";
        let result;
        try {
            result = await this.orm.call("textmeflow.pair", "start_pair", []);
        } catch {
            result = { status: "error", error: "unreachable" };
        }
        this.apply(result);
        if (["pending", "pending_no_qr_yet"].includes(result.status)) {
            this.timer = setInterval(() => this.poll(), POLL_MS);
        }
    }

    async poll() {
        if (this.busy || !this.pairId) {
            return;
        }
        this.busy = true;
        try {
            const result = await this.orm.call("textmeflow.pair", "poll_pair", [this.pairId]);
            if (result.status === "expired") {
                this.stop();
                if (this.restarts < MAX_AUTO_RESTARTS) {
                    this.restarts += 1;
                    await this.start();
                } else {
                    this.apply(result);
                }
            } else {
                this.apply(result);
                if (result.status === "connected" || result.status === "error") {
                    this.stop();
                }
            }
        } catch {
            // Temporary network problem: keep polling.
        } finally {
            this.busy = false;
        }
    }

    onNewCode() {
        this.restarts = 0;
        this.start();
    }

    get isWaiting() {
        return ["pending", "pending_no_qr_yet", "loading"].includes(this.state.status);
    }
}

registry.category("actions").add("textmeflow_pair_screen", TextMeFlowPairScreen);
