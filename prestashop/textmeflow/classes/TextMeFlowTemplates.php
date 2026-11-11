<?php

/**
 * Copyright (c) Fades Management EOOD (TextMeFlow)
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Academic Free License (AFL 3.0)
 * https://opensource.org/licenses/AFL-3.0
 *
 * @author    TextMeFlow <info@fades.tech>
 * @copyright Fades Management EOOD
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License (AFL 3.0)
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Default message texts (nl, en, fr, de) and placeholder rendering.
 *
 * Message texts are intentionally not routed through the module translation catalogue:
 * they must follow the language of the customer's order, not the language of the
 * employee who is logged in.
 */
class TextMeFlowTemplates
{
    public const PLACEHOLDERS = ['{first_name}', '{order_number}', '{order_total}', '{shop_name}', '{tracking_url}', '{status}'];

    /** @var array<string,array<string,string>> kind => iso => text */
    private static $defaults = [
        'payment' => [
            'nl' => 'Hallo {first_name}, bedankt voor je bestelling {order_number} bij {shop_name}. We hebben je betaling van {order_total} ontvangen.',
            'en' => 'Hi {first_name}, thank you for your order {order_number} at {shop_name}. We have received your payment of {order_total}.',
            'fr' => 'Bonjour {first_name}, merci pour votre commande {order_number} chez {shop_name}. Nous avons bien reçu votre paiement de {order_total}.',
            'de' => 'Hallo {first_name}, danke für deine Bestellung {order_number} bei {shop_name}. Wir haben deine Zahlung von {order_total} erhalten.',
        ],
        'shipping' => [
            'nl' => 'Goed nieuws {first_name}: je bestelling {order_number} bij {shop_name} is onderweg. Volg je pakket: {tracking_url}',
            'en' => 'Good news {first_name}: your order {order_number} from {shop_name} is on its way. Track your parcel: {tracking_url}',
            'fr' => 'Bonne nouvelle {first_name} : votre commande {order_number} chez {shop_name} est en route. Suivez votre colis : {tracking_url}',
            'de' => 'Gute Nachrichten {first_name}: deine Bestellung {order_number} bei {shop_name} ist unterwegs. Sendung verfolgen: {tracking_url}',
        ],
        'delivered' => [
            'nl' => 'Hallo {first_name}, je bestelling {order_number} bij {shop_name} is geleverd. Veel plezier ermee!',
            'en' => 'Hi {first_name}, your order {order_number} from {shop_name} has been delivered. Enjoy!',
            'fr' => 'Bonjour {first_name}, votre commande {order_number} chez {shop_name} a été livrée. Bonne utilisation !',
            'de' => 'Hallo {first_name}, deine Bestellung {order_number} bei {shop_name} wurde geliefert. Viel Freude damit!',
        ],
        'generic' => [
            'nl' => 'Hallo {first_name}, de status van je bestelling {order_number} bij {shop_name} is gewijzigd naar: {status}.',
            'en' => 'Hi {first_name}, the status of your order {order_number} at {shop_name} changed to: {status}.',
            'fr' => 'Bonjour {first_name}, le statut de votre commande {order_number} chez {shop_name} est maintenant : {status}.',
            'de' => 'Hallo {first_name}, der Status deiner Bestellung {order_number} bei {shop_name} lautet jetzt: {status}.',
        ],
        'merchant' => [
            'nl' => 'Nieuwe bestelling {order_number} van {first_name} ({order_total}) in {shop_name}.',
            'en' => 'New order {order_number} from {first_name} ({order_total}) in {shop_name}.',
            'fr' => 'Nouvelle commande {order_number} de {first_name} ({order_total}) dans {shop_name}.',
            'de' => 'Neue Bestellung {order_number} von {first_name} ({order_total}) in {shop_name}.',
        ],
        'test' => [
            'nl' => 'Dit is een testbericht van {shop_name} via TextMeFlow. Je koppeling werkt.',
            'en' => 'This is a test message from {shop_name} via TextMeFlow. Your connection works.',
            'fr' => 'Ceci est un message de test de {shop_name} via TextMeFlow. Votre connexion fonctionne.',
            'de' => 'Das ist eine Testnachricht von {shop_name} über TextMeFlow. Deine Verbindung funktioniert.',
        ],
    ];

    /**
     * @param string $kind payment|shipping|delivered|generic|merchant|test
     * @param string $iso language ISO code; unknown languages fall back to English
     *
     * @return string
     */
    public static function getDefault($kind, $iso)
    {
        $iso = strtolower((string) $iso);
        if (!isset(self::$defaults[$kind])) {
            $kind = 'generic';
        }

        return isset(self::$defaults[$kind][$iso]) ? self::$defaults[$kind][$iso] : self::$defaults[$kind]['en'];
    }

    /**
     * Default "kind" per standard PrestaShop order state configuration key.
     *
     * @return array<int,string> order state id => kind
     */
    public static function getDefaultKindMap()
    {
        $map = [];
        $keys = [
            'PS_OS_PAYMENT' => 'payment',
            'PS_OS_SHIPPING' => 'shipping',
            'PS_OS_DELIVERED' => 'delivered',
        ];
        foreach ($keys as $key => $kind) {
            $id = (int) Configuration::get($key);
            if ($id > 0) {
                $map[$id] = $kind;
            }
        }

        return $map;
    }

    /**
     * @param string $text
     * @param array<string,string> $values placeholder => value
     *
     * @return string plain text, tags stripped, whitespace tidied
     */
    public static function render($text, array $values)
    {
        $out = strtr((string) $text, $values);
        $out = strip_tags($out);
        $out = preg_replace('/[ \t]+/', ' ', $out);

        return trim($out);
    }
}
