# TextMeFlow voor PrestaShop

Module `textmeflow` (PrestaShop 8.0+ en 9, PHP 7.2+): stuur klanten orderupdates
via WhatsApp, vanaf het eigen nummer van de winkelier, via [TextMeFlow](https://textmeflow.eu).
Verbinden gebeurt met een knop, zonder API-sleutel te kopieren
(zie `docs/connect-contract.md` in de repo-root).

## Installatie in 3 stappen (voor de winkelier)

1. Beheer > Modules > Module Manager > **Een module uploaden** en kies `textmeflow.zip`. Daarna **Configureren**.
2. Klik **Verbind met TextMeFlow**, log in of maak gratis een account, klik **Toestaan**. Je komt vanzelf terug in het beheer.
3. Scan de QR-code in WhatsApp (Instellingen > Gekoppelde apparaten). Klaar: berichten voor "betaling ontvangen", "verzonden" en "geleverd" staan aan.

Daarna kan je per orderstatus berichten aan/uit zetten of de tekst aanpassen, een
testbericht naar jezelf sturen, en een melding voor elke nieuwe bestelling inschakelen.
Klanten krijgen enkel berichten als ze bij het registreren/bestellen het vakje
"Stuur mij updates via WhatsApp" aanvinken (standaard niet aangevinkt).

## Zip maken

```bash
python3 integrations/prestashop/dev/build-zip.py   # -> integrations/prestashop/dist/textmeflow.zip
```

De map in de zip heet `textmeflow` (moet gelijk zijn aan de modulenaam). Het script gebruikt
Python `zipfile` (geen `__MACOSX`, `._*` of `.DS_Store`; Addons raadt zippen met macOS `zip` af)
en laat door PrestaShop gegenereerde `config_xx.xml` weg. De handleiding
`textmeflow/docs/readme_en.pdf` komt uit `dev/docs/readme_en.html` (`dev/build-docs.py`).

## Ontwikkelaarsnotities

| Onderdeel | Bestand |
|---|---|
| Module, hooks, configuratiepagina | `textmeflow/textmeflow.php` |
| API-client (cURL, token versleuteld met `PhpEncryption`) | `textmeflow/classes/TextMeFlowClient.php` |
| Logica: toestemming, wachtrij, privenota's | `textmeflow/classes/TextMeFlowService.php` |
| Nummer naar E.164 (EU + GB/US/CA/CH/NO/IS/LI) | `textmeflow/classes/TextMeFlowPhone.php` |
| Standaardteksten nl/en/fr/de + placeholders | `textmeflow/classes/TextMeFlowTemplates.php` |
| Terug-URL van /connect (front controller) | `textmeflow/controllers/front/callback.php` |
| AJAX: status, QR, polling, testbericht (admin-token) | `textmeflow/controllers/admin/AdminTextMeFlowController.php` |
| UI | `textmeflow/views/templates/admin/configure.tpl`, `views/js/admin.js` |
| Vertalingen UI (XLIFF, nieuw vertaalsysteem) | `textmeflow/translations/<locale>/` |
| Handleiding (Addons: in `docs/`) | `textmeflow/docs/readme_en.pdf` |
| Beveiliging map / externe domeinen | `textmeflow/.htaccess` (geen directe PHP), `textmeflow/header_csp.txt` |

Hooks: `actionOrderStatusPostUpdate` (bericht bij statuswijziging),
`actionValidateOrder` (melding naar de winkelier), `additionalCustomerFormFields`
(opt-in vinkje in registratie, in de gegevensstap van de checkout en in "Mijn gegevens"),
`actionCustomerAccountAdd` / `actionCustomerAccountUpdate` (opt-in opslaan in tabel `textmeflow_optin`).

Ontwerpkeuzes:

- **Token nooit in de browser.** De browser praat met `AdminTextMeFlow` (admin-token + rechtencontrole `configure` op de module); die praat met de API.
- **Asynchroon.** PrestaShop heeft geen wachtrij; berichten worden na de respons verstuurd (`fastcgi_finish_request` + shutdown-functie, timeout 8 s). Onder mod_php wacht het proces dus kort na de respons, nooit de klant zelf bij FastCGI/PHP-FPM.
- **Fouten** gaan naar het PrestaShop-log (Geavanceerd > Logs) en als privenota op de bestelling; nooit een exception naar de klant.
- **Toestemming**: zonder vinkje wordt er niets naar de klant gestuurd. Intrekken kan in "Mijn gegevens".
- **Teksten** volgen de taal van de bestelling (niet van de ingelogde medewerker). Een eigen tekst per status geldt voor alle talen.
- **Geen externe calls** zonder actie van de gebruiker, behalve: statuscontrole + QR als de beheerder de configuratiepagina opent, berichten bij bestellingen, en het intrekken van het token bij verbreken of verwijderen van de module.
- **Configuratiesleutels** beginnen met `TEXTMEFLOW_` (Addons-eis: prefix = modulenaam).
- **Andere API (testen)**: omgevingsvariabelen `TEXTMEFLOW_API_URL` (bv. `http://127.0.0.1:8099/v1`) en `TEXTMEFLOW_SITE_URL` (waar de knop `/connect` opent). Enkel een absolute http(s)-URL wordt aanvaard, anders blijft het `https://api.textmeflow.eu/v1` / `https://textmeflow.eu`. Test: `dev/tests/run.php`.
- **Privénotitie op de bestelling** gaat in de klantthread van de bestelling (`CustomerMessage`, privé, thread gesloten): sinds PS 1.7.7 toont de orderpagina de oude `Message`-tabel niet meer.
- **Terug-URL** wordt bij het starten bewaard en bij het inwisselen exact hergebruikt (in een meertalige winkel kan de callback in een andere taal binnenkomen).
- Multistore: instellingen worden per shop/groep opgeslagen via `Configuration`; de koppeling is per context.
- Bekende beperking: nummers zonder landcode worden met het land van het adres omgezet (nationale `0` weggelaten); Italiaanse vaste nummers (nul hoort erbij) worden niet speciaal behandeld.

### Lokaal testen (dev/)

Alles in `dev/` gaat niet mee in de zip.

```bash
export DOCKER_HOST=unix://$HOME/.colima/default/docker.sock   # colima
cd integrations/prestashop/dev
docker-compose -p tmfps up -d                                  # PS 8 op http://localhost:8088 (admin-dev, demo@prestashop.com / prestashop_demo)
docker exec -d tmfps-shop-1 php -S 0.0.0.0:8099 /mock/mock-api.php
docker exec -u www-data -w /var/www/html tmfps-shop-1 php bin/console prestashop:module install textmeflow
python e2e.py /tmp/shots                                       # alle stappen (pip install playwright; gebruikt Google Chrome)
docker-compose -p tmfps down -v
```

- `mock-api.php`: nagebootste TextMeFlow (`/connect` keurt meteen goed met code `mockcode123`, token, QR, pair-status, berichten, intrekken). Hulpjes: `/mock/scan` (QR "gescand"), `/mock/revoke` (token ingetrokken), `/mock/requests` (log). Er gaat nooit iets naar textmeflow.eu.
- Testen vanuit de zip zoals een winkelier: `MODULE_SRC=<lege map> docker-compose -p tmfps up -d` en stap `upload-zip` (Modules > Een module uploaden).
- PrestaShop 9: `PS_IMAGE=prestashop/prestashop:9 PS_PORT=8089 docker-compose -p tmfps9 up -d` en `PS_BASE=http://localhost:8089 PS_CONTAINER=tmfps9-shop-1 python e2e.py ...`.
- `tests/run.php`: unittests zonder PrestaShop (API-URL-override, telefoonnummers, teksten). `check-translations.py`: elke UI-tekst heeft nl/fr/de.
- `store-assets.py`: logo's (uit `storage/app/textmeflow-icon-512.png`) en genummerde screenshots naar `../store-assets/`.

### Getest (11/11/2026)

- **PrestaShop 8.2.9** (PHP 8.1, MariaDB 10.11, debugmodus aan), module geüpload uit `dist/textmeflow.zip` via Modules > Een module uploaden, automatisch met `dev/e2e.py` (Chrome via Playwright), alles groen:
  installeren; Verbind-knop -> `/connect` (app, site, return, state) -> callback -> code ingewisseld met exact dezelfde return-URL; dezelfde state een tweede keer geweigerd; QR + polling; "gescand" -> "Verbonden met WhatsApp-nummer"; testbericht (nummer `0470 ...` -> `+32470...`); instellingen opslaan, ongeldig nummer geweigerd; opt-in vinkje bij registratie (niet aangevinkt, aan en uit); bestelling als klant met en zonder opt-in en als gast met opt-in in de checkout; bericht aan de winkelier bij een nieuwe bestelling; statuswijziging in het beheer -> bericht enkel met opt-in (standaardtekst "betaling ontvangen", eigen tekst bij "verzonden"); privénotitie zichtbaar op de bestelling; toestemming intrekken in "Mijn gegevens" -> geen bericht meer; API weigert (token ingetrokken) -> notitie "not sent (unauthorized)", statuswijziging gaat gewoon door; verbreken -> token ingetrokken; opnieuw verbinden; vertalingen fr/nl/de in beheer en winkel; verbinden met een beheerderstaal die in de winkel uitstaat; verwijderen -> token ingetrokken, tabel, tab en `TEXTMEFLOW_*`-sleutels weg.
  Geen PHP-meldingen van de module in het debuglog.
- **PrestaShop 9.2.1** (`prestashop/prestashop:9`, PHP 8.5, thema Hummingbird, debugmodus aan): dezelfde e2e-stappen (module gemount, niet uit de zip), alles groen. Gevonden en opgelost: `curl_close()` is deprecated in PHP 8.5.
- PHPStan niveau 5 met PrestaShop-stubs (`prestashop/php-dev-tools`) tegen PS 8.2.9: 0 fouten. `php-cs-fixer` met de PrestaShop-regels: 0 wijzigingen. `prestashop/autoindex`: geen ontbrekende `index.php`. `dev/tests/run.php`: 27 asserties OK.
- **Niet getest**: de echte textmeflow.eu (`/connect`, `/v1/connect/token`, levering op een telefoon), multistore, PHP 7.2-7.4, PrestaShop 8.0/8.1 (enkel 8.2.9), betaalmodules die de status zelf zetten (getest met "betaling bij levering" + statuswijziging in het beheer), de officiële validator (validator.prestashop.com vraagt een login).

### PrestaShop Addons-eisen (lokaal nagekeken)

De officiële validator (validator.prestashop.com) vraagt een Addons-login; die moet Dimitry
zelf draaien (zie `SUBMIT.md`). Lokaal nagekeken tegen de Addons-richtlijnen (docs.cloud.prestashop.com):

- [x] Mapnaam = modulenaam = zipnaam (`textmeflow`), klasse `TextMeFlow`; `index.php` in elke map (ook `docs/`); `.htaccess` in de root (geen directe PHP, geen mappenlijst)
- [x] `if (!defined('_PS_VERSION_')) exit;` in elk PHP-bestand; AFL-3.0-licentieheader met `@author TextMeFlow <info@fades.tech>` in elk bestand
- [x] `ps_versions_compliancy` = 8.0.0 tot 9.99.99 (niet `_PS_VERSION_`), `config.xml`, `logo.png` 32x32, `$this->tab = 'emailing'`
- [x] Configuratiesleutels met prefix `TEXTMEFLOW_`; eigen tabel `textmeflow_optin` (geen core-tabel aangepast), opgeruimd bij het verwijderen van een klant en bij uninstall
- [x] Invoer via `Tools::getValue`, SQL met `(int)`, uitvoer geëscaped in Smarty; `json_encode` met `JSON_HEX_*` in het data-attribuut
- [x] Geen `eval`, `serialize`, `exec`, `file_get_contents` met invoer, `$_GET/$_POST`, `var_dump`, `console.log`, geen uitgecommentarieerde code
- [x] Externe calls in try/catch, fouten naar `PrestaShopLogger`; externe domeinen in `header_csp.txt`
- [x] Vertalingen via het nieuwe systeem (`$this->trans`, `isUsingNewTranslationSystem()`), XLIFF nl/fr/de compleet
- [x] Geen fouten of meldingen met debugmodus aan (PS 8.2.9 en 9.2.1)
- [x] Handleiding als PDF in `docs/`
- [ ] `module_key` (krijg je in het Seller Dashboard na het aanmaken van het product): toevoegen in `__construct` (`$this->module_key = '...';`) en een nieuwe zip bouwen
