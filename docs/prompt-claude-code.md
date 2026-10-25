Tu vas m'aider à concevoir **Kami**, un CMS construit sur Laravel et Filament, successeur de mon CMS actuel Velox.

`docs/brief.md` est le cahier des charges : contexte, Velox, contraintes, principes, sites de référence, cadre du projet. Il fait foi pour toute la suite. `docs/comparatif-cms.md` est le référentiel fonctionnel.

La conception se fait sur **plusieurs sessions**, avec les skills de Matt Pocock (`mattpocock/skills`) comme méthode, comme le brief le demande. Ce document décrit tout le parcours, pour que chaque session sache où elle se situe. **Aucun code applicatif** tant que l'architecture et le premier spec ne sont pas validés.

Certains skills (`/setup-matt-pocock-skills`, `/wayfinder`, `/grill-with-docs`, `/to-spec`, `/to-tickets`) ne se déclenchent que si je les lance moi-même : quand une phase en a besoin, dis-moi quelle commande lancer plutôt que de reproduire le skill à la main.

## Parcours

### Phase 0 — Mise en place (cette session)

1. Lis `docs/brief.md` en entier. Ne lis pas encore Velox ni les sites : c'est le travail de la phase 1.
2. `git init`, puis je lance `/setup-matt-pocock-skills`. Recommandation : GitHub comme tracker (wayfinder a besoin de liens de blocage natifs), un seul contexte (`GLOSSARY.md` + `docs/adr/` à la racine).
3. Rédige un premier `CLAUDE.md` court, en complément du bloc « Agent skills » écrit par le setup, en suivant `/writing-for-agents` : langues de travail, ordre des principes, contraintes du mutualisé, anti-objectifs, méthode, conventions de code. Il renvoie au brief au lieu de le recopier. Il sera complété à la fin de la phase 3.

### Phase 1 — Recherche (cette session, en arrière-plan)

Lance `/research` en parallèle, une note sourcée par sujet dans `docs/research/` :

- `velox-adr.md` : les ADR de Velox, leurs décisions et leur statut ;
- `velox-frictions.md` : les frictions relevées dans les journaux de migration et les issues ;
- `site-aguet-dev.md`, `site-psyao-ch.md`, `site-steve-et-aline-ch.md` : pour chaque site, les types de contenu, les blocs, les gabarits, la structure des URL, les besoins spécifiques et ce qui contourne Velox ;
- `mutualise.md` : ce que Laravel 13 et Filament 5 permettent sur un hébergement mutualisé (queue, planificateur, cache, recherche, traitement d'images), d'après les docs officielles.

Chaque note cite ses sources (fichier et ligne, URL). Elle rapporte des faits, pas des recommandations.

### Phase 2 — Rétrospective Velox (nouvelle session)

À partir des notes de `docs/research/`, sans relire les sources brutes sauf pour vérifier un point, rédige `docs/velox-retrospective.md` :

1. **À garder** : les décisions de Velox qui ont fait leurs preuves, avec les ADR concernés.
2. **À changer** : ce qui a coincé ou limite Velox.
3. **Ce qui manque** à Velox par rapport au MVP du comparatif.
4. **Chemin de migration** Velox 1.x → Kami pour les trois sites, dans les grandes lignes. aguet.dev est en production : sa migration doit se faire sans perte de contenu ni d'URL.

Arrête-toi et attends ma validation.

### Phase 3 — Wayfinder (une session par ticket)

Je lance `/wayfinder` pour cartographier la conception.

**Destination** : une architecture validée et une roadmap en jalons, prêtes à passer jalon par jalon dans `/to-spec`.

**Tickets de décision probables** (à affiner pendant la cartographie, pas à reprendre tels quels) :

- distribution : options comparées (package, starter kit, monorepo…) ;
- structure du code : packages, namespaces, dossiers ;
- modèle de domaine : entités, relations ;
- modélisation du contenu : comment un développeur déclare un type de contenu, ses champs et ses blocs. Ticket `prototype` : je veux voir l'API avant de la valider ;
- multilingue ; routing et résolution d'URL ; brouillons, révisions et publications programmées ; médias ; SEO ; rôles et permissions ; cache et invalidation ; recherche ; rendu Blade ;
- points d'extension : événements, hooks, enregistrement de types, de champs et de blocs ;
- matrice des modes d'exécution en mutualisé et sur serveur. Ticket `research`, nourri par `mutualise.md` ;
- migration d'aguet.dev sans perte d'URL ;
- stratégie de tests, dont un test d'intégration qui simule les contraintes du mutualisé.

Chaque ticket résolu :

- met à jour `GLOSSARY.md` au fil de l'eau (le vocabulaire d'un CMS est piégeux : entrée, type, bloc, révision, page…) ;
- produit un ADR dans `docs/adr/` **seulement** si la décision est difficile à revenir en arrière, surprenante sans contexte et le fruit d'un vrai compromis. Le reste vit dans la résolution du ticket.

**Checklist de la destination**, à reporter dans les Notes du map. Le map n'est terminé que quand ces documents peuvent être écrits sans nouvelle décision :

- `docs/architecture.md`, lisible en une heure :
  - ouvert par un **résumé des décisions clés** sur une page, que je peux valider ou contester point par point ;
  - vision et principes directeurs, sur une page au plus ;
  - un diagramme Mermaid du modèle de domaine ;
  - un court exemple de l'API de déclaration d'un type de contenu ;
  - un **tableau des dépendances** : package, rôle, licence, maintenance, alternative écartée, compatibilité mutualisé (oui / non / option serveur) ;
  - la **matrice des modes d'exécution** : pour chaque sous-système (queue, cache, recherche, images, planificateur), le comportement en mutualisé et sur serveur ;
  - les risques et les décisions ouvertes.
  
  Le document détaille ce qui est structurant et résume le reste, en renvoyant aux ADR plutôt qu'en les recopiant.
- `docs/roadmap.md` : le plan jusqu'au MVP tel que le brief le définit (les trois sites en production), découpé en **jalons**. Pour chaque jalon : objectif, fonctionnalités du référentiel couvertes, critères d'acceptation, dépendances. Chaque jalon doit pouvoir passer tel quel dans `/to-spec`. Une fonctionnalité MVP qui ne sert à aucun des trois sites est proposée explicitement pour la V1.

Quand le map est dégagé, rédige ces deux documents, complète `CLAUDE.md` (commandes utiles, renvoi vers l'architecture), et arrête-toi pour validation.

### Phase 4 — Spec et tickets, jalon par jalon

Pour chaque jalon de la roadmap, dans l'ordre : `/to-spec`, puis `/to-tickets`. Les tickets doivent faire de quelques heures à une journée au plus, et chacun laisse le projet dans un état stable, testé et documenté (voir la capacité dans le brief). Un seul jalon spécifié d'avance : le suivant se spécifie quand le précédent est livré.

Ensuite : `/implement` par ticket (ou `/implement-spec` pour un jalon entier), puis `/retro` à la fin de chaque jalon.

## Méthode

- Pose les questions bloquantes une par une, chacune avec ta recommandation par défaut.
- Quand tu hésites entre deux approches, présente-les brièvement avec ta recommandation au lieu de trancher en silence.
- Reste concret : noms de classes, de tables, de commandes Artisan envisagées.
- Après chaque livrable qui demande validation, arrête-toi.
- Entre les phases, tout l'état se lit dans le dépôt et le tracker (notes de recherche, map, ADR, glossaire, roadmap), jamais dans ma mémoire ni dans une session précédente.
