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
cd integrations/prestashop
zip -r textmeflow.zip textmeflow -x '*.DS_Store'
```

De map in de zip heet `textmeflow` (moet gelijk zijn aan de modulenaam).

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
- Multistore: instellingen worden per shop/groep opgeslagen via `Configuration`; de koppeling is per context.
- Bekende beperking: nummers zonder landcode worden met het land van het adres omgezet (nationale `0` weggelaten); Italiaanse vaste nummers (nul hoort erbij) worden niet speciaal behandeld.

### Getest

- `php -l` op alle PHP-bestanden (PHP 8.3), `node --check` op de JS, `xmllint` op de XLIFF-bestanden.
- Geinstalleerd (`bin/console prestashop:module install textmeflow`) in `prestashop/prestashop:8` (PS 8.x, PHP 8.1) met MariaDB 10.11; getest: hooks en tab aangemaakt, configuratiepagina, opslaan/validatie, Verbind-redirect (state), terug-URL (verkeerde/herbruikte state geweigerd), AJAX (token vereist), opt-in vinkje bij registratie (aan en uit), berichtenflow tot en met API-aanroep en foutafhandeling (log + privenota).
- Niet getest: echte QR-koppeling en effectieve levering (de serverkant van `/connect` bestond nog niet), PrestaShop 9, multistore, volledige checkout als gast.

### PrestaShop-validator, handmatige checklist

- [x] Mapnaam = modulenaam = klassenaam (`textmeflow` / `TextMeFlow`); `index.php` in elke map
- [x] `if (!defined('_PS_VERSION_')) exit;` in elk PHP-bestand; licentieheader (AFL 3.0) in elk bestand
- [x] `ps_versions_compliancy`, `$this->tab` uit de officiele lijst (`emailing`), `config.xml`, `logo.png` (32x32)
- [x] Invoer enkel via `Tools::getValue`/`Tools::isSubmit`, gevalideerd (regex, `Validate::isCleanHtml`, lengte); uitvoer geescaped in Smarty (`escape:'html':'UTF-8'`)
- [x] Geen `eval`, geen `$_GET/$_POST`, geen `file_put_contents` buiten de module, geen `@`-onderdrukking
- [x] Vertalingen via nieuw systeem (`$this->trans`, `{l s='' d='Modules.Textmeflow.Admin'}`) + XLIFF nl/fr/de
- [x] Install/uninstall ruimt tabel, tab en `Configuration`-sleutels op
- [x] Token versleuteld opgeslagen, nooit naar de browser; CSRF via admin-token op formulieren en AJAX; rechtencontrole in de AJAX-controller
- [ ] Nog te doen door de indiener: de officiele validator (validator.prestashop.com) laten lopen op de zip en screenshots toevoegen
