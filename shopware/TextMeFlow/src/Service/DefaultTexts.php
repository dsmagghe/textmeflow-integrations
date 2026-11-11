<?php declare(strict_types=1);

namespace TextMeFlow\Service;

use TextMeFlow\EventType;

/**
 * Ready-made message texts in the languages TextMeFlow supports. The language is the one
 * of the customer's order, not of the administrator. The tracking link sits on its own line
 * so the line can be dropped when a parcel has no tracking link.
 */
final class DefaultTexts
{
    public const FALLBACK_LOCALE = 'en';

    private const TEXTS = [
        'OrderPlaced' => [
            'en' => 'Hi {first_name}, thank you for your order {order_number} at {shop_name}. Total: {order_total}. We will keep you posted.',
            'nl' => 'Hallo {first_name}, bedankt voor uw bestelling {order_number} bij {shop_name}. Totaal: {order_total}. We houden u op de hoogte.',
            'fr' => 'Bonjour {first_name}, merci pour votre commande {order_number} chez {shop_name}. Total : {order_total}. Nous vous tiendrons informé.',
            'de' => 'Hallo {first_name}, vielen Dank für Ihre Bestellung {order_number} bei {shop_name}. Summe: {order_total}. Wir halten Sie auf dem Laufenden.',
        ],
        'OrderPaid' => [
            'en' => 'Hi {first_name}, we received your payment for order {order_number}. Thank you! - {shop_name}',
            'nl' => 'Hallo {first_name}, we hebben uw betaling voor bestelling {order_number} ontvangen. Bedankt! - {shop_name}',
            'fr' => 'Bonjour {first_name}, nous avons bien reçu votre paiement pour la commande {order_number}. Merci ! - {shop_name}',
            'de' => 'Hallo {first_name}, Ihre Zahlung für Bestellung {order_number} ist bei uns eingegangen. Vielen Dank! - {shop_name}',
        ],
        'OrderInProgress' => [
            'en' => 'Hi {first_name}, we are preparing your order {order_number}. - {shop_name}',
            'nl' => 'Hallo {first_name}, we maken uw bestelling {order_number} klaar. - {shop_name}',
            'fr' => 'Bonjour {first_name}, nous préparons votre commande {order_number}. - {shop_name}',
            'de' => 'Hallo {first_name}, wir bereiten Ihre Bestellung {order_number} vor. - {shop_name}',
        ],
        'OrderShipped' => [
            'en' => "Hi {first_name}, your order {order_number} is on its way.\n{tracking_url}\n- {shop_name}",
            'nl' => "Hallo {first_name}, uw bestelling {order_number} is onderweg.\n{tracking_url}\n- {shop_name}",
            'fr' => "Bonjour {first_name}, votre commande {order_number} est en route.\n{tracking_url}\n- {shop_name}",
            'de' => "Hallo {first_name}, Ihre Bestellung {order_number} ist unterwegs.\n{tracking_url}\n- {shop_name}",
        ],
        'OrderCompleted' => [
            'en' => 'Hi {first_name}, your order {order_number} is complete. Thank you for shopping at {shop_name}!',
            'nl' => 'Hallo {first_name}, uw bestelling {order_number} is afgerond. Bedankt voor uw aankoop bij {shop_name}!',
            'fr' => 'Bonjour {first_name}, votre commande {order_number} est terminée. Merci d\'avoir acheté chez {shop_name} !',
            'de' => 'Hallo {first_name}, Ihre Bestellung {order_number} ist abgeschlossen. Vielen Dank für Ihren Einkauf bei {shop_name}!',
        ],
        'OrderCancelled' => [
            'en' => 'Hi {first_name}, your order {order_number} has been cancelled. Questions? Just contact {shop_name}.',
            'nl' => 'Hallo {first_name}, uw bestelling {order_number} is geannuleerd. Vragen? Neem gerust contact op met {shop_name}.',
            'fr' => 'Bonjour {first_name}, votre commande {order_number} a été annulée. Des questions ? Contactez {shop_name}.',
            'de' => 'Hallo {first_name}, Ihre Bestellung {order_number} wurde storniert. Fragen? Kontaktieren Sie einfach {shop_name}.',
        ],
        'OwnerNewOrder' => [
            'en' => 'New order {order_number} at {shop_name}: {order_total} from {first_name} {last_name}.',
            'nl' => 'Nieuwe bestelling {order_number} bij {shop_name}: {order_total} van {first_name} {last_name}.',
            'fr' => 'Nouvelle commande {order_number} chez {shop_name} : {order_total} de {first_name} {last_name}.',
            'de' => 'Neue Bestellung {order_number} bei {shop_name}: {order_total} von {first_name} {last_name}.',
        ],
    ];

    /**
     * @param string $locale any locale code such as "nl", "nl-NL" or "nl_BE"
     */
    public static function get(EventType $event, string $locale): string
    {
        $language = self::language($locale);

        return self::TEXTS[$event->value][$language] ?? self::TEXTS[$event->value][self::FALLBACK_LOCALE];
    }

    /**
     * Text of the "send me a test message" button.
     */
    public static function testMessage(string $locale): string
    {
        return match (self::language($locale)) {
            'nl' => 'Dit is een testbericht van uw Shopware-winkel via TextMeFlow. De koppeling werkt!',
            'fr' => 'Ceci est un message de test de votre boutique Shopware via TextMeFlow. La connexion fonctionne !',
            'de' => 'Dies ist eine Testnachricht Ihres Shopware-Shops über TextMeFlow. Die Verbindung funktioniert!',
            default => 'This is a test message from your Shopware shop through TextMeFlow. The connection works!',
        };
    }

    /**
     * Reduces a locale code to one of the supported languages, falling back to English.
     */
    public static function language(string $locale): string
    {
        $language = strtolower(substr($locale, 0, 2));

        return isset(self::TEXTS['OrderPlaced'][$language]) ? $language : self::FALLBACK_LOCALE;
    }
}
