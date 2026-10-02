# Flux produits pour PrestaShop 9

Publie votre catalogue pour **Google Merchant Center**, **Microsoft Merchant
Center**, **Meta** (Facebook, Instagram) et **Pinterest**. Chaque plateforme a
sa propre adresse, au format qu'elle attend, et télécharge le fichier toute
seule. Les fichiers se mettent à jour quand vous ajoutez, modifiez ou supprimez
un produit, sans tâche cron.

- Compatible PrestaShop 9.0 et supérieur, PHP 8.1 et supérieur (testé sur 9.1.5)
- Multiboutique : chaque boutique a ses flux et ses réglages
- Interface en français et en anglais
- Licence MIT

Ce module succède à « Flux produits Microsoft Ads » (`microsoftadsfeed`), voir
[Venir de l'ancien module](#venir-de-lancien-module).

## Installation

1. Construisez l'archive : `./build.sh`, qui produit `dist/productfeed.zip`, ou
   téléchargez-la depuis la dernière version publiée sur GitHub.
2. Dans le back-office, **Modules, Gestionnaire de modules, Installer un module**,
   déposez l'archive.
3. Ouvrez la configuration du module. Les flux sont générés dès la première
   visite et la page indique ce qui est envoyé et ce qui ne l'est pas.

Le dossier `modules/productfeed/export/` doit être accessible en écriture par le
serveur web.

## Les adresses

La page de configuration donne une adresse par plateforme, de la forme
`https://votre-boutique.fr/product-feed/<plateforme>/<clé>` (avec `/fr/` devant
si la boutique a plusieurs langues). Collez-la là où la plateforme demande l'URL
d'un fichier de produits, et laissez vides l'éventuel nom d'utilisateur et le
mot de passe : la clé de 32 caractères tient lieu de mot de passe.

| Plateforme | Où coller l'adresse |
| --- | --- |
| Google Merchant Center | Sources de données, ajouter une source de produits à partir d'un fichier, saisir le lien |
| Microsoft Merchant Center | Flux, Créer un flux, méthode « Automatically download file from URL » |
| Meta | Commerce Manager, Catalogue, Sources de données, flux de données planifié |
| Pinterest | Annonces, Catalogues, Ajouter une source de données |

Choisissez un téléchargement quotidien. Pour chaque plateforme, la langue et le
pays déclarés doivent correspondre à la langue réglée dans le module.

## Ce qui change d'une plateforme à l'autre

Les quatre fichiers contiennent les mêmes produits et les mêmes prix. Seuls
quelques détails de forme diffèrent, selon la spécification de chacune :

| | Google | Microsoft | Meta | Pinterest |
| --- | --- | --- | --- | --- |
| Colonne de catégorie | `google_product_category` | `product_category` | `google_product_category` | `google_product_category` |
| Disponibilité | `in_stock` | `in stock` | `in stock` | `in stock` |
| Prix réduit | `11.82 EUR` | `11.82` | `11.82 EUR` | `11.82 EUR` |
| Frais de port | `FR:::6.90 EUR` | `6.90` | `FR:::6.90 EUR` | `FR:::6.90 EUR` |
| `identifier_exists` | `yes` / `no` | `TRUE` / `FALSE` | absent | absent |
| Description | 5 000 caractères | 10 000 | 9 999 | 10 000 |

Une même taxonomie sert à tous : celle de Google, par ID ou par chemin.

## Comment les fichiers restent à jour

| Ce qui change | Ce qui se passe |
| --- | --- |
| Un produit enregistré dans le back-office : nom, prix, description, images, déclinaisons, promotion, stock, activation, suppression | Les fichiers sont régénérés juste après l'enregistrement, une fois la page renvoyée, sans ralentir le back-office |
| Une marque, une catégorie ou un attribut renommé | Idem |
| Le stock qui baisse après une commande | Les fichiers sont seulement marqués comme périmés, pour ne pas ralentir la commande du client. Ils sont régénérés quand une plateforme vient chercher le sien |
| Une promotion qui commence ou finit à une date donnée | Un fichier de plus d'une heure est régénéré au téléchargement |

Une génération complète prend environ 30 ms pour une cinquantaine de lignes et
0,6 s pour un millier, une seule fois par enregistrement, quel que soit le
nombre de plateformes.

## Contenu des fichiers

Des fichiers texte tabulés en UTF-8, avec une ligne par produit ou par
déclinaison.

| Colonne | Source PrestaShop |
| --- | --- |
| `id` | ID du produit, ou `ID produit-ID déclinaison` |
| `item_group_id` | ID du produit, pour regrouper ses déclinaisons |
| `title` | Nom du produit, suivi des attributs de la déclinaison |
| `description` | Description complète ou résumé, sans mise en forme |
| `link` | Adresse de la fiche, déclinaison présélectionnée |
| `image_link`, `additional_image_link` | Image de couverture puis jusqu'à 10 autres, celles de la déclinaison en priorité |
| `price` | Prix TTC sans réduction, suivi de la devise (`12.50 EUR`) |
| `sale_price`, `sale_price_effective_date` | Prix réduit et période de la promotion |
| `brand` | Marque du produit, ou marque par défaut |
| `gtin` | EAN-13, ISBN ou UPC, après contrôle de la clé |
| `mpn` | Champ MPN, ou la référence si vous l'autorisez |
| `identifier_exists` | Absence de code-barres et de couple marque et MPN (Google, Microsoft) |
| catégorie | Catégorie Google associée à la catégorie du produit |
| `product_type` | Fil d'Ariane de la catégorie par défaut |
| `color`, `size`, `material`, `pattern` | Attributs de la déclinaison, selon vos réglages |
| `shipping` | Frais de port, si vous les renseignez |
| `excluded_destination` | Google seulement, si l'option « pas de fiches locales » est active |
| `availability`, `availability_date` | En stock, épuisé ou en précommande, avec la date de sortie pour Google |
| `condition` | État du produit : `new`, `used` ou `refurbished` |

Les prix sont calculés exactement comme la fiche produit les affiche à un
visiteur non connecté : les plateformes comparent les deux. Les colonnes vides
pour tous les produits sont omises.

Ne sont pas envoyés : les produits désactivés ou invisibles, ceux qui ne sont
pas disponibles à la commande, ceux sans image ou à prix nul, et ceux que vous
excluez. La page de configuration les liste avec la raison, ainsi que les
produits sans catégorie, sans marque ou sans identifiant.

## Catégories

Une sous-catégorie hérite de sa catégorie parente : renseigner les catégories
principales suffit souvent. Quelques valeurs utiles pour des produits de la
ruche :

| ID | Catégorie |
| --- | --- |
| 4947 | Alimentation, boissons et tabac > Aliments > Sauces et condiments > Miel |
| 2188 | Alimentation, boissons et tabac > Aliments > À tartiner > Confitures et gelées |
| 4748 | Alimentation, boissons et tabac > Aliments > Bonbons et chocolat |
| 1876 | Alimentation, boissons et tabac > Aliments > Boulangerie |
| 2073 | Alimentation, boissons et tabac > Boissons > Thé et infusions |
| 588 | Maison et jardin > Décorations > Parfums d'intérieur > Bougies |
| 505375 | Arts et loisirs > Loisirs et arts créatifs > Matériaux pour loisirs créatifs > Cire brute |
| 5134 | Maison et jardin > Arts de la table et arts culinaires > Stockage des aliments > Pots à miel |
| 784 | Médias > Livres |

Liste complète : [en français](https://www.google.com/basepages/producttype/taxonomy-with-ids.fr-FR.txt),
[en anglais](https://www.google.com/basepages/producttype/taxonomy-with-ids.en-US.txt).

## Réglages

- **Langue** des flux, à accorder avec celle déclarée sur chaque plateforme.
- **Prix TTC** : obligatoire pour la France, l'Allemagne et le Royaume-Uni.
- **Une ligne par déclinaison**, ou seulement la déclinaison par défaut.
- **Produits en rupture** : envoyés comme épuisés, ce que Google et Microsoft
  recommandent, ou retirés des flux.
- **Description** complète ou résumé, **taille des images**.
- **Marque par défaut** pour les produits sans marque, par exemple vos propres
  productions. Meta refuse les produits sans marque.
- **Référence comme MPN**, pour les produits que vous fabriquez.
- **Frais de port** forfaitaires et seuil de gratuité, facultatifs en France.
- **Produits exclus** par ID, et catégories exclues dans le tableau.
- **Google : pas de fiches locales**, pour une boutique sans magasin physique,
  voir ci-dessous.
- **Attributs** : lesquels envoyer comme couleur, taille, matière ou motif.

### « Données d'inventaire en magasin manquantes » chez Google

Google affiche cette erreur quand les modules complémentaires « Fiches locales
gratuites » et « Annonces produits en magasin » sont actifs dans Merchant Center
sans inventaire de magasin. Sans magasin physique, deux solutions équivalentes :

- supprimer ces deux modules complémentaires dans Merchant Center, pour tout le
  compte ;
- ou activer **Google : pas de fiches locales** dans le module, qui ajoute
  `excluded_destination` = `Free_local_listings,Local_inventory_ads` au flux
  Google.

## Autres services

Le flux Google suit le format Google Shopping, que d'autres services savent lire
directement : c'est l'adresse à leur donner. Les annonces OpenAI (ChatGPT), par
exemple, acceptent un flux au format Google. Vérifiez l'aperçu d'import du
service avant de lancer une campagne.

TikTok n'est pas couvert : son catalogue attend une colonne `sku_id` à la place
d'`id` et un fichier CSV pour les flux planifiés.

## Venir de l'ancien module

À l'installation, ce module reprend les réglages de `microsoftadsfeed`, boutique
par boutique, avec sa clé. L'ancienne adresse `/microsoft-ads-feed/<clé>`
continue de répondre, au format Microsoft, à l'octet près : rien n'est à
changer chez Microsoft ni chez Google.

L'ordre compte :

1. Installez ce module **avant** de désinstaller l'ancien : la désinstallation
   de l'ancien efface ses réglages et sa clé.
2. Désinstallez puis supprimez `microsoftadsfeed`. Un avertissement le rappelle
   tant qu'il est installé.
3. Dans Google Merchant Center, remplacez l'ancienne adresse par l'adresse
   Google, qui donne le bon nom de colonne de catégorie et les valeurs que
   Google documente. Chez Microsoft, l'ancienne adresse peut rester.

## Sécurité

L'adresse contient une clé de 32 caractères. Sans elle, la page répond 404. Le
bouton **Changer les adresses** en crée de nouvelles et invalide les anciennes,
ancienne adresse Microsoft comprise : pensez alors à les remplacer sur chaque
plateforme. Les fichiers générés sont servis par PrestaShop, jamais directement :
`export/` est fermé par un `.htaccess`. Les flux ne contiennent d'ailleurs que
des informations déjà visibles sur la boutique.

Les flux restent accessibles quand la boutique est en maintenance ou quand la
géolocalisation bloque des pays : les plateformes téléchargent depuis
l'étranger.

## Dépannage

**Une plateforme signale un échec de téléchargement.** Ouvrez l'adresse depuis
un autre réseau que celui de la boutique. Si un pare-feu applicatif ou un
service comme Cloudflare protège le site, vérifiez dans son journal que les
robots de la plateforme ne sont pas bloqués.

**Des produits manquent.** La section **Points à vérifier** de la
configuration donne, produit par produit, la raison de l'absence.

**Le module affiche une erreur d'écriture.** Rendez `modules/productfeed/export/`
accessible en écriture par l'utilisateur du serveur web.

## Pourquoi des adresses à la racine

PrestaShop 9 interdit de servir les fichiers `.txt` du dossier `modules/`, et
le `robots.txt` qu'il génère en interdit l'exploration. Le module déclare donc
ses propres adresses, servies par un contrôleur qui régénère les fichiers si
besoin avant de les envoyer.

## Limites

- Le prix à l'unité de mesure (prix au kilo) n'est pas transmis.
- Les frais de port se limitent à un forfait avec seuil de gratuité ; sinon,
  laissez chaque plateforme appliquer ses propres réglages de livraison.
- Pas d'inventaire de magasin physique (fiches locales Google).
