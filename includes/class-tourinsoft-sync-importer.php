<?php
/**
 * Logique d'import des données Tourinsoft.
 * @package Tourinsoft_Sync
 */

if ( ! defined( 'ABSPATH' ) ) {
        exit;
}

class Tourinsoft_Sync_Importer
{
        //-- Emplacement de l'URL et du crédit dans un objet photo du flux.
        //-- Ex. : { "Photo": { "Url": "https://...", "Credit": "© Nom" } }
        //-- À adapter si le flux utilise d'autres noms de clés.
        const PHOTO_URL_PATH    = 'Photo.Url';
        const PHOTO_CREDIT_PATH = 'Photo.Credit';

        private $num_updated_posts = 0;
        private $num_created_posts = 0;

        //-- IDs des termes déjà trouvés pendant l'import : [taxonomie][ThesID] => ID du terme
        private $terms_cache = [];

        //-- IDs des médias déjà trouvés pendant l'import : [URL d'origine] => ID du média
        private $attachments_cache = [];

        /**
         * Importe les données depuis Tourinsoft et les insère dans WordPress.
         *
         *   1. Parcourt les post types déclarés dans ACF ayant un réglage 'tourinsoft_flux'
         *   2. Télécharge et décode le flux JSON pour chaque post type
         *   3. Crée les posts non existants trouvés dans le flux
         *   4. Pour chaque post, récupère les champs ACF qui ont un 'tourinsoft_path'
         *      et met les valeurs à jour avec les valeurs correspondantes du flux Tourinsoft
         *   5. Met à la corbeille les posts qui ne sont plus dans le flux
         *   6. Écrit dans le log les informations sur l'import.
         *
         * @return void
         */
        public function import()
        {
                //-- Sans ACF Pro, rien ne peut fonctionner : on s'arrête tout de suite
                if (!function_exists('acf_get_acf_post_types'))
                {
                        Tourinsoft_Sync_Logger::error('ACF Pro n’est pas actif. Le plugin Tourinsoft Sync nécessite ACF Pro pour fonctionner.');
                        return;
                }

                foreach (acf_get_acf_post_types() as $post_type)
                {
                        //-- Filtre pour ne garder que les post types ayant un flux Tourinsoft défini.
                        $tourinsoft_flux = $post_type['tourinsoft_flux'] ?? null;

                        if (!$tourinsoft_flux) continue;

                        Tourinsoft_Sync_Logger::info("Import du flux Tourinsoft '{$tourinsoft_flux}' pour le post type '{$post_type['post_type']}'");

                        $fields_to_import = $this->get_fields_to_import($post_type['post_type']);

                        $flux_data = $this->get_flux_data($tourinsoft_flux);

                        //-- Si le flux n'a pas pu être récupéré on passe au post type suivant
                        if (!$flux_data) continue;

                        $this->num_updated_posts = 0;
                        $this->num_created_posts = 0;

                        $active_post_ids = [];

                        foreach ($flux_data['value'] as $index => $element)
                        {
                                if (empty($element['SyndicObjectID']))
                                {
                                        $name = $element['SyndicObjectName'] ?? 'sans nom';
                                        Tourinsoft_Sync_Logger::warning("Élément n°{$index} du flux ignoré ('{$name}') : il n'a pas de SyndicObjectID.");
                                        continue;
                                }

                                //-- L'élément est dans le flux : son post ne doit pas être mis à la corbeille,
                                //-- même s'il est ignoré ci-dessous.
                                $active_post_ids[] = $element['SyndicObjectID'];

                                $post_id = $this->get_or_create_post($post_type['post_type'], $element);

                                //-- Élément ignoré ou création du post impossible
                                if (!$post_id) continue;

                                //-- Pour chaque champ on construit un tableau avec sa valeur et celle de ses sous champs le cas échéant
                                //   puis on l'enregistre en une seule fois.
                                //-- Pour un répéteur, update_field() reçoit la liste complète des lignes :
                                //   ACF réécrit les lignes existantes et supprime celles en trop.
                                foreach ($fields_to_import as $field_key => $field)
                                {
                                        $value = $this->build_value($field, $element);
                                        update_field($field_key, $value, $post_id);
                                }
                        }

                        //-- Met à la corbeille les posts qui ne sont plus présents dans le flux Tourinsoft
                        $num_trashed_posts = $this->trash_inactive_posts($post_type['post_type'], $active_post_ids);

                        Tourinsoft_Sync_Logger::info(
                                "Import terminé pour le flux Tourinsoft '{$tourinsoft_flux}' : "
                                . "{$this->num_created_posts} créés, {$this->num_updated_posts} mis à jour, "
                                . "{$num_trashed_posts} mis à la corbeille."
                        );
                }
        }

        /**
         * Récupère l'arborescence des champs ACF à importer pour un post type.
         *
         * Parcourt tous les groupes de champs ACF rattachés au post type et
         * renvoie les champs à importer, avec leurs sous-champs (voir build_fields_tree()).
         *
         * @param string $post_type Slug du post type.
         * @return array Arborescence des champs, indexée par clé ACF (field_xxx).
         */
        private function get_fields_to_import($post_type)
        {
                $fields_to_import = [];

                $field_groups = acf_get_field_groups(array('post_type' => $post_type));

                foreach ($field_groups as $group)
                {
                        //-- Ajoute les champs de chaque groupe à la liste
                        $fields_to_import += $this->build_fields_tree(acf_get_fields($group));
                }

                return $fields_to_import;
        }

        /**
         * Transforme une liste de champs ACF en arborescence exploitable par l'import.
         *
         * Chaque champ devient un tableau :
         *   [
         *     'name'       => nom du champ
         *     'type'       => type ACF (text, group, repeater...),
         *     'path'       => chemin Tourinsoft
         *     'sub_fields' => sous-champs, même format (groupes et répéteurs uniquement),
         *   ]
         *
         * Le chemin d'un sous-champ est relatif à son parent :
         *   Répéteur 'equipements' → path 'EQUIPEMENTSs'  (la liste du flux)
         *     └ Texte 'libelle'    → path 'ThesLibelle'   (lu dans chaque élément de la liste)
         *
         * @param array $fields Champs ACF
         * @return array Arborescence indexée par clé ACF.
         */
        private function build_fields_tree(array $fields): array
        {
                $unsupported_types = ['flexible_content', 'relationship', 'post_object', 'file'];

                $tree = [];

                foreach ($fields as $field)
                {
                        $path = trim($field['tourinsoft_path'] ?? '');

                        if (in_array($field['type'], $unsupported_types, true))
                        {
                                if ($path !== '')
                                        Tourinsoft_Sync_Logger::warning("Champ '{$field['name']}' ignoré : le type '{$field['type']}' n'est pas encore géré par l'import.");
                                continue;
                        }

                        $node = [
                                'name' => $field['name'],
                                'type' => $field['type'],
                                'path' => $path,
                        ];

                        if ($field['type'] === 'group' || $field['type'] === 'repeater')
                        {
                                //-- Appel récursif : les sous-champs peuvent eux-mêmes être des groupes ou des répéteurs
                                $node['sub_fields'] = $this->build_fields_tree($field['sub_fields'] ?? []);

                                //-- Aucun sous-champ à importer : rien à faire pour ce champ
                                if (!$node['sub_fields']) continue;

                                if ($field['type'] === 'repeater' && $path === '')
                                {
                                        Tourinsoft_Sync_Logger::warning("Répéteur '{$field['name']}' ignoré : il lui faut un chemin Tourinsoft vers une liste.");
                                        continue;
                                }
                        }
                        else if ($path === '')
                        {
                                //-- Champ sans chemin (hors groupe) : non importé
                                continue;
                        }

                        if ($field['type'] === 'taxonomy')
                        {
                                //-- Taxonomie dans laquelle chercher ou créer les termes
                                $node['taxonomy'] = $field['taxonomy'];

                                //-- checkbox / multi_select : plusieurs termes ; radio / select : un seul
                                $node['multiple'] = in_array($field['field_type'] ?? '', ['checkbox', 'multi_select'], true);
                        }

                        //-- Construit un tableau indicé par clé acf pour correspondre au format attendu par ACF
                        $tree[$field['key']] = $node;
                }

                return $tree;
        }

        /**
         * Construit la valeur à enregistrer pour un champ, à partir des données du flux.
         *
         * Fonction récursive pour les champs imbriqués
         *
         * @param array $field Nœud de l'arborescence (voir build_fields_tree()).
         * @param array $data  Données sur lesquelles le chemin s'applique : l'élément
         *                     du flux au premier niveau, puis la partie du flux
         *                     correspondant au parent pour les sous-champs.
         * @return mixed
         */
        private function build_value(array $field, array $data)
        {
                //-- Chemin vide (possible pour un groupe) : on reste sur les données du parent.
                $source = $field['path'] === ''
                        ? $data
                        : $this->get_value_from_path($data, $field['path']);

                switch ($field['type'])
                {
                        case 'group':
                                //-- Absent du flux : on vide le groupe
                                if (!is_array($source)) return null;

                                return $this->build_row($field['sub_fields'], $source);

                        case 'repeater':
                                //-- Absent du flux : aucune ligne (les lignes existantes seront supprimées)
                                if (!is_array($source)) return [];

                                $rows = [];
                                foreach ($source as $item)
                                {
                                        //-- Chaque élément de la liste donne une ligne du répéteur
                                        if (is_array($item))
                                                $rows[] = $this->build_row($field['sub_fields'], $item);
                                }
                                return $rows;

                        case 'taxonomy':
                                //-- Absent du flux : aucun terme
                                if (!is_array($source)) return [];

                                //-- Le flux peut contenir un objet unique ou une liste d'objets :
                                //--   - objet unique (ex. TYPEHEBERGEMENT) : on le met dans une liste d'un élément ;
                                //--   - liste (ex. LANGUESPARLEESs) : inchangée.
                                $items = isset($source['ThesID'])
                                        ? [$source]
                                        : $source;

                                $term_ids = [];
                                foreach ($items as $item)
                                {
                                        if (!is_array($item)) continue;

                                        $term_id = $this->get_or_create_term($item, $field['taxonomy']);
                                        if ($term_id)
                                                $term_ids[] = $term_id;
                                }

                                //-- Champ à choix unique : on garde le premier terme
                                return $field['multiple']
                                        ? $term_ids
                                        : ($term_ids[0] ?? null);

                        case 'image':
                                //-- Le chemin peut pointer sur une photo ou sur la liste des photos :
                                //-- dans ce cas on prend la première.
                                $photo = (is_array($source) && isset($source[0])) ? $source[0] : $source;

                                return $this->get_or_create_attachment($photo);

                        case 'gallery':
                                //-- On ramène la source à une liste de photos :
                                //--   - URL seule (texte) ou photo seule (objet) : liste d'un élément ;
                                //--   - liste de photos : inchangée.
                                if (is_string($source))
                                        $photos = [$source];
                                else if (is_array($source))
                                        $photos = isset($source[0]) ? $source : [$source];
                                else
                                        return [];

                                $attachment_ids = [];
                                foreach ($photos as $photo)
                                {
                                        $attachment_id = $this->get_or_create_attachment($photo);
                                        if ($attachment_id) $attachment_ids[] = $attachment_id;
                                }
                                return $attachment_ids;

                        default:
                                //-- Champ simple : valeur brute du flux
                                return $source;
                }
        }

        /**
         * Construit une ligne de répéteur ou le contenu d'un groupe.
         *
         * @param array $sub_fields Sous-champs du groupe ou du répéteur.
         * @param array $data       Partie du flux correspondant à cette ligne.
         * @return array [clé du sous-champ => valeur]
         */
        private function build_row(array $sub_fields, array $data): array
        {
                $row = [];

                foreach ($sub_fields as $sub_field_key => $sub_field)
                        $row[$sub_field_key] = $this->build_value($sub_field, $data);

                return $row;
        }

        /**
         * Renvoie l'ID du terme WordPress
         * Le crée s'il n'existe pas encore
         *
         * Le terme est retrouvé grâce au ThesID, enregistré dans la meta 'tourinsoft_thes_id'
         *
         * @param array  $item     Entrée du flux (ThesID, ThesLibelle, ThesCode...).
         * @param string $taxonomy Slug de la taxonomie.
         * @return int|null ID du terme, ou null en cas d'erreur.
         */
        private function get_or_create_term(array $item, string $taxonomy)
        {
                $thes_id = $item['ThesID'] ?? null;
                $label   = trim($item['ThesLibelle'] ?? '');

                if (!$thes_id || $label === '') return null;

                //-- Cache pour l'import en cours
                if (isset($this->terms_cache[$taxonomy][$thes_id])) return $this->terms_cache[$taxonomy][$thes_id];

                //-- 1. Terme déjà importé : on le retrouve par son ThesID
                $terms = get_terms([
                        'taxonomy'   => $taxonomy,
                        'hide_empty' => false,
                        'meta_key'   => 'tourinsoft_thes_id',
                        'meta_value' => $thes_id,
                        'number'     => 1,
                ]);

                if ($terms && !is_wp_error($terms))
                {
                        $term_id = (int) $terms[0]->term_id;

                        //-- Libellé modifié dans Tourinsoft : on renomme le terme.
                        //-- WordPress enregistre « & » sous la forme « &amp; » : on compare les textes décodés.
                        if (html_entity_decode($terms[0]->name, ENT_QUOTES) !== $label)
                        {
                                $result = wp_update_term($term_id, $taxonomy, ['name' => $label]);

                                if (is_wp_error($result))
                                        Tourinsoft_Sync_Logger::warning("Terme '{$label}' ({$taxonomy}) non renommé : {$result->get_error_message()}");
                        }
                }
                else
                {
                        //-- 2. Terme inconnu : on le crée
                        $result = wp_insert_term($label, $taxonomy);

                        if (is_wp_error($result))
                        {
                                //-- Un terme du même nom existe déjà (créé à la main) : on le reprend.
                                //-- WordPress renvoie son ID dans les données de l'erreur 'term_exists'.
                                $term_id = (int) $result->get_error_data('term_exists');

                                if (!$term_id)
                                {
                                        Tourinsoft_Sync_Logger::error("Terme '{$label}' ({$taxonomy}) : {$result->get_error_message()}");
                                        return null;
                                }
                        }
                        else
                        {
                                $term_id = (int) $result['term_id'];
                                Tourinsoft_Sync_Logger::info("Création du terme '{$label}' dans la taxonomie '{$taxonomy}'");
                        }

                        //-- On enregistre le ThesID pour retrouver le terme aux prochains imports
                        update_term_meta($term_id, 'tourinsoft_thes_id', $thes_id);
                }

                return $this->terms_cache[$taxonomy][$thes_id] = $term_id;
        }

        /**
         * Renvoie l'ID du média WordPress correspondant à une photo du flux.
         * Télécharge l'image dans la médiathèque si elle n'y est pas encore.
         *
         * La photo peut être :
         *   - un objet du flux : l'URL et le crédit sont lus avec PHOTO_URL_PATH et PHOTO_CREDIT_PATH ;
         *   - directement une URL (texte) : pas de crédit dans ce cas.
         *
         * Le média est retrouvé grâce à son URL d'origine, enregistrée dans la meta
         * 'tourinsoft_url' : une image n'est jamais téléchargée deux fois.
         * Le crédit est enregistré dans la légende du média, et mis à jour à chaque
         * import s'il a changé dans Tourinsoft.
         *
         * @param mixed $photo Objet photo du flux, ou URL.
         * @return int|null ID du média, ou null si l'image n'a pas pu être récupérée.
         */
        private function get_or_create_attachment($photo)
        {
                //-- Lecture de l'URL et du crédit
                if (is_array($photo))
                {
                        $url    = $this->get_value_from_path($photo, self::PHOTO_URL_PATH);
                        $credit = $this->get_value_from_path($photo, self::PHOTO_CREDIT_PATH);
                }
                else
                {
                        $url    = $photo;
                        $credit = null;
                }

                $credit = is_string($credit) ? trim($credit) : '';

                if (!is_string($url) || !filter_var($url, FILTER_VALIDATE_URL)) return null;

                //-- Déjà traitée pendant cet import (même photo sur plusieurs posts ou plusieurs champs)
                if (isset($this->attachments_cache[$url])) return $this->attachments_cache[$url];

                //-- 1. Image déjà importée : on la retrouve par son URL d'origine
                $existing = get_posts([
                        'post_type'   => 'attachment',
                        'post_status' => 'inherit',
                        'meta_key'    => 'tourinsoft_url',
                        'meta_value'  => $url,
                        'numberposts' => 1,
                        'fields'      => 'ids',
                ]);

                if ($existing)
                {
                        $attachment_id = (int) $existing[0];

                        //-- Crédit modifié dans Tourinsoft : on met à jour la légende.
                        //-- Crédit vide dans le flux : on ne touche pas à la légende (elle a pu être saisie à la main).
                        if ($credit !== '' && get_post_field('post_excerpt', $attachment_id) !== $credit)
                        {
                                wp_update_post([
                                        'ID'           => $attachment_id,
                                        'post_excerpt' => $credit,
                                ]);
                        }

                        return $this->attachments_cache[$url] = $attachment_id;
                }

                //-- 2. Nouvelle image : on la télécharge.
                //-- Ces fonctions ne sont chargées que dans l'admin : il faut les inclure
                //-- quand l'import tourne en tâche planifiée (cron).
                require_once ABSPATH . 'wp-admin/includes/file.php';
                require_once ABSPATH . 'wp-admin/includes/media.php';
                require_once ABSPATH . 'wp-admin/includes/image.php';

                //-- Téléchargement dans un fichier temporaire (30 secondes maximum)
                $tmp_file = download_url($url, 30);

                if (is_wp_error($tmp_file))
                {
                        Tourinsoft_Sync_Logger::error("Image '{$url}' : {$tmp_file->get_error_message()}");
                        return null;
                }

                //-- WordPress a besoin d'un nom de fichier avec une extension.
                //-- Si l'URL n'en a pas, on la déduit du contenu du fichier.
                $file_name = basename(parse_url($url, PHP_URL_PATH));

                if (!pathinfo($file_name, PATHINFO_EXTENSION))
                {
                        $extensions = [
                                'image/jpeg' => 'jpg',
                                'image/png'  => 'png',
                                'image/webp' => 'webp',
                                'image/gif'  => 'gif',
                        ];
                        $file_name .= '.' . ($extensions[wp_get_image_mime($tmp_file)] ?? 'jpg');
                }

                //-- Ajout à la médiathèque (copie du fichier + génération des miniatures).
                //-- 0 : le média n'est rattaché à aucun post.
                $attachment_id = media_handle_sideload(
                        ['name' => $file_name, 'tmp_name' => $tmp_file],
                        0,
                        null,
                        //-- Crédit dans la légende. Sans crédit, WordPress garde la légende
                        //-- éventuellement présente dans les métadonnées de la photo.
                        $credit !== '' ? ['post_excerpt' => $credit] : []
                );

                if (is_wp_error($attachment_id))
                {
                        @unlink($tmp_file); //-- media_handle_sideload ne supprime pas le fichier temporaire en cas d'échec
                        Tourinsoft_Sync_Logger::error("Image '{$url}' : {$attachment_id->get_error_message()}");
                        return null;
                }

                //-- On enregistre l'URL d'origine pour retrouver le média aux prochains imports
                update_post_meta($attachment_id, 'tourinsoft_url', $url);

                Tourinsoft_Sync_Logger::info("Import de l'image '{$file_name}'");

                return $this->attachments_cache[$url] = (int) $attachment_id;
        }

        /**
         * Récupère une valeur dans un élément du flux à partir d'un chemin.
         *
         * Le chemin sépare chaque niveau par un point :
         *   - 'SyndicObjectName'                      → valeur simple
         *   - 'TYPEHEBERGEMENT.ThesLibelle'           → valeur dans un objet
         *   - 'DESCRIPTIFs.0.Descriptioncommerciale'  → 1er élément d'une liste
         *   - 'EQUIPEMENTSs.*.ThesLibelle'            → tableau des valeurs de tous les éléments
         *
         * @param array  $data Élément du flux Tourinsoft.
         * @param string $path Chemin de la valeur.
         * @return mixed La valeur, ou null si le chemin n'existe pas.
         */
        private function get_value_from_path(array $data, string $path)
        {
                $keys = explode('.', $path);
                $value = $data;

                foreach ($keys as $index => $key)
                {
                        //-- Joker : on applique la suite du chemin à chaque élément de la liste.
                        if ($key === '*')
                        {
                                if (!is_array($value)) return null;

                                $rest = implode('.', array_slice($keys, $index + 1));

                                if ($rest === '') return array_values($value);

                                $values = [];
                                foreach ($value as $item)
                                {
                                        if (!is_array($item)) continue;

                                        $item_value = $this->get_value_from_path($item, $rest);
                                        if ($item_value !== null) $values[] = $item_value;
                                }

                                return $values;
                        }

                        if (!is_array($value) || !array_key_exists($key, $value)) return null;

                        $value = $value[$key];
                }

                return $value;
        }

        /**
         * Télécharge et décode le flux JSON Tourinsoft.
         *
         * @param string $url URL du flux.
         * @return array|false Données du flux, ou false en cas d'erreur (écrite dans le log).
         */
        private function get_flux_data(string $url)
        {
                try
                {
                        //-- 60 secondes maximum : les flux Tourinsoft peuvent être volumineux
                        $response = wp_remote_get($url, ['timeout' => 60]);

                        if (is_wp_error($response))
                                throw new Exception($response->get_error_message());

                        $status = wp_remote_retrieve_response_code($response);

                        if ($status !== 200)
                                throw new Exception("Le serveur a répondu avec le code HTTP {$status}.");

                        $flux_content = wp_remote_retrieve_body($response);

                        if ($flux_content === '')
                                throw new Exception("Le flux est vide.");

                        //-- JSON_THROW_ON_ERROR : lève une JsonException si le JSON est invalide.
                        $flux_data = json_decode($flux_content, true, 512, JSON_THROW_ON_ERROR);

                        if (!isset($flux_data['value']) || !is_array($flux_data['value']))
                                throw new Exception("Le flux ne contient pas de clé 'value' exploitable.");

                        return $flux_data;
                }
                catch (Exception $e)
                {
                        Tourinsoft_Sync_Logger::error("Flux Tourinsoft '{$url}' : {$e->getMessage()}");
                        return false;
                }
        }

        /**
         * Renvoie l'ID du post correspondant à un élément du flux.
         * Le crée s'il n'existe pas encore.
         *
         * Le post est retrouvé grâce au SyndicObjectID, enregistré dans la meta 'tourinsoft_id'.
         *   - Post existant                       : son titre est mis à jour si le nom a changé dans Tourinsoft.
         *   - Post mis à la corbeille par l'import : il était sorti du flux et y revient, on le restaure.
         *   - Post mis à la corbeille à la main    : on le laisse dans la corbeille, l'élément est ignoré.
         *   - Sinon                                : le post est créé.
         *
         * @param string $post_type Slug du post type.
         * @param array  $element   Élément du flux (doit avoir un SyndicObjectID).
         * @return int|null ID du post, ou null si l'élément est ignoré ou si la création a échoué.
         */
        private function get_or_create_post(string $post_type, array $element)
        {
                $tourinsoft_id = $element['SyndicObjectID'];
                $title         = trim($element['SyndicObjectName'] ?? '');

                //-- Nom affiché dans le log : le titre, ou à défaut l'ID Tourinsoft
                $log_name = $title !== '' ? $title : $tourinsoft_id;

                $existing_posts = get_posts([
                        'post_type'   => $post_type,
                        'meta_key'    => 'tourinsoft_id',
                        'meta_value'  => $tourinsoft_id,
                        //-- 'any' n'inclut pas la corbeille : on l'ajoute pour ne pas créer de doublon
                        'post_status' => ['any', 'trash'],
                        'numberposts' => 1,
                ]);

                if ($existing_posts)
                {
                        $post = $existing_posts[0];

                        if ($post->post_status === 'trash')
                        {
                                //-- La meta 'tourinsoft_trashed' n'est posée que par trash_inactive_posts()
                                if (!get_post_meta($post->ID, 'tourinsoft_trashed', true))
                                {
                                        Tourinsoft_Sync_Logger::info("Élément '{$log_name}' ignoré : il a été mis à la corbeille manuellement.");
                                        return null;
                                }

                                //-- Par défaut, WordPress restaure en brouillon.
                                //-- Ce filtre lui fait reprendre le statut qu'il avait avant la corbeille.
                                add_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10, 3);
                                wp_untrash_post($post->ID);
                                remove_filter('wp_untrash_post_status', 'wp_untrash_post_set_previous_status', 10);

                                delete_post_meta($post->ID, 'tourinsoft_trashed');

                                Tourinsoft_Sync_Logger::info("Restauration de l'élément '{$log_name}' : il est de retour dans le flux.");
                        }

                        //-- Nom modifié dans Tourinsoft : on met à jour le titre.
                        //-- WordPress enregistre « & » sous la forme « &amp; » : on compare les textes décodés.
                        if ($title !== '' && html_entity_decode($post->post_title, ENT_QUOTES) !== $title)
                        {
                                wp_update_post([
                                        'ID'         => $post->ID,
                                        'post_title' => $title,
                                ]);
                        }

                        Tourinsoft_Sync_Logger::info("Mise à jour de l'élément '{$log_name}'");
                        $this->num_updated_posts++;

                        return $post->ID;
                }

                //-- true : renvoie une WP_Error en cas d'échec (sinon WordPress renvoie 0 sans explication)
                $post_id = wp_insert_post([
                        'post_type'   => $post_type,
                        'post_title'  => $title,
                        'post_status' => 'publish',
                ], true);

                if (is_wp_error($post_id))
                {
                        Tourinsoft_Sync_Logger::error("Création de l'élément '{$log_name}' impossible : {$post_id->get_error_message()}");
                        return null;
                }

                update_post_meta($post_id, 'tourinsoft_id', $tourinsoft_id);

                Tourinsoft_Sync_Logger::info("Création de l'élément '{$log_name}'");
                $this->num_created_posts++;

                return $post_id;
        }

        /**
         * Met à la corbeille les posts dont l'élément n'est plus dans le flux.
         *
         * Corbeille plutôt que suppression définitive : en cas d'erreur, les posts peuvent
         * être restaurés (WordPress vide la corbeille au bout de 30 jours).
         * Si l'élément revient dans le flux, get_or_create_post() restaure le post.
         *
         * Les posts sans 'tourinsoft_id' (créés à la main) ne sont jamais concernés.
         *
         * @param string $post_type       Slug du post type.
         * @param array  $active_post_ids SyndicObjectID des éléments présents dans le flux.
         * @return int Nombre de posts mis à la corbeille.
         */
        private function trash_inactive_posts(string $post_type, array $active_post_ids): int
        {
                //-- Sécurité : avec une liste vide, WordPress ignorerait le filtre 'NOT IN'
                //-- et renverrait TOUS les posts importés. Un flux vide est sans doute
                //-- une erreur côté Tourinsoft : on ne touche à rien.
                if (!$active_post_ids)
                {
                        Tourinsoft_Sync_Logger::warning("Aucun élément valide dans le flux pour '{$post_type}' : par sécurité, aucun post n'est mis à la corbeille.");
                        return 0;
                }

                $posts_to_trash = get_posts([
                        'post_type'    => $post_type,
                        'meta_key'     => 'tourinsoft_id',
                        'meta_value'   => $active_post_ids,
                        'meta_compare' => 'NOT IN',
                        'post_status'  => 'any', //-- n'inclut pas les posts déjà dans la corbeille
                        'numberposts'  => -1,
                        'fields'       => 'ids',
                ]);

                $num_trashed_posts = 0;

                foreach ($posts_to_trash as $post_id)
                {
                        if (!wp_trash_post($post_id))
                        {
                                Tourinsoft_Sync_Logger::error("Impossible de mettre à la corbeille le post {$post_id}.");
                                continue;
                        }

                        //-- Indique que c'est l'import qui l'a mis à la corbeille (et pas un utilisateur)
                        update_post_meta($post_id, 'tourinsoft_trashed', 1);
                        $num_trashed_posts++;

                        $post_title    = get_the_title($post_id);
                        $tourinsoft_id = get_post_meta($post_id, 'tourinsoft_id', true);
                        Tourinsoft_Sync_Logger::info("Mise à la corbeille de l'élément '{$post_title}' (tourinsoft_id '{$tourinsoft_id}') : il n'est plus présent dans le flux Tourinsoft.");
                }

                return $num_trashed_posts;
        }
}
