# Kami — brief

Cahier des charges durable de Kami. Rédigé en français par Steve : comme `docs/comparatif-cms.md`, c'est une source à lire, à ne pas traduire. Il évolue avec le projet ; les décisions qui en découlent vont dans les ADR (`docs/adr/`) et les règles de travail dans `CLAUDE.md`.

## Contexte

- Je suis développeur Laravel/PHP en agence web, en Suisse. Kami doit me servir pour des sites clients et mes propres sites.
- J'ai comparé 10 CMS existants (WordPress, Drupal, Craft, Kirby, Statamic, October, Grav, Payload, TallCMS, Lara Zeus Sky). Le résultat est dans `docs/comparatif-cms.md` : 333 fonctionnalités classées MVP / V1 / V2 / Hors périmètre, avec leur couverture chez les concurrents, les différenciants (★) et des pistes de briques techniques. **Lis ce fichier en entier avant toute chose.** C'est la référence fonctionnelle.
- Les « briques » indiquées dans ce fichier sont des pistes, pas des décisions. Remets-les en question si tu as mieux.
- `docs/research/comparatif-cms.xlsx` est l'**archive figée** de l'analyse du 25 octobre 2026 : pour chacun des 10 CMS et chaque fonctionnalité, son statut (natif, officiel, tiers, partiel, absent), une explication et un lien vers la documentation officielle, ainsi que les fonctionnalités relevées hors grille et les idées à reprendre. Consulte-la quand tu as besoin de savoir *comment* un concurrent traite un sujet. Elle n'est jamais mise à jour : pour les priorités, `docs/comparatif-cms.md` fait foi.

### Kami succède à Velox

Kami n'est pas un premier essai : il **succède à Velox** (`~/Code/velox`, dépôt `psyao/velox`), mon CMS Laravel 13 / Filament 5 actuel, en version 1.3. Velox fait déjà tourner aguet.dev en production sur un mutualisé Infomaniak, et psyao.ch et steve-et-aline.ch y ont été migrés localement depuis WordPress. Velox a ses Content Types en classes PHP, ses Entries avec origine et traductions, un RichText TipTap, une médiathèque, des menus, des redirections, des comptes à rôles, un `velox:install`, une documentation (`docs/`) et des ADR.

C'est ta **source la plus précieuse**, avant même le comparatif :

- lis le code, la documentation et les ADR de Velox ;
- lis les journaux de migration des trois sites (`VELOX-MIGRATION.md` dans `~/Code/aguet`, `~/Code/psyao` et `~/Code/steve-et-aline`) : chaque friction y est consignée avec son verdict (lacune de Velox, choix assumé, problème du site). Ce sont des retours de terrain réels ;
- si `gh` est disponible, consulte les issues de `psyao/velox`, en particulier les « maps » de migration.

**Kami est une réécriture assumée, libre de s'écarter de Velox** : modèle de données, API, conventions, noms. Velox est une source d'enseignements, pas une contrainte de compatibilité. Mais Kami ne doit pas refaire les erreurs déjà corrigées dans Velox, ni abandonner ses bonnes décisions par simple oubli. Quand tu t'en écartes sur un point structurant, dis-le en une phrase, avec la raison.

## Langues de travail

- **Nos échanges se font en français** : réponses, questions et explications dans la conversation.
- **Tout ce qui est produit est en anglais** : documents (`docs/architecture.md`, `docs/roadmap.md`, ADR), code, noms de classes, tables et commandes, commentaires, PHPDoc, messages de commit, README, messages d'erreur et logs.
- Les textes d'interface ne sont jamais écrits en dur : ils passent par les fichiers de traduction Laravel, avec l'anglais comme langue source et le français comme première traduction.
- `docs/brief.md` et `docs/comparatif-cms.md` sont en français : ce sont des sources que je maintiens, ne les traduis pas.

## Contraintes techniques

- **Laravel et Filament** : dernières versions stables. Vérifie les versions actuelles (Packagist, documentation officielle) au lieu de te fier à ta mémoire. PHP en conséquence.
- **Base de données agnostique** : MySQL/MariaDB, PostgreSQL et SQLite. Évite tout SQL spécifique à un moteur, ou isole-le derrière une abstraction.
- **Langues du contenu** : français et anglais au départ. L'architecture doit permettre d'ajouter d'autres langues (allemand, italien) sans migration lourde.
- **Front-end** : Blade uniquement dans un premier temps, sans Livewire côté public. L'architecture ne doit cependant pas empêcher d'ajouter Livewire plus tard. L'admin, lui, est en Filament.
- **Hébergement mutualisé : cible de premier rang, pas un mode dégradé.** Kami doit fonctionner pleinement sur un hébergement mutualisé (référence : Infomaniak) comme sur un serveur (Serveur Cloud managé Infomaniak). Conséquences à respecter partout :
  - aucun processus permanent : pas de worker de queue, pas de WebSocket ;
  - **pas de vrai cron** : le mutualisé Infomaniak ne propose qu'un planificateur web qui appelle une URL, toutes les 15 minutes au minimum. Conçois donc un **planificateur hybride**, configurable :
    1. *vrai cron* (`schedule:run` chaque minute) quand il est disponible sur serveur ;
    2. *planificateur web* : une URL protégée par un secret, appelée par l'hébergeur, qui exécute les tâches dues et vide la queue (driver database) dans un budget de temps borné, compatible avec `max_execution_time` ;
    3. *déclenchement par les visites* (principe de WP-Cron, sans ses défauts) : exécution après l'envoi de la réponse (`defer()` / `fastcgi_finish_request`), protégée par un verrou et un intervalle minimal, le visiteur n'attend jamais ; l'activité dans l'admin compte aussi.
  - **rien de visible ne doit dépendre du planificateur** : publication et dépublication programmées sont évaluées à la lecture (`published_at <= now()`), sans tâche qui bascule un statut. Le planificateur ne gère que les effets secondaires tolérants au retard (invalidation de cache, sitemap, webhooks, nettoyage). Le cache de page doit expirer au plus tard à la prochaine publication programmée ;
  - limiter ce qui passe par la queue : conversions d'images générées à la première demande (à la Glide) plutôt qu'en tâche de fond, traitements légers via `defer()`, la queue étant réservée aux gros traitements découpés en lots ;
  - pas de Redis : le cache fonctionne avec les pilotes fichier ou base de données. **Ces pilotes ne supportent pas les tags de cache** : conçois l'invalidation sans tags (clés versionnées, invalidation par événements) ;
  - recherche avec Scout en pilote base de données par défaut. Meilisearch, Redis et Reverb restent des options activables sur serveur, jamais des prérequis ;
  - limites de mémoire et de temps d'exécution : conversions d'images, imports, exports et sauvegardes découpés en petits jobs ; uploads de gros fichiers par morceaux ;
  - extensions PHP variables : détecter GD ou Imagick et le support AVIF/WebP, avec repli propre ;
  - installation et mises à jour via SSH + Composer (disponibles chez Infomaniak), document root sur `public/`, et `.htaccess` pour Apache.
  
  Prévois une commande de diagnostic (`kami:doctor` ou équivalent) qui vérifie l'environnement : version PHP, extensions, mode de planification actif et date du dernier passage, droits d'écriture, pilotes configurés.
- **Kami est un produit, pas un site.** Le déploiement est l'affaire du site qui l'utilise : Kami n'impose aucune méthode (CI, rsync, git pull via SSH…), mais doit se déployer proprement avec toutes :
  - aucun Node.js requis sur le serveur : les assets de l'admin sont livrés précompilés dans le package ;
  - aucune écriture dans `vendor/` ; les fichiers générés vont dans `storage/` ou `public/` selon les conventions Laravel ;
  - commandes d'installation et de mise à jour (`kami:install`, `kami:upgrade` ou équivalent) idempotentes et non interactives, utilisables en CI ;
  - migrations passant par le `php artisan migrate` standard ; configuration entièrement pilotable par `.env`.
- **Distribution, à déterminer** : package Composer (plugin Filament) intégré à une app Laravel, application autonome, ou les deux. Comme Kami n'est pas un site, je penche pour **un package + un starter kit** servant de squelette de site. Compare les options, confirme ou contredis cette piste, et explique l'impact sur la structure du code.
- **Une installation = un site**, mais **le modèle de données prévoit le multi-site dès le départ** (clé de site sur les contenus concernés, résolution du site courant), pour que le multi-site (V1) s'active sans migration lourde.
- **Volumes visés en mutualisé** : jusqu'à environ 30 000 contenus, 50 000 médias et une dizaine d'éditeurs, sans dégradation notable (kawaiidream.net tient déjà environ 27 000 images sur ce mutualisé). Au-delà, un serveur est recommandé : le schéma doit y tenir 1 000 000 de contenus sans migration. Plus loin encore, c'est une application sur mesure, pas Kami.
- **Budget de performance** (front, cache de page désactivé, mutualisé) : nombre de requêtes SQL constant par page quel que soit le volume (aucun N+1), viser moins de 15 requêtes pour une page type ; aucun JavaScript requis pour afficher le contenu ; avec cache de page, la réponse doit éviter tout démarrage complet de l'application si possible. Propose des cibles chiffrées (TTFB, poids de page) et un moyen de les vérifier automatiquement.
- **Dépendance à Filament** : Filament a connu des versions majeures avec ruptures. Isole le cœur de Kami (domaine, contenu, rendu front) de Filament, qui ne doit servir qu'à l'admin. Explique comment absorber une future version majeure de Filament sans casser les sites.
- **Licence, à déterminer** : signale toute dépendance dont la licence restreindrait une distribution open source ou commerciale future.

## Principes directeurs

Kami est conçu **pour les développeurs d'abord** : le développeur modélise le site en code (types de contenu, champs, blocs) et livre à l'éditeur une interface sur mesure, simple et sans superflu. C'est l'approche de Statamic ou Craft, pas celle de WordPress.

Les principes ci-dessous sont **classés par ordre de priorité**. En cas de conflit, le principe le plus haut l'emporte. Chaque décision d'architecture qui arbitre entre deux principes doit le dire explicitement, par exemple dans l'ADR concerné.

1. **Sécurité par défaut.** Une installation fraîche est sûre sans aucune configuration : en-têtes de sécurité et CSP actifs, uploads assainis, limitation des tentatives de connexion, 2FA disponible, permissions refusées par défaut, journal d'audit des actions sensibles. Toute option qui affaiblit la sécurité est désactivée par défaut et documentée comme telle.
2. **Vie privée par conception.** Conformité nLPD et RGPD intégrée au cœur : aucun cookie non essentiel, aucun tracking, aucune collecte non nécessaire. Rétention configurable et outils d'export et de suppression des données personnelles.
3. **Souveraineté des données.** Kami fonctionne entièrement **sans aucun service tiers** : aucun appel externe par défaut, ni CDN, ni police distante, ni télémétrie. Tout service externe (IA, moteur de recherche, stockage S3, CDN) est optionnel, explicitement activé et remplaçable.
4. **Accessibilité.** L'admin et le front généré par défaut visent WCAG 2.2 AA. Les composants Blade fournis sont accessibles, et l'éditeur est guidé (alt text obligatoire, structure des titres).
5. **Stabilité.** Avant la 1.0, l'architecture peut évoluer librement. À partir de la 1.0 : SemVer strict, aucune rupture hors version majeure, dépréciations annoncées une version mineure à l'avance, migrations de données non destructives et guide de mise à jour pour chaque version majeure. Concevoir dès maintenant des API publiques étroites (classes `@internal` clairement séparées de l'API publique) pour que cette promesse soit tenable.
6. **Simplicité.** Pour l'éditeur : publier sans formation, n'afficher que ce dont il a besoin. Pour le développeur : déclarer un type de contenu en quelques lignes lisibles, conventions plutôt que configuration, peu de concepts à apprendre. Préférer une solution simple qui couvre 90 % des cas à une solution générique complexe.
7. **Performance et sobriété.** Rapide sur un hébergement mutualisé : peu de requêtes par page (zéro N+1), pages légères, aucun JavaScript inutile sur le front, cache de page efficace. Mesurer plutôt que supposer.
8. **Laravel natif d'abord.** Utiliser en priorité ce que Laravel et Filament fournissent, en suivant leurs conventions. Chaque package tiers doit être justifié : besoin couvert, alternatives, licence, activité de maintenance vérifiée, compatibilité mutualisé. Moins de dépendances, c'est aussi moins de surface d'attaque et moins de ruptures (principes 1 et 5).

Règles qui en découlent :

- Les types de contenu sont définis en code et versionnables. Le schéma passe par des migrations et le contenu reste en base, pour des déploiements sans perte de données.
- Code typé, testé avec Pest, et lisible par d'autres développeurs et par des agents IA.

### Anti-objectifs

Ce que Kami ne doit **pas** devenir, en tirant les leçons des concurrents :

- **L'enfer des plugins à la WordPress** : un site qui dépend de 30 extensions tierces de qualité inégale pour des besoins de base. Le cœur couvre nativement le socle (SEO, redirections, formulaires simples, multilingue) ; les extensions ajoutent, elles ne réparent pas.
- **Le changement de licence en cours de route**, comme October CMS : la licence choisie doit pouvoir être tenue durablement.
- **La configuration infinie** : pas d'écran d'options pour tout. Des conventions solides, et du code pour les cas particuliers.
- **La dépendance à un service** : rien d'essentiel ne passe par un service distant, y compris de l'éditeur de Kami.
- **Le projet abandonné à moitié**, comme plusieurs CMS Filament analysés : mieux vaut un périmètre réduit et fini qu'un périmètre large et bancal.

## Sites de référence

Confronte ton architecture à ces sites réels. Pour chacun, vérifie que le modèle de contenu, le routing, le multilingue et les médias permettent de le reconstruire proprement, et signale ce qui coincerait. Le code des trois premiers est en local ; lis-le.

**Mes sites (déjà sur Velox)**

- **aguet.dev** (`~/Code/aguet`) : portfolio one-page bilingue (français à `/`, anglais à `/en`), **en production sur le mutualisé Infomaniak** (Apache). Contenus : une page d'accueil singleton, des projets non publics triés à la main avec mise en avant, des groupes de compétences. Formulaire de contact avec chaîne de notification, déclenchée par le planificateur web d'Infomaniak via une URL à jeton (`/cron/{token}`, toutes les 15 minutes). Pages d'erreur thématiques, tests de régression visuelle, pipeline de déploiement GitHub Actions vers le mutualisé (`DEPLOY.md`).
- **psyao.ch** (`~/Code/psyao`) : blog technique monolingue migré de WordPress : 146 articles, 8 catégories, 306 tags, des pages, un menu principal sur deux niveaux et un menu de pied de page. Particularités : vidéos YouTube/Vimeo (dont beaucoup mortes), code inline et blocs `<pre>`, redirection des anciennes URL WordPress datées, import automatisé (`psyao:import`).
- **steve-et-aline.ch** (`~/Code/steve-et-aline`) : blog de couple migré de WordPress : peu d'articles mais **deux auteurs** (dont une rédactrice invitée), et surtout **10 galeries de 1 105 photos (720 Mo)** reprises de NextGEN, avec type de contenu et bloc Galerie, et lightbox. Design éditorial travaillé pour le contraste WCAG AA (`DESIGN.md`).
**Mon site hors Velox : le cas limite**

- **kawaiidream.net** (`~/Code/matisse`) : plateforme communautaire de galerie d'images anime, manga et jeux vidéo, migrée d'un site 4images vieux de 20 ans vers une **application Laravel 13 / Filament 5 sur mesure** (sans Velox), déployée sur le mutualisé Infomaniak (staging et production). Environ **27 000 images (13 Go)** dans une arborescence de catégories, des membres inscrits, des commentaires (y compris d'invités), des notes, des demandes DMCA, une recherche à trois niveaux de repli, de l'anti-spam en couches et des compteurs dénormalisés. Ses décisions d'architecture sont dans `docs/explanation-architecture.md`.

  Ce n'est pas un site éditorial : c'est une application communautaire dont le cœur est un modèle métier propre. **Ne cherche pas à le faire entrer dans les types de contenu de Kami.** Utilise-le pour répondre à deux questions : à quel moment une application doit-elle rester du code sur mesure plutôt que du contenu Kami ? Et comment Kami pourrait-il s'y ajouter pour les parties éditoriales (pages d'information, règles, actualités), en cohabitant avec ses propres modèles, utilisateurs, routes et panel Filament ? Profites-en aussi pour relever ses bonnes pratiques réutilisables : sanitization, anti-spam, cache versionné de l'arborescence, URL à hashid, et import idempotent par lots avec `legacy_id`.

**Réalisations d'agence** (voir aguet.dev) : elles montrent que Kami vivra souvent **dans une application Laravel qui fait autre chose en plus du contenu** :

- **CVCI** : portail institutionnel avec CMS, espace membre, SSO Microsoft Entra et synchronisation bidirectionnelle avec un CRM Dynamics ;
- **Vaud Rando** : catalogue de randonnées, inscriptions, espace membre et gestion des bénévoles ;
- **Terre & Nature** : magazine papier numérisé, avec import XML automatisé du contenu imprimé ;
- **Montreux Noël** : microsite de concours (check-in par QR code, tirages au sort).

Des projets comme une API d'assurance ou un système de réservation n'ont pas besoin d'un CMS. Mais s'ils avaient quelques pages éditoriales, Kami devrait pouvoir s'y ajouter sans prendre le contrôle de l'application : routes, utilisateurs, panel Filament et modèles existants doivent cohabiter avec lui. C'est un argument fort pour la forme « package », à évaluer dans le choix de distribution.

Les sites de l'agence peuvent donc être de **tout type** : vitrine, institutionnel, éditorial, microsite, application avec pages éditoriales. Ne suppose pas un profil unique.

Une partie des sites tournent aujourd'hui sous **WordPress**. Les imports de psyao.ch et steve-et-aline.ch montrent ce que ça demande (wpautop, shortcodes, galeries, URL datées, auteurs). Le modèle de données doit accueillir les concepts WordPress sans contorsion, et l'expérience de ces imports doit nourrir l'importeur générique (V1).

## Qualité

Dès la première étape de la roadmap :

- reprendre la boîte à outils de mes projets récents (aguet, matisse) plutôt que d'en inventer une : Laravel Boost, Pest (y compris tests navigateur et visuels là où ils ont du sens), Larastan au niveau maximum raisonnable (indique lequel et pourquoi), Rector, Pint avec une configuration partagée, lefthook pour les hooks Git, gitleaks contre les fuites de secrets ;
- intégration continue (GitHub Actions) qui lance tests, analyse statique et style, sur la matrice des bases de données supportées (SQLite, MySQL, PostgreSQL) ;
- objectif de couverture de tests pour le cœur (domaine, routing, contenu), plus souple pour l'admin.

## Cadre du projet

- **Définition du MVP terminé** : aguet.dev, psyao.ch et steve-et-aline.ch **tournent en production sur Kami**. Les fonctionnalités MVP du comparatif restent la référence, mais c'est cet objectif qui tranche. Une fonctionnalité MVP qui ne sert à aucun des trois sites et retarde leur mise en production peut glisser en V1 : propose-le explicitement plutôt que de la livrer à moitié. À l'inverse, un besoin de ces sites absent de la liste MVP y entre.
- **Capacité** : je travaille sur Kami **quelques heures par semaine**, en parallèle de mon travail en agence, **par phases intenses entrecoupées de pauses** parfois longues. La roadmap doit en tenir compte :
  - des étapes de quelques heures à une journée au plus, chacune laissant le projet dans un état stable, testé et documenté ;
  - une reprise facile après une pause : l'état du projet se lit dans les issues et la roadmap, pas dans ma mémoire ;
  - pas de grande étape « big bang » qui immobilise le projet pendant des semaines.
- **Méthode** : je travaille avec les **skills de Matt Pocock** (`mattpocock/skills`), comme pour Velox. C'est la méthode du projet : spécification, découpage en tickets, maps, « grilling », TDD, revue d'architecture, passation entre sessions suivent ces skills plutôt qu'un processus inventé pour Kami. Vérifie les skills installés et leurs noms exacts (ils ont changé d'une version à l'autre), et organise tes livrables pour qu'ils s'enchaînent naturellement avec eux.
- **Documentation** : structure libre. Propose-en une adaptée à un CMS distribué (installation, guides, référence de l'API publique, décisions), cohérente avec ce que Velox a déjà en `docs/`.
- **Nom et vendor** : le nom de travail est **Kami** (紙, « papier » ; aussi 神). Le vendor envisagé est `psyao/kami`, par cohérence avec `psyao/velox`. Avant de figer le namespace, vérifie la disponibilité sur Packagist et GitHub, et signale toute collision gênante. À ma connaissance, le nom est déjà porté par un vieux CMS PHP abandonné sur SourceForge (2013) et par une application éducative d'annotation de PDF assez connue.

