# CaurisFlux for WooCommerce

Plugin WordPress (WooCommerce) pour accepter les paiements Mobile Money & Carte Bancaire en Afrique francophone via [CaurisFlux](https://caurisflux.com).

## Fonctionnalités

- Passerelle de paiement WooCommerce native, listée à côté de Stripe / PayPal.
- Mode **checkout hébergé** : redirection vers une page CaurisFlux sécurisée (PCI hors scope).
- Méthodes supportées : Wave, Orange Money, MTN MoMo, Free Money, Moov Money, Airtel, Mixx By Yas, M-Pesa, Opay, Visa/Mastercard 3DS.
- Multi-devises : XOF, XAF, GHS, NGN, EUR, USD avec conversion automatique côté CaurisFlux.
- Webhooks signés HMAC SHA256 — mise à jour automatique du statut de commande.
- Sandbox / Production switchable.
- HPOS-compatible (High-Performance Order Storage).
- Idempotency via header `X-Idempotency-Key` (= `wc_order_<id>_<key>`).

## Architecture

```
caurisflux-wp/
├── caurisflux-wp.php                   # Bootstrap + autoloader + hooks plugin
├── readme.txt                          # WP.org standard readme
├── README.md                           # Ce fichier (dev)
├── uninstall.php                       # Cleanup à la suppression
├── assets/
│   ├── css/admin.css                   # Styles page settings
│   └── images/logo.svg                 # Icône passerelle
└── includes/
    ├── class-caurisflux-plugin.php     # Singleton bootstrap
    ├── class-caurisflux-client.php     # Wrapper HTTP de l'API CaurisFlux
    ├── class-caurisflux-gateway.php    # WC_Payment_Gateway
    ├── class-caurisflux-webhook.php    # REST endpoint webhook + HMAC verify
    └── class-caurisflux-logger.php     # wc_get_logger() avec masquage des secrets
```

## Endpoints API utilisés

| Méthode | Endpoint | Usage |
|---|---|---|
| POST | `/payments/initiate` | Création de la session de paiement (mode `checkout`) |
| GET  | `/payments/status/:txId` | (futur) Polling de statut en fallback |

Auth : header `X-API-Key: pk_xxx:sk_xxx`.
Idempotence : `X-Idempotency-Key: wc_order_<id>_<key>` — assure qu'un retry browser ne crée pas 2 transactions.

## Webhook

Endpoint exposé : `POST /wp-json/caurisflux/v1/webhook`

Vérification : signature HMAC SHA256 du raw body, comparée au header `X-Cauris-Signature` (formats acceptés : `sha256=<hex>` ou `<hex>`).

Idempotence : hash MD5 du body stocké en transient 24h pour ignorer les redelivery.

Events gérés :
- `payment.completed` → `WC_Order::payment_complete()`
- `payment.failed` / `payment.cancelled` / `payment.expired` → `WC_Order::update_status('failed')`

## Développement local

Pré-requis : WordPress 6.0+, WooCommerce 7.0+, PHP 7.4+.

1. Symlink ou copie du dossier dans `wp-content/plugins/caurisflux-wp/`
2. Activer le plugin
3. Configurer dans **WooCommerce → Réglages → Paiements → CaurisFlux**
   - Environnement : Sandbox
   - API Key : votre `pk_test_xxx:sk_test_xxx`
   - Webhook secret : à récupérer côté dashboard CaurisFlux
4. Côté CaurisFlux : ajouter l'URL webhook affichée dans la page settings.

## Roadmap v1.x

- [ ] Endpoint refund (`/payments/:id/refund`) — bouton "Rembourser" dans WC.
- [ ] Block Checkout support (Gutenberg).
- [ ] Affichage du logo dynamique de la méthode utilisée dans la commande.
- [ ] Multi-currency display côté checkout (déjà fait côté CaurisFlux).
- [ ] Support Subscriptions (paiements récurrents) — quand l'API le permettra.

## Licence

GPL v2 or later — compatible WordPress / WooCommerce.
