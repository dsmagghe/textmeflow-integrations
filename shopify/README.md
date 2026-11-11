# TextMeFlow Order Messages voor Shopify

Shopify-app die winkeliers automatisch WhatsApp-berichten laat sturen bij
**bestelling geplaatst**, **verzonden (met track & trace)** en **geannuleerd**,
vanaf hun eigen WhatsApp-nummer via TextMeFlow.

De app is geen apart project: ze draait **in deze Laravel-app** (textmeflow.eu)
en gebruikt dezelfde koppel- en berichtenlogica als het portaal en de API.

## Voor de winkelier: in 3 stappen

1. **Installeer** de app in de Shopify App Store en open ze. Klik op
   **Verbind met TextMeFlow**. In een nieuw tabblad log je in op textmeflow.eu
   (of maak je gratis een account) en klik je **Toestaan**.
2. Terug in Shopify verschijnt een **QR-code**. Open WhatsApp op je telefoon,
   ga naar *Instellingen, Gekoppelde apparaten, Apparaat koppelen* en scan de code.
3. Klaar. Berichten in de taal van je winkel staan aan. Stuur jezelf een
   testbericht, pas de teksten aan als je wil, en kies of enkel klanten met
   toestemming een bericht krijgen.

## Hoe het werkt (ontwikkelaars)

| Onderdeel | Waar |
|---|---|
| Routes | `routes/integrations/shopify.php` (ingeladen onderaan `routes/web.php`) |
| Controllers, middleware, job, services | `app/Integrations/Shopify/` |
| Tabellen | `database/migrations/2026_11_11_120000_create_shopify_tables.php` (`shopify_shops`, `shopify_message_log`) |
| Views | `resources/views/integrations/shopify/` |
| Vertalingen (UI + standaardberichten) | `lang/{en,nl,fr,de}/shopify.php` |
| Config | `config/shopify.php` (env: `SHOPIFY_API_KEY`, `SHOPIFY_API_SECRET`, `SHOPIFY_SCOPES`, `SHOPIFY_API_VERSION`, `SHOPIFY_APP_HANDLE`) |
| App-configuratie voor Shopify CLI | `integrations/shopify/shopify.app.toml` |
| Tests | `tests/Feature/Integrations/Shopify/` |

### Installatie en authenticatie (actuele Shopify-eisen)

- **Managed install**: de scopes (`read_orders,read_locales`) staan in
  `shopify.app.toml`; Shopify regelt het toestemmingsscherm. Geen eigen
  OAuth-redirect nodig.
- **Embedded app met App Bridge** (laatste versie, van Shopify's CDN). De
  App URL `GET /shopify` controleert de `hmac` van de query en stuurt een
  `Content-Security-Policy: frame-ancestors https://<shop> https://admin.shopify.com`.
  Buiten de admin geopend → doorsturen naar de app in de admin.
- **Session tokens** in plaats van cookies: elke JSON-call (`/shopify/api/*`)
  draagt `Authorization: Bearer <id token>`; `VerifySessionToken` controleert
  de JWT (HS256 met de client secret, `aud`, `exp`/`nbf`, `dest`/`iss`). Die
  routes draaien zonder sessie/CSRF/cookies (App Store-eis 1.1.1).
- **Token exchange** bij de eerste keer laden: session token → *expiring
  offline* access token (`expiring=1`, verplicht voor nieuwe openbare apps).
  Die wordt versleuteld bewaard (`encrypted` cast) en enkel gebruikt om de
  winkelnaam en hoofdtaal op te halen. De berichten zelf komen volledig uit
  webhooks, dus er is geen achtergrond-refresh nodig. Wil je later wel
  Admin-API-calls doen buiten een sessie: implementeer de refresh-flow met
  `refresh_token` (staat al opgeslagen).

### Koppeling winkel → TextMeFlow-account

Intern, zonder token-uitwisseling (de app draait in dezelfde Laravel-app):

1. Knop "Verbind met TextMeFlow" → `POST /shopify/api/link` maakt een
   eenmalig ticket (48 tekens, 30 min, enkel sha256 bewaard).
2. Nieuw tabblad `https://textmeflow.eu/shopify/link/<ticket>`:
   niet ingelogd → keuze *Inloggen* / *Gratis account maken* (de URL staat als
   `url.intended` in de sessie; `PortalController` stuurt na de magic link nu
   via `redirect()->intended()` terug). Ingelogd → goedkeurscherm, POST met CSRF.
3. `shopify_shops.account_id` = account. De embedded pagina pollt elke 3 s en
   toont dan stap 2.

"Verbinding verbreken" in de app maakt `account_id` leeg (de app blijft geïnstalleerd).

### QR-code

`TextMeFlowBridge` roept intern dezelfde `PairController` aan als
`POST /v1/accounts/me/pair` (toestellimiet, opruimen van verlaten QR's,
worker-QR). Polling elke 3 s; zoals de portaalpagina (`MyPair`) geldt de
WhatsApp-sessie als waarheid (`connected` → klaar). Verlopen → automatisch
een nieuwe code (max 3x), daarna een knop "Nieuwe code". Toestellimiet →
"Upgrade je plan" met link naar /pricing.

### Berichten

| Shopify-webhook | Gebeurtenis |
|---|---|
| `orders/create` | Bestelling geplaatst |
| `orders/fulfilled` | Verzonden; trackinglink uit de laatste fulfillment met `tracking_url` |
| `orders/cancelled` | Geannuleerd |

`POST /shopify/webhooks`: HMAC (`X-Shopify-Hmac-Sha256`) → anders 401;
dubbele levering (`X-Shopify-Webhook-Id`, 2 dagen in cache) → genegeerd; dan
de job `SendOrderNotification` met enkel de nodige velden (geen volledige payload).

De job beslist en logt in `shopify_message_log` (één rij per winkel +
bestelling + gebeurtenis, dus nooit dubbel): uitgeschakeld, geen toestemming,
geen/ongeldig nummer → `skipped`; verzonden → `queued` met `message_id`;
geweigerd door TextMeFlow (STOP, quotum, ongeldig) → `failed` met reden.
De winkelier ziet de laatste 10 regels in de app ("Recente activiteit").

Versturen gaat via `MessageController::store` (zelfde anti-spam, opt-out,
quotum en Redis-wachtrij als `/v1/messages`). In productie staat
`QUEUE_CONNECTION=sync`: de job loopt dan meteen in de webhook-request; dat is
snel (één insert + LPUSH, het echte versturen doet de Node-worker). Met een
Laravel-queue-worker loopt hij async en worden tijdelijke fouten herhaald.

Telefoonnummer: leveradres, dan factuuradres, dan order/klant; met het land
van dat adres naar E.164 via libphonenumber (`0470 12 34 56` + BE →
`+32470123456`). Taal: die van de klant (`customer_locale`) als de app ze
kent, anders de hoofdtaal van de winkel, anders Engels. Placeholders:
`{first_name} {order_number} {order_total} {shop_name} {tracking_url} {tracking_number} {carrier}`.

### Uninstall en privacy-webhooks

- `app/uninstalled`: tokens, ticket en koppeling gewist, `uninstalled_at` gezet.
  Instellingen blijven tot `shop/redact` (handig bij snelle herinstallatie).
- `customers/data_request`: wordt gelogd in `audit_log` met de betrokken
  bestellingen en bericht-ID's. Support bezorgt de export aan de winkelier
  (binnen 30 dagen); de app bewaart buiten die berichten geen klantgegevens.
- `customers/redact`: berichten van de genoemde bestellingen worden
  geanonimiseerd (`recipient`/`text` → `redacted`), logregels verwijderd.
- `shop/redact`: alle berichten van de winkel geanonimiseerd, winkel + log verwijderd.

## GDPR-afweging: toestemming

Shopify kent geen WhatsApp-toestemming, enkel **e-mail-** en
**sms-marketingtoestemming**. Die zijn niet hetzelfde als toestemming voor
WhatsApp-dienstberichten. Daarom:

- Instelling **"Enkel klanten berichten die toestemming gaven voor
  sms-berichten"**, standaard **aan**. Dan krijgt enkel een klant met
  `customer.sms_marketing_consent.state = subscribed` een bericht. Dat is de
  voorzichtigste keuze en de dichtste benadering die Shopify biedt.
- De winkelier kan ze **uitzetten**. Bestelupdates (bevestiging, verzending,
  annulering) zijn dienstberichten die rechtstreeks met de uitvoering van de
  overeenkomst te maken hebben; daarvoor is marketingtoestemming in de regel
  niet vereist (AVG art. 6.1.b, of gerechtvaardigd belang art. 6.1.f), zolang
  de berichten geen reclame bevatten. Maar: de klant gaf zijn nummer voor de
  levering, niet expliciet voor WhatsApp, en WhatsApp is een dienst van
  Meta. De uitleg in de app zegt daarom uitdrukkelijk dat de winkelier eerst
  zijn eigen privacyverplichtingen moet nagaan (privacyverklaring vermelden).
- Elke klant kan altijd **STOP** antwoorden; TextMeFlow zet het nummer dan op
  de opt-outlijst en ook de app stuurt niet meer (`recipient_opted_out`).
- Dataminimalisatie: de app bewaart zelf geen telefoonnummers of teksten,
  enkel bestel-ID, ordernummer, gebeurtenis en status. Nummer en tekst staan
  alleen in `messages` (nodig om te versturen) en worden bij de
  privacy-webhooks geanonimiseerd.
- Openbaarmaking: de app-pagina en de listing melden dat telefoonnummer en
  berichttekst naar TextMeFlow gaan (EU-gehost, Fades Management EOOD,
  Bulgarije), met links naar /legal/privacy en /legal/terms.
- Shopify "protected customer data" niveau 2 is nodig (naam, telefoon,
  adres). Zonder goedkeuring zijn die velden leeg in de webhooks en stuurt de
  app niets (`no_phone`). Zie SUBMIT.md.

## Lokaal testen

```bash
php -d memory_limit=1G ./vendor/bin/phpunit tests/Feature/Integrations/Shopify
./vendor/bin/pint app/Integrations routes/integrations tests/Feature/Integrations
```

De tests bootsen Shopify na: HMAC-getekende webhooks en app-queries,
session tokens (JWT) en de token-exchange/GraphQL-calls (Http::fake).

Echt end-to-end testen vraagt een Shopify Partner-account en een
development store (zie SUBMIT.md, stap 3): `shopify app deploy` met
`shopify.app.toml`, app installeren op de dev store, bestelling plaatsen.
