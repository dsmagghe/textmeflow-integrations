# Can /connect serve as Zapier OAuth2 (authorization code)?

Short answer: yes, with small server additions. The contract flow is already an authorization-code flow: browser redirect with `state`, one-time `code`, server-side exchange, long-lived token. Zapier OAuth2 needs:

| Zapier needs | Contract today | Server must add |
| --- | --- | --- |
| Authorize URL called with `client_id`, `redirect_uri`, `state`, `response_type=code` | `/connect?app&site&return&state` | Accept the OAuth names as aliases (`redirect_uri` = `return`, `client_id` = app identifier, ignore `response_type`). `app=zapier` implied by the client_id. |
| Redirect URI whitelist | `return` must be https (or localhost) | Allow `https://zapier.com/dashboard/auth/oauth/return/App<ID>CLIAPI/` (exact value shown in the Zapier developer dashboard after `register`). The `site` shown on the approval screen would be "Zapier". |
| Token endpoint: `POST` with `code`, `client_id`, `client_secret`, `redirect_uri`, `grant_type=authorization_code` (form-encoded by default) | `POST /v1/connect/token` JSON `{code, return}` | Either accept `redirect_uri` as alias and form-encoded bodies, or we override `getAccessToken` in the Zapier app to send JSON (possible, supported). Client secret check optional. |
| Response `{ access_token }` | `{ token, account_email, plan, ... }` | We map `token` to `access_token` in the Zapier app (custom `getAccessToken`), so no change needed. |
| Refresh | not needed | Tokens are long-lived and revocable ("Gekoppelde apps"), so no refresh token; Zapier then simply asks to reconnect on 401. |
| Test call | `GET /v1/accounts/me` with Bearer | none |
| Connection label | `email` | none |

Extra: the approval screen text should say "Zapier wants to send WhatsApp messages via your TextMeFlow account". Per-user revocation already exists (token per connected app).

Implementation sketch in this app (`src/authentication.js`): `type: 'oauth2'`, `authorizeUrl` = `https://textmeflow.eu/connect`, `getAccessToken` = POST JSON to `/v1/connect/token` with `{ code, return: bundle.inputData.redirect_uri }` returning `{ access_token: data.token }`, `test` unchanged, and `addAuth` reading `bundle.authData.access_token`. Not implemented now because the server side does not exist yet; API key works today and is accepted by Zapier for public listing.
