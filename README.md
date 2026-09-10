# KƐNƐYA WorkFlow

[![CI](https://github.com/LoloGH/keneya_workflow/actions/workflows/ci.yml/badge.svg)](https://github.com/LoloGH/keneya_workflow/actions/workflows/ci.yml)

Gestion de file d'attente hospitalière avec renvoi inter-services et **dossier
patient unique**, développée par AXESs pour l'**Hôpital Fousseyni Daou de Kayes**
(Mali), et conçue pour être réutilisée dans d'autres établissements maliens.

> **Nom du produit** : `KƐNƐYA WorkFlow` (affiché tel quel dans l'interface et la
> documentation).
> **Identifiant technique** : `keneya-workflow` - le caractère `Ɛ` n'apparaît
> jamais dans le code, les configurations ou les identifiants système.

---

## Sommaire

1. [Principe : un rôle, une interface](#1-principe--un-rôle-une-interface)
2. [Pile technique](#2-pile-technique)
3. [Installation avec Docker (recommandé)](#3-installation-avec-docker-recommandé)
4. [Installation sans Docker](#4-installation-sans-docker)
5. [Configuration](#5-configuration)
6. [Passerelle SMS](#6-passerelle-sms)
7. [Sauvegardes](#7-sauvegardes)
8. [Modèle de données](#8-modèle-de-données)
9. [Identité, passages et flux de renvoi](#9-identité-passages-et-flux-de-renvoi)
10. [Pièces jointes, caisse, ordonnances et rendez-vous](#10-pièces-jointes-caisse-ordonnances-et-rendez-vous)
11. [Portail patient, impression et suppression de dossier](#11-portail-patient-impression-et-suppression-de-dossier)
12. [Catalogues administrables et interfaces générées](#12-catalogues-administrables-et-interfaces-générées)
13. [Hospitalisation et planning de soins](#13-hospitalisation-et-planning-de-soins)
14. [Notifications, profil et relèves](#14-notifications-profil-et-relèves)
15. [Ordonnances et documents imprimés](#15-ordonnances-et-documents-imprimés)
16. [Langage visuel](#16-langage-visuel)
17. [Journal d'audit et plannings](#17-journal-daudit-et-plannings)
18. [Vérification d'un déploiement](#18-vérification-dun-déploiement)
19. [Tests](#19-tests)
20. [Organisation du code](#20-organisation-du-code)

---

## 1. Principe : un rôle, une interface

Contrainte de conception centrale : **chaque rôle n'a accès qu'à sa propre
interface**. Il n'y a aucune navigation croisée - pas de menu partagé, pas de
lien vers un autre module, pas de tableau de bord générique. Chaque poste
(tablette ou PC dédié) n'affiche que l'interface du rôle connecté.

| Rôle | Interface unique | Contenu |
|---|---|---|
| `admin` | `/admin` | Établissement, services, réceptionnistes, médecins (avec réaffectation), plannings du personnel (jour par jour et génération groupée), vue globale des patients avec accès à tout dossier, suppression définitive d'un dossier, journal d'audit. |
| `receptionist` | `/reception` | Recherche de dossier existant, enregistrement patient et visiteur, rendez-vous du jour, passages du jour (avec réimpression du ticket), écran de salle d'attente, son propre planning. |
| `doctor` | `/service` | File d'attente, renvois entrants et sortants, clôture de renvoi et de dossier, conclusion de consultation, ordonnances, « Mes patients » et « Mes rendez-vous », son propre planning, et le dossier patient dans un panneau de la même page. **Aucune fonction de caisse.** |
| `cashier` | `/caisse` | Les files de caisse - « Caisse Ticket », « Caisse Services », et toute caisse ajoutée ensuite - encaissement et orientation vers le service qui attend, son propre planning. |
| *(type de personnel sans rôle)* | `/staff/{slug}` | Interface **composée** des seules fonctions cochées par l'administrateur - voir §12. Cloisonnée exactement comme les quatre autres. |

Mise en œuvre :

- Après authentification, `HomeController` redirige immédiatement vers `/admin`,
  `/reception`, `/service` ou `/caisse` selon le rôle - jamais vers une page
  commune.
- Le middleware `EnsureRoleScope` (alias `role.scope`) protège chaque groupe de
  routes. Un utilisateur qui tape une autre URL à la main est **redirigé vers sa
  propre interface avec un message clair**, et non bloqué sur une erreur 403 :
  le personnel hospitalier ne doit jamais rester devant une page d'erreur
  technique.
- Un médecin rattaché à plusieurs services reste sur `/service`, avec un
  sélecteur limité à **ses** services (composant `ServiceSelector`, adossé au
  trait `ScopedToOwnService` qui refuse tout autre `service_id`, même forcé côté
  client).
- `/board` est l'affichage public de la salle d'attente, sans authentification.
  Ce n'est pas une interface « de rôle » : c'est un écran mural, également
  visible depuis le poste d'accueil.
- **Chaque fonctionnalité ajoutée est une section d'une interface existante,
  jamais une nouvelle route partagée.** Même les téléchargements sont
  dédoublés : `/service/pieces-jointes/{id}` pour le médecin,
  `/admin/pieces-jointes/{id}` pour l'admin. Deux tests verrouillent la règle -
  l'un vérifie les redirections, l'autre qu'aucune route ne porte deux
  `role.scope` différents.
- `EnsureRoleScope` est évalué **avant** la résolution des modèles de route
  (priorité de middleware explicite dans `bootstrap/app.php`) : sans cela, un
  utilisateur du mauvais rôle recevrait un 404 quand l'identifiant n'existe pas
  et une redirection quand il existe, ce qui lui permettrait de deviner les
  identifiants d'un autre service.

## 2. Pile technique

| Élément | Choix |
|---|---|
| Backend | Laravel 12, PHP 8.4 |
| Frontend | Livewire 3 + Alpine (embarqué par Livewire) |
| Base de données | MySQL / MariaDB |
| Rôles et permissions | `spatie/laravel-permission` |
| Journal d'audit | `spatie/laravel-activitylog` |
| Export PDF | `barryvdh/laravel-dompdf` |
| SMS | `App\Services\SmsGateway` → API HTTP de SMSGate |

**Aucune pipeline de build JS.** Pas de React, pas de Vue, pas de Vite : toute
la logique métier reste côté PHP et la feuille de style est servie telle quelle
depuis `public/css/app.css`. Un serveur sans connectivité internet peut donc
déployer l'application sans jamais lancer `npm install`.

L'interface est pensée **mobile et tablette d'abord** : zones tactiles d'au
moins 48 px, boutons larges, et aucune interaction dépendant du survol de la
souris.

## 3. Installation avec Docker (recommandé)

Cinq services : `app` (PHP-FPM 8.4 + Laravel), `web` (Nginx), `db`
(MariaDB), `scheduler` et `queue-worker`. Le `docker-compose.yml` tourne
**à l'identique** sous Docker Engine (Linux) et Docker Desktop / WSL2
(Windows), sans modification.

Le service **`scheduler`** est apparu en v3.2.3 : même image et même code que
`app`, mais il ne sert aucune requête HTTP - il lance `php artisan
schedule:work`, qui réveille le planificateur Laravel chaque minute. C'est lui
qui envoie les rappels de rendez-vous ; **sans ce conteneur, ils ne partiront
jamais et rien ne le signalera.** `docker compose logs scheduler` montre ses
exécutions.

Le service **`queue-worker`** est apparu en v3.2.8, sur le même modèle et pour
la même raison : il lance `php artisan queue:work --tries=3
--backoff=30,60,120`, qui envoie les SMS en arrière-plan. Depuis cette version,
enregistrer un patient ne consiste plus à attendre la passerelle : l'action
rend la main immédiatement et le SMS part ensuite. **Sans ce conteneur,
l'application fonctionne normalement mais aucun SMS ne part** - les messages
s'empilent dans la table `jobs`, et la section « SMS » de `/admin` les montre
bloqués en « En file ». C'est un conteneur distinct du `scheduler` à dessein :
un worker qui plante et redémarre ne doit interrompre ni les rappels de
rendez-vous, ni le serveur web.

### Linux

```bash
git clone <url-du-depot> keneya-workflow
cd keneya-workflow
cp .env.example .env      # puis éditer les mots de passe
./install.sh
```

### Windows

```powershell
git clone <url-du-depot> keneya-workflow
cd keneya-workflow
Copy-Item .env.example .env    # puis éditer les mots de passe
powershell -ExecutionPolicy Bypass -File .\install.ps1
```

Les deux scripts font la même chose : construction des conteneurs, démarrage de
la pile, génération de `APP_KEY`, `php artisan migrate --seed`, puis mise en
cache de la configuration. Ils sont idempotents et peuvent être relancés.

L'application est alors disponible sur `http://localhost:8080` (port réglable
par `APP_HTTP_PORT`), et l'écran de salle d'attente sur
`http://localhost:8080/board`.

### Commandes courantes

```bash
docker compose up -d                            # démarrer
docker compose down                             # arrêter
docker compose logs -f app                      # journaux applicatifs
docker compose exec app php artisan migrate     # migrations
docker compose exec app php artisan tinker      # console
```

Sous Windows, les mêmes commandes fonctionnent telles quelles dans PowerShell.

## 4. Installation sans Docker

À utiliser si Docker n'est pas installable sur un site donné.

Prérequis communs : PHP 8.4 avec les extensions `pdo_mysql`, `mbstring`,
`intl`, `zip`, `bcmath`, `gd`, `openssl`, `fileinfo` ; Composer 2 ; MySQL 8 ou
MariaDB 10.6+.

> **`gd` n'est pas facultative.** C'est elle qui permet à dompdf d'embarquer
> une image. Sans elle, la génération d'une ordonnance s'arrête sur « The PHP
> GD extension is required » : ni le logo de l'en-tête, ni le cachet de
> l'établissement, ni la signature du médecin n'arrivent sur le document.
> `composer install` la réclame désormais, l'installation échoue donc tout de
> suite plutôt qu'à la première impression.

> **Plafonds d'envoi à ajuster.** Un PHP fraîchement installé plafonne les
> envois à 2 Mo, alors que les pièces jointes sont acceptées jusqu'à 10 Mo :
> sans ce réglage, un dépôt de fichier échoue sans message clair. Dans le
> `php.ini` du serveur, et dans la configuration du serveur web :
>
> ```ini
> upload_max_filesize = 12M
> post_max_size = 16M
> ```
>
> ```nginx
> client_max_body_size 16M;   # Nginx
> ```
>
> Sous IIS, relever `maxAllowedContentLength` ; sous Apache,
> `LimitRequestBody`. La règle est la même partout : serveur web ≥
> `post_max_size` ≥ `upload_max_filesize` > plafond applicatif (10 Mo,
> `App\Models\Attachment::MAX_SIZE_KB`). L'image Docker applique déjà ces
> valeurs.

### Linux - Nginx + PHP-FPM

```bash
sudo apt install php8.4-fpm php8.4-mysql php8.4-mbstring php8.4-intl \
                 php8.4-zip php8.4-bcmath php8.4-gd nginx mariadb-server

git clone <url-du-depot> /var/www/keneya-workflow
cd /var/www/keneya-workflow

composer install --no-dev --optimize-autoloader
cp .env.example .env        # DB_HOST=127.0.0.1
php artisan key:generate
php artisan migrate --seed --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache

# Signatures et tampons (v3.2.9) : dossier prive, jamais servi directement.
sudo install -d -o www-data -g www-data -m 750 storage/app/signatures

# Worker de la file SMS et planificateur : sans eux, aucun SMS ne part.
sudo cp deploy/systemd/keneya-*.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now keneya-queue keneya-scheduler
```

> **Les deux services d'arriere-plan ne sont pas optionnels.** La pile Docker
> les declare (`queue-worker`, `scheduler`) ; une installation native, elle,
> demarre sans. L'application fonctionne alors normalement en apparence, mais
> les SMS s'empilent dans la table `jobs` sans jamais partir, et les rappels de
> rendez-vous comme les liens de sondage ne se declenchent pas — sans qu'aucun
> ecran ne le signale. Les unites sont fournies dans `deploy/systemd/` ;
> adaptez-y `WorkingDirectory`, `User` et `Group` si le depot n'est pas dans
> `/var/www/keneya-workflow`.
>
> ```bash
> systemctl is-active keneya-queue keneya-scheduler   # doit repondre « active »
> sudo systemctl restart keneya-queue                 # apres chaque deploiement
> ```
>
> Le worker sort de lui-meme toutes les heures (`--max-time=3600`) et systemd
> le relance : les redemarrages reguliers dans `journalctl -u keneya-queue`
> sont le fonctionnement attendu, pas un incident.

Serveur virtuel Nginx (`/etc/nginx/sites-available/keneya-workflow`) - la
configuration de `docker/nginx/default.conf` sert de base ; il suffit de
remplacer `fastcgi_pass app:9000;` par
`fastcgi_pass unix:/run/php/php8.4-fpm.sock;` et d'adapter `root` :

```nginx
server {
    listen 80;
    server_name keneya.hopital.local;
    root /var/www/keneya-workflow/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.4-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Windows - IIS + PHP, ou Apache

**IIS + PHP (FastCGI)**

1. Installer PHP 8.4 (build NTS, x64) dans `C:\php`, activer les extensions
   ci-dessus dans `php.ini`.
2. Dans le Gestionnaire IIS : *Mappages de gestionnaires* → *Ajouter un mappage
   de module* → chemin `*.php`, module `FastCgiModule`, exécutable
   `C:\php\php-cgi.exe`.
3. Créer un site dont le répertoire physique est
   `C:\inetpub\keneya-workflow\public`.
4. Installer le module **URL Rewrite** et déposer dans `public\web.config` la
   règle de réécriture Laravel :

```xml
<?xml version="1.0" encoding="UTF-8"?>
<configuration>
  <system.webServer>
    <rewrite>
      <rules>
        <rule name="Laravel" stopProcessing="true">
          <match url="^" />
          <conditions logicalGrouping="MatchAll">
            <add input="{REQUEST_FILENAME}" matchType="IsDirectory" negate="true" />
            <add input="{REQUEST_FILENAME}" matchType="IsFile" negate="true" />
          </conditions>
          <action type="Rewrite" url="index.php" />
        </rule>
      </rules>
    </rewrite>
  </system.webServer>
</configuration>
```

5. Donner au compte `IIS_IUSRS` les droits d'écriture sur `storage\` et
   `bootstrap\cache\`.

**Apache (type Laragon en usage serveur)**

Installer Laragon avec PHP 8.4 et MySQL, placer le projet dans
`C:\laragon\www\keneya-workflow`, puis pointer le `DocumentRoot` sur le
sous-dossier `public` :

```apache
<VirtualHost *:80>
    ServerName keneya.hopital.local
    DocumentRoot "C:/laragon/www/keneya-workflow/public"
    <Directory "C:/laragon/www/keneya-workflow/public">
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

Le `.htaccess` livré par Laravel dans `public/` gère la réécriture ; le module
`mod_rewrite` doit être actif.

Ensuite, dans les deux cas :

```powershell
composer install --no-dev --optimize-autoloader
Copy-Item .env.example .env      # DB_HOST=127.0.0.1
php artisan key:generate
php artisan migrate --seed --force
php artisan config:cache; php artisan route:cache; php artisan view:cache
```

## 5. Configuration

Tout se règle dans `.env` (voir `.env.example`, entièrement commenté). Aucun
identifiant n'est écrit en dur dans le code.

| Variable | Rôle |
|---|---|
| `KENEYA_NAME` | Nom affiché dans l'interface et dans les SMS. |
| `KENEYA_HOSPITAL` | Nom de l'établissement. |
| `KENEYA_CODE_PREFIX` | Préfixe des identifiants de dossier (`HFD` → `HFD-00001`). **À changer pour un autre établissement.** |
| `KENEYA_POLL_INTERVAL` | Rafraîchissement Livewire des écrans de travail (défaut `10s`). |
| `KENEYA_BOARD_POLL_INTERVAL` | Rafraîchissement de l'écran de salle d'attente (défaut `5s`). |
| `SEED_DEFAULT_PASSWORD` | Mot de passe des comptes créés par les seeders. |
| `APP_HTTP_PORT` | Port publié par Docker Compose (défaut `8080`). |

### Comptes de démonstration

`php artisan db:seed` crée les huit services de départ (Médecine Générale,
Urgences, Maternité, Administration, Échographie, Laboratoire, plus les deux
caisses **Caisse Ticket** et **Caisse Services**) ainsi que :

| Compte | Rôle | Interface |
|---|---|---|
| `admin@keneya.local` | `admin` | `/admin` |
| `accueil@keneya.local` | `receptionist` | `/reception` |
| `caisse@keneya.local` | `cashier` | `/caisse` |
| `medecine@keneya.local` | `doctor` | `/service` (Médecine Générale) |
| `urgences@keneya.local` | `doctor` | `/service` (Urgences) |
| `maternite@keneya.local` | `doctor` | `/service` (Maternité) |
| `echographie@keneya.local` | `doctor` | `/service` (Échographie) |
| `laboratoire@keneya.local` | `doctor` | `/service` (Laboratoire) |

Le mot de passe est la valeur de `SEED_DEFAULT_PASSWORD`. **Changez ces comptes
avant toute mise en service réelle.**

Aucun compte de démonstration n'est créé pour un type de personnel à interface
dédiée : ces types n'existent que si l'administrateur en crée. La marche à suivre
est « Personnel → Types de personnel », puis « Personnel → Personnels » pour y
rattacher quelqu'un.

Depuis la v3.2.3, « Personnel → Personnels » gère **tout** le personnel - les
sections séparées « Médecins », « Réceptionnistes » et « Interfaces dédiées » ont
fusionné. Le menu « Type de personnel » liste tous les `staff_types` et le menu
« Service » tous les services, caisses comprises ; c'est le type choisi qui
décide du rôle attribué et de la table de rattachement (`doctors`,
`receptionists`, `cashiers` ou `staff_members`). Un compte ne change pas de rôle
en changeant de type : le formulaire refuse explicitement, il faut supprimer le
rattachement et en créer un autre.

## 6. Passerelle SMS

Les SMS partent par **SMSGate**, une application Android auto-hébergée : un
téléphone posé sur le réseau de l'hôpital expose une API HTTP, et
`App\Services\SmsGateway::send($to, $text)` l'appelle.

```env
SMSGATE_ENABLED=true
SMSGATE_URL=http://192.168.1.50:8080
SMSGATE_LOGIN=sms
SMSGATE_PASSWORD=…
SMSGATE_COUNTRY_CODE=223
```

- Les numéros saisis au comptoir à 8 chiffres sont automatiquement mis au
  format international (`76445566` → `+22376445566`).
- Une passerelle injoignable **ne fait jamais échouer l'acte métier** : le
  patient est enregistré, le renvoi est créé, et l'échec d'envoi est simplement
  journalisé.
- `SMSGATE_ENABLED=false` désactive complètement les envois (formation,
  recette). Les tests tournent toujours avec les SMS désactivés.

## 7. Sauvegardes

Les données vivent dans le volume Docker dédié **`keneya_db`**, distinct du
code : `docker compose down` ne l'efface pas (`docker compose down -v`, si).

Depuis la v2, **les pièces jointes vivent dans le volume `keneya_storage`**
(`storage/app/attachments`), rejointes en v3.2.9 par **les signatures et
tampons des médecins** (`storage/app/signatures`). Une base restaurée sans eux
réimprime des ordonnances amputées de ce qui les authentifie : ce sont des
documents à valeur légale. Une sauvegarde complète comprend donc les deux :
l'export SQL ci-dessous **et** une copie de ce volume, par exemple

```bash
docker run --rm -v keneya_storage:/data -v "$PWD/backups:/out" alpine \
    tar czf /out/keneya_storage-$(date +%Y%m%d).tar.gz -C /data .
```

Export `.sql` - la même commande des deux côtés :

```bash
docker compose exec -T db mariadb-dump \
    --user=keneya --password=<mot-de-passe> --single-transaction \
    keneya_workflow > keneya_workflow.sql
```

`scripts/backup.sh` fait les trois d'un coup — export SQL, archive des pièces
jointes, archive des signatures — et conserve trente exemplaires de chaque
série, comptés série par série. Les deux scripts horodatent l'export :

```bash
./scripts/backup.sh /var/sauvegardes/keneya                       # Linux
powershell -File .\scripts\backup.ps1 -Destination D:\sauvegardes # Windows
```

**Planification - Linux (cron), tous les jours à 22h00 :**

```cron
0 22 * * * cd /var/www/keneya-workflow && ./scripts/backup.sh /var/sauvegardes/keneya >> /var/log/keneya-backup.log 2>&1
```

**Planification - Windows (Planificateur de tâches) :**

```powershell
$action  = New-ScheduledTaskAction -Execute 'powershell.exe' `
    -Argument '-ExecutionPolicy Bypass -File C:\keneya-workflow\scripts\backup.ps1 -Destination D:\sauvegardes'
$trigger = New-ScheduledTaskTrigger -Daily -At 22:00
Register-ScheduledTask -TaskName 'Sauvegarde KEneYa WorkFlow' -Action $action -Trigger $trigger -RunLevel Highest
```

Restauration :

```bash
docker compose exec -T db mariadb --user=keneya --password=<mot-de-passe> \
    keneya_workflow < keneya_workflow.sql
```

## 8. Modèle de données

| Table | Rôle |
|---|---|
| `service_kinds` | Types de service administrables, avec `requires_payment_gate`. Trois types d'origine reconnus par leur `slug`. |
| `services` | Services de l'établissement, rattachés à un `service_kind_id`. |
| `staff_types` | Types de personnel : `matched_role` (réutilise une interface existante) ou `slug` + `capabilities` (interface générée). |
| `staff_members` | Rattachement d'un compte à un type générique et à son service, sur le modèle de `doctors`. |
| `rooms` | Salles d'hospitalisation : service responsable et nombre de lits. L'occupation n'est pas stockée. |
| `hospitalizations` | Séjour d'un patient : salle, service, admission, sortie. |
| `care_task_types` | Catalogue des types de soins (sérum, injection, pansement…). |
| `handoff_notes` | Notes de relève entre équipes sur un séjour : texte libre, daté et signé. |
| `staff_notifications` | Une ligne **par destinataire** - c'est ce qui permet de dire « lue » pour l'un et pas pour l'autre. |
| `care_tasks` | Une administration, individuellement marquable `pending` / `done` / `missed` / `cancelled`. Un soin annulé garde son motif, son auteur et son heure d'annulation. |
| `doctors` | Rattachement d'un compte à un service, réaffectable à tout moment. Un médecin multi-services a plusieurs lignes. |
| `receptionists` | Rattachement d'un compte au rôle d'accueil. |
| `cashiers` | Rattachement d'un compte au rôle de caissier, sur le modèle de `receptionists`. |
| **`patients`** | **Identité permanente et rien d'autre** : `patient_code` (`HFD-00001`) à vie, `crno` (dossier papier), profession, note libre de l'accueil, plus `access_code` (4 chiffres) et `portal_token` (UUID) pour le portail. |
| **`visits`** | **Un passage / épisode de soins** : service courant, ticket, statut (`waiting`, `called`, `closed`), ouverture et clôture, plus `pending_next_service_id` - la destination qui attend le paiement. |
| `companions` | Accompagnateurs d'un patient. Information non médicale, sans ticket propre. |
| `visitors` | Fiche visiteur (`HFD-V-00001`), avec ticket dans la file du service visité et **le patient visité** (`patient_id`, facultatif hors service clinique). |
| `portal_access_attempts` | Tentatives de code du portail par dossier, avec verrouillage temporaire. |
| `referrals` | Renvoi d'un service à un autre : instructions, résultat, puis clôture par le prescripteur. Auteur, exécutant et clôturant sont chacun un médecin **ou** un membre du personnel générique. |
| `patient_history` | Journal **append-only** du parcours, ancré sur la visite. `type` couvre aussi `consultation_conclusion`, `payment_confirmed`, `hospitalization_admitted`, `hospitalization_discharged` et `care_task_completed`. Signé par `doctor_id` **ou** `staff_member_id`. |
| `attachments` | Pièces jointes (PDF, JPG, PNG). `patient_id` est le point d'ancrage ; `referral_id` et `patient_history_id` ne sont renseignés qu'en contexte. |
| `payments` | Encaissements, en FCFA sans décimales : `ticket` (consultation) ou `service` (acte). |
| `prescriptions` | Ordonnances **ligne par ligne** (`lines`, JSON : médicament, posologie, durée), exportables en PDF. `content` ne sert plus qu'aux ordonnances antérieures à la v3.2.6. |
| `appointments` | Rendez-vous : `scheduled`, `checked_in`, `no_show`, `cancelled`. |
| `schedules` | Créneaux de travail du personnel. |
| `settings` | Réglages modifiables sans redéploiement (nom de l'établissement). |
| `activity_log` | Journal d'audit (`spatie/laravel-activitylog`). |

Trois garanties structurelles :

- **`patient_code` est attribué dans `PatientObserver::creating`**, jamais dans
  un contrôleur. Quel que soit le point d'entrée - formulaire, seeder, import,
  `tinker` - un patient ne peut pas exister sans identifiant unique.
- **`patient_history` est append-only** : `PatientHistoryObserver` lève une
  exception sur toute tentative de mise à jour ou de suppression. Le seul point
  d'écriture est `PatientHistoryRecorder`.
- **L'identité ne porte aucun état de passage.** Un patient qui revient six mois
  plus tard ouvre une nouvelle `visits` sous le même `patient_code` : l'épisode
  précédent reste intact et consultable, distinct du nouveau.

**Les séries de numérotation (v3.2.6).** La première série va de `HFD-00001` à
`HFD-99999`. Au-delà, une lettre prend le relais : `HFD-A0001` à `HFD-A9999`,
puis `HFD-B0001`, jusqu'à `HFD-Z9999`. Le numéro reste court - dictable au
téléphone, lisible sur un ticket thermique - et la capacité passe à un peu plus
de 350 000 dossiers.

Le générateur ordonne sur le **code lui-même**, pas sur l'identifiant de ligne :
dès qu'un dossier de la série A précède une reprise de la série numérique, trier
par `id` redonnerait un numéro déjà pris. Au bout de `Z9999` il s'arrête avec un
message explicite plutôt que de fabriquer un numéro ambigu - un numéro de
dossier vaut à vie, un doublon ne se rattrape pas.

Les migrations `2025_04_01_*` ajoutent la couche v3.2.1 : les types de service
(avec conversion des trois valeurs de l'ancien `enum`), les types de personnel,
l'ouverture du parcours de soin au personnel générique, et l'hospitalisation.
Leur `down()` est fonctionnel et vérifié sur SQLite comme sur MariaDB - celui des
types de service restaure l'ancien `enum`, les types ajoutés par l'admin
retombant sur « clinique », faute d'équivalent.

Les migrations `2025_03_01_*` ajoutent la couche v3.2 : les caissiers, le
troisième type de service, le routage sous condition de paiement, le patient
visité et le portail. Leur `down()` est fonctionnel et vérifié sur SQLite comme
sur MariaDB - celui du type de service supprime au passage les caisses et leurs
files, sans quoi MariaDB refuserait de rétrécir la colonne.

### Migration depuis la v1

Les migrations `2025_02_01_*` font la bascule. Elles **reprennent les données
existantes** plutôt que de simplement supprimer des colonnes : chaque patient
déjà enregistré reçoit une `visits` portant son service, son ticket et son
statut d'origine, et les lignes `referrals` / `patient_history` sont rattachées
à cette visite. Le `down()` fait le chemin inverse et restaure fidèlement
l'ancien schéma - vérifié dans les deux sens.

> Les données de démonstration du VPS sont jetables, comme confirmé. La reprise
> a quand même été écrite : elle coûte une dizaine de lignes et rend la
> migration réversible sans perte, ce qui vaut mieux qu'un `dropColumn` sec.

## 9. Identité, passages et flux de renvoi

Le `patient_id` **ne change jamais**, et le `patient_code` non plus. C'est la
**visite** qui se déplace : un épisode unique traverse les services, change de
`service_id` et reçoit un nouveau ticket à chaque renvoi, puis se clôture une
seule fois, à la fin.

### Reprendre un dossier existant

Dans `/reception`, la recherche précède toujours l'enregistrement :

1. Recherche par `patient_code`, nom ou téléphone.
2. Si un patient est trouvé, son identité s'affiche pour **confirmation visuelle
   par la réceptionniste** - deux homonymes ne doivent jamais être confondus.
3. « Nouvel épisode » crée une ligne `visits` rattachée au `patient_id`
   existant, avec un motif en texte libre. **Aucune ligne `patients`, aucun
   nouveau `patient_code`.**
4. Si aucun patient n'est trouvé, le formulaire d'enregistrement classique crée
   une nouvelle identité.

### Retour d'un renvoi

Quand le service destinataire saisit le résultat, **le patient lui-même revient** :
`CompleteReferral` bascule `visits.service_id` sur `referrals.from_service_id`,
régénère un ticket dans cette file et repasse la visite en `waiting`. Sans cela,
un patient revenu du laboratoire resterait indéfiniment dans la file du
laboratoire, même si son résultat s'affiche bien chez le médecin.

**Le retour ne repasse jamais par la caisse.** Le paiement ne conditionne que le
trajet *aller* : on ne fait pas payer deux fois un patient pour revenir voir le
médecin qui l'a envoyé. `SendReferral` et `CompleteReferral` restent donc **deux
chemins de code séparés** qui n'appellent jamais la même logique de routage -
mutualiser les deux réintroduirait le péage sur le retour à la première
refactorisation. Un dossier clôturé entre-temps ne revient pas en file : un
résultat en retard ne doit pas ressusciter un dossier clos.

### Renvoi et clôture

1. Le médecin choisit une visite de sa file, un service destinataire et saisit
   des instructions.
2. `SendReferral`, en une transaction : crée `referrals` (`pending`), déplace la
   visite (`service_id`, nouveau `token`, `waiting`), insère
   `patient_history` (`referral_sent`), puis envoie un SMS au patient.
3. Le praticien destinataire voit le renvoi dans son panneau « Renvois en
   attente », saisit le résultat et **peut y joindre des fichiers**.
   `CompleteReferral` passe le renvoi en `done` et notifie le prescripteur.
4. Le prescripteur lit le résultat dans « Résultats reçus » et clôt la boucle
   avec **« Terminer »** (`CloseReferral` → `closed`). Le renvoi quitte alors ce
   panneau mais **reste dans l'historique du patient**.
5. En fin de prise en charge, **« Clôturer le dossier »** (`CloseVisit`) ferme
   l'épisode. L'action est **bloquée tant qu'un renvoi attend son résultat**, et
   le message le dit explicitement plutôt que de griser un bouton sans
   expliquer pourquoi.

Une visite `closed` sort de la file active - « Appeler le suivant » ne la
propose plus - mais **son dossier reste intégralement lisible** : la clôture ne
filtre jamais la lecture, seulement la file d'attente.

### Files et tickets

Les files repartent à 1 chaque matin, par service. **Patients et visiteurs
tirent dans la même séquence** : deux personnes ne voient jamais le même numéro
affiché sur `/board`. La règle « file du jour » est définie une seule fois
(`Visit::scopeInTodaysQueue`) et partagée par l'attribution des tickets, la file
du médecin et l'écran de salle d'attente.

## 10. Pièces jointes, caisse, ordonnances et rendez-vous

### Pièces jointes

PDF, JPG et PNG, 10 Mo maximum, 5 fichiers par résultat. **Le type et la taille
sont revérifiés côté serveur dans `StoreAttachment`** - le type MIME réel du
fichier reçu, pas l'extension annoncée : un formulaire se contourne, pas une
action.

Stockage sur le disque `attachments` (`storage/app/attachments`), donc sur le
volume Docker **`keneya_storage`**, persistant entre redéploiements. Aucun
stockage cloud : la connectivité du site ne le permet pas.

**Lecture et écriture depuis le dossier.** Une pièce jointe s'ajoute
directement au dossier d'un patient - depuis « Mes patients » côté `/service`,
depuis la vue globale des patients côté `/admin` - et plus seulement en réponse
à un renvoi. `attachments.patient_id` reste le point d'ancrage ;
`patient_history_id` et `referral_id` ne sont renseignés que pour une pièce
rattachée à un contexte précis.

**Chronologie unifiée.** Le dossier n'affiche pas des blocs séparés par nature
de document : consultations, renvois, ordonnances, conclusions, paiements et
pièces jointes forment **une seule frise triée par date**, découpée par passage.
Le service `App\Services\PatientTimeline` la construit pour les deux
interfaces, afin que médecin et admin lisent exactement la même histoire.

**Impression.** Chaque pièce jointe et chaque ordonnance porte une action
« Imprimer » qui ouvre un rendu autonome (`resources/views/print/`) déclenchant
`window.print()` - pas de génération PDF côté serveur pour cela. Le PDF
dompdf de l'ordonnance reste disponible en téléchargement côté médecin.

### Caisse : un rôle, une interface, un passage obligé

Dans cet hôpital, **les médecins n'encaissent jamais**. Un patient règle à la
caisse avant d'être orienté vers le service qui doit le prendre en charge. La
caisse coordonne financièrement l'ensemble des services : elle a donc son propre
rôle (`cashier`) et sa propre interface (`/caisse`), cloisonnée comme les trois
autres.

Les caisses sont des **services à part entière** (`services.kind = caisse`,
créés par le seeder) : même file, même token, même « Appeler le suivant » que
partout ailleurs - aucune mécanique parallèle à maintenir.

| Caisse | Ce qu'on y règle |
|---|---|
| **Caisse Ticket** | Le ticket de consultation, avant de voir un praticien. |
| **Caisse Services** | Un acte de plateau technique (échographie, laboratoire…). |

L'**accueil** est un service depuis la v3.2.5, pour la même raison : il a des
heures et du personnel. Une réceptionniste n'ayant aucun service de
rattachement, son créneau n'avait auparavant rien à désigner - elle n'était donc
de garde nulle part, et ne recevait jamais de notification. Comme la caisse, ce
n'est **pas une destination de soins** : `ServiceKind::NON_CARE_SLUGS` les tient
tous deux hors des menus d'orientation.

Depuis la v3.2.3, l'administrateur peut en **déclarer d'autres** : le menu
« Type » de « Services → Liste des services » liste tous les `service_kinds`,
caisse comprise, et `/caisse` construit **une section par service de type
caisse**. Une caisse créée à la main est donc réellement tenable, et non un
service mort dans la base. Le routage sous condition de paiement, lui, continue
de désigner les deux caisses nommées : quelle caisse encaisse quoi est une règle
métier, pas une conséquence du nombre de guichets.

Le routage sous condition de paiement s'appuie sur une seule colonne,
`visits.pending_next_service_id` :

- **À l'enregistrement**, la réceptionniste choisit le service clinique voulu,
  mais la `visits` créée pointe d'abord sur « Caisse Ticket », la destination
  choisie attendant dans `pending_next_service_id`.
- **Sur un renvoi vers un plateau technique**, la visite est routée vers
  « Caisse Services ». La ligne `referrals` garde, elle, la destination
  **médicale réelle** : la caisse est une étape de routage, jamais la
  destination d'un renvoi au sens clinique.
- **`ConfirmCaissePayment`** (« Confirmer et orienter ») enregistre le paiement,
  écrit une ligne `patient_history` (`payment_confirmed`), bascule
  `visits.service_id` sur `pending_next_service_id`, vide la colonne et
  **régénère un token dans la file cible**.

La caisse n'est jamais proposée comme **destination de soins** : ni dans la
liste des services de l'accueil, ni dans celle des renvois - la règle est
appliquée côté serveur (`Service::careServices()`, `SendReferral`), pas
seulement dans les listes déroulantes. En revanche, depuis la v3.2.3, elle est
proposée comme **service d'affectation** dans « Personnel → Personnels » :
affecter quelqu'un à un guichet est une décision d'organisation, que l'outil
n'a pas à trancher à la place de l'établissement.

Une installation qui **n'utilise pas** la caisse continue de fonctionner : sans
service `kind = caisse` en base, `RouteThroughCaisse` laisse le patient aller
directement au service choisi.

Montants en **FCFA sans décimales**, saisis librement à chaque encaissement :
aucune grille tarifaire n'existe dans le schéma. Une table `service_prices`
pourra venir plus tard si HFD veut figer des tarifs.

### Ordonnances

Texte libre pour cette version, comme convenu. Chaque ordonnance laisse une
ligne `patient_history` (`prescription`) et s'exporte en PDF via
`barryvdh/laravel-dompdf`. Un médecin ne peut télécharger que **ses propres**
ordonnances.

### Conclusion de consultation

Distincte de l'ordonnance, qui reste dédiée aux médicaments : un champ libre
« Conclusion de la consultation » écrit une ligne `patient_history`
(`consultation_conclusion`) rattachée au passage en cours. Pas de table
supplémentaire - la conclusion prend place dans la même frise que le reste.

### Rendez-vous

Fixés depuis `/service`, **pour tout patient de « Mes patients »** et plus
seulement en fin de consultation immédiate. La section « Mes rendez-vous »,
juste sous « Mes patients », liste les rendez-vous à venir du médecin connecté,
triés par date. La section « Rendez-vous du
jour » de `/reception` liste les rendez-vous, avec **« Orienter le patient »** :
à l'arrivée, une nouvelle `visits` s'ouvre directement dans la file du service
prévu, sous l'identité existante - pas de réenregistrement pour quelqu'un que
l'hôpital connaît déjà. `no_show` distingue le patient qui ne s'est pas
présenté d'une annulation volontaire.

## 11. Portail patient, impression et suppression de dossier

### Portail patient - lien permanent, code à quatre chiffres

Le patient consulte ses documents et ses rendez-vous depuis son téléphone, sur
`/mes-documents/{portal_token}`. **Le lien ne périme jamais**, c'est la demande
du porteur du projet ; la sécurité repose donc sur deux couches et non sur une :

1. `patients.portal_token` est un **UUID non devinable** - jamais le
   `patient_code`, jamais l'`id` auto-incrémenté. Il faut déjà connaître ce lien
   précis pour tenter quoi que ce soit.
2. `patients.access_code`, quatre chiffres générés à la création du dossier et
   **remis de vive voix à l'accueil** (et imprimés sur le ticket), n'a que
   10 000 combinaisons : le portail verrouille donc le dossier **15 minutes
   après 5 tentatives ratées** (`portal_access_attempts`), et la route est
   limitée par `throttle:10,1`.

Le verrouillage est volontairement **temporaire** : un patient qui se trompe
deux fois ne doit pas rester bloqué à vie devant ses propres documents.

La page **n'affiche rien** - pas même le nom du patient - tant que le code n'a
pas été validé, et l'accès accordé vit en session côté serveur, pas dans une
propriété Livewire qui voyagerait avec le client. Le SMS contenant le lien part
**sur demande explicite** depuis `/reception` ou `/service` (« Envoyer le lien de
mes documents »), jamais automatiquement à chaque événement du dossier ; le code
ne voyage jamais par SMS.

### Impression du ticket

Bouton « Imprimer le ticket » à deux endroits dans `/reception` : juste après
l'enregistrement, et sur chaque ligne des « Passages du jour » pour une
réimpression. La vue `resources/views/reception/print-ticket.blade.php` est une
page autonome - ni barre de navigation, ni navigation verticale - et l'impression
est déclenchée par `window.print()` côté navigateur, sans PDF serveur :
l'imprimante est déjà branchée au poste de la réceptionniste.

Largeurs exprimées en `ch` et non en millimètres : le ticket reste lisible sur
une imprimante thermique 58-80 mm comme sur une feuille A4.

| Ticket | Contenu |
|---|---|
| **Patient** | Hôpital, `patient_code`, numéro de token, service, date et heure, et le code personnel à quatre chiffres. |
| **Visiteur** | Hôpital, numéro de token, **nom du patient visité**, service, date et heure. Ni dossier ni code personnel. |

### Visiteurs rattachés à un patient

`visitors.patient_id` relie un visiteur au patient qu'il vient voir. Le champ
est **obligatoire dès qu'un service clinique est choisi** (la règle est appliquée
dans `RegisterVisitor`, pas seulement dans le formulaire) et reste facultatif
pour un service non clinique - une démarche administrative n'a pas de patient à
visiter. Le nom du patient visité apparaît sur `/board` et dans les passages du
jour, pour que le personnel sache qui orienter où.

### Suppression d'un dossier patient

Opération sensible, réservée à `admin` et à `/admin` - jamais atteignable depuis
`/reception`, `/service` ou `/caisse`, y compris en appelant le composant
directement.

- Suppression **réelle et en cascade** : `visits`, `referrals`,
  `patient_history`, `attachments` (fichiers compris), `payments`,
  `prescriptions`, `appointments`, `companions`. Un visiteur, lui, est
  **détaché** et non supprimé : sa fiche documente une entrée dans l'hôpital,
  ce n'est pas une donnée du dossier médical.
- Confirmation en deux temps : l'admin **retape le `patient_code` exact** et
  saisit un **motif obligatoire**. Un simple « Êtes-vous sûr ? » ne protège de
  rien - on clique oui par réflexe.
- **Jamais journalisée dans `patient_history`** (elle disparaîtrait avec le
  reste) : l'opération est consignée **exclusivement dans le journal d'audit**,
  qui ne référence pas le patient par clé étrangère et lui survit donc. L'entrée
  conserve le `patient_code`, le nom, l'admin responsable, la date et le motif.
  Un test vérifie explicitement cette survie.
- `patient_history` étant append-only (son observer refuse toute suppression),
  cette cascade est **la seule exception prévue** : elle passe sous le modèle en
  SQL direct plutôt que d'affaiblir le garde-fou pour tout le monde.

## 12. Catalogues administrables et interfaces générées

### Types de service

`services.kind` était un `enum` codé en dur : chaque nouveau type demandait une
migration, donc un développeur. Les types vivent désormais dans **`service_kinds`**,
gérés depuis « Services → Types de service » dans `/admin`.

Le seul comportement porté par un type est **`requires_payment_gate`** : un renvoi
vers un service de ce type passe par la **Caisse Services** avant réalisation.
C'est cet indicateur, coché par l'admin, qui remplace la comparaison codée en dur
sur « plateau technique ».

> **Changement de comportement à connaître.** Avant, *tout* renvoi passait par une
> caisse. Désormais seul un renvoi vers un type coché passe par la caisse : les
> trois types d'origine sont convertis avec `requires_payment_gate = true`
> **uniquement pour « Plateau technique »**, donc un avis inter-services entre
> deux services cliniques ne se paie plus. Décocher la case sur un type suffit à
> supprimer l'étape, sans toucher au code.

Trois types sont posés à l'installation - Clinique, Plateau technique, Caisse - et
restent structurants : le code les reconnaît par leur **`slug`**, jamais par leur
libellé. Ils sont donc renommables (« Plateau technique » peut devenir « Examens
complémentaires ») mais pas supprimables. Un type créé ensuite n'a aucun
comportement codé, et n'est supprimable que s'il n'est utilisé par aucun service.

### Types de personnel

Nouvelle section « Personnel → Types de personnel » dans `/admin`. **Deux chemins
distincts**, et l'admin voit lequel il emprunte :

1. **`matched_role` renseigné** (`doctor`, `receptionist`, `cashier` - jamais
   `admin`, qui n'a pas vocation à être multiplié) : le type réutilise telle
   quelle une des quatre interfaces déjà construites et testées. Rien ne change
   dans leur fonctionnement, et les personnes continuent d'être créées dans les
   tables `doctors` / `receptionists` / `cashiers` comme avant.

   Depuis la v3.2.3, c'est le type choisi dans « Personnel → Personnels » qui
   décide de tout cela : son `matched_role` désigne à la fois le rôle Spatie
   synchronisé et la table de rattachement (`doctors`, `receptionists`,
   `cashiers`, ou `staff_members` s'il est vide). Sans cette correspondance,
   élargir le menu aurait créé des comptes portant le rôle « médecin » avec un
   type « Caissier ». **Un compte ne change pas de rôle en changeant de type** :
   le formulaire refuse explicitement - changer de rôle change de table, donc
   d'interface et d'historique - et il faut passer par la suppression du
   rattachement, avec ses garde-fous.

2. **`matched_role` vide** : le type reçoit une interface **composée de briques
   existantes** sur `/staff/{slug}`, pilotée par ses `capabilities` :

| Capacité | Ce qu'elle fait apparaître |
|---|---|
| `has_queue` | File d'attente du service, « Appeler le suivant » |
| `can_send_referral` | Envoyer un patient vers un autre service |
| `can_receive_referral` | Renvois reçus et saisie du résultat |
| `can_view_dossier` | Dossier patient en lecture (même frise unifiée) |
| `can_accept_payment` | Encaissement d'un acte de son service |
| `can_close_visit` | Clôture d'un dossier depuis la file |
| `can_print_ticket` | Impression du ticket depuis la file |
| `has_care_tasks` | Soins programmés des patients hospitalisés |

### Capacités obligatoires et optionnelles

Depuis le v3.2.2, **tous** les types portent des capacités, y compris ceux
adossés à un rôle. La distinction n'est plus « avec ou sans rôle » mais
**obligatoire ou optionnelle** :

- les capacités **obligatoires** font le rôle et vivent dans le code
  (`StaffType::ROLE_CAPABILITIES`), jamais en base : un médecin sans file
  d'attente ni dossier patient n'est plus un médecin. Elles apparaissent
  cochées et verrouillées dans le formulaire, et `normalizeCapabilities()` les
  réintroduit à l'enregistrement - les décocher depuis le navigateur, ou en
  forger une par requête directe, ne change rien à ce qui est écrit ;
- les capacités **optionnelles** sont de vrais choix d'organisation : cet
  hôpital fait-il prescrire ses sages-femmes, hospitaliser ses urgentistes ?
  L'admin tranche, type par type.

| Rôle | Obligatoires | Optionnelles |
|---|---|---|
| `doctor` | file, envoi et réception de renvoi, dossier, clôture | ordonnance, rendez-vous, hospitalisation, soins |
| `receptionist` | dossier, enregistrement d'un patient | visiteur, rendez-vous, impression du ticket |
| `cashier` | file, encaissement | impression du reçu |

Un type **sans rôle** n'impose rien : tout le catalogue y reste optionnel.

Les sections de `/service`, `/reception` et `/caisse` se plient à ces capacités
par le **même mécanisme** que `/staff/{slug}` - `User::hasCapability()` - et
non par une seconde règle à tenir à jour. Masquer une section ne suffisant
jamais, le trait `RequiresCapability` refuse aussi côté serveur : un composant
Livewire s'appelle sans passer par le menu.

`users.staff_type_id` rattache un compte à un type précis quand plusieurs
partagent le même rôle (« Médecin » et « Sage-femme » adossés à `doctor`) ;
laissé vide, la résolution retombe sur le type d'origine du rôle, de sorte
qu'un compte antérieur au v3.2.2 en ait toujours un.

**Une seule route pour tous ces types** - `Route::get('/staff/{slug}', StaffInterfaceController::class)`
- jamais une route générée à la volée : `route:cache` ne verrait pas des routes
déclarées depuis la base. Le `slug` n'est qu'une valeur lue en base par une route
qui existe déjà dans le code.

`EnsureRoleScope` gère ce cas dynamiquement (`role.scope:staff`) : il compare le
type du compte connecté au slug demandé, avec la **même redirection propre** que
pour les quatre rôles fixes. Un slug inconnu est traité comme un refus, jamais
comme un 404 - on ne laisse pas deviner quels types existent en tapant des URL.

Une capacité non cochée n'affiche pas une section vide : elle n'affiche rien, et
**le composant correspondant refuse dès son montage**. La capacité est un
garde-fou serveur, pas un filtre d'affichage.

`staff_members` rattache une personne à son type et à son service, sur le modèle
de `doctors`. Un acte posé par un membre du personnel générique est signé par
`*_staff_member_id` - **jamais** par `*_doctor_id` : on ne fabrique pas de faux
médecins dans un dossier. Le petit objet `App\Support\Caregiver` traduit une
fois pour toutes « qui agit » vers le bon couple de colonnes, ce qui évite de
dupliquer chaque Action en deux versions.

### Ce que ça ne fait pas

Ce n'est **pas un générateur de code** : c'est un assemblage de briques
existantes. Un métier qui demande une logique entièrement nouvelle (gestion de
stock pharmaceutique, dossier de kinésithérapie structuré) demande toujours du
développement dédié.

Au moment de créer un type sans rôle, l'admin voit **un aperçu des sections qui
apparaîtront réellement**, recalculé à chaque case cochée : il doit comprendre ce
qu'il vient de créer avant qu'un membre du personnel ne s'y connecte. Et les
combinaisons de capacités sont couvertes par une **suite paramétrée**, pas par un
test manuel par métier - sinon la surface fonctionnelle grandirait plus vite que
ce qui est vérifié.

## 13. Hospitalisation et planning de soins

Distinct du système de file d'attente : un patient hospitalisé n'attend pas un
tour avec un ticket, il occupe un lit et reçoit des soins programmés sur
plusieurs jours.

### Salles et capacité

Catalogue administrable dans « Hospitalisation → Salles ». **L'occupation n'est
jamais stockée** : elle se compte à la volée sur les hospitalisations actives -
un compteur en base finit toujours par mentir sur ce qu'il prétend compter.

À l'admission, une salle déjà pleine **avertit sans bloquer** : l'admission passe
après confirmation explicite. Une urgence hospitalière dépasse parfois la
capacité nominale, et un blocage strict serait dangereux plutôt que protecteur.
Une salle qui héberge encore quelqu'un n'est pas supprimable.

### Admission et sortie

L'admission se déclenche depuis `/service` sur un patient appelé. Elle **clôture
sa visite en cours** - il quitte la file d'attente - et ouvre une
`hospitalizations`, les deux dans la même transaction : un patient hospitalisé
qui resterait dans une file serait une incohérence visible au tableau
d'affichage. Une ligne `patient_history` (`hospitalization_admitted`) la place
sur la même frise chronologique que le reste du dossier.

La sortie est **bloquée tant qu'il reste des soins `pending`**, avec un message
explicite : clôturer silencieusement un plan de soins inachevé reviendrait à
effacer la trace de ce qui n'a pas été fait. Un soin peut être marqué « manqué »
- il reste alors visible dans le dossier, qualifié plutôt qu'effacé.

### Planning de soins

Le médecin prescrit le soin et sa récurrence ; **il n'assigne pas nommément un
infirmier à chaque occurrence**. La tâche devient visible pour tout membre du
personnel dont le type porte `has_care_tasks` et qui est **de garde sur ce
service au moment présent**, d'après les `schedules` déjà en place - s'appuyer
sur les rotations enregistrées plutôt que dupliquer une logique d'assignation qui
casserait au premier remplacement d'équipe.

La **récurrence est résolue à la création**, comme pour la génération groupée de
planning : « toutes les 8 h pendant 3 jours » produit neuf lignes `care_tasks`,
chacune marquable individuellement comme faite ou manquée. Un garde-fou refuse
une prescription au-delà de 200 administrations.

Le médecin peut désigner quelqu'un nommément dans un cas précis
(`assigned_to_user_id`), mais c'est une **priorité d'affichage, pas une
restriction d'accès** : un soin ne doit jamais rester bloqué parce que la
personne désignée est absente.

Un soin dont l'heure est passée sans être marqué s'affiche **en retard**, calculé
à l'affichage - pas de bascule automatique de statut, donc aucune dépendance à un
scheduler dans cette version. La traçabilité réelle vient de
`completed_by_user_id`, renseigné au moment du geste, et chaque soin réalisé
écrit une ligne `patient_history` (`care_task_completed`) sur la frise du dossier.

### Corriger ou annuler un soin

Une prescription se corrige : mauvais dosage saisi, mauvaise heure, soin arrêté
parce que l'état du patient a changé, administration notée par erreur. Depuis la
v3.2.3, « Voir et corriger les soins » ouvre la liste des soins d'un séjour dans
`/service`, chacun **corrigeable** (type, heure, instructions, assignation) et
**annulable**.

**Rien n'est effacé.** Une correction est journalisée avec son avant et son
après ; une annulation pose le statut `cancelled` avec son motif - obligatoire -
son auteur et son heure. Les deux écrivent aussi une ligne `patient_history`
(`care_task_revised`, `care_task_cancelled`) : le dossier d'un patient ne se
réécrit pas, il s'augmente.

**Qui peut le faire** : le médecin prescripteur, ou un médecin du même service
porteur de `has_hospitalization` - celui qui tient le service quand le
prescripteur n'est pas de garde. L'infirmier exécute un soin et peut le marquer
« manqué » ; décider de son arrêt ne relève pas de lui. Le refus est explicite,
et le cloisonnement par service précède le contrôle d'accès : un soin d'un autre
service n'existe pas.

**Un soin annulé ne compte plus nulle part** - ni dans « n soin(s) en attente »,
ni dans les soins qui bloquent une sortie d'hospitalisation, ni sur la feuille de
garde du personnel (scope `CareTask::countable()`). Il reste lisible au dossier,
barré, avec son motif. Annuler corrige le compte ; ça ne le maquille pas.

### Suppressions dans `/admin`

Chaque table d'administration porte une **action de suppression sur chaque
ligne**, rendue par une icône (`<x-delete-action>`). Elle n'est jamais masquée,
même quand la suppression sera refusée : un bouton qui disparaît sans
explication laisse l'administrateur devant une case vide, sans savoir si le
droit lui manque ou si l'action n'a jamais existé. **C'est le serveur qui
refuse, en disant pourquoi** - un message visible immédiatement, y compris sur
tablette où aucune infobulle ne s'affiche au survol.

Les refus en place :

| Objet | Refusé quand |
|---|---|
| Type de service | Il est posé à l'installation, ou encore porté par un service |
| Type de personnel | Une personne le porte encore |
| Service | Il compte encore des médecins ou des passages |
| Salle | Elle héberge une hospitalisation en cours |
| Type de soin | Des soins programmés s'y rattachent |
| Médecin, réceptionniste, caissier, personnel dédié | Le compte a laissé une trace au dossier d'un patient (entrée d'historique, renvoi, ordonnance, rendez-vous, encaissement, pièce jointe, soin) |

Pour un compte du personnel, `DeleteStaffAccount` applique une règle simple :
**ce qui a signé un acte n'est pas effaçable.** Les colonnes `*_doctor_id` et
`*_staff_member_id` sont ce qui dit qui a fait quoi ; les vider rendrait
anonymes des consultations et des ordonnances déjà signées. Reste donc
supprimable ce qui doit l'être : le compte créé par erreur, ou jamais utilisé.
Le planning personnel, lui, part avec le compte - il n'appartient pas au dossier
patient. Un médecin rattaché à plusieurs services ne perd que le rattachement
retiré ; son compte ne disparaît qu'avec le dernier.

La suppression est journalisée **avant** l'opération, dans l'audit, qui lui
survit - même principe que pour la suppression d'un dossier patient.

## 14. Notifications, profil et relèves

### Cloche de notification

Une cloche dans la barre de marque, sur les cinq interfaces, avec le nombre de
non-lues en badge et `wire:poll` à dix secondes - même intervalle que le reste
de l'application, **pas de WebSocket** dans cette version, pour la même raison
qu'ailleurs : une dépendance de plus à faire tourner sur le VPS pour un gain que
l'usage n'a pas encore réclamé. Si le délai s'avère trop long en service réel,
Laravel Reverb est la suite logique.

Depuis la v3.2.5 l'intervalle est de **cinq secondes**, et toutes les listes de
travail le partagent. Trois écrans portaient encore leur propre rythme (10 s,
15 s, 30 s) : un soin prescrit mettait ainsi jusqu'à trente secondes à
apparaître chez l'infirmier, et la notification arrivait bien avant la tâche
qu'elle annonçait. Une seule valeur à régler - `KENEYA_POLL_INTERVAL` - si la
charge devenait sensible sur le VPS.

**Cinq déclencheurs**, tous ciblés sur le personnel *effectivement de garde* :

| Événement | Qui est prévenu |
|---|---|
| Patient ou visiteur entre dans une file | Le personnel de garde sur ce service |
| Résultat de renvoi reçu | Le **médecin prescripteur** seul (`referrals.from_doctor_id`), pas son service |
| Soins prescrits | Le personnel de garde du service porteur de `has_care_tasks` |
| Rendez-vous qui approche | Le médecin concerné |
| Planning publié | Chaque personne concernée par les lignes créées |

Le ciblage passe par **`App\Services\OnDutyRoster`**, unique règle de garde de
l'application. `User::isOnDutyFor()` répondait déjà à « moi, maintenant ? » ; il
manquait la question inverse - « eux, maintenant ? ». Les deux lisent la même
table et la même fenêtre horaire. `schedules` est le bon support et le seul : il
porte à la fois la personne et le service, quelle que soit sa table de
rattachement - médecin, réceptionniste, caissier ou personnel générique y
entrent de la même façon.

L'entrée en file est captée par un **observateur sur `Visit`**, pas par un appel
dans chaque action : un patient entre dans une file par six chemins
(enregistrement, nouvel épisode, arrivée sur rendez-vous, sortie de caisse,
renvoi envoyé, retour d'un renvoi complété). Les énumérer un à un aurait garanti
d'en oublier un au prochain chemin ajouté. L'observateur écoute **après le
commit** : une transaction annulée ne laisse pas derrière elle la notification
d'un patient qui n'est jamais entré.

**Le son** ne part qu'à l'arrivée d'une notification, jamais à chaque sondage :
le composant garde le dernier total connu et n'émet l'événement
`notification-nouvelle` que si le nombre augmente. La comparaison est faite dans
le composant plutôt que dans le navigateur - c'est la même règle, mais celle-ci
se teste.

Le fichier livré est `public/sounds/notification.wav`, une cloche courte générée
avec le dépôt. `notification_sound_url()` retient le **premier format présent**
parmi `.mp3`, `.ogg`, `.wav` : déposer `public/sounds/notification.mp3` la
remplace sans toucher une ligne de code, comme pour le logo. Aucun fichier du
tout, et la cloche reste muette sans erreur.

Les navigateurs refusent de jouer un son tant que la page n'a reçu aucune
interaction. Le personnel s'étant déjà connecté, la condition est remplie en
pratique - mais `play()` est quand même enveloppé dans un `catch` : une cloche
muette vaut mieux qu'une erreur JavaScript dans la console d'un poste de soins.

### Rappel de rendez-vous

Seul déclencheur qui ne répond à aucun geste humain, donc le seul qui exige une
tâche périodique : `keneya:rappels-rendez-vous`, toutes les quinze minutes.
`appointments.reminder_sent_at` garantit **un rappel et un seul** - la commande
repasse sur une fenêtre qui se recouvre largement.

Le délai est réglable via la table `settings`
(clé `appointment_reminder_minutes`, 60 minutes par défaut), comme
`hospital_name` - pas codé en dur : une consultation programmée ne se prépare
pas comme un bloc.

### Carte de profil

L'icône de déconnexion isolée était la seule action du coin supérieur droit, ce
qui n'y laissait aucune place pour la seule chose qu'un agent ait besoin de faire
sur son propre compte. Elle cède la place à un **avatar aux initiales** - pas de
photo à stocker ni à redimensionner - qui ouvre une carte : nom, fonction,
service s'il y en a un, adresse e-mail, puis « Changer le mot de passe » et
« Se déconnecter ».

Le changement de mot de passe vérifie l'actuel **côté serveur** (`Hash::check`),
impose huit caractères avec lettres et chiffres - rien de plus sévère, un mot de
passe impossible à retenir finit écrit sur un papier collé à l'écran - puis
ferme les autres sessions (`Auth::logoutOtherDevices()`) en gardant la courante.
L'événement `mot_de_passe_change` est journalisé ; **ni l'ancien mot de passe ni
le nouveau n'y figurent**, sous aucune forme.

> **Correctif d'infrastructure au passage.** `AuthenticatesSessions` figurait
> dans la liste de priorité des middlewares mais n'était jamais ajouté au groupe
> `web`. Sans lui, `logoutOtherDevices()` ne ferme rien : il réécrit un marqueur
> que personne ne lit, et les sessions ouvertes ailleurs continuent de
> fonctionner. Il est désormais appliqué. Les sessions déjà ouvertes au moment du
> déploiement ne sont pas coupées : sans marqueur, le middleware le pose et
> laisse passer.

### Le créneau sans service

Le formulaire présente le service comme facultatif, et il l'est pour lire son
propre planning. Mais un créneau muet ne rendait de garde pour **aucun** service :
ni notification, ni soin visible, sans que rien ne le dise. Un planning généré
avec « Service : - Aucun - » - le choix par défaut - était donc entièrement
inopérant.

Depuis la v3.2.5, un tel créneau vaut pour les **services de rattachement** de la
personne : un médecin de Médecine Générale planifié « 08h à 14h, aucun service »
est de garde à Médecine Générale, ce qui est la seule lecture raisonnable. Il ne
déborde pas pour autant sur les autres services.

Une réceptionniste ou un caissier n'ont pas de service de rattachement : leur
créneau doit nommer le service (Accueil, Caisse Ticket…). C'est précisément ce
pour quoi l'accueil est devenu un service.

La règle vit en un seul endroit, `App\Services\OnDutyRoster` - « moi,
maintenant ? » comme « eux, maintenant ? » y passent, `User::isOnDutyFor()`
compris.

### Qui apparaît dans les plannings

Les deux formulaires de « Personnel → Plannings » interrogeaient les **rôles
Spatie**. Un type de personnel sans rôle - un infirmier, un brancardier - n'en
porte aucun : il était donc introuvable dans les menus, et **personne ne pouvait
lui poser un créneau**. Or c'est le planning qui décide de sa garde, donc de
tout ce qu'il voit. C'était la cause réelle des « soins programmés invisibles
côté infirmier ». Le formulaire jour par jour oubliait en plus les caissiers.

Les deux formulaires et le filtre partagent désormais **une seule source**,
`User::staff()` : tout compte porteur d'un rattachement, quelle que soit sa
table (`doctors`, `receptionists`, `cashiers`, `staff_members`). Le rattachement
est le bon critère - il existe pour les quatre chemins, là où le rôle n'existe
que pour trois. Un administrateur, qui n'exerce dans aucun service, reste
naturellement hors de ces menus.

Le libellé retombe sur le nom du type quand il n'y a pas de rôle : « Bakary
Coulibaly (Infirmier) » plutôt qu'une parenthèse vide.

### Suppression en masse des créneaux

Une génération groupée produit facilement vingt-deux créneaux ; les retirer un
par un n'est pas praticable. Une case par ligne, une case « tout sélectionner »
en tête, et une barre d'actions qui **n'apparaît que lorsqu'une ligne est
cochée** - une barre toujours présente mais inactive n'apprend rien et vole de
la place sur tablette.

Deux garde-fous, tous deux couverts par un test :

- « tout sélectionner » ne porte que sur **ce qui est affiché** : une case qui
  emporterait aussi des créneaux hors écran est un piège, pas un raccourci ;
- une sélection devenue invisible après un changement de filtre **n'est pas
  supprimée** - on n'efface pas ce que l'administrateur ne voit plus.

La suppression est journalisée en une ligne récapitulative (« 4 créneau(x)
supprimés en une fois : Dr Modibo Keita (4) »), pas en quatre lignes séparées.

La liste se rafraîchit désormais après une génération groupée : les deux
formulaires sont deux composants voisins, et sans l'événement
`plannings-mis-a-jour`, l'administrateur générait vingt-deux créneaux et voyait
un tableau vide jusqu'au rechargement suivant.

### Notes de relève entre équipes

La rotation du personnel sur un patient hospitalisé est **déjà réglée
structurellement**, et c'est vérifié par un test plutôt que supposé :
« Patients hospitalisés » n'est filtré que par service, jamais par médecin
admettant, et les soins sont ouverts à tout le personnel de garde -
l'assignation nommée reste une priorité d'affichage. Aucun mécanisme de
transfert explicite n'est donc nécessaire.

Ce qui manquait est d'un autre ordre : ce qu'une équipe a besoin de dire à la
suivante et qu'aucune colonne ne capture. `handoff_notes` porte du texte libre,
daté et signé, accessible depuis la fiche d'un patient hospitalisé dans
`/service` et depuis la section « Relèves » de `/staff/{slug}` pour les types
porteurs de `has_care_tasks`. Chaque note écrit une ligne `patient_history`
(`handoff_note`) : elle fait partie du parcours du patient, pas d'un carnet
parallèle.

Hors garde, une note **se lit mais ne s'écrit pas** - même règle que les soins,
et dite à l'écran plutôt que subie. Pas de champ « lu par », pas d'accusé de
réception : une note visible suffit, et exiger une lecture confirmée ajouterait
une file de plus à traiter pour un besoin que l'usage n'a pas montré.

## 15. Ordonnances et documents imprimés

### L'ordonnance s'écrit ligne par ligne

L'ordonnance était une zone de texte unique, rendue telle quelle à l'impression.
Elle s'écrit désormais **ligne par ligne** : le médecin remplit médicament,
posologie et durée, puis « Ajouter une ligne » en ouvre une autre, numérotée.
Vingt lignes au maximum - un clic resté appuyé n'en crée pas mille.

Les lignes ouvertes mais laissées vides sont écartées à l'enregistrement : le
médecin peut avoir ouvert une ligne de trop. Une posologie sans médicament est
refusée - le médicament fait la ligne. La dernière ligne ne disparaît pas quand
on la retire, elle se vide : sans champ, le formulaire n'aurait plus rien à
remplir.

Les lignes vivent dans une colonne JSON (`prescriptions.lines`) plutôt que dans
une table dédiée : elles ne sont jamais interrogées seules, toujours lues avec
leur ordonnance. Même choix que pour `staff_types.capabilities`.

**Une ordonnance porte ses lignes, ou son ancien texte, jamais les deux.**
`Prescription::lignes()` est le seul point de lecture : il rend les lignes
telles quelles, ou découpe le texte libre d'une ordonnance antérieure en lignes
sans posologie ni durée distinctes. L'affichage et l'impression n'ont ainsi
qu'un seul chemin à connaître.

### Les documents imprimés

Trois documents sortent de l'application, tous rendus en HTML : le **ticket** et
le **reçu** au format ticket (44 caractères de large, du 58-80 mm au A4 sans
supposer un format précis), et l'**ordonnance** en A4, à l'écran comme en PDF via
dompdf.

L'ordonnance porte l'établissement en tête, le numéro de dossier à droite où
l'œil le cherche, les quatre renseignements du passage sur une ligne, puis le
tableau numéroté des lignes. En pied : le cachet de l'établissement à gauche,
la signature puis le cachet du médecin à droite, au-dessus du trait nommé.
**Chaque image absente laisse son cadre pointillé** plutôt que de disparaître :
une ordonnance s'imprime pour un médecin qui n'a rien déposé, et le cadre dit
alors où apposer le tampon à la main.

Rien n'y est laissé au navigateur côté PDF : **dompdf ne connaît ni flexbox ni
grid**, la mise en page repose donc sur des tableaux et des marges.

#### Les trois images sont encodées dans le document (v3.3.1)

Le cachet de l'établissement, la signature et le cachet du médecin vivent sur
le disque `signatures`, **hors de `public/`** : une signature de praticien ne
s'attrape pas en devinant une URL. Elles n'ont donc aucune adresse, et le
chemin de fichier qui suffisait à dompdf ne voulait rien dire pour un
navigateur - la vue imprimable affichait une case vide là où le PDF montrait le
cachet.

`Doctor::fichierEncode()` rend désormais l'image **encodée en source de
données**. Une seule forme sert les deux rendus, aucune route nouvelle n'est
ouverte, et l'image est présente au moment où l'on appuie sur Imprimer - une
image encore en cours de chargement ne part pas à l'imprimante. C'est la raison
d'être de `PrescriptionPdfData` : le patient doit voir la même ordonnance que
son médecin, quel que soit le rendu.

**Les images sont ramenées à la taille utile au dépôt (v3.3.1).**
`StoreSignatureImage::COTE_MAX` borne le plus long côté à 1200 px. Un cachet
fait cinq centimètres de large sur une ordonnance ; à 300 points par pouce cela
fait six cents pixels, et 1200 laisse donc le double de ce que le papier peut
rendre. Les proportions sont conservées - un cachet rond ne doit pas devenir
ovale - la transparence aussi pour PNG et WebP, et **une image déjà assez petite
n'est jamais agrandie** : agrandir un scan ne lui ajoute aucun détail.

Une réduction qui échoue laisse passer l'original plutôt que de refuser le
dépôt : c'est la même règle que partout ici - l'absence d'une signature ne doit
jamais empêcher d'imprimer une ordonnance.

> **Les images déjà déposées ne sont pas retouchées.** La règle vaut pour les
> dépôts à venir. Un cachet versé avant la v3.3.1 garde sa taille jusqu'à ce
> qu'on le redépose depuis l'administration ou la carte de profil.

**`gd` est indispensable au PDF.** dompdf s'en sert pour toute image qu'il
embarque, le logo de l'en-tête compris. Sans elle, la génération s'arrêtait sur
« The PHP GD extension is required » et aucune ordonnance ne sortait en PDF.
L'extension est maintenant installée par l'image Docker et réclamée par
`composer install` : une installation qui en manque échoue tout de suite, et
non à la première impression.

## 16. Langage visuel

La feuille de style est unique, servie telle quelle depuis `public/` : aucun
pipeline de build front, le serveur peut n'avoir aucune connectivité internet.
Son ordre est fixe : jetons, base, primitives partagées, puis composants métier.
**Un composant ne redéfinit jamais une couleur ni un espacement en dur** - il
puise dans les jetons.

| Famille | Jetons |
|---|---|
| Typographie | `--texte-xs` … `--texte-2xl`, base à 14px |
| Espacement | `--e1` … `--e10`, échelle de 4px |
| Rayons | `--rayon-sm` (8px), `--rayon`, `--rayon-lg` (12px), `--rayon-pilule` |
| Élévation | `--ombre-1` à `--ombre-3`, de la bordure appuyée au panneau flottant |
| Mouvement | `--duree`, `--duree-lente`, `--courbe`, `--transition` |

Le mouvement est court (140 ms) et décéléré : sur une tablette, une transition
longue donne l'impression que l'application rame. **Le survol enrichit, il ne
conditionne jamais** - rien de ce qu'il apporte n'est nécessaire pour utiliser
l'écran au doigt. `prefers-reduced-motion` coupe l'ensemble.

L'anneau de focus est unique pour toute l'application.

### La palette est celle de Keneya-DME (v3.3.0)

Un agent qui passe d'un produit à l'autre ne doit pas avoir l'impression de
changer de logiciel. Le marine et la sarcelle ont donc laissé la place au **bleu
clinique** et au **vert keneya** du DME, et l'échelle de gris à l'ardoise
légèrement bleutée qui les accompagne.

Les noms de jetons (`--bleu*`, `--sarcelle*`) sont conservés : deux mille lignes
de règles s'y réfèrent, et les renommer aurait noyé le changement de palette
dans un diff illisible. Seules les valeurs changent, et tout suit.

Les rôles, eux, ne changent pas : **ce qui est bleu se clique, ce qui est vert se
remarque**. Deux conséquences visibles partout :

- **l'entrée active de la barre latérale est un aplat bleu pâle**, jamais un pavé
  saturé ; le repérage que faisait la couleur pleine est repris par la graisse du
  libellé, qui monte à 600 quand les autres restent à 500 ;
- **la couleur pleine est réservée au bouton primaire**, un seul par écran. Le
  bouton de tous les jours est blanc et bordé.

Une carte est posée par sa bordure, pas par son relief : coins à 12px, ombre
presque effacée, en-tête séparé du corps par un filet qui traverse toute la
carte. Sur une page qui empile dix cartes, dix ombres marquées font un relief de
carton ondulé.

Les documents imprimés suivent la même substitution - ils portent leur propre
feuille de style, sans jetons, et seraient restés au marine pendant que l'écran
passait au bleu. Le **moniteur de salle d'attente**, lui, garde son fond sombre
et ses teintes réglées pour une lecture à cinq mètres : un écran clair y
deviendrait un projecteur.

### La navigation ne fait plus attendre (v3.3.0)

**Déplier un groupe de sections ne passe plus par le serveur.** Les
sous-sections sont toujours rendues, Alpine les montre ou les cache, et le
serveur ne donne plus que l'état de départ - celui qui garantit que le groupe de
la section courante s'ouvre au chargement.

**Changer de section pose le repère immédiatement**, avant même la réponse. Un
filet de progression apparaît au-delà de cent millisecondes - en deçà, la section
est déjà là et un éclair de barre ne ferait que clignoter. L'entrée que l'on
quitte s'efface le temps de l'échange : jamais deux entrées actives à la fois.

**Le fil d'Ariane mène quelque part (v3.3.0).** Ses maillons étaient du texte
inerte. Le premier porte le nom de l'espace et ramène à sa première section ; un
maillon intermédiaire porte un intitulé de famille de la barre latérale et mène à
la première section de cette famille ; le dernier est la page courante et n'est
donc pas une cible. Ce sont des boutons et non des liens : une section n'a pas
d'adresse propre, c'est un état de la barre latérale. **Aucun maillon ne sort de
l'espace courant** - un rôle, une interface.

### Le logo

Le logo est fourni par le porteur du projet. Ses deux fichiers d'origine sont
versionnés tels quels dans `public/images/` :

| Fichier | Contenu |
|---|---|
| `keneya-logo-source.svg` | Logo complet : monogramme + « KƐNƐYA WORKFLOW » |
| `keneya-icone-source.svg` | Monogramme seul |

Ce ne sont pas des dessins vectoriels : chacun n'est qu'une **image matricielle
encodée en base64** dans une balise `<image>` - 506 Ko et 1,3 Mo. Les servir tels
quels ferait passer 1,8 Mo sur le réseau de l'hôpital au premier chargement. On
en dérive donc, une fois pour toutes, les fichiers réellement servis :

| Fichier | Où | Pourquoi |
|---|---|---|
| `keneya-logo.png` | Carte de connexion | Fond clair |
| `keneya-logo-clair.png` | Moniteur de salle d'attente | Fond sombre |
| `keneya-icone.png` | - | Monogramme, fond clair |
| `keneya-icone-claire.png` | Barre de navigation, décor de connexion | Fond sombre |
| `keneya-icone-impression.png` | Ticket, reçu, ordonnance, PDF | Aplati sur du blanc |
| `favicon.svg`, `favicon.ico`, `apple-touch-icon.png` | Onglet, écran d'accueil | |

`php artisan` n'y touche pas : la dérivation se relance à la main, et seulement
si le porteur du projet fournit de nouveaux fichiers d'origine.

```bash
pip install pillow numpy
python3 scripts/generer-logos.py
```

**La déclinaison claire est calculée, pas dessinée.** Le bleu nuit passe au
blanc, le vert à un vert plus clair. Deux pièges, tous deux visibles à l'œil
avant d'être corrigés : le passage bleu → vert du W est un dégradé, et un seuil
net y laissait un bord en dents de scie - la teinte est donc mélangée
progressivement ; et les trois pastilles qui prolongent l'arc s'effacent par
transparence, pas par la couleur, si bien que posées sur un fond sombre elles
viraient au gris - leur opacité est relevée.

**La version des impressions est aplatie sur du blanc.** dompdf range la
transparence d'un PNG dans un masque séparé qu'il ne compresse pas : le fichier
des écrans, transparent et cinq fois plus grand, ajoutait une centaine de
kilo-octets à chaque ordonnance PDF. Le papier étant blanc, la transparence n'y
sert à rien.

Tout passe par un seul composant, `<x-brand-logo>` :

```blade
<x-brand-logo />                          {{-- monogramme couleur --}}
<x-brand-logo variant="light" />          {{-- monogramme clair --}}
<x-brand-logo lockup variant="color" />   {{-- logo complet --}}
<x-brand-logo svg x="10" y="10" ... />    {{-- à l'intérieur d'un <svg> --}}
```

`svg` sert au décor de la page de connexion, où le logo est posé dans une
illustration : une balise `<img>` n'a pas cours à l'intérieur d'un `<svg>`, il
faut un `<image>`.

### Deux choix de mise en page qui portent le reste

**La file d'attente est une grille explicite**, pas un `flex` qui se rabat. Avec
le flex, le nom du patient se coupait en deux (« Aminata / Traore ») alors qu'il
restait la moitié de la largeur libre à droite. Le jeton, l'identité, l'état et
les actions ont chacun leur colonne ; sous 700px, l'identité passe sous le jeton
et l'action prend la largeur.

**Le tableau signale qu'il déborde.** Sur tablette, rien n'indiquait qu'il
restait des colonnes à droite : des ombres portées apparaissent sur les bords
tant qu'il reste à faire défiler.

### Ton des textes

L'interface dit **quoi faire**, pas pourquoi le code est ainsi. Les paragraphes
qui expliquaient l'architecture à l'utilisateur (« il est enregistré en base :
aucun redéploiement n'est nécessaire », « c'est ce qui donne sa valeur au
journal ») ont été ramenés à ce qui sert : « Ce nom apparaît dans la barre de
toutes les interfaces et sur les tickets imprimés. », « Lecture seule. »

Le tiret cadratin reste un séparateur - `- Choisir -`, `52 ans - Homme`, une
valeur absente - jamais une articulation de phrase.

## 17. Journal d'audit et plannings

### Journal d'audit

`spatie/laravel-activitylog`, exposé dans une section de `/admin` uniquement.
Le journal couvre **la quasi-totalité des actions du personnel**, et non les
seules connexions :

- Le trait `App\Models\Concerns\RecordsActivity` équipe les quinze modèles
  concernés (`patients`, `visits`, `referrals`, `payments`, `prescriptions`,
  `appointments`, `attachments`, `schedules`, `companions`, `visitors`,
  `services`, `doctors`, `receptionists`, `cashiers`, `settings`) et n'enregistre
  que les attributs réellement modifiés. Les colonnes sensibles en sont exclues :
  `patients.access_code` et `patients.portal_token` ne figurent jamais dans un
  log.
- Les actions métier qui ne sont **pas** de simples opérations CRUD portent un
  log explicite et lisible en français - « Appeler le suivant », « Envoyer vers
  un service », « Clôturer un dossier », « Confirmer le paiement et orienter »,
  « Générer un planning », « Supprimer un dossier » - plutôt qu'un diff
  d'attributs illisible à la relecture.
- Ces logs sont écrits **après** le commit de leur transaction : un journal ne
  doit jamais mentionner une opération qui a fini par échouer.

Le tableau reste filtrable par utilisateur, par type d'action et par date, et
paginé - le volume est nettement plus important qu'avant. Il est
**en lecture seule sans exception** : le composant n'expose aucune méthode de
modification ni de suppression, y compris pour l'admin - un journal que l'on
peut retoucher ne prouve rien. Un test vérifie l'absence de ces méthodes.

### Plannings

La gestion complète - créer, modifier, supprimer - est **exclusivement dans
`/admin`**, avec deux formulaires complémentaires : la saisie jour par jour pour
les ajustements ponctuels, et une **génération groupée** (`BulkCreateSchedule`)
qui décrit une plage de dates et les jours de la semaine concernés et crée un
créneau par date correspondante. Relancer une génération ne duplique rien : un
créneau identique déjà présent est ignoré. Chaque médecin et chaque réceptionniste consulte **le sien**, en
lecture seule, dans sa propre interface, via un composant partagé qui filtre
systématiquement sur l'utilisateur connecté. Aucun rôle ne voit le planning
d'un autre.

### Identité visuelle

- Page de connexion : formulaire centré sur une image de fond institutionnelle.
  Le placeholder livré est `public/images/login-background.jpg` - le remplacer
  par une photo de l'hôpital suffit, aucun code à toucher.
- Barre de marque sur les quatre interfaces : logo à gauche, **nom de
  l'établissement à droite, lu depuis la table `settings`** et modifiable par
  l'admin sans redéploiement. Aucun lien de navigation croisée n'y figure.

## 18. Vérification d'un déploiement

Avant de remplacer une version en service, un script enchaîne les contrôles et
rend un verdict :

```bash
./scripts/verify-deploy.sh                      # ou : ./scripts/verify-deploy.sh https://demo.exemple.ml
```

Il vérifie, dans cet ordre : la pile est démarrée, les migrations passent dans
les deux sens contre le moteur réel, `patients` ne porte plus de colonnes de
passage et aucune visite n'est orpheline, la suite de tests est au vert, une
réceptionniste connectée est bien redirigée depuis `/admin`, `/service` et les
routes de téléchargement, et les plafonds d'envoi sont ordonnés correctement.

Il sort en code 1 dès qu'un contrôle est rouge - utilisable tel quel dans une
procédure de mise à jour.

## 19. Tests

```bash
php artisan test                          # sans Docker
docker compose exec app php artisan test  # avec Docker
```

### La suite ne parle qu'à une base jetable (v3.3.1)

**`tests/TestCase.php` impose SQLite en mémoire, en PHP, avant toute
migration** - et refuse de démarrer si la base résolue est autre chose.

Ce verrou n'est pas une précaution théorique. `phpunit.xml` demandait déjà
SQLite sans jamais l'obtenir : `docker-compose.yml` injecte le fichier `.env`
comme variables d'environnement réelles du conteneur, et **Laravel lit sa
configuration depuis `$_SERVER`**, où docker a posé `DB_CONNECTION=mysql`. Les
balises `<env>` de PHPUnit n'y changent rien, même avec `force="true"` : elles
corrigent `getenv()`, pas ce que Laravel consulte.

La suite tournait donc contre la base de développement. Deux conséquences,
l'une agaçante et l'autre grave :

- un test qui comptait trois entrées de journal en trouvait sept, parce qu'il
  voyait celles de l'installation ;
- `demo:reset` - que la suite exécute pour le vérifier - y a vidé les patients,
  les passages et les ordonnances d'une installation de travail.

Ce que le verrou impose, et pourquoi chaque ligne compte :

| Réglage | Sans lui |
|---|---|
| `app.env` à `testing` | `migrate:fresh` refuse de tourner en production et abandonne **en silence** : aucune table n'est créée, chaque test échoue sur « no such table ». |
| `database.default` à SQLite en mémoire | La suite écrit dans la base de l'hôpital. |
| `queue.failed.database` | La file des échecs nomme sa connexion séparément : un travail échoué s'inscrivait dans `failed_jobs` de la base de développement. |
| `session`, `cache`, `queue`, `mail` en mémoire | `.env` les règle tous sur `database` : sessions et travaux de test se déposaient dans la base réelle. |
| `services.smsgate.url` à `.invalid` | `SMSGATE_URL` désigne le téléphone qui héberge la passerelle, sur le réseau de l'hôpital. Un test qui oublierait de simuler la couche HTTP enverrait de vrais SMS. |

Les deux premiers vont ensemble et **jamais l'un sans l'autre** : forcer
l'environnement seul autoriserait `migrate:fresh` à s'exécuter sur la base
pointée par docker, et en supprimerait toutes les tables.

**281 tests, 972 assertions.** La suite couvre :

| Fichier | Objet |
|---|---|
| `RoleScopeTest` | Cloisonnement des interfaces, redirections, `/board` public, **et le fait qu'aucune route de l'addendum n'est ouverte à deux rôles**. |
| `PatientCodeTest` | Génération du `patient_code`, unicité, préfixe configurable, **et qu'un second passage ne crée ni patient ni code supplémentaire**. |
| `EpisodeFlowTest` | Recherche de dossier, ouverture d'un nouvel épisode, regroupement chronologique des visites, « Mes patients ». |
| `ClosureFlowTest` | Clôture d'un renvoi et clôture d'un dossier, avec tous leurs garde-fous. |
| `ReferralFlowTest` | Transaction de renvoi complète, notifications, historique append-only. |
| `AttachmentTest` | Dépôt de pièce jointe, refus serveur d'un type ou d'une taille invalide, cloisonnement du téléchargement. |
| `CaisseFlowTest` | Cloisonnement du rôle `cashier`, routage sous condition de paiement à l'enregistrement et sur renvoi vers un plateau technique, bascule après confirmation, refus de la caisse comme destination, et journalisation d'« Appeler le suivant » et « Confirmer le paiement ». |
| `PatientPortalTest` | Génération du code et du jeton, page muette avant validation, code correct, cinq codes erronés → verrouillage temporaire, expiration du verrou, jeton invalide, envoi du lien par SMS. |
| `PatientDeletionTest` | Suppression en cascade, **survie explicite de l'entrée d'audit**, absence de trace dans `patient_history`, visiteur détaché, garde-fous de confirmation, inaccessibilité aux autres rôles. |
| `DossierWorkflowV32Test` | Visiteurs rattachés, création groupée de planning, dépôt de pièce jointe depuis le dossier (médecin et admin), frise unifiée triée par date, actions d'impression, « Mes rendez-vous », conclusion de consultation. |
| `PrintTicketTest` | Rendu du ticket patient et du ticket visiteur avec les champs propres à chacun, masquage de la navigation à l'impression, réimpression depuis les passages du jour. |
| `CashPrescriptionAppointmentTest` | Ordonnance + export PDF, rendez-vous et « Orienter le patient », **et que les anciennes portes de caisse côté médecin et accueil sont bien condamnées**. |
| `AuditSettingsScheduleTest` | Nom de l'établissement en base, journal d'audit filtrable et en lecture seule, plannings cloisonnés. |
| `ServiceInterfaceTest` | Appel du suivant, renvoi, saisie du résultat, refus d'un service qui n'est pas le sien. |
| `ReceptionInterfaceTest` | Enregistrement, files par service, **séquence de tickets partagée patients / visiteurs**. |
| `AdminInterfaceTest` | Services, personnels, réaffectation, dossiers. |
| `NavigationLayoutTest` | Barre de marque, navigation verticale en arbre, présence de toutes les sections dans chaque interface. |
| `BrandLogoTest` | Logo aux deux variantes, favicon, absence de dépendance externe. |
| `ReferralReturnTest` | Retour d'un renvoi complété : le patient réapparaît dans la file du prescripteur, avec un nouveau ticket, **sans repasser par la caisse** ; un dossier clôturé entre-temps ne ressuscite pas. |
| `ServiceKindTest` | Types de service administrables, slug figé des trois types d'origine, garde-fous de suppression, menu « Type » listant **tous** les types, caisse comprise, et caisse supplémentaire réellement tenable depuis `/caisse`. |
| `StaffTypeInterfaceTest` | Types de personnel, cloisonnement de `/staff/{slug}`, et **suite paramétrée par combinaison de capacités** : seules les sections cochées apparaissent, et une action hors capacité est refusée côté serveur. |
| `HospitalizationTest` | Admission clôturant la visite, occupation calculée à la volée, salle pleine avertissant sans bloquer, génération groupée de soins, filtrage « de garde », marquage fait/manqué, sortie bloquée tant qu'un soin reste en attente. |
| `CareTaskRevisionTest` | Correction d'un soin avec son avant/après au journal, annulation motivée d'un soin même déjà administré, exclusion de tous les décomptes, et contrôle d'accès : prescripteur ou médecin du service, jamais l'infirmier de garde. |
| `StaffCareTasksVisibilityTest` | De la prescription à l'écran réel, par la route `/staff/{slug}` : le soin apparaît, le rattachement au service est ce qui le relie à l'infirmier, et les deux configurations qui font disparaître les soins sont couvertes - capacité non cochée, planning absent, avec l'avertissement côté `/admin`. |
| `StaffNotificationTest` | Les cinq déclencheurs, chacun vérifié aussi par la négative : hors garde, autre service, autre rôle. Rappel de rendez-vous dans la fenêtre configurée et une seule fois, son émis au seul incrément du compteur, cloison entre les cloches de deux comptes. |
| `ProfileCardTest` | Mot de passe actuel incorrect refusé, confirmation et complexité, hash remplacé, journal sans aucune trace du mot de passe, middleware `AuthenticateSession` en place et session concurrente réellement rejetée. |
| `ReceptionServiceAndDutyTest` | L'accueil comme service exclu des destinations de soins, la réceptionniste de garde, et le créneau sans service qui vaut pour le service de rattachement - sans déborder sur les autres, et sans suffire à qui n'a pas de rattachement. |
| `PatientProfessionNoteTest` | Profession et note enregistrées, facultatives, vidées entre deux patients, et relues au dossier par le médecin. |
| `PrescriptionLinesTest` | Saisie ligne à ligne : ligne vide d'emblée, ajout borné à vingt, dernière ligne qui se vide au lieu de disparaître, lignes vides écartées, posologie sans médicament refusée, relecture d'une ordonnance antérieure, impression numérotée et portail patient. |
| `PollIntervalTest` | Aucun écran de travail ne porte son propre intervalle : tous suivent `keneya.poll_interval`. |
| `ScheduleStaffCoverageTest` | Le personnel générique et les caissiers proposés dans **les deux** formulaires de planning, listes identiques, libellé sans parenthèse vide, rafraîchissement après génération, et suppression groupée avec ses deux garde-fous (sélection limitée à l'affiché, sélection invisible épargnée). |
| `HandoffNoteTest` | La visibilité par service n'est pas restreinte au médecin admettant (vérifié avant de rien construire), note lue par l'équipe suivante, ligne `patient_history`, refus hors garde et sur séjour clôturé. |
| `StaffManagerTest` | Section « Personnels » : les deux menus reflètent les tables, le type choisi décide du rôle et de la table de rattachement, refus du changement de rôle, liste réunissant tout le personnel. |
| `StaffTypeCapabilitiesTest` | Capacités obligatoires indécochables (UI **et** requête forgée), colonne « Fonctions » sans tiret, sections de `/service` et `/reception` pliées aux capacités, non-régression des comptes de démonstration après migration. |
| `AdminDeletionActionsTest` | Présence de l'action de suppression dans **toutes** les tables de `/admin`, et refus motivés : type d'origine, type encore utilisé, compte ayant laissé une trace au dossier. |
| `LoginScreenTest` | Contrat du formulaire de connexion, scène et carte, erreurs et limitation de tentatives. |
| `AcceptanceScenarioTest` | Le scénario d'acceptation de bout en bout, dans l'ordre. |
| `SmsGatewayTest` | Format international, passerelle désactivée ou injoignable. |

Les tests tournent sur SQLite en mémoire et n'envoient jamais de SMS. La suite
a également été passée **contre MariaDB 10.11** - 281 tests au vert - et les
37 migrations ont été vérifiées **dans les deux sens** sur les deux moteurs.

## 20. Organisation du code

Aucune logique métier ne vit dans les vues Blade ou Livewire : les composants
valident puis délèguent à une action ou à un service.

```
app/
├── Actions/            RegisterPatient, OpenNewEpisode, CallNextPatient,
│                       SendReferral, CompleteReferral, CloseReferral,
│                       CloseVisit, RegisterVisitor, StoreAttachment,
│                       RecordPayment, CreatePrescription,
│                       ScheduleAppointment, CheckInAppointment
├── Http/
│   ├── Controllers/    Contrôleurs minces, une interface par rôle,
│   │                   + Admin/ et Service/ pour les téléchargements cloisonnés
│   ├── Middleware/     EnsureRoleScope (cloisonnement), RedirectIfAuthenticated
│   └── Requests/       LoginRequest
├── Listeners/          LogAuthenticationActivity (connexions au journal)
├── Livewire/
│   ├── Admin/          HospitalSettings, ServiceManager, DoctorManager,
│   │                   ReceptionistManager, ScheduleManager,
│   │                   PatientDirectory, ActivityLogViewer
│   ├── Board/          WaitingBoard (public et poste d'accueil)
│   ├── Reception/      PatientLookup, PatientRegistrationForm,
│   │                   VisitorRegistrationForm, TicketCashier,
│   │                   TodayAppointments, TodayVisits
│   ├── Service/        ServiceQueue, IncomingReferrals, OutgoingReferrals,
│   │                   ConsultationActions, MyPatients, PatientRecordPanel,
│   │                   ServiceSelector + Concerns/ScopedToOwnService
│   └── Shared/         MySchedule (même composant dans /service et /reception,
│                       toujours filtré sur l'utilisateur connecté)
├── Models/             Patient (identité), Visit (passage), Service, Doctor,
│                       Receptionist, Visitor, Companion, Referral,
│                       PatientHistory, Attachment, Payment, Prescription,
│                       Appointment, Schedule, Setting, User
├── Observers/          PatientObserver (patient_code), VisitorObserver,
│                       PatientHistoryObserver (append-only)
├── Services/           SmsGateway, TokenAllocator, PatientCodeGenerator,
│                       PatientHistoryRecorder
└── Support/            Roles (rôles ↔ interfaces), Audit (journal),
                        helpers.php (hospital_name)
```

L'interface est intégralement en français, y compris les messages de
validation et d'erreur.

---

© AXESs - KƐNƐYA WorkFlow.
