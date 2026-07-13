<?php
/**
 * fondouk — Fondouk translations (FR).
 *
 * @author    Synapsea
 * @copyright 2026 Synapsea
 * @license   https://opensource.org/licenses/MIT MIT License
 */
if (!defined('_PS_VERSION_')) {
    exit;
}

global $_MODULE;
$_MODULE = array();
$_MODULE['<{fondouk}prestashop>fondouk_ca0a147ef041bd41f363e9bdd98a9960'] = '%d produit(s) trouvé(s).';
$_MODULE['<{fondouk}prestashop>fondouk_27a56edbcd2bbbbd156adb9d5e3c6e42'] = '%d produit(s) résolu(s).';
$_MODULE['<{fondouk}prestashop>diagnostic_fba06a247c5484a7b3c7e9508b8d536d'] = '<strong>DÉSACTIVÉE</strong> — prérequis d\'environnement : sans elle, le manifeste <code>/.well-known/ucp</code> n\'est pas routé. Activez « Friendly URL » dans les paramètres SEO &amp; URLs.';
$_MODULE['<{fondouk}prestashop>diagnostic_4bb76e56ec291ff246c6e53826b2a81c'] = '<strong>MANQUANT</strong> — le paquet Composer n\'est pas installé ; les points d\'accès renverraient une erreur 500. Reconditionnez le module (<code>composer install --no-dev</code>).';
$_MODULE['<{fondouk}prestashop>diagnostic_ea745cbab171636b8460b8bb4c03a66c'] = '<strong>NON</strong> — la spécification UCP <em>exige</em> HTTPS pour le manifeste. En production, servez la boutique en HTTPS : sans cela, aucun agent réel ne peut la découvrir.';
$_MODULE['<{fondouk}prestashop>diagnostic_7be1a910be97a6d851809b117879efb7'] = 'Points d\'accès agents (opérationnels)';
$_MODULE['<{fondouk}prestashop>fondouk_7457091eb7a172f945264f2bcc880e7a'] = 'Interfaces agents';
$_MODULE['<{fondouk}prestashop>fondouk_4174d46e2393f418c835fed0c6b87bdd'] = 'Devises :';
$_MODULE['<{fondouk}prestashop>diagnostic_5821953ae21bf6bef9319c16f84418b3'] = 'Dépendances (<code>vendor/autoload</code>)';
$_MODULE['<{fondouk}prestashop>diagnostic_5573c3e0c97898279e070f70c85952ff'] = 'Vérifications d\'environnement';
$_MODULE['<{fondouk}prestashop>fondouk_8e8181764af2fa55804d41cf0a654f65'] = 'Récupère plusieurs produits par identifiants en un seul appel (lot). À utiliser lorsque vous disposez DÉJÀ des identifiants de produits/déclinaisons et souhaitez leur prix/disponibilité actuels. 100 identifiants maximum. Lecture seule.';
$_MODULE['<{fondouk}prestashop>fondouk_525441b1671383fb5ca4a97290859d15'] = 'Récupère les détails complets d\'UN produit à partir de son identifiant (obtenu via search_catalog ou lookup_catalog). À utiliser lorsque le client souhaite les détails, les déclinaisons ou la disponibilité d\'un produit précis. Renvoie une erreur "not_found" claire si l\'identifiant ne correspond à aucun produit public. Lecture seule.';
$_MODULE['<{fondouk}prestashop>fondouk_c6473e3b01e34fd0b058e9a3ed91df7b'] = 'Fondouk — Prêt pour les agents IA (UCP, MCP)';
$_MODULE['<{fondouk}prestashop>diagnostic_959a514da2a040ec340d9dded9c56fcc'] = 'Fondouk — diagnostics';
$_MODULE['<{fondouk}prestashop>fondouk_308a087e3a652fd94d82a7bc1a267517'] = 'Texte de recherche libre.';
$_MODULE['<{fondouk}prestashop>fondouk_9ff7d5d8633cca70ceb282dce77e6edf'] = 'Langues :';
$_MODULE['<{fondouk}prestashop>fondouk_3775d3e917af0f63574048fc4fad3be9'] = 'Rend la boutique visible et interrogeable par les agents IA (UCP, MCP) — lecture seule, aucune configuration.';
$_MODULE['<{fondouk}prestashop>fondouk_61c6c4cfcd8c52868a7d9826a759b677'] = 'Paramètre "id" manquant. Fournissez un identifiant de produit obtenu via search_catalog.';
$_MODULE['<{fondouk}prestashop>diagnostic_af0a19660a81b970494d168ce9fe2dd5'] = 'Module <strong>opérationnel</strong> : le manifeste UCP, l\'API catalogue REST et le serveur MCP sont servis. Lecture seule, aucune configuration — rien à régler ici.';
$_MODULE['<{fondouk}prestashop>fondouk_7a868ee34326b0da1c0744ef50697a5c'] = 'Aucun produit public ne correspond à l\'identifiant "%s". Utilisez search_catalog pour trouver des produits, puis réessayez avec un identifiant renvoyé.';
$_MODULE['<{fondouk}prestashop>fondouk_7ecac4794e2883f50b1805a857cabc42'] = 'Localisation facultative : langue (BCP 47), devise (ISO 4217), address_country (ISO 3166-1 alpha-2).';
$_MODULE['<{fondouk}prestashop>diagnostic_9d903ff575a47d238a78c9b682828aa8'] = 'Conflit <code>.well-known</code> physique';
$_MODULE['<{fondouk}prestashop>fondouk_790d57aec6e5e14ed546c888aaa62a16'] = 'Identifiant de produit ou de déclinaison.';
$_MODULE['<{fondouk}prestashop>fondouk_443e9dc151a4403dfd9eda954dfd7727'] = 'Produit : %s';
$_MODULE['<{fondouk}prestashop>fondouk_f7ee45d8f717ebed7fdbd9b9291b5161'] = 'Accès en LECTURE SEULE au catalogue public du marchand. Utilisez search_catalog pour découvrir des produits par mots-clés, get_product pour les détails d\'un produit précis, lookup_catalog pour récupérer plusieurs produits par identifiants. Les prix sont publics (visiteur anonyme), TTC selon le pays par défaut de la boutique.';
$_MODULE['<{fondouk}prestashop>diagnostic_fee367d016f8a36f1dd7829a97dac36b'] = 'Catalogue REST';
$_MODULE['<{fondouk}prestashop>fondouk_08897d3ed394643a7b915d173de95a51'] = 'Recherche des produits dans le catalogue PUBLIC du marchand par texte libre et filtres facultatifs (catégories, prix). À utiliser lorsque vous n\'avez PAS d\'identifiant de produit — p. ex. « chaussures de running bleues à moins de 100 € ». Renvoie des produits avec prix (TTC), déclinaisons et disponibilité. Paginé (curseur). Lecture seule.';
$_MODULE['<{fondouk}prestashop>fondouk_fdb0c388de01d545017cdf9ccf00eb72'] = 'Boutique';
$_MODULE['<{fondouk}prestashop>fondouk_518e82e93e0a134bade3070785cceba0'] = 'Cette boutique est interrogeable par les agents IA via des interfaces structurées.';
$_MODULE['<{fondouk}prestashop>fondouk_036cdad32204063681e7f73cab0f5981'] = 'Trop d\'identifiants (100 maximum). Raccourcissez la liste et réessayez.';
$_MODULE['<{fondouk}prestashop>diagnostic_a61ffe296b7b8396ec2bb63f167837b2'] = 'Manifeste UCP';
$_MODULE['<{fondouk}prestashop>fondouk_4b3ef12bd2eeef1edbc137c5f1211f5a'] = 'Manifeste UCP (catalogue interrogeable en temps réel — prix, stock) :';
$_MODULE['<{fondouk}prestashop>fondouk_2790ee530716aa457b029db0c66169fd'] = 'Documentation du protocole UCP :';
$_MODULE['<{fondouk}prestashop>diagnostic_497c9aa51ead13fcfb00bafcecb3202b'] = 'Réécriture d\'URL (URL simplifiées)';
$_MODULE['<{fondouk}prestashop>fondouk_c7e1caeaec10f9c289610f7ba9a3b4ae'] = 'Désinstaller Fondouk ? Le manifeste et les points d\'accès agents ne seront plus servis.';
$_MODULE['<{fondouk}prestashop>fondouk_ff9252461e9ccf74d0bb37a793169daf'] = 'Outil inconnu : "%s". Outils disponibles : search_catalog, get_product, lookup_catalog.';
$_MODULE['<{fondouk}prestashop>diagnostic_60ed9e9926f4d59b81681a187831bc22'] = 'un dossier/fichier <code>.well-known</code> existe et peut masquer la route (Apache sert le fichier physique). Le repli sur fichier statique est une activation explicite.';
$_MODULE['<{fondouk}prestashop>fondouk_ce5eb9c176deaaa9a85790b97eba97b3'] = 'p. ex. {"categories":["3"],"price":{"max":10000}} (prix en unités mineures).';
$_MODULE['<{fondouk}prestashop>diagnostic_d78a23690010fca3c728583d998aefbb'] = 'activée.';
$_MODULE['<{fondouk}prestashop>diagnostic_db8364551ec38ac92a995fb6398bac21'] = 'aucun.';
$_MODULE['<{fondouk}prestashop>diagnostic_4900b6f63478aa2ff80a170781a8a4a4'] = 'présentes.';
$_MODULE['<{fondouk}prestashop>diagnostic_4f7b50bec76bcf2b862eeb2140701427'] = 'oui.';
$_MODULE['<{fondouk}prestashop>diagnostic_7b3bd7e258aa745f19c2ba647e408600'] = '<strong>Fondouk Pro est actif</strong> — il assure le service agent (manifeste, catalogue, MCP, llms.txt). Ce module gratuit est en veille : il n\'enregistre aucune route pour éviter tout conflit. Désinstallez ou désactivez Fondouk Pro pour lui rendre le service.';
$_MODULE['<{fondouk}prestashop>fondouk_b5bfb06bd504c928eaf1531681678053'] = 'Recherche catalogue (commodité GET pour navigateurs agentiques) :';
