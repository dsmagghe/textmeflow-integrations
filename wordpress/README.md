# TextMeFlow voor WordPress

Plugin "TextMeFlow – WhatsApp notifications for WooCommerce & WordPress forms" (slug `textmeflow`, map `textmeflow/`). Contract: `docs/connect-contract.md`.

## Installatie in 3 stappen (voor de winkelier)

1. Installeer de plugin (Plugins, Nieuwe plugin toevoegen, zoek "TextMeFlow") en activeer ze.
2. Open **TextMeFlow** in het menu en klik **Verbind met TextMeFlow**. Log in of maak een gratis account en klik **Toestaan**. Je komt vanzelf terug.
3. Scan de QR-code met WhatsApp (Instellingen, Gekoppelde apparaten). Vul je eigen nummer in, klik **Stuur mij een testbericht**, bewaar.

## Wat de plugin doet

- **WooCommerce** (HPOS- en Checkout-blok-compatibel): berichten bij in behandeling, voltooid, in de wacht, geannuleerd, terugbetaald, mislukt. Standaard aan: in behandeling en voltooid. Opt-in vinkje bij de checkout (standaard niet aangevinkt); zonder vinkje geen bericht. Optioneel bericht "nieuwe bestelling" naar de winkelier. Verzending asynchroon via Action Scheduler, resultaat als ordernotitie.
- **Formulieren**: Contact Form 7, WPForms, Elementor Forms (Pro), Gravity Forms. Melding "nieuwe inzending" naar de eigenaar; bevestiging naar de invuller enkel als er een telefoonveld is en een aangevinkt toestemmingsveld waarvan naam of label `whatsapp`, `consent`, `toestemming`, `optin`, `consentement` of `einwilligung` bevat (filter `textmeflow_consent_words`).
- Talen: en (bron), nl_NL, fr_FR, de_DE (`languages/`). Standaardberichten volgen de **sitetaal** (nl/fr/de, anders en).

## Ontwikkelaarsnotities

- Geen buildstap, geen Composer. PHP 7.4+, WP 5.8+. Prefix `TextMeFlow_` / `textmeflow_`.
- Bestanden: `includes/class-textmeflow-{api,connection,settings,sender,admin,woocommerce,forms,phone,log}.php`, `assets/admin.{js,css}`, `uninstall.php`.
- **Token**: libsodium-versleuteld (sleutel uit `wp_salt('auth')`) in optie `textmeflow_token`; nooit naar de browser. De browser praat enkel met `admin-ajax.php` (nonce + `manage_options`): acties `textmeflow_status`, `textmeflow_pair_start`, `textmeflow_pair_poll`, `textmeflow_test`.
- **Connect**: `admin-post.php?action=textmeflow_connect` (nonce) redirect naar `/connect` met `state` (transient per gebruiker, 15 min); terugkeer op `admin-post.php?action=textmeflow_connect_callback` controleert `state` met `hash_equals`, wisselt de code in. De `return`-URL bevat zelf een querystring (`?action=...`); de server moet `code` en `state` dus met `&` toevoegen.
- **Test/ontwikkeling**: definieer in `wp-config.php` `TEXTMEFLOW_API_BASE` en `TEXTMEFLOW_SITE_BASE` om naar een lokale of staging-server te wijzen.
- **Nummers**: `TextMeFlow_Phone::normalize()` (E.164, tabel EU + GB/US/CH/NO/IS/LI). Land: factuurland, anders instelling, anders winkelland, anders sitelocale.
- **Filters**: `textmeflow_order_tracking_url`, `textmeflow_consent_words`.
- **Vertalingen opnieuw maken**: `xgettext` over `textmeflow.php uninstall.php includes/*.php` naar `languages/textmeflow.pot`, `.po` bijwerken, `msgfmt -o x.mo x.po`.
- **Lokaal testen** (docker): `wordpress:php8.2-apache` + `wordpress:cli-php8.2` + `mariadb`, plugin read-only gemount; een mini-mockserver (`php -S`) voor `/v1/...`.

## Getest

Zie de PR-beschrijving.
