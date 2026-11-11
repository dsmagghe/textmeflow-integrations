# Listing text (n8n Creator Portal / npm / community nodes)

Package: `n8n-nodes-textmeflow`. Nodes: "TextMeFlow", "TextMeFlow Trigger". Category: Communication.

## English

**Name:** TextMeFlow

**Short description:** Send WhatsApp messages from your own number and start workflows on incoming messages. EU-hosted, free up to 50 messages a month.

**Long description:**
TextMeFlow lets your n8n workflows send WhatsApp messages from your own WhatsApp number (linked with a QR code, like WhatsApp Web) and react when someone replies.

- Send a message with text and an optional image or file link.
- Check whether a message was delivered or read.
- Start a workflow on every incoming message, with signature verification.
- Phone numbers are cleaned up to international format; pick a default country for numbers written the national way.

Setup takes three steps: install, paste your API key from textmeflow.eu/admin/portal, scan the QR code. Free up to 50 messages per month; paid plans for more.

**Features:** Send message; Get message status; Trigger on incoming message (HMAC-verified, duplicate-safe); number normalisation; EU hosting.

**Screenshots to take:** (1) Send node filled in with a Belgian number; (2) credential dialog with the explanation; (3) Trigger node with its setup notice; (4) a workflow: Webhook trigger, IF, Send reply; (5) the TextMeFlow portal page showing the API key (blur the key).

**FAQ**
- *Where is my API key?* At textmeflow.eu/admin/portal after logging in (you get a sign-in link by email, no password).
- *Do I need WhatsApp Business?* No. You link your own WhatsApp number by scanning a QR code.
- *Do I have to paste a webhook URL for the trigger?* No. The trigger registers its own URL with TextMeFlow when the workflow is activated and removes it when you deactivate it.
- *Is it free?* Yes up to 50 messages per month.

**Privacy / external service:** Phone numbers and message text are sent to TextMeFlow (EU-hosted, Fades Management EOOD, Bulgaria). Privacy: https://textmeflow.eu/legal/privacy. Terms: https://textmeflow.eu/legal/terms.

## Nederlands

**Naam:** TextMeFlow

**Korte beschrijving:** Stuur WhatsApp-berichten vanaf je eigen nummer en start workflows bij inkomende berichten. EU-gehost, gratis tot 50 berichten per maand.

**Lange beschrijving:**
Met TextMeFlow versturen je n8n-workflows WhatsApp-berichten vanaf je eigen WhatsApp-nummer (gekoppeld met een QR-code, zoals WhatsApp Web) en reageren ze wanneer iemand antwoordt.

- Verstuur een bericht met tekst en eventueel een link naar een afbeelding of bestand.
- Controleer of een bericht afgeleverd of gelezen is.
- Start een workflow bij elk inkomend bericht, met handtekeningcontrole.
- Telefoonnummers worden omgezet naar internationaal formaat; kies een standaardland voor nummers die nationaal geschreven zijn.

Instellen in drie stappen: installeren, je API-sleutel plakken van textmeflow.eu/admin/portal, de QR-code scannen. Gratis tot 50 berichten per maand.

**Privacy / externe dienst:** Telefoonnummers en berichttekst worden verwerkt door TextMeFlow (EU-gehost, Fades Management EOOD, Bulgarije). Privacy: https://textmeflow.eu/legal/privacy. Voorwaarden: https://textmeflow.eu/legal/terms.

**FAQ:** Waar vind ik mijn API-sleutel? Op textmeflow.eu/admin/portal na het inloggen (je krijgt een inloglink per mail). Heb ik WhatsApp Business nodig? Nee, je koppelt je eigen nummer met een QR-code.

## Francais

**Nom :** TextMeFlow

**Description courte :** Envoyez des messages WhatsApp depuis votre propre numero et declenchez des workflows a la reception d'un message. Heberge dans l'UE, gratuit jusqu'a 50 messages par mois.

**Description longue :**
TextMeFlow permet a vos workflows n8n d'envoyer des messages WhatsApp depuis votre propre numero (lie par QR code, comme WhatsApp Web) et de reagir aux reponses.

- Envoi de messages avec texte et lien optionnel vers une image ou un fichier.
- Verification de la distribution et de la lecture.
- Declenchement d'un workflow a chaque message entrant, avec verification de la signature.
- Numeros convertis au format international; pays par defaut au choix.

Mise en place en trois etapes : installer, coller votre cle API depuis textmeflow.eu/admin/portal, scanner le QR code. Gratuit jusqu'a 50 messages par mois.

**Confidentialite / service externe :** Les numeros de telephone et le texte des messages sont traites par TextMeFlow (heberge dans l'UE, Fades Management EOOD, Bulgarie). Confidentialite : https://textmeflow.eu/legal/privacy. Conditions : https://textmeflow.eu/legal/terms.

**FAQ :** Ou trouver ma cle API ? Sur textmeflow.eu/admin/portal apres connexion (lien de connexion par e-mail, sans mot de passe). Faut-il WhatsApp Business ? Non, vous liez votre propre numero par QR code.

## Deutsch

**Name:** TextMeFlow

**Kurzbeschreibung:** Senden Sie WhatsApp-Nachrichten von Ihrer eigenen Nummer und starten Sie Workflows bei eingehenden Nachrichten. In der EU gehostet, kostenlos bis 50 Nachrichten pro Monat.

**Langbeschreibung:**
Mit TextMeFlow senden Ihre n8n-Workflows WhatsApp-Nachrichten von Ihrer eigenen WhatsApp-Nummer (per QR-Code verbunden, wie WhatsApp Web) und reagieren auf Antworten.

- Nachrichten mit Text und optionalem Link zu Bild oder Datei senden.
- Zustellung und Lesestatus abfragen.
- Workflow bei jeder eingehenden Nachricht starten, mit Signaturprufung.
- Rufnummern werden ins internationale Format gebracht; Standardland wahlbar.

Einrichtung in drei Schritten: installieren, API-Schlussel von textmeflow.eu/admin/portal einfugen, QR-Code scannen. Kostenlos bis 50 Nachrichten pro Monat.

**Datenschutz / externer Dienst:** Telefonnummern und Nachrichtentext werden von TextMeFlow verarbeitet (EU-gehostet, Fades Management EOOD, Bulgarien). Datenschutz: https://textmeflow.eu/legal/privacy. Bedingungen: https://textmeflow.eu/legal/terms.

**FAQ:** Wo finde ich meinen API-Schlussel? Auf textmeflow.eu/admin/portal nach dem Login (Anmeldelink per E-Mail, kein Passwort). Brauche ich WhatsApp Business? Nein, Sie verbinden Ihre eigene Nummer per QR-Code.
