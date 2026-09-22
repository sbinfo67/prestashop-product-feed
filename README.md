# Flux produits Microsoft Ads pour PrestaShop 9

Publie votre catalogue au format que Microsoft Merchant Center sait
télécharger tout seul, pour vos campagnes Shopping et Performance Max sur
Bing, Edge, Copilot et le réseau Microsoft. Le fichier se met à jour quand
vous ajoutez, modifiez ou supprimez un produit, sans tâche cron.

- Compatible PrestaShop 9.0 et supérieur, PHP 8.1 et supérieur (testé sur 9.1.5)
- Multiboutique : chaque boutique a son propre flux et ses propres réglages
- Licence MIT

## Installation

1. Construisez l'archive : `./build.sh`, qui produit `dist/microsoftadsfeed.zip`.
2. Dans le back-office, **Modules, Gestionnaire de modules, Installer un module**,
   déposez l'archive.
3. Ouvrez la configuration du module. Le flux est généré dès la première visite
   et la page indique ce qui est envoyé et ce qui ne l'est pas.

Le dossier `modules/microsoftadsfeed/export/` doit être accessible en écriture
par le serveur web : c'est là que le fichier est rangé entre deux
téléchargements.

## Brancher le flux dans Microsoft Merchant Center

1. Dans la configuration du module, copiez l'**adresse du flux**. Elle a la forme
   `https://votre-boutique.fr/microsoft-ads-feed/3f9c…` (avec `/fr/` devant si la
   boutique a plusieurs langues).
2. Dans Microsoft Advertising, **Outils, Merchant Center**, choisissez votre
   magasin, onglet **Flux**, puis **Créer un flux**.
3. Pays de vente **France**, langue **français**. Ces choix doivent correspondre à
   la langue réglée dans le module.
4. Méthode de saisie : **Automatically download file from URL**. Collez
   l'adresse dans **Source URL** et laissez **User name** et **Password** vides :
   l'adresse contient déjà une clé secrète.
5. Fréquence : **Daily**, à l'heure de votre choix.

Le domaine de la boutique doit être vérifié dans Merchant Center, sans quoi
Microsoft refuse les liens produits.

## Comment le fichier reste à jour

| Ce qui change | Ce qui se passe |
| --- | --- |
| Un produit enregistré dans le back-office : nom, prix, description, images, déclinaisons, promotion, stock, activation, suppression | Le fichier est régénéré juste après l'enregistrement, une fois la page renvoyée, sans ralentir le back-office |
| Une marque, une catégorie ou un attribut renommé | Idem |
| Le stock qui baisse après une commande | Le fichier est seulement marqué comme périmé, pour ne pas ralentir la commande du client. Il est régénéré à l'instant où Microsoft le télécharge |
| Une promotion qui commence ou finit à une date donnée | Un fichier de plus d'une heure est régénéré au téléchargement |

Microsoft reçoit donc toujours un fichier à jour. La publication chez Microsoft
suit ensuite son propre rythme : le téléchargement planifié, puis sa
vérification des produits, qui peut prendre jusqu'à 24 heures.

## Contenu du fichier

Un fichier texte tabulé en UTF-8, le format natif de Microsoft, avec une ligne
par produit ou par déclinaison.

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
| `identifier_exists` | `FALSE` quand il n'y a ni code-barres ni couple marque et MPN |
| `product_category` | Catégorie Microsoft associée à la catégorie du produit |
| `product_type` | Fil d'Ariane de la catégorie par défaut |
| `color`, `size`, `material`, `pattern` | Attributs de la déclinaison, selon vos réglages |
| `shipping` | Frais de port, si vous les renseignez |
| `availability` | `in stock`, `out of stock` ou `preorder` |
| `condition` | État du produit : `new`, `used` ou `refurbished` |

Les prix sont calculés exactement comme la fiche produit les affiche à un
visiteur non connecté : Microsoft compare les deux. Les colonnes vides pour
tous les produits sont omises.

Ne sont pas envoyés : les produits désactivés ou invisibles, ceux qui ne sont
pas disponibles à la commande, ceux sans image ou à prix nul, et ceux que vous
excluez. La page de configuration les liste avec la raison.

## Catégories Microsoft

Microsoft utilise la taxonomie produits de Google, par ID ou par chemin
complet. Une sous-catégorie hérite de sa catégorie parente : renseigner les
catégories principales suffit souvent. Quelques valeurs utiles pour des
produits de la ruche :

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

- **Langue** du flux, à accorder avec celle choisie chez Microsoft.
- **Prix TTC** : obligatoire pour la France, l'Allemagne et le Royaume-Uni.
- **Une ligne par déclinaison**, ou seulement la déclinaison par défaut.
- **Produits en rupture** : envoyés comme épuisés, ce que Microsoft
  recommande, ou retirés du flux.
- **Description** complète ou résumé, **taille des images**.
- **Marque par défaut** pour les produits sans marque, par exemple vos propres
  productions.
- **Référence comme MPN**, pour les produits que vous fabriquez.
- **Frais de port** forfaitaires et seuil de gratuité, facultatifs en France.
- **Produits exclus** par ID, et catégories exclues dans le tableau.
- **Attributs** : lesquels envoyer comme couleur, taille, matière ou motif.

## Sécurité

L'adresse contient une clé de 32 caractères. Sans elle, la page répond 404.
Le bouton **Changer l'adresse** en crée une nouvelle et invalide l'ancienne ;
pensez alors à la remplacer chez Microsoft. Les fichiers générés sont servis
par PrestaShop, jamais directement : `export/` est fermé par un `.htaccess`.
Le flux ne contient d'ailleurs que des informations déjà visibles sur la
boutique.

Le flux reste accessible quand la boutique est en maintenance ou quand la
géolocalisation bloque des pays : Microsoft télécharge depuis l'étranger.

## Dépannage

**Microsoft signale un échec de téléchargement.** Ouvrez l'adresse depuis un
autre réseau que celui de la boutique. Si elle répond, vérifiez qu'aucun
pare-feu applicatif ou règle anti-robots du serveur ne bloque les robots de
Microsoft.

**Des produits manquent.** La section **Points à vérifier** de la
configuration donne, produit par produit, la raison de l'absence.

**Le module affiche une erreur d'écriture.** Rendez `modules/microsoftadsfeed/export/`
accessible en écriture par l'utilisateur du serveur web.

## Pourquoi une adresse à la racine

PrestaShop 9 interdit de servir les fichiers `.txt` du dossier `modules/`, et
le `robots.txt` qu'il génère en interdit l'exploration. Le module déclare donc
sa propre adresse, `/microsoft-ads-feed/…`, servie par un contrôleur qui
régénère le fichier si besoin avant de l'envoyer.

## Limites

- Le prix à l'unité de mesure (prix au kilo) n'est pas transmis.
- Les frais de port se limitent à un forfait avec seuil de gratuité ; sinon,
  laissez Microsoft appliquer les réglages de livraison du magasin.
- Le format XML de Google n'est pas proposé : le texte tabulé est le format
  natif de Microsoft et le plus simple à relire.
