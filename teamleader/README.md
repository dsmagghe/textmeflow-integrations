# TextMeFlow voor Teamleader Focus

Deze integratie draait **in de TextMeFlow-app zelf** (Laravel), niet als aparte plugin. Een klant koppelt Teamleader Focus aan zijn TextMeFlow-account; daarna verstuurt TextMeFlow automatisch WhatsApp-berichten bij gebeurtenissen in Teamleader.

## Installatie in 3 stappen (voor de klant)

1. Log in op het TextMeFlow-portaal en open **Teamleader** in het menu. Klik **Koppel Teamleader** en keur de toegang goed in Teamleader.
2. Koppel je WhatsApp-nummer (pagina "Koppelen", QR-code scannen) als je dat nog niet deed.
3. Geef in Teamleader de tag **WhatsApp toegestaan** aan de contacten die berichten willen ontvangen. Klaar: de berichten staan al aan.

Op de instellingenpagina kan je per gebeurtenis aan/uit zetten en de tekst aanpassen (nl, en, fr, de), een testbericht sturen en de koppeling verbreken.

## Wat er gebeurt

| Teamleader-gebeurtenis | Bericht |
|---|---|
| `invoice.booked` | Factuur geboekt: bedrag, vervaldatum en betaallink naar de klant |
| `meeting.created` | Bevestiging aan de contactpersonen + herinnering de dag ervoor (10:00 lokale tijd) |
| `deal.won` | Bedankbericht aan de contactpersoon van de deal |

De herinnering wordt vertraagd op de wachtrij gezet en controleert vlak voor het versturen of de afspraak nog bestaat en niet verschoven is.

## Toestemming (GDPR)

Er gaat **enkel** een bericht naar een contact/bedrijf met de opt-in-tag (standaard `WhatsApp toegestaan`, instelbaar, hoofdletterongevoelig) of met een aangepast veld (id instelbaar) op ja. Zonder opt-in wordt niets verstuurd; de reden staat in `teamleader_events`.

## Techniek

- Code: `app/Integrations/Teamleader/`, pagina `app/Filament/Pages/MyTeamleader.php`, routes `routes/integrations/teamleader.php` (1 `require` in `routes/web.php`), config `config/teamleader.php`, vertalingen `lang/{nl,en,fr,de}/teamleader.php`, view `resources/views/integrations/teamleader/`, migratie `2026_11_11_120000_create_teamleader_tables.php`.
- OAuth2 authorization code. Tokens versleuteld (`encrypted` cast); refresh tokens zijn eenmalig, dus verversen gebeurt onder een cache-slot en het nieuwe paar wordt meteen bewaard. Mislukte refresh zet de koppeling op `needs_reconnect`.
- Bij het koppelen: `webhooks.register` met een geheime URL per koppeling (`/integrations/teamleader/webhook/{token}`). Ontkoppelen: `webhooks.unregister` + koppeling (tokens) verwijderen.
- Webhook-verwerking: direct 200, dedupe op type+id+datum (Teamleader herhaalt), daarna een queued job die het object via de API ophaalt (de webhook bevat enkel type en id).
- Versturen gaat door `TextMessageSender`: dezelfde stappen als `POST /v1/messages` (E.164-validatie, opt-out, quota, rij in `messages`, push naar de Node-worker).
- Telefoon: mobiel eerst, naar E.164 met libphonenumber en het land uit het adres (anders `TEAMLEADER_DEFAULT_COUNTRY`).
- Draait een queue worker (`php artisan queue:work`)? Vereist: de verwerking en herinneringen lopen via de wachtrij.

## Configuratie (.env op de server)

```
TEAMLEADER_CLIENT_ID=
TEAMLEADER_CLIENT_SECRET=
# optioneel
TEAMLEADER_DEFAULT_COUNTRY=BE
TEAMLEADER_TIMEZONE=Europe/Brussels
TEAMLEADER_VERIFY_SIGNATURE=false
```

Daarna `php artisan migrate`. Redirect-URL in Teamleader: `https://textmeflow.eu/integrations/teamleader/callback`.

## Tests

`php -d memory_limit=1G ./vendor/bin/phpunit tests/Feature/TeamleaderIntegrationTest.php` (Http::fake voor Teamleader; OAuth, webhook-registratie, verwerking tot bericht, refresh, opt-in, ontkoppelen).

## Open punten (verifieer in een Teamleader-sandbox)

De developer-documentatie van Teamleader was niet automatisch leesbaar (rendert met JavaScript). Gebaseerd op publieke voorbeelden en kennis van de API; controleer voor indiening:

- Authorize-/token-host (`focus.teamleader.eu/oauth2/...`; sommige voorbeelden gebruiken `app.teamleader.eu`) en API-host (`api.focus.teamleader.eu`): overschrijfbaar via `TEAMLEADER_*_URL`.
- Exacte eventnamen (`invoice.booked`, `deal.won`, `meeting.created`) en de payload (`type`, `subject.id`, `date`).
- Of/hoe de webhook-handtekening (`X-Teamleader-Signature`) berekend wordt; standaard uit, de geheime URL beschermt.
- Velden van `invoices.info` voor een klantgerichte betaallink; de interne Teamleader-URL wordt bewust niet gebruikt. Zonder link valt de regel weg; de klant kan een eigen betaallink-sjabloon instellen.
- Hoe Teamleader de koppeling start vanuit de Marketplace (login-URL van de integratie).
