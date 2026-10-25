# Kami — référentiel fonctionnel issu du comparatif CMS

Source : comparatif de 10 CMS (WordPress, Drupal, Craft CMS, Kirby, Statamic, October CMS, Grav, Payload, TallCMS, Lara Zeus Sky) réalisé le 25 octobre 2026 d'après leur documentation officielle. 211 fonctionnalités évaluées + 132 ajoutées après analyse.

## Légende

- **Couverture** : nombre de CMS sur 10 qui offrent la fonctionnalité en natif ou via extension officielle (`ajout` = fonctionnalité repérée hors grille initiale).
- **★** : différenciant — peu couvert nativement par les concurrents, forte valeur.
- **Brique** : composant Laravel / Filament / package pressenti. Ce sont des pistes à valider, pas des décisions.

## Choix produit déjà actés

- Base de données relationnelle uniquement (pas de flat-file).
- **L'hébergement mutualisé est une cible de premier rang**, au même titre qu'un serveur : aucun processus permanent (pas de worker, pas de WebSocket), pas de Redis garanti, limites mémoire et temps d'exécution. Tout ce qui exige un serveur (Redis, Meilisearch, Reverb) reste optionnel.
- **Pas de vrai cron en mutualisé Infomaniak** (planificateur web, 15 min minimum) : planificateur hybride (cron, URL appelée par l'hébergeur, déclenchement par les visites après réponse). Rien de visible ne dépend du planificateur : publications programmées évaluées à la lecture.
- Pas d'e-commerce, pas de marketplace, pas de mises à jour ni d'installation d'extensions en un clic depuis l'admin.
- Templates non éditables depuis l'admin (sécurité).

## MVP — socle indispensable au lancement (109)


### Modélisation de contenu

- Types de contenu définis en code (schéma versionnable) `8/10` — brique : Classes PHP + migrations
- Champs personnalisés (bibliothèque de types de champs) `8/10` — brique : Champs Filament Forms
- Champs répétables / groupes de champs `7/10` — brique : Filament Repeater
- Relations entre contenus (1-n, n-n) `7/10` — brique : Relations Eloquent + Filament Select/RelationManager
- Taxonomies (catégories, tags, vocabulaires personnalisés) `6/10` — brique : spatie/laravel-tags ou modèles dédiés
- Contenu hiérarchique (arborescence parent/enfant) `9/10` — brique : kalnoy/nestedset ou adjacency list
- Ensembles de champs réutilisables (fieldsets / blueprints) `7/10` — brique : Méthodes de schéma Filament réutilisables
- Contenus globaux / singletons (réglages de site) `8/10` — brique : spatie/laravel-settings + Filament Settings
- Validation des champs (règles, champs obligatoires) `10/10` — brique : Validation Laravel (natif Filament)
- Champs conditionnels (affichage selon valeur) `6/10` — brique : Filament visible() / live() (natif)
- UUID et liens internes stables (résistent aux renommages) `ajout` — brique : HasUuids Eloquent (vu chez : Kirby, Craft)
- Champs de mise en page (onglets, sections, colonnes) `ajout` — brique : Filament Tabs / Section / Grid (natif) (vu chez : Payload, Statamic)
- Ordre manuel par glisser-déposer `ajout` — brique : Filament reorderable() + eloquent-sortable (vu chez : Payload, Statamic)
- Classe PHP dédiée par type de contenu (méthodes métier) `ajout` — brique : Modèles Eloquent (vu chez : Kirby, Drupal)
- Création rapide d'un contenu lié depuis un champ relation `ajout` — brique : Filament createOptionForm (natif) (vu chez : October)

### Édition et blocs

- Éditeur WYSIWYG / rich text `10/10` — brique : Filament RichEditor (TipTap)
- Éditeur par blocs (block editor / page builder) `8/10` — brique : Filament Builder
- Blocs personnalisés définis par le développeur `9/10` — brique : Filament Builder\Block
- Live preview / aperçu avant publication `9/10` — brique : Plugin preview Filament / iframe signée
- Sélecteur de liens internes (pages et entrées) `ajout` — brique : Champ lien personnalisé (vu chez : October, Craft, Kirby)

### Médias

- Médiathèque centralisée `8/10` — brique : spatie/laravel-medialibrary + Curator
- Transformations d'images à la volée (tailles, formats) `6/10` — brique : Génération à la première demande (Glide), sans queue
- Conversion WebP/AVIF `7/10` — brique : Conversions medialibrary (AVIF selon GD/Imagick dispo)
- Métadonnées des médias (alt, légende, crédits) `7/10` — brique : Custom properties medialibrary
- Stockage distant (S3, CDN, etc.) `6/10` — brique : Filesystem Laravel (S3, Infomaniak Swiss Backup S3)
- Gestion vidéo / audio / documents `10/10` — brique : medialibrary
- Upload par glisser-déposer / multiple `9/10` — brique : Filament FileUpload (natif)
- Upload par morceaux (gros fichiers) `ajout` — brique : Livewire / Filament chunked upload (vu chez : Kirby)
- srcset / images responsives automatiques `ajout` — brique : spatie/laravel-medialibrary responsive images (vu chez : Grav, WordPress, Statamic)

### Versioning et planification

- Brouillons `10/10` — brique : Colonne statut + scopes
- Historique des révisions `7/10` — brique : Table de révisions (ex. mpociot/versionable)
- Restauration d'une révision `7/10` — brique : Action Filament
- Publication programmée ★ `8/10` — brique : Évaluée à la lecture (published_at <= now), sans dépendre du planificateur
- Corbeille / suppression douce `6/10` — brique : SoftDeletes + Filament TrashedFilter (natif)
- Duplication de contenu `6/10` — brique : Filament ReplicateAction (natif)
- Liens d'aperçu partageables et expirables ★ `ajout` — brique : URLs signées temporaires Laravel (vu chez : Craft, Kirby, TallCMS, WordPress)
- Suivi créateur / dernier modificateur `ajout` — brique : Observers Eloquent (vu chez : October)

### Recherche

- Recherche interne dans l'admin `10/10` — brique : Filament global search (natif)

### Utilisateurs et rôles

- Rôles personnalisables `7/10` — brique : spatie/laravel-permission + Filament Shield
- Permissions granulaires (par type de contenu / action) `9/10` — brique : Policies Laravel + Shield
- Authentification 2FA `7/10` — brique : Filament MFA (natif v4)
- Protection contre l'énumération d'utilisateurs `ajout` — brique : Messages d'erreur génériques (vu chez : Craft)

### Notifications et e-mails

- Configuration SMTP / transports mail `9/10` — brique : Laravel Mail (natif)

### Multilingue et multi-site

- Multilingue natif ★ `9/10` — brique : spatie/laravel-translatable + plugin Filament
- Traduction au niveau des champs `8/10` — brique : spatie/laravel-translatable
- Langue de repli (fallback) `7/10` — brique : Fallback laravel-translatable
- Traduction des chaînes d'interface du site depuis l'admin `ajout` — brique : Translation loader en base (vu chez : Kirby, October)

### Routing

- Permaliens / structure d'URL configurable `5/10` — brique : Routes dynamiques + table d'URL
- Slugs automatiques et éditables `9/10` — brique : spatie/laravel-sluggable
- URL hiérarchiques (parent/enfant) `8/10` — brique : Chemin calculé depuis l'arborescence
- Routes personnalisées déclaratives `7/10` — brique : Routing Laravel (natif)
- Gestionnaire de redirections (301/302) ★ `4/10` — brique : Table de redirections + middleware
- Redirection auto lors du changement de slug ★ `2/10` — brique : Observer Eloquent
- URL multilingues (préfixe, sous-domaine, domaine) `5/10` — brique : Middleware de locale
- URL canoniques `5/10` — brique : Composant SEO

### Templating

- Layouts et partials `9/10` — brique : Blade (natif)
- Composants de vue réutilisables `9/10` — brique : Composants Blade (natif)
- Variables globales / helpers dans les vues `9/10` — brique : View::share / composers (natif)
- Contrôleurs / view models par template `ajout` — brique : View composers / contrôleurs Laravel (vu chez : Kirby, Statamic)

### Thèmes et front-end

- Pipeline d'assets front intégré (Vite, etc.) `5/10` — brique : Vite (natif Laravel)

### SEO

- Meta title / description par entrée ★ `5/10` — brique : Champs SEO communs (ralphjsmit/laravel-seo)
- Open Graph / Twitter cards ★ `6/10` — brique : Composant SEO
- Sitemap XML automatique ★ `5/10` — brique : spatie/laravel-sitemap
- Gestion robots.txt / noindex ★ `2/10` — brique : Route + champ noindex
- Hreflang automatique ★ `2/10` — brique : Composant SEO multilingue

### API et headless

- API locale typée sans HTTP `ajout` — brique : Classes de service / repositories (vu chez : Payload)

### Accessibilité

- Thèmes front accessibles par défaut ★ `2/10` — brique : Thème de base WCAG AA
- Alt text obligatoire / rappels ★ `2/10` — brique : Validation sur les médias

### Stockage des données

- Base de données relationnelle `8/10` — brique : Eloquent
- Moteurs supportés (MySQL, PostgreSQL, SQLite…) `0/10` — brique : Laravel (natif)
- Transactions base de données sur les opérations multiples `ajout` — brique : DB::transaction Laravel (vu chez : Payload)

### Configuration as code

- Schémas / blueprints stockés en fichiers `8/10` — brique : Classes PHP
- Configuration par environnement (.env) `7/10` — brique : Laravel (natif)

### Synchronisation entre environnements

- Sync config entre dev / staging / prod `6/10` — brique : Git + migrations
- Déploiement sans perte du contenu prod `6/10` — brique : Contenu en DB, schéma en migrations

### Extensibilité

- Hooks / événements / filtres `8/10` — brique : Events Laravel
- Champs personnalisés en code `10/10` — brique : Champs Filament
- Personnalisation de l'admin par code `10/10` — brique : Filament Panels
- Pages admin personnalisées `10/10` — brique : Filament Pages
- Double mode : CMS autonome ou plugin d'un panel Filament existant ★ `ajout` — brique : Plugin Filament (vu chez : TallCMS)
- Actions personnalisées sur les contenus (menu d'actions) `ajout` — brique : Filament Actions (natif) (vu chez : Statamic)
- Modèles et ressources surchargeables ou désactivables `ajout` — brique : Config + container Laravel (vu chez : Sky)

### Performance et cache

- Cache de fragments / requêtes `7/10` — brique : Cache Laravel
- Lazy loading des images `4/10` — brique : loading=lazy dans les composants
- Optimisation des requêtes (eager loading, etc.) `9/10` — brique : Eloquent + preventLazyLoading

### Tâches planifiées et files d'attente

- Planificateur de tâches (cron) ★ `8/10` — brique : Planificateur hybride : cron, URL appelée par l'hébergeur (15 min), déclenchement par visites via defer()
- Files d'attente / jobs asynchrones `8/10` — brique : Queue database vidée par lots bornés en temps par le planificateur hybride
- Traitements lourds en arrière-plan (images, imports) `5/10` — brique : Jobs courts et découpés (limites mémoire/temps du mutualisé)

### Sécurité

- Mises à jour de sécurité régulières / politique de divulgation `9/10` — brique : SECURITY.md + process
- Protection CSRF / XSS natives `9/10` — brique : Laravel (natif)
- Limitation des tentatives de connexion `10/10` — brique : RateLimiter Laravel
- Journal d'audit (activité utilisateurs) ★ `1/10` — brique : spatie/laravel-activitylog
- Headers de sécurité / CSP ★ `0/10` — brique : spatie/laravel-csp
- Sanitization des uploads `8/10` — brique : Validation mimes + rendu sûr des SVG

### Outils développeur

- CLI dédiée `10/10` — brique : Artisan
- Scaffolding / générateurs de code `7/10` — brique : Commandes make:*
- Migrations de base de données `7/10` — brique : Laravel (natif)
- Seeders / fixtures `3/10` — brique : Factories / seeders Laravel
- Documentation développeur complète `8/10`

### Installation et mises à jour

- Installation via Composer / CLI `10/10` — brique : composer create-project + commande install
- Mode maintenance `7/10` — brique : php artisan down (natif)
- Compatibilité hébergement mutualisé ★ `3/10` — brique : Queue database + cron, cache fichier/DB, aucun process permanent

### Analytics et tableau de bord

- Tableau de bord admin `9/10` — brique : Filament Dashboard

### Interface d'administration

- Admin responsive / mobile `10/10` — brique : Filament (natif)
- Mode sombre `7/10` — brique : Filament (natif)
- Interface traduite (dont français) `8/10` — brique : Filament traduit (FR, DE, IT…)
- Actions groupées (bulk) `9/10` — brique : Filament BulkActions (natif)

### Navigation et menus

- Constructeur de menus arborescents `ajout` — brique : Plugin menu Filament ou nested set (vu chez : Statamic, Drupal, WordPress, TallCMS, Sky)
- Emplacements de menus multiples `ajout` — brique : Config (vu chez : TallCMS, WordPress)

## V1 — attendu par le marché (123)


### Modélisation de contenu

- Relations bidirectionnelles / reverse lookup ★ `3/10` — brique : Relations inverses Eloquent
- Champs calculés / dérivés `3/10` — brique : Filament afterStateUpdated / accessors Eloquent
- Cascade d'héritage des données et valeurs par défaut (globales → section → entrée) `ajout` — brique : Logique applicative (vu chez : Statamic, TallCMS)
- Format de titre automatique / titres générés `ajout` — brique : Mutators Eloquent (vu chez : Craft, Statamic)
- Modes d'affichage multiples par type (carte, liste, complet) `ajout` — brique : Vues Blade par mode (vu chez : Drupal)
- Champs spécialisés : adresse, lien multi-type, couleur, icône, montant, JSON/code `ajout` — brique : Champs Filament + plugins (vu chez : Craft, Payload, Kirby)
- Plusieurs types d'entrée dans une même section `ajout` — brique : Single table + type (vu chez : Craft, October)

### Édition et blocs

- Blocs imbriqués `7/10` — brique : Builder dans Builder
- Blocs / patterns réutilisables (synchronisés) ★ `3/10` — brique : Modèle « bloc global » référencé
- Support Markdown `6/10` — brique : Filament MarkdownEditor + league/commonmark
- Aperçu responsive (mobile/tablette) `5/10` — brique : Iframe redimensionnable
- Sauvegarde automatique des brouillons `6/10` — brique : Livewire + autosave
- Verrouillage de blocs / mode d'édition restreint au contenu ★ `ajout` — brique : Permissions par bloc (vu chez : WordPress)
- Visibilité des blocs par appareil ou par condition `ajout` — brique : Réglages communs aux blocs (vu chez : WordPress, TallCMS)
- Panneau de structure des blocs avec réordonnancement `ajout` — brique : Filament Builder collapsible / reorderable (vu chez : TallCMS)
- Sélecteur de blocs avec aperçus visuels, recherche et récents `ajout` — brique : Filament Builder blockPreviews / blockPickerColumns (vu chez : Statamic, TallCMS)
- Copier / coller de blocs et d'entrées entre champs `ajout` — brique : Filament Builder cloneable + presse-papiers (vu chez : Craft)
- Tokens dynamiques dans le contenu (merge tags, shortcodes) `ajout` — brique : Parseur de contenu (vu chez : TallCMS, WordPress, Grav, Kirby, Drupal)
- Barre d'administration sur le front-end `ajout` — brique : Composant Blade conditionnel (vu chez : WordPress, Payload)
- Copie d'un contenu vers une autre langue `ajout` — brique : Action Filament (vu chez : Payload)
- Édition en panneau latéral et édition inline dans les listes `ajout` — brique : Filament slideOver / colonnes éditables (natif) (vu chez : Craft)

### Médias

- Dossiers / organisation des médias `5/10` — brique : Plugin médiathèque Filament
- Recadrage / point focal `4/10` — brique : Filament imageEditor + point focal
- Détection des médias inutilisés ★ `0/10` — brique : Index des références
- Renommage avec mise à jour des références ★ `ajout` — brique : UUID + références par ID (vu chez : Statamic)
- Intégrations oEmbed (YouTube, Vimeo…) `ajout` — brique : Package oEmbed (vu chez : WordPress, Kirby)
- Fichiers privés soumis aux permissions `ajout` — brique : Disque privé + URLs signées Laravel (vu chez : Drupal, Grav)

### Versioning et planification

- Comparaison de versions (diff) ★ `4/10` — brique : Diff JSON / texte
- Dépublication programmée / expiration `4/10` — brique : Évaluée à la lecture (unpublished_at)

### Recherche

- Recherche front-end intégrée `8/10` — brique : Laravel Scout driver database (compatible mutualisé)
- Recherche plein texte `3/10` — brique : Scout + MySQL fulltext
- Index personnalisables (champs indexés) `4/10` — brique : toSearchableArray() Scout
- Intégrations moteurs externes (Algolia, Meilisearch, Elasticsearch) ★ `3/10` — brique : Drivers Scout, optionnels (hors mutualisé)

### Formulaires

- Constructeur de formulaires (no-code) ★ `4/10` — brique : Plugin form builder (ex. Lara Zeus Bolt)
- Stockage des soumissions `7/10` — brique : Modèle Eloquent
- Notifications e-mail des soumissions `8/10` — brique : Notifications Laravel
- Anti-spam (captcha, honeypot) `5/10` — brique : spatie/laravel-honeypot, Turnstile
- Upload de fichiers dans les formulaires `7/10` — brique : Validation fichiers Laravel
- Export des soumissions `5/10` — brique : Filament ExportAction (natif)
- Validation en direct côté client `ajout` — brique : Laravel Precognition (vu chez : Statamic)
- Insertion d'un formulaire dans le contenu (bloc) `ajout` — brique : Bloc formulaire (vu chez : Sky, WordPress)

### Utilisateurs et rôles

- Permissions par champ ★ `3/10` — brique : Filament visible()/disabled() selon policy
- Permissions par entrée / propriétaire `7/10` — brique : Policies Laravel
- Impersonation (se connecter en tant que) ★ `2/10` — brique : Plugin impersonate Filament
- Passkeys (WebAuthn) ★ `ajout` — brique : Package WebAuthn / passkeys (vu chez : Craft, Statamic, Kirby)
- Sessions élevées (re-vérification avant action sensible) `ajout` — brique : password.confirm middleware Laravel (vu chez : Craft, Statamic)
- Protection de contenu front (mot de passe, IP, connecté) `ajout` — brique : Middleware (vu chez : Statamic, Sky)
- Clés API par utilisateur `ajout` — brique : Laravel Sanctum (vu chez : Payload)
- Sessions actives révocables `ajout` — brique : Driver de session database (vu chez : Payload)
- Accès restreint par langue ou par site `ajout` — brique : Policies (vu chez : Payload, October)
- Plusieurs populations d'utilisateurs authentifiés (admin, membres, clients) `ajout` — brique : Guards Laravel (vu chez : Payload)

### Workflows éditoriaux

- Statuts de contenu personnalisables ★ `2/10` — brique : Enum + config
- Workflow d'approbation / validation ★ `3/10` — brique : State machine (ex. spatie/laravel-model-states)
- Verrouillage d'entrée pendant l'édition ★ `4/10` — brique : Plugin edit-lock Filament / cache lock

### Notifications et e-mails

- Notifications aux éditeurs (in-app) ★ `3/10` — brique : Filament Database Notifications (natif)
- Webhooks sortants sur événements ★ `3/10` — brique : spatie/laravel-webhook-server

### Multilingue et multi-site

- Workflow de traduction / statut par langue ★ `1/10` — brique : Statut par locale
- Multi-site (plusieurs sites, une install) `7/10` — brique : Filament multi-tenancy
- Détection de la langue du visiteur `ajout` — brique : Middleware Accept-Language (vu chez : Kirby, October)
- Traduction de la configuration et des réglages `ajout` — brique : spatie/laravel-translatable (vu chez : Drupal)

### Routing

- Pages 404 personnalisables / journal des 404 ★ `3/10` — brique : Handler d'exceptions + log 404

### Templating

- Hiérarchie / fallback de templates `5/10` — brique : View::first()
- Choix du template par entrée `5/10` — brique : Champ select de vue
- Interactivité déclarative côté serveur (AJAX, HTMX, Livewire) `ajout` — brique : Livewire (natif TALL) (vu chez : October, Drupal, WordPress)

### Thèmes et front-end

- Système de thèmes `6/10` — brique : Namespaces de vues + packages
- Starter kits / sites de démo `9/10` — brique : Seeders + starter kit Composer
- Mode headless / découplé `8/10` — brique : API Resources
- Réglages du thème par formulaire dans l'admin `ajout` — brique : Filament settings page (vu chez : October)
- Contenu de démo importable avec un thème `ajout` — brique : Seeders (vu chez : October, Statamic)
- Starter kits modulaires et mettables à jour `ajout` — brique : Packages Composer (vu chez : Statamic)

### SEO

- Données structurées (schema.org / JSON-LD) ★ `2/10` — brique : spatie/schema-org
- Injection de code par site (head, début / fin de body) `ajout` — brique : Réglages globaux (vu chez : TallCMS)

### API et headless

- API REST `8/10` — brique : API Resources Laravel
- Authentification API (tokens, OAuth) `7/10` — brique : Laravel Sanctum / Passport
- Filtrage / pagination / tri via l'API `7/10` — brique : spatie/laravel-query-builder
- Webhooks de mise à jour du contenu ★ `3/10` — brique : spatie/laravel-webhook-server
- Endpoints personnalisés par type de contenu `ajout` — brique : Routes Laravel (vu chez : Payload)
- Sélection des champs et profondeur de relations dans l'API `ajout` — brique : API Resources + includes (vu chez : Payload)
- Documentation OpenAPI / JSON Schema générée ★ `ajout` — brique : Scramble (dedoc/scramble) (vu chez : TallCMS, Drupal)
- CORS configurable `ajout` — brique : Config CORS Laravel (natif) (vu chez : Kirby, Grav)

### Accessibilité

- Admin conforme WCAG `3/10` — brique : Filament

### Configuration as code

- Réglages exportables / importables `6/10` — brique : Commandes Artisan
- Project config / config sync `7/10` — brique : Config PHP + migrations

### Extensibilité

- Système de plugins / modules `10/10` — brique : Service providers + plugins Filament
- Widgets / tableaux de bord personnalisés `9/10` — brique : Filament Widgets
- Dépendances déclarées entre extensions `ajout` — brique : Composer (vu chez : WordPress, Grav)

### Performance et cache

- Cache de pages complet `4/10` — brique : spatie/laravel-responsecache (pilote fichier)
- Cache statique (HTML pré-généré) ★ `3/10` — brique : HTML pré-généré servi par Apache/.htaccess, idéal en mutualisé
- Invalidation intelligente du cache ★ `5/10` — brique : Events + clés de cache versionnées (pas de tags : pilotes file/database)
- Pilote de cache Redis `ajout` — brique : Laravel natif, optionnel (fichier/DB par défaut) (vu chez : Kirby, Payload, Laravel)

### Sécurité

- Gestion des politiques de mot de passe `4/10` — brique : Rules\Password Laravel

### Outils développeur

- Suite de tests / helpers de tests ★ `5/10` — brique : Pest + helpers CMS
- Debug bar / outils de profilage `4/10` — brique : Telescope / Debugbar
- Typage / autocomplétion IDE ★ `2/10` — brique : Classes typées, ide-helper
- Environnement Docker / DDEV fourni `ajout` — brique : Laravel Sail (vu chez : October)
- Intégration du monitoring d'erreurs `ajout` — brique : Sentry / Flare (vu chez : Payload (Sentry))

### Installation et mises à jour

- Mode de récupération / safe mode (extensions désactivées) ★ `ajout` — brique : Logique applicative (vu chez : WordPress, Craft)
- Sauvegardes planifiées `ajout` — brique : spatie/laravel-backup (vu chez : Grav)
- Diagnostic de santé du site ★ `ajout` — brique : spatie/laravel-health (vu chez : WordPress (Site Health), TallCMS)

### IA

- Traduction automatique IA ★ `3/10` — brique : Prism PHP / DeepL
- Génération d'alt text IA ★ `1/10` — brique : Modèle vision via Prism
- Intégration MCP / agents IA ★ `4/10` — brique : laravel/mcp
- llms.txt généré automatiquement ★ `ajout` — brique : Route dédiée (vu chez : Grav, TallCMS)
- Version Markdown de chaque page pour les agents ★ `ajout` — brique : Convertisseur HTML → Markdown (vu chez : Grav)
- Guidelines de développement pour agents IA `ajout` — brique : Laravel Boost (vu chez : Statamic (Laravel Boost))

### Conformité et vie privée

- Outils RGPD (export / suppression de données) ★ `1/10` — brique : Package GDPR / commandes dédiées
- Bannière de consentement cookies ★ `0/10` — brique : Composant consentement
- Rétention des données configurable ★ `0/10` — brique : Model::prunable() Laravel

### Analytics et tableau de bord

- Intégration Google Analytics / Matomo / Plausible `2/10` — brique : Réglage d'injection

### Interface d'administration

- White-label / personnalisation de la marque `7/10` — brique : Filament brandName / colors
- Navigation admin personnalisable `7/10` — brique : Filament navigation
- Recherche globale / command palette `3/10` — brique : Filament global search / plugin spotlight
- Import / export de contenu (CSV, JSON, XML) `4/10` — brique : Filament Import/Export (natif)
- Importeur depuis un autre CMS (WordPress…) ★ `4/10` — brique : Importeur WXR / API REST WordPress
- Vues de liste enregistrées et partageables `ajout` — brique : Plugin Filament / tabs de table (vu chez : Payload, Craft)
- Groupement dans les listes `ajout` — brique : Filament Table groups (natif) (vu chez : Payload)
- Avertissement avant suppression d'un contenu référencé ★ `ajout` — brique : Relations inverses (vu chez : Craft)
- Utilitaires d'administration (cache, e-mail de test, files d'attente) `ajout` — brique : Pages Filament (vu chez : Craft, Statamic)
- Liens contextuels du front vers l'édition `ajout` — brique : Barre d'admin front (vu chez : Drupal)
- Navigation dans l'arborescence (arbre, colonnes) `ajout` — brique : Plugin tree Filament (vu chez : Grav)

### Écosystème et communauté

- Politique de support des versions publiée `ajout` (vu chez : Kirby, Drupal)

### Navigation et menus

- Types d'éléments de menu extensibles (entrée, URL, texte, personnalisé) `ajout` — brique : Logique applicative (vu chez : Statamic, Sky)

### Blog et interactions

- Flux RSS / Atom automatiques `ajout` — brique : spatie/laravel-feed (vu chez : WordPress, TallCMS)

## V2 — plus tard (75)


### Modélisation de contenu

- Types de contenu personnalisés (sans code) `4/10` — brique : Générateur de schéma dans l'admin
- Constructeur de listes / requêtes sans code (pages, blocs, flux) ★ `ajout` — brique : Filament Table + query builder (vu chez : Drupal (Views), WordPress (Query Loop))
- Dictionnaires : listes d'options issues de sources externes `ajout` — brique : Filament Select options() (vu chez : Statamic)
- Contenu virtuel issu de sources externes (API, autre base) `ajout` — brique : Eloquent / Sushi (vu chez : Kirby)
- Champs de géolocalisation avec requêtes de proximité `ajout` — brique : Plugin carte Filament + requêtes spatiales (vu chez : Payload, Drupal (contrib))
- Outils de refactor de schéma (fusion, nettoyage, réenregistrement en masse) `ajout` — brique : Commandes Artisan (vu chez : Craft, October)

### Édition et blocs

- Édition en contexte (front-end) ★ `3/10` — brique : Livewire sur le front
- Raccourcis clavier / slash commands `4/10` — brique : Extensions TipTap
- Liaison de blocs à des champs ou sources de données `ajout` — brique : Logique applicative (vu chez : WordPress (Block Bindings))
- Surcharge locale de blocs réutilisables `ajout` — brique : Logique applicative (vu chez : WordPress (Pattern Overrides))
- Temps de lecture et nombre de mots `ajout` — brique : Helper (vu chez : Statamic, TallCMS)
- Vue calendrier des contenus programmés `ajout` — brique : Plugin calendrier Filament (vu chez : Statamic)

### Médias

- Import d'un média depuis une URL `ajout` — brique : Http + medialibrary addMediaFromUrl (vu chez : Payload)
- Upload direct du navigateur vers le stockage distant `ajout` — brique : URLs présignées S3 (vu chez : Payload)
- Filigrane (watermark) `ajout` — brique : Glide / Intervention Image (vu chez : Statamic, Grav)
- Types de fichiers avec champs propres (templates de fichiers) `ajout` — brique : Collections medialibrary (vu chez : Kirby, Statamic)

### Versioning et planification

- Snapshots nommés et épinglés `ajout` — brique : Table de révisions (vu chez : TallCMS)
- Revue groupée de changements avant publication (workspaces) ★ `ajout` — brique : Logique applicative (vu chez : Drupal)
- Résumé des changements en attente de l'équipe `ajout` — brique : Logique applicative (vu chez : Kirby)

### Recherche

- Filtres / facettes `2/10` — brique : Meilisearch facets

### Formulaires

- Logique conditionnelle `3/10` — brique : Logique applicative
- Formulaires multi-étapes `ajout` — brique : Filament Wizard (natif) (vu chez : October)
- Synchronisation vers outils marketing (Mailchimp, Brevo…) `ajout` — brique : Intégrations HTTP (vu chez : TallCMS (Pro))

### Utilisateurs et rôles

- Utilisateurs front-end (inscription, profils, espace membre) `7/10` — brique : Laravel Fortify / starter kits
- SSO / OAuth / SAML / LDAP ★ `5/10` — brique : Socialite, LdapRecord, SAML2
- Groupes d'utilisateurs `4/10` — brique : Modèle dédié
- Connexion sans mot de passe (lien magique, code) `ajout` — brique : URLs signées Laravel (vu chez : Kirby, Grav)
- Voir le site / l'admin en tant qu'un rôle `ajout` — brique : Logique applicative (vu chez : October)

### Workflows éditoriaux

- Assignation de tâches / relecteurs ★ `1/10` — brique : Notifications Laravel
- Notes / commentaires internes ★ `2/10` — brique : Modèle commentaires polymorphe
- Édition collaborative en temps réel ★ `4/10` — brique : Reverb + Yjs : impossible en mutualisé, option serveur uniquement
- Automatisations no-code événement → condition → action ★ `ajout` — brique : Events + jobs Laravel (vu chez : Drupal (ECA))
- Workflows multi-étapes avec reprises automatiques `ajout` — brique : Bus::chain / batch Laravel (vu chez : Payload)

### Notifications et e-mails

- Modèles d'e-mails éditables ★ `2/10` — brique : Mailables + templates en base

### Multilingue et multi-site

- Contenu partagé entre sites `4/10` — brique : Relations n-n site/contenu
- Langues RTL `8/10` — brique : Filament (natif RTL)
- Domaines personnalisés avec vérification DNS et TLS `ajout` — brique : Logique applicative (vu chez : TallCMS (Pro))
- Extensions activables par site `ajout` — brique : Logique applicative (vu chez : October)

### Templating

- Formats de sortie multiples par page (HTML, JSON, RSS…) `ajout` — brique : Routes / responses (vu chez : Grav, Kirby)

### Thèmes et front-end

- Thèmes enfants / héritage `4/10` — brique : Cascade de chemins de vues
- Génération de site statique (SSG) ★ `1/10` — brique : Export HTML (ex. spatie/laravel-export)
- Styles globaux / design tokens éditables ★ `ajout` — brique : Variables CSS + réglages (vu chez : WordPress (theme.json), Drupal (Brand Kit))
- Bibliothèque de polices `ajout` — brique : Logique applicative (vu chez : WordPress)
- Recettes / templates de site composables ★ `ajout` — brique : Commandes d'installation (vu chez : Drupal)

### SEO

- Analyse / score SEO du contenu ★ `2/10` — brique : Analyse de contenu

### API et headless

- API GraphQL `5/10` — brique : Lighthouse
- API en lecture et écriture `7/10` — brique : Controllers API
- SDK client officiel ★ `2/10` — brique : Client TypeScript généré
- Preview headless (brouillons via API) `4/10` — brique : Tokens de preview
- Limites de complexité / profondeur des requêtes `ajout` — brique : Lighthouse (vu chez : Payload)
- Concurrence optimiste (ETag / If-Match) `ajout` — brique : Middleware (vu chez : Grav)
- Négociation de contenu (HTML, JSON, Markdown par page) ★ `ajout` — brique : Responses Laravel (vu chez : Kirby, Grav)

### Accessibilité

- Vérification d'accessibilité du contenu ★ `0/10` — brique : Contrôles à la sauvegarde (alt, titres, contrastes)

### Synchronisation entre environnements

- Sync / migration du contenu ★ `2/10` — brique : Export/import de contenu

### Extensibilité

- Registre de capacités exposées aux agents IA ★ `ajout` — brique : laravel/mcp (vu chez : WordPress (Abilities API), Grav (mcp.yaml))

### Performance et cache

- Intégration CDN `2/10` — brique : ASSET_URL / disque CDN
- Cache par tags avec invalidation fine `ajout` — brique : Cache tags Laravel : Redis requis, donc option serveur (vu chez : Drupal)
- Préchauffage du cache `ajout` — brique : Commande Artisan (vu chez : Statamic)
- Préchargement spéculatif des liens `ajout` — brique : Speculation Rules API (vu chez : WordPress)

### Outils développeur

- Outils de migration automatique entre versions majeures `ajout` — brique : Rector (vu chez : Payload, Grav)

### Installation et mises à jour

- Installeur web `5/10` — brique : Assistant d'installation
- Rollback automatique d'une mise à jour échouée ★ `ajout` — brique : Logique applicative (vu chez : WordPress, TallCMS)

### IA

- Assistance à la rédaction IA ★ `3/10` — brique : Prism PHP / SDK LLM
- Recherche sémantique / vectorielle ★ `2/10` — brique : pgvector / Meilisearch hybride

### Conformité et vie privée

- Politique de confidentialité générée / gérée ★ `1/10` — brique : Page modèle nLPD / RGPD
- Anonymisation des données ★ `1/10` — brique : Jobs planifiés

### Analytics et tableau de bord

- Statistiques de visite intégrées ★ `3/10` — brique : Intégration Plausible / Matomo API
- Rapports de contenu (obsolète, liens cassés) ★ `2/10` — brique : Jobs planifiés

### Interface d'administration

- Préférences personnelles (apparence, accessibilité) `ajout` — brique : Logique applicative (vu chez : Statamic, Payload, Grav, Craft)

### Navigation et menus

- Mega menus et règles de visibilité `ajout` — brique : Logique applicative (vu chez : TallCMS (Pro))

### Blog et interactions

- Commentaires avec modération `ajout` — brique : Package commentaires (vu chez : WordPress, TallCMS, October)
- Pages auteur avec profil `ajout` — brique : Logique applicative (vu chez : TallCMS, Sky)
- Contenus liés automatiques `ajout` — brique : Requête par taxonomies (vu chez : Sky)
- Mise en avant temporisée (sticky avec expiration) `ajout` — brique : Champ date (vu chez : Sky, TallCMS)
- Modération des contenus soumis par les visiteurs `ajout` — brique : Logique applicative (vu chez : October)

## Hors périmètre — écarté volontairement (26)


### Édition et blocs

- Composants front codés et éditables dans le navigateur `ajout` (vu chez : Drupal (Canvas))

### Notifications et e-mails

- Newsletters / listes de diffusion `4/10` — brique : Intégration externe

### Multilingue et multi-site

- Mode SaaS multi-tenant (plans, quotas, facturation) `ajout` — brique : Filament tenancy + Cashier (vu chez : TallCMS (Pro))

### Templating

- Templates éditables depuis l'admin `3/10` — brique : Risque sécurité
- Bac à sable de templates pour éditeurs `ajout` (vu chez : Craft, Grav)

### Thèmes et front-end

- Marketplace de thèmes `5/10`

### SEO

- A/B testing de contenu `ajout` (vu chez : Payload)

### Commerce

- E-commerce natif `0/10`
- E-commerce via extension officielle `5/10` — brique : Lunar en complément
- Paiements (Stripe, PayPal…) `6/10` — brique : Laravel Cashier
- Produits numériques / abonnements `2/10`
- Gestion des taxes / TVA `4/10`

### Stockage des données

- Mode flat-file `3/10` — brique : Choix d'architecture : DB
- Mode hybride (fichiers + DB) `3/10`
- Contenu versionnable dans Git `3/10`

### Synchronisation entre environnements

- Outils de staging intégrés `1/10`

### Extensibilité

- Marketplace d'extensions `9/10`
- Installation d'extensions depuis l'admin `ajout` — brique : Composer depuis l'admin : peu fiable en mutualisé (vu chez : Drupal, TallCMS, Grav)
- Gestion des licences d'extensions `ajout` (vu chez : Kirby, TallCMS)

### Outils développeur

- Générateur visuel d'extensions `ajout` (vu chez : October (Builder))

### Installation et mises à jour

- Mises à jour en un clic depuis l'admin `6/10` — brique : Mises à jour via Composer
- Mises à jour automatiques `1/10`
- Bac à sable de démo en ligne `ajout` (vu chez : WordPress (Playground))

### Interface d'administration

- Application mobile `ajout` (vu chez : WordPress)

### Écosystème et communauté

- Hébergement managé officiel `ajout` (vu chez : Craft (Cloud))
- Licence de développement / essai gratuit illimité en local `ajout` (vu chez : Kirby, Statamic)

## Différenciants retenus (★), toutes priorités

- [V1] Modélisation de contenu — Relations bidirectionnelles / reverse lookup `3/10`
- [V2] Modélisation de contenu — Constructeur de listes / requêtes sans code (pages, blocs, flux) `ajout`
- [V1] Édition et blocs — Blocs / patterns réutilisables (synchronisés) `3/10`
- [V2] Édition et blocs — Édition en contexte (front-end) `3/10`
- [V1] Édition et blocs — Verrouillage de blocs / mode d'édition restreint au contenu `ajout`
- [V1] Médias — Détection des médias inutilisés `0/10`
- [V1] Médias — Renommage avec mise à jour des références `ajout`
- [V1] Versioning et planification — Comparaison de versions (diff) `4/10`
- [MVP] Versioning et planification — Publication programmée `8/10`
- [MVP] Versioning et planification — Liens d'aperçu partageables et expirables `ajout`
- [V2] Versioning et planification — Revue groupée de changements avant publication (workspaces) `ajout`
- [V1] Recherche — Intégrations moteurs externes (Algolia, Meilisearch, Elasticsearch) `3/10`
- [V1] Formulaires — Constructeur de formulaires (no-code) `4/10`
- [V1] Utilisateurs et rôles — Permissions par champ `3/10`
- [V2] Utilisateurs et rôles — SSO / OAuth / SAML / LDAP `5/10`
- [V1] Utilisateurs et rôles — Impersonation (se connecter en tant que) `2/10`
- [V1] Utilisateurs et rôles — Passkeys (WebAuthn) `ajout`
- [V1] Workflows éditoriaux — Statuts de contenu personnalisables `2/10`
- [V1] Workflows éditoriaux — Workflow d'approbation / validation `3/10`
- [V2] Workflows éditoriaux — Assignation de tâches / relecteurs `1/10`
- [V2] Workflows éditoriaux — Notes / commentaires internes `2/10`
- [V1] Workflows éditoriaux — Verrouillage d'entrée pendant l'édition `4/10`
- [V2] Workflows éditoriaux — Édition collaborative en temps réel `4/10`
- [V2] Workflows éditoriaux — Automatisations no-code événement → condition → action `ajout`
- [V1] Notifications et e-mails — Notifications aux éditeurs (in-app) `3/10`
- [V2] Notifications et e-mails — Modèles d'e-mails éditables `2/10`
- [V1] Notifications et e-mails — Webhooks sortants sur événements `3/10`
- [MVP] Multilingue et multi-site — Multilingue natif `9/10`
- [V1] Multilingue et multi-site — Workflow de traduction / statut par langue `1/10`
- [MVP] Routing — Gestionnaire de redirections (301/302) `4/10`
- [MVP] Routing — Redirection auto lors du changement de slug `2/10`
- [V1] Routing — Pages 404 personnalisables / journal des 404 `3/10`
- [V2] Thèmes et front-end — Génération de site statique (SSG) `1/10`
- [V2] Thèmes et front-end — Styles globaux / design tokens éditables `ajout`
- [V2] Thèmes et front-end — Recettes / templates de site composables `ajout`
- [MVP] SEO — Meta title / description par entrée `5/10`
- [MVP] SEO — Open Graph / Twitter cards `6/10`
- [MVP] SEO — Sitemap XML automatique `5/10`
- [V1] SEO — Données structurées (schema.org / JSON-LD) `2/10`
- [MVP] SEO — Gestion robots.txt / noindex `2/10`
- [V2] SEO — Analyse / score SEO du contenu `2/10`
- [MVP] SEO — Hreflang automatique `2/10`
- [V2] API et headless — SDK client officiel `2/10`
- [V1] API et headless — Webhooks de mise à jour du contenu `3/10`
- [V1] API et headless — Documentation OpenAPI / JSON Schema générée `ajout`
- [V2] API et headless — Négociation de contenu (HTML, JSON, Markdown par page) `ajout`
- [V2] Accessibilité — Vérification d'accessibilité du contenu `0/10`
- [MVP] Accessibilité — Thèmes front accessibles par défaut `2/10`
- [MVP] Accessibilité — Alt text obligatoire / rappels `2/10`
- [V2] Synchronisation entre environnements — Sync / migration du contenu `2/10`
- [MVP] Extensibilité — Double mode : CMS autonome ou plugin d'un panel Filament existant `ajout`
- [V2] Extensibilité — Registre de capacités exposées aux agents IA `ajout`
- [V1] Performance et cache — Cache statique (HTML pré-généré) `3/10`
- [V1] Performance et cache — Invalidation intelligente du cache `5/10`
- [MVP] Tâches planifiées et files d'attente — Planificateur de tâches (cron) `8/10`
- [MVP] Sécurité — Journal d'audit (activité utilisateurs) `1/10`
- [MVP] Sécurité — Headers de sécurité / CSP `0/10`
- [V1] Outils développeur — Suite de tests / helpers de tests `5/10`
- [V1] Outils développeur — Typage / autocomplétion IDE `2/10`
- [MVP] Installation et mises à jour — Compatibilité hébergement mutualisé `3/10`
- [V2] Installation et mises à jour — Rollback automatique d'une mise à jour échouée `ajout`
- [V1] Installation et mises à jour — Mode de récupération / safe mode (extensions désactivées) `ajout`
- [V1] Installation et mises à jour — Diagnostic de santé du site `ajout`
- [V2] IA — Assistance à la rédaction IA `3/10`
- [V1] IA — Traduction automatique IA `3/10`
- [V1] IA — Génération d'alt text IA `1/10`
- [V2] IA — Recherche sémantique / vectorielle `2/10`
- [V1] IA — Intégration MCP / agents IA `4/10`
- [V1] IA — llms.txt généré automatiquement `ajout`
- [V1] IA — Version Markdown de chaque page pour les agents `ajout`
- [V1] Conformité et vie privée — Outils RGPD (export / suppression de données) `1/10`
- [V1] Conformité et vie privée — Bannière de consentement cookies `0/10`
- [V2] Conformité et vie privée — Politique de confidentialité générée / gérée `1/10`
- [V2] Conformité et vie privée — Anonymisation des données `1/10`
- [V1] Conformité et vie privée — Rétention des données configurable `0/10`
- [V2] Analytics et tableau de bord — Statistiques de visite intégrées `3/10`
- [V2] Analytics et tableau de bord — Rapports de contenu (obsolète, liens cassés) `2/10`
- [V1] Interface d'administration — Importeur depuis un autre CMS (WordPress…) `4/10`
- [V1] Interface d'administration — Avertissement avant suppression d'un contenu référencé `ajout`

## Idées à reprendre des concurrents


### WordPress

- Un registre d'« abilities » typées (JSON Schema) partagé entre palette de commandes, API et serveur MCP, à brancher sur les actions Filament.
- Patterns synchronisés avec overrides par instance : blocs réutilisables dont certains champs restent éditables localement.
- Design tokens centralisés type theme.json, éditables visuellement et consommés par le front et l'éditeur.
- Notes inline sur sélection de texte avec @mentions et résolution, liées aux révisions et liens de révision partageables.
- Révisions visuelles avec curseur de comparaison et navigation vers les changements dans le rendu.
- Traitement des images côté navigateur (compression, AVIF/HEIC, vignettes) pour décharger un serveur mutualisé.
- Mode de récupération qui désactive automatiquement une extension fautive et rollback des mises à jour défaillantes.
- Outils RGPD exportateurs/effaceurs extensibles par module, avec page de confidentialité alimentée par chaque extension.

### Drupal

- Cache tags automatiques par entité et invalidation ciblée de toutes les sorties qui l'affichent.
- Recettes composables (config + contenu + questions) appliquables à un site existant, en commande Artisan.
- Workspaces : espace de staging de contenu publiable en lot, avec revue groupée des changements.
- Constructeur de listes type Views en Filament (requête, filtres exposés, rendu bloc/page/API).
- Modes d'affichage multiples par type (teaser, carte, complet) choisis par contexte.
- Workflows configurables (états, transitions, permissions par transition) appliqués à tout modèle.
- Code components éditables dans le navigateur liés à un Brand Kit, à la manière de Canvas.
- Génération de JSON Schema des modèles pour API, IA et validation front.

### Craft CMS

- Stocker tout le schéma (types, champs, layouts) en YAML versionné avec verrou allowAdminChanges en production.
- Types d'entrées globaux réutilisables comme blocs de page builder et comme types de section, avec alias locaux.
- Constructeur de conditions générique réutilisé pour champs, sources d'index et filtres de relations.
- Brouillons provisoires automatiques par utilisateur, fusion des modifications live et liens d'aperçu tokenisés.
- Generated Fields : champs calculés par expression, stockés et triables dans les tables admin.
- Méthodes de propagation multi-site et de traduction par champ (site, groupe, langue).
- Deletion blockers : avertir des relations avant suppression et proposer un remplacement.
- Commandes CLI resave/merge pour refactorer le schéma et les données en masse.

### Kirby

- Stocker les brouillons de modifications côté serveur (version « changes » distincte de « latest ») avec dialogue des changements de toute l'équipe.
- Liens de prévisualisation partageables à jeton pour relecteurs externes, avec vue comparaison publié/modifié.
- Schémas de contenu en fichiers avec mécanisme extends pour champs, onglets et sections réutilisables.
- Représentations de contenu par extension d'URL (/page.json, .rss) et négociation de contenu Markdown pour agents IA.
- Pages virtuelles : brancher une source externe et l'éditer dans l'admin comme du contenu natif.
- Permaliens UUID (/@/page/uuid) pour des liens internes indestructibles.
- Boutons de vue personnalisables et aperçus de fichiers extensibles par classe.
- Licence de développement gratuite et politique de support publiée en JSON.

### Statamic

- Stocker blueprints/fieldsets en YAML versionnable avec édition bidirectionnelle CP ↔ fichiers.
- Drivers de stockage interchangeables par dépôt (fichiers ou Eloquent) avec commandes import/export.
- Live Preview à preview targets multiples, sélecteur d'appareils et morphing HTML sans rechargement.
- Cascade de données (entrée → origine → inject collection → globals) pour les fallbacks multilingues.
- Static cache avec règles d'invalidation déclaratives par collection et préchauffage en queue.
- Sessions élevées (re-vérification) pour les actions sensibles et passkeys WebAuthn.
- Field actions par champ et command palette extensible en PHP/JS.
- Aperçus image/icône des blocs dans le sélecteur de sets.

### October CMS

- Déclarer les types de contenu en YAML/PHP versionnable et générer automatiquement migrations, ressources Filament et permissions
- Variantes de types (entry, stream, structure, single, global, submission, mixin) avec comportements prêts à l'emploi
- Modes de synchronisation multisite par type (sync, all, locale) et repeaters translatable: sync
- File de modération des soumissions front avec balayage spam par IP et purge automatique
- Permissions générées par type, incluant « entrées d'autres auteurs » et séparation brouillon/publication
- Pagefinder : sélecteur unifié de liens internes résolvant les URL des pages et entrées
- Seeding de thème/starter (contenu, médias, blueprints) importable en une commande
- Serveur MCP exposant schémas, plugins et structure du thème aux agents IA

### Grav

- Servir chaque entrée en Markdown (.md ou Accept: text/markdown) avec un llms.txt, pour les agents IA.
- Faire déclarer leurs outils MCP aux plugins via un manifeste, puis les exposer automatiquement par un serveur MCP.
- Permissions par entrée dans le contenu (groupes et lettres crudpl) héritées par les enfants.
- Concurrence optimiste par ETag/If-Match sur l'API et l'admin pour éviter les écrasements.
- Surcharges de config par environnement dans l'admin, en n'écrivant que les clés qui diffèrent.
- Métadonnées des médias en fichier compagnon versionnable, interrogeables en requête (filterBy, sortBy).
- Sonde de tableau de bord qui vérifie si des dossiers sensibles sont accessibles publiquement.
- Formats de sortie multiples par entrée (.json, .rss, .md) via une convention de template.

### Payload

- Fonctions d'accès retournant soit un booléen soit une contrainte de requête (scope Eloquent) pour les permissions par entrée.
- Champ virtuel join pour afficher et éditer les relations inverses dans les ressources Filament.
- Versions avec diff champ par champ, autosave et schedulePublish branché sur les queues Laravel.
- Locked Documents : verrou automatique pendant l'édition avec prise de contrôle.
- Query Presets : vues de liste enregistrées et partageables avec permissions.
- Plugin MCP exposant les modèles aux agents IA avec opérations autorisées par collection.
- Statut de publication par locale et bouton « copier vers une locale ».
- Corbeille avec permissions distinctes pour suppression douce et définitive.

### TallCMS

- Proposer le CMS à la fois comme application standalone et comme plugin Filament installable dans une app existante.
- Étendre le RichEditor Filament avec des blocs custom, une palette slash, un outline drag-and-drop et des métadonnées de bloc (catégorie, icône, mots-clés).
- Révisions automatiques avant/après avec hash, plus snapshots manuels épinglés et diff visuel des blocs.
- Jetons d'aperçu partageables à durée limitée et révocables, soumis à une permission dédiée.
- Héritage Global Defaults / overrides par site qui ne stocke que les valeurs divergentes.
- Double autorisation API : abilities du jeton + policies Shield, webhooks HMAC avec validation DNS au moment de l'envoi.
- Widget « Content Health » (contenus non revus depuis N mois, méta manquantes) et champs de relecture experte façon E-E-A-T.
- Updater signé avec sauvegarde, détection de fichiers modifiés et rollback pour les installs sans accès SSH.

### Lara Zeus Sky

- Interface ContentEditor (component() + render()) pour rendre l'éditeur configurable sans toucher aux ressources.
- Chaîne de parseurs de contenu configurables pour des shortcodes type <bolt>slug</bolt> injectant des composants Livewire.
- API fluide du plugin : activer/masquer/remplacer chaque ressource et surcharger les modèles par panneau.
- Navigation builder par handle avec itemType() extensible pour lier n'importe quel modèle.
- Statuts de contenu sous forme d'énum PHP remplaçable (HasLabel/HasColor/HasIcon) directement exploitée par Filament.
- Mise en avant temporisée (sticky_until) et contenus protégés par mot de passe, simples et utiles.
- Taxonomies multiples typées sur une seule table de tags polymorphe.
