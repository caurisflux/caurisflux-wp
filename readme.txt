=== CaurisFlux for WooCommerce ===
Contributors: caurispay
Tags: payment, woocommerce, mobile money, wave, orange money, mtn, africa, west africa, cameroun, senegal, cote d'ivoire
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.0.0
Requires PHP: 7.4
WC requires at least: 7.0
WC tested up to: 9.4
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Acceptez les paiements Mobile Money (Wave, Orange Money, MTN, Free Money, Moov, Airtel...) et Carte Bancaire (3D Secure) en Afrique francophone via CaurisFlux.

== Description ==

**CaurisFlux** est une passerelle de paiement panafricaine qui agrège les principaux providers Mobile Money et Carte Bancaire en zones UEMOA, CEMAC, Ghana, Nigeria et plus.

= Méthodes supportées =

* **Mobile Money** : Wave, Orange Money, MTN MoMo, Free Money, Moov Money, Airtel Money, Mixx By Yas, M-Pesa, Opay…
* **Carte Bancaire** : Visa / Mastercard avec 3D Secure
* **Multi-devises** : XOF, XAF, GHS, NGN, EUR, USD avec conversion automatique
* **Multi-pays** : SN, CI, ML, BF, BJ, TG, NE, GN, CM, GA, CG, TD, GH, NG, KE, etc.

= Fonctionnement =

Le client est redirigé vers une **page de checkout sécurisée** hébergée par CaurisFlux où il choisit sa méthode et confirme. Pas de données de carte stockées sur votre serveur (PCI-DSS hors scope).

= Notifications de paiement =

Le plugin expose un endpoint de webhook qui reçoit les notifications signées (HMAC SHA256) de CaurisFlux et met à jour le statut de la commande automatiquement (paid, failed, cancelled, expired).

= Sandbox / Production =

Bascule simple entre les environnements depuis la page de configuration. Utilisez les clés `pk_test_*` / `sk_test_*` pour tester sans mouvement de fonds réel.

== Installation ==

1. Téléversez le dossier `caurisflux-wp` dans `/wp-content/plugins/` (ou installez via le marketplace).
2. Activez le plugin depuis le menu **Extensions** de WordPress.
3. Allez dans **WooCommerce → Réglages → Paiements → CaurisFlux**.
4. Renseignez votre **Clé API** (format `pk_xxx:sk_xxx`) et le **Secret webhook**.
5. Copiez l'**URL webhook** affichée et configurez-la dans votre dashboard CaurisFlux.
6. Choisissez l'environnement **Sandbox** pour tester, puis **Production** pour passer en live.

== Frequently Asked Questions ==

= Où trouver mes clés API CaurisFlux ? =

Connectez-vous à votre dashboard marchand CaurisFlux → menu **API Keys**. Une clé Sandbox (`pk_test_...:sk_test_...`) est générée automatiquement à la création du compte.

= Le plugin stocke-t-il des données de carte bancaire ? =

Non. Le checkout est hébergé par CaurisFlux. Aucune donnée carte ne transite par votre WordPress.

= Quelle devise dois-je configurer dans WooCommerce ? =

La devise principale du marchand (XOF par défaut pour la zone UEMOA, XAF pour CEMAC). CaurisFlux convertit automatiquement vers la devise locale du client à la page de checkout.

= Mon webhook ne marche pas. Que vérifier ? =

* Que l'URL est bien copiée depuis la page de réglages (`/wp-json/caurisflux/v1/webhook`)
* Que le secret HMAC correspond à celui configuré côté CaurisFlux
* Que les permaliens WordPress ne sont pas en mode "Plain" (REST API requise)
* Activez les **logs de debug** dans les réglages → **WooCommerce → Status → Logs**

== Changelog ==

= 1.0.0 =
* Première version stable.
* Passerelle de paiement WooCommerce avec checkout hébergé.
* Webhooks signés HMAC SHA256.
* Support Sandbox / Production.
* Compatibilité HPOS (High-Performance Order Storage).
* Logs de debug intégrés à WooCommerce → Status → Logs.

== Upgrade Notice ==

= 1.0.0 =
Première version publique.
