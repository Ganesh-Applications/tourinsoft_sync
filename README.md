# Tourinsoft Sync

Plugin WordPress qui importe un flux **Tourinsoft** (hébergements, restaurants, activités…) dans des post types WordPress, en remplissant leurs champs **ACF**.

Tout se configure depuis l'admin ACF : on indique l'URL du flux sur le post type, puis, sur chaque champ, l'emplacement de la donnée dans le flux. Le code n'a pas besoin d'être modifié pour ajouter un post type ou un champ.

---

## Sommaire

1. [Prérequis](#prérequis)
2. [Installation](#installation)
3. [Configuration](#configuration)
4. [Écrire un chemin Tourinsoft](#écrire-un-chemin-tourinsoft)
5. [Types de champs gérés](#types-de-champs-gérés)
6. [Lancer l'import](#lancer-limport)
7. [Ce que fait l'import](#ce-que-fait-limport)
8. [Journal](#journal)
9. [Données enregistrées par le plugin](#données-enregistrées-par-le-plugin)
10. [Limites connues](#limites-connues)
11. [Organisation du code](#organisation-du-code)

---

## Prérequis

- WordPress 6.5 ou plus
- **ACF Pro** 6.1 ou plus : les post types doivent être créés **depuis ACF** (menu *ACF → Post Types*), car c'est là que se règle le flux.
- PHP 7.4 ou plus

## Installation

1. Copier le dossier `tourinsoft_sync` dans `wp-content/plugins/`.
2. Activer **ACF Pro**, puis **Tourinsoft Sync**, depuis le menu *Extensions*.

Un menu **Tourinsoft** apparaît dans l'admin.

---

## Configuration

### 1. Associer un flux à un post type

Dans *ACF → Post Types*, ouvrir le post type, puis l'onglet **Tourinsoft** :

| Réglage | Valeur |
|---|---|
| **Flux Tourinsoft** | URL du flux JSON |

Seuls les post types qui ont un flux sont importés.

### 2. Indiquer où lire chaque champ

Dans le groupe de champs ACF rattaché au post type, chaque champ a un réglage **Path Tourinsoft**. Il indique où trouver la valeur dans un élément du flux (voir la section suivante).

- Un champ **sans chemin n'est pas touché** par l'import : il reste modifiable à la main.
- Le titre du post est rempli automatiquement avec `SyndicObjectName`. Pas besoin de champ pour ça.

---

## Écrire un chemin Tourinsoft

Le chemin part d'**un élément du flux**. Chaque niveau est séparé par un point.

| Chemin | Résultat |
|---|---|
| `SyndicObjectName` | Valeur simple |
| `TYPEHEBERGEMENT.ThesLibelle` | Valeur dans un objet |
| `DESCRIPTIFs.0.Descriptioncommerciale` | Valeur dans le **1er** élément d'une liste (on compte à partir de 0) |
| `EQUIPEMENTSs.*.ThesLibelle` | **Liste** des valeurs de tous les éléments (`*` = tous) |

Si le chemin n'existe pas dans un élément, le champ est vidé pour ce post.

### Chemins des sous-champs (groupes et répéteurs)

Le chemin d'un sous-champ **part de son parent**, pas de l'élément entier :

```
Répéteur "equipements"   → Path : EQUIPEMENTSs    (la liste dans le flux)
  ├─ Texte "libelle"     → Path : ThesLibelle     (lu dans chaque élément de la liste)
  └─ Groupe "detail"     → Path : DETAIL
       └─ Texte "code"   → Path : Code            (lu dans DETAIL)

Groupe "adresse"         → Path : (vide)          (reste sur l'offre elle-même)
  └─ Texte "ville"       → Path : Commune
```

On peut imbriquer à n'importe quelle profondeur (un répéteur dans un groupe dans un répéteur, etc.).

---

## Types de champs gérés

| Type ACF | Chemin attendu | Ce qui est enregistré |
|---|---|---|
| Texte, zone de texte, nombre, WYSIWYG, URL, e-mail, vrai/faux… | Une valeur | La valeur brute du flux |
| **Groupe** | Un objet, ou vide | Ses sous-champs, lus dans l'objet |
| **Répéteur** | Une liste (obligatoire) | Une ligne par élément de la liste |
| **Taxonomie** | Une entrée, ou une liste d'entrées | Les termes correspondants (créés si besoin) |
| **Image** | Une photo, une liste de photos ou une URL | La première photo, importée dans la médiathèque |
| **Galerie** | Une liste de photos, une photo ou une URL | Toutes les photos, importées dans la médiathèque |

Types **pas encore gérés**, ignorés avec un avertissement dans le journal : contenu flexible, relation, objet de publication, fichier.

### Répéteurs

À chaque import, le répéteur reçoit la liste complète des lignes. ACF réécrit les lignes existantes et **supprime celles en trop**. Le répéteur reflète donc toujours le flux, sans doublons d'un import à l'autre.

### Taxonomies

Les deux formes du flux sont acceptées :

```jsonc
// Objet unique
"TYPEHEBERGEMENT": { "ThesID": "cf52…", "ThesLibelle": "Hôtel-restaurant", … }

// Liste
"LANGUESPARLEESs": [ { "ThesID": "6f26…", "ThesLibelle": "Français", … }, … ]
```

Le chemin pointe sur le nœud lui-même (`TYPEHEBERGEMENT` ou `LANGUESPARLEESs`).

- Les termes sont retrouvés grâce à leur **`ThesID`**.
- Un terme inconnu est **créé**. Si un terme du même nom existe déjà (créé à la main), il est **repris**.
- Si le libellé change dans Tourinsoft, le terme est **renommé**.
- Champ à choix unique (liste déroulante, bouton radio) : seul le premier terme est gardé.

> **À activer :** dans le champ taxonomie ACF, cocher **« Enregistrer les termes »**. Sans ce réglage, les termes sont seulement enregistrés dans le champ ACF et ne sont pas rattachés au post dans WordPress : filtres, archives et `tax_query` ne les voient pas.

### Images et galeries

Le chemin pointe sur la ou les photos (par exemple `PHOTOSs`). L'URL et le crédit sont lus dans chaque objet photo, à des emplacements fixés dans le code :

- Une image n'est **téléchargée qu'une seule fois**. Elle est ensuite retrouvée grâce à son URL d'origine.
- Le **crédit** est enregistré dans la **légende** du média.
- Si le chemin pointe directement sur une URL (texte), l'image est importée sans crédit.

---

## Lancer l'import

### Automatiquement

L'import se lance **tous les jours à 3 h** (heure du site, réglée dans *Réglages → Général*) grâce à une tâche planifiée WP-Cron.

- La tâche est créée à l'activation du plugin et supprimée à sa désactivation.
- La date du prochain import est affichée sur la page **Tourinsoft** de l'admin.
- Pour changer l'heure, modifier la constante `TIME` dans `includes/class-tourinsoft-sync-cron.php`, puis désactiver et réactiver le plugin pour replanifier la tâche.

> **WP-Cron dépend des visites.** WordPress ne lance ses tâches planifiées que lorsqu'une page est chargée. Sur un site peu visité la nuit, l'import peut donc partir en retard. Pour une heure fiable, désactiver WP-Cron dans `wp-config.php` :
>
> ```php
> define('DISABLE_WP_CRON', true);
> ```
>
> puis ajouter une vraie tâche cron sur le serveur, qui appelle WP-Cron toutes les 5 minutes par exemple :
>
> ```
> */5 * * * * curl -s https://exemple.fr/wp-cron.php?doing_wp_cron > /dev/null
> ```

### À la main

Menu **Tourinsoft** dans l'admin → bouton **Synchroniser**.

La page affiche ensuite les 100 dernières lignes du journal.
Pour voir le journal complet, ouvrir le fichier dans `wp-content/uploads/tourinsoft-sync/`.

Depuis le code :

```php
Tourinsoft_Sync::instance()->sync();
```


### Premier import

Il télécharge toutes les images et génère leurs miniatures. Il peut donc être long. Le plugin lève la limite de temps d'exécution de PHP quand l'hébergeur le permet. Les imports suivants sont beaucoup plus rapides.

---

## Ce que fait l'import

Pour chaque post type qui a un flux :

1. **Lecture du flux.** En cas d'erreur (serveur injoignable, code HTTP autre que 200, JSON invalide, pas de clé `value`), le post type est passé et l'erreur est écrite dans le journal.
2. **Pour chaque élément**, identifié par son `SyndicObjectID` :

   | Situation | Action |
   |---|---|
   | Nouvel élément | Création du post (publié) |
   | Élément déjà importé | Mise à jour des champs, et du titre s'il a changé |
   | Post mis à la corbeille **par l'import** | Restauration avec son statut d'avant, puis mise à jour |
   | Post mis à la corbeille **à la main** | Élément ignoré : le post reste dans la corbeille |
   | Élément sans `SyndicObjectID` | Élément ignoré, avec un avertissement |

3. **Éléments disparus du flux** : leurs posts sont **mis à la corbeille**.
4. **Bilan** dans le journal : nombre de posts créés, mis à jour et mis à la corbeille.

### Sécurités

- **Flux vide :** si le flux ne contient aucune offre valide, **rien n'est mis à la corbeille**.
- **Posts créés à la main** (sans `tourinsoft_id`) : jamais modifiés ni mis à la corbeille par l'import.
- **Champs sans chemin :** jamais modifiés.

> ⚠️ Les champs **avec** un chemin et le **titre** sont réécrits à chaque import. Une modification faite à la main dans WordPress sera écrasée par la valeur Tourinsoft.

---

## Journal

Chaque import écrit dans un fichier de log :

```
wp-content/uploads/tourinsoft-sync/tourinsoft-sync-<hash>.log
```

- Le dossier est protégé contre l'accès depuis le navigateur (`.htaccess` et nom de fichier difficile à deviner).
- Au-delà de 5 Mo, le fichier est renommé en `.log.1` et un nouveau fichier commence.
- Trois niveaux : `INFO`, `WARNING`, `ERROR`.

Depuis le code :

```php
Tourinsoft_Sync_Logger::info('Message');
Tourinsoft_Sync_Logger::warning('Message');
Tourinsoft_Sync_Logger::error('Message');
```

---

## Données enregistrées par le plugin

| Où | Clé | Contenu |
|---|---|---|
| Réglages du post type ACF | `tourinsoft_flux` | URL du flux |
| Réglages des champs ACF | `tourinsoft_path` | Chemin Tourinsoft |
| Meta des posts | `tourinsoft_id` | `SyndicObjectID` de l'offre |
| Meta des posts | `tourinsoft_trashed` | Présente si le post a été mis à la corbeille par l'import |
| Meta des termes | `tourinsoft_thes_id` | `ThesID` de l'entrée de thésaurus |
| Meta des médias | `tourinsoft_url` | URL d'origine de l'image |

Ces metas permettent de retrouver les éléments d'un import à l'autre. **Il ne faut pas les supprimer**, sinon les posts, termes et images seront recréés en double.

---

## Limites connues

- Contenu flexible, relation, objet de publication et fichier : pas encore gérés.
- Images : seul le crédit est récupéré (pas de description, pas de texte alternatif).
- Pas d'image mise en avant automatique.
- Les images qui ne sont plus utilisées et les termes qui ne servent plus restent dans WordPress. Pour les termes, c'est voulu : un terme peut servir ailleurs sur le site.
- L'heure de l'import automatique se règle dans le code (`Tourinsoft_Sync_Cron::TIME`), pas depuis l'admin.

---

## Organisation du code

```
tourinsoft_sync/
├── tourinsoft_sync.php                     Point d'entrée : constantes, chargement des classes, hooks d'activation
├── uninstall.php                           Nettoyage à la suppression du plugin
└── includes/
    ├── class-tourinsoft-sync.php           Classe principale (singleton) : sync() lance l'import (avec un verrou : un seul import à la fois)
    ├── class-tourinsoft-sync-admin.php     Page d'admin « Tourinsoft » : bouton et journal
    ├── class-tourinsoft-sync-acf.php       Réglages ajoutés à ACF : flux (post type) et chemin (champs)
    ├── class-tourinsoft-sync-cron.php      Import automatique quotidien (WP-Cron)
    ├── class-tourinsoft-sync-importer.php  Logique d'import
    └── class-tourinsoft-sync-logger.php    Écriture et lecture du journal
```

### Fonctions de l'importeur (`Tourinsoft_Sync_Importer`)

| Fonction | Rôle |
|---|---|
| `import()` | Boucle principale : post types → offres → champs |
| `get_flux_data()` | Télécharge et décode le flux JSON |
| `get_fields_to_import()` | Récupère les champs ACF d'un post type |
| `build_fields_tree()` | Transforme les champs ACF en arborescence (sous-champs compris) |
| `build_value()` | Construit la valeur d'un champ à partir du flux (récursive) |
| `build_row()` | Construit une ligne de répéteur ou le contenu d'un groupe |
| `get_value_from_path()` | Lit une valeur dans le flux à partir d'un chemin |
| `get_or_create_post()` | Retrouve, restaure ou crée le post d'une offre |
| `get_or_create_term()` | Retrouve ou crée un terme de taxonomie |
| `get_or_create_attachment()` | Retrouve ou télécharge une image |
| `trash_inactive_posts()` | Met à la corbeille les posts sortis du flux |

### Ajouter la gestion d'un nouveau type de champ

1. Retirer le type de `$unsupported_types` dans `build_fields_tree()`.
2. Si le type a besoin de réglages du champ ACF (comme `taxonomy`), les ajouter au nœud dans `build_fields_tree()`.
3. Ajouter un `case` dans `build_value()`, qui renvoie la valeur au format attendu par `update_field()`.
