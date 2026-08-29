# KƐnƐya WorkFlow

Gestion de file d'attente hospitalière avec renvoi inter-services et **dossier
patient unique**, développée par AXESs pour l'**Hôpital Fousseyni Daou de Kayes**
(Mali), et conçue pour être réutilisée dans d'autres établissements maliens.

> **Nom du produit** : `KƐnƐya WorkFlow` (affiché tel quel dans l'interface et la
> documentation).
> **Identifiant technique** : `keneya-workflow` — le caractère `Ɛ` n'apparaît
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
14. [Journal d'audit et plannings](#14-journal-daudit-et-plannings)
15. [Vérification d'un déploiement](#15-vérification-dun-déploiement)
16. [Tests](#16-tests)
17. [Organisation du code](#17-organisation-du-code)

---

## 1. Principe : un rôle, une interface

Contrainte de conception centrale : **chaque rôle n'a accès qu'à sa propre
interface**. Il n'y a aucune navigation croisée — pas de menu partagé, pas de
lien vers un autre module, pas de tableau de bord générique. Chaque poste
(tablette ou PC dédié) n'affiche que l'interface du rôle connecté.

| Rôle | Interface unique | Contenu |
|---|---|---|
| `admin` | `/admin` | Établissement, services, réceptionnistes, médecins (avec réaffectation), plannings du personnel (jour par jour et génération groupée), vue globale des patients avec accès à tout dossier, suppression définitive d'un dossier, journal d'audit. |
| `receptionist` | `/reception` | Recherche de dossier existant, enregistrement patient et visiteur, rendez-vous du jour, passages du jour (avec réimpression du ticket), écran de salle d'attente, son propre planning. |
| `doctor` | `/service` | File d'attente, renvois entrants et sortants, clôture de renvoi et de dossier, conclusion de consultation, ordonnances, « Mes patients » et « Mes rendez-vous », son propre planning, et le dossier patient dans un panneau de la même page. **Aucune fonction de caisse.** |
| `cashier` | `/caisse` | Les deux files de caisse (« Caisse Ticket » et « Caisse Services »), encaissement et orientation vers le service qui attend, son propre planning. |
| *(type de personnel sans rôle)* | `/staff/{slug}` | Interface **composée** des seules fonctions cochées par l'administrateur — voir §12. Cloisonnée exactement comme les quatre autres. |

Mise en œuvre :

- Après authentification, `HomeController` redirige immédiatement vers `/admin`,
  `/reception`, `/service` ou `/caisse` selon le rôle — jamais vers une page
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
  `/admin/pieces-jointes/{id}` pour l'admin. Deux tests verrouillent la règle —
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
| Backend | Laravel 12, PHP 8.3 |
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

Trois services : `app` (PHP-FPM 8.3 + Laravel), `web` (Nginx), `db`
(MariaDB). Le `docker-compose.yml` tourne **à l'identique** sous Docker Engine
(Linux) et Docker Desktop / WSL2 (Windows), sans modification.

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

Prérequis communs : PHP 8.3 avec les extensions `pdo_mysql`, `mbstring`,
`intl`, `zip`, `bcmath`, `openssl`, `fileinfo` ; Composer 2 ; MySQL 8 ou
MariaDB 10.6+.

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

### Linux — Nginx + PHP-FPM

```bash
sudo apt install php8.3-fpm php8.3-mysql php8.3-mbstring php8.3-intl \
                 php8.3-zip php8.3-bcmath nginx mariadb-server

git clone <url-du-depot> /var/www/keneya-workflow
cd /var/www/keneya-workflow

composer install --no-dev --optimize-autoloader
cp .env.example .env        # DB_HOST=127.0.0.1
php artisan key:generate
php artisan migrate --seed --force
php artisan config:cache && php artisan route:cache && php artisan view:cache

sudo chown -R www-data:www-data storage bootstrap/cache
```

Serveur virtuel Nginx (`/etc/nginx/sites-available/keneya-workflow`) — la
configuration de `docker/nginx/default.conf` sert de base ; il suffit de
remplacer `fastcgi_pass app:9000;` par
`fastcgi_pass unix:/run/php/php8.3-fpm.sock;` et d'adapter `root` :

```nginx
server {
    listen 80;
    server_name keneya.hopital.local;
    root /var/www/keneya-workflow/public;
    index index.php;

    location / { try_files $uri $uri/ /index.php?$query_string; }

    location ~ \.php$ {
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### Windows — IIS + PHP, ou Apache

**IIS + PHP (FastCGI)**

1. Installer PHP 8.3 (build NTS, x64) dans `C:\php`, activer les extensions
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

Installer Laragon avec PHP 8.3 et MySQL, placer le projet dans
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
est « Personnel → Types de personnel », puis « Personnel → Interfaces dédiées »
pour y rattacher quelqu'un.

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

### Quand le serveur n'est pas sur le réseau du téléphone

Sur un serveur distant (VPS), le téléphone n'est pas joignable par son adresse
locale. Trois topologies, par ordre de simplicité :

| Topologie | `SMSGATE_URL` | Prérequis |
|---|---|---|
| Même réseau local | `http://<ip-locale>:8080` | Aucun |
| Tunnel VPN (WireGuard) | `http://<ip-vpn-du-telephone>:8080` | Tunnel déjà monté |
| Relais cloud SMSGate | l'URL de l'API cloud | Compte chez le relais |

Le code n'a pas à changer d'une topologie à l'autre : seule `SMSGATE_URL`
diffère, et `https://` est accepté tel quel.

> **Optimisation de batterie Android — indispensable.** Une fois SMSGate en
> arrière-plan, Android gèle l'application : la connexion TCP s'établit encore
> (le noyau répond), mais plus aucune requête n'est traitée, et l'envoi échoue
> au bout de `SMSGATE_TIMEOUT`. Symptôme caractéristique : la passerelle répond
> instantanément quand l'application est à l'écran, et reste muette sinon ;
> les SMS arrivent alors groupés et en retard, au réveil du téléphone.
> Il faut exclure SMSGate de l'optimisation de batterie, l'ajouter à
> l'autostart sur les surcouches constructeur (Xiaomi, Oppo, Samsung, Huawei),
> et garder le téléphone branché sur secteur.

**Diagnostic rapide** — depuis le serveur, sans envoyer de SMS :

```bash
curl -s -o /dev/null -m 8 -w '%{http_code}\n' http://<ip>:8080/message
# 401 : la passerelle répond et exige l'authentification — c'est bon signe
# 000 en ~8 s : téléphone endormi ou injoignable
# 000 immédiat : rien n'écoute sur ce port
```

Un `401` suivi d'un `200` avec les identifiants prouve la connectivité ; il ne
prouve pas la remise du SMS. Seule la réception sur un vrai téléphone, et
l'onglet *Messages* de SMSGate, confirment l'acheminement.

## 7. Sauvegardes

Les données vivent dans le volume Docker dédié **`keneya_db`**, distinct du
code : `docker compose down` ne l'efface pas (`docker compose down -v`, si).

Depuis la v2, **les pièces jointes vivent dans le volume `keneya_storage`**
(`storage/app/attachments`). Une sauvegarde complète comprend donc les deux :
l'export SQL ci-dessous **et** une copie de ce volume, par exemple

```bash
docker run --rm -v keneya_storage:/data -v "$PWD/backups:/out" alpine \
    tar czf /out/keneya_storage-$(date +%Y%m%d).tar.gz -C /data .
```

Export `.sql` — la même commande des deux côtés :

```bash
docker compose exec -T db mariadb-dump \
    --user=keneya --password=<mot-de-passe> --single-transaction \
    keneya_workflow > keneya_workflow.sql
```

Deux scripts prêts à l'emploi horodatent l'export et ne conservent que les 30
dernières sauvegardes. `backup.sh` détecte lui-même la topologie : il passe par
le conteneur `db` s'il tourne, et attaque sinon directement le serveur MariaDB
indiqué par le `.env` — utile pour une installation native (section 4). Le mot
de passe n'apparaît jamais dans `ps` (fichier temporaire en `600`), et un dump
vide est signalé comme une erreur au lieu d'être conservé :

```bash
./scripts/backup.sh /var/sauvegardes/keneya                       # Linux
powershell -File .\scripts\backup.ps1 -Destination D:\sauvegardes # Windows
```

**Installation de la tâche quotidienne, en une commande :**

```bash
./scripts/install-backup-cron.sh 02:30            # heure au choix
crontab -l | grep keneya                          # vérification
```

Le script est idempotent : le relancer remplace la ligne existante.

**Planification manuelle — Linux (cron), tous les jours à 22h00 :**

```cron
0 22 * * * cd /var/www/keneya-workflow && ./scripts/backup.sh /var/sauvegardes/keneya >> /var/log/keneya-backup.log 2>&1
```

**Planification — Windows (Planificateur de tâches) :**

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

### Réinitialiser un environnement de démonstration

Un serveur de démonstration commerciale s'encombre au fil des présentations :
patients fictifs, tickets, encaissements, pièces jointes, et parfois le nom
d'un établissement saisi pour un prospect précédent. `demo:reset` remet cet
environnement à un état propre :

```bash
php artisan demo:reset             # vide les données produites par l'usage
php artisan demo:reset --complet   # vide aussi services, personnel et comptes
php artisan demo:reset --force     # sans confirmation (usage non interactif)
```

La commande vide les tables transactionnelles (patients, passages, renvois,
encaissements, ordonnances, rendez-vous, pièces jointes, journal d'audit),
**supprime les fichiers joints sur le disque** — une pièce orpheline resterait
lisible par qui connaît son chemin —, relance les seeders de démonstration puis
rétablit explicitement le nom de l'établissement : `SettingSeeder` utilise
`firstOrCreate` et ne corrigerait pas une valeur déjà présente.

> **Elle n'est accessible qu'en ligne de commande.** Aucune route ne l'expose,
> et elle refuse de s'exécuter hors console : un `Artisan::call()` déclenché
> par une requête HTTP est rejeté. Un test vérifie qu'aucune route ne contient
> `demo`. Elle n'a évidemment rien à faire sur l'installation réelle d'un
> hôpital : c'est un outil d'environnement de démonstration, où toutes les
> données sont fictives.

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
| `care_tasks` | Une administration, individuellement marquable `pending` / `done` / `missed`. |
| `doctors` | Rattachement d'un compte à un service, réaffectable à tout moment. Un médecin multi-services a plusieurs lignes. Jamais à une caisse. |
| `receptionists` | Rattachement d'un compte au rôle d'accueil. |
| `cashiers` | Rattachement d'un compte au rôle de caissier, sur le modèle de `receptionists`. |
| **`patients`** | **Identité permanente et rien d'autre** : `patient_code` (`HFD-00001`) à vie, `crno` (dossier papier), plus `access_code` (4 chiffres) et `portal_token` (UUID) pour le portail. |
| **`visits`** | **Un passage / épisode de soins** : service courant, ticket, statut (`waiting`, `called`, `closed`), ouverture et clôture, plus `pending_next_service_id` — la destination qui attend le paiement. |
| `companions` | Accompagnateurs d'un patient. Information non médicale, sans ticket propre. |
| `visitors` | Fiche visiteur (`HFD-V-00001`), avec ticket dans la file du service visité et **le patient visité** (`patient_id`, facultatif hors service clinique). |
| `portal_access_attempts` | Tentatives de code du portail par dossier, avec verrouillage temporaire. |
| `referrals` | Renvoi d'un service à un autre : instructions, résultat, puis clôture par le prescripteur. Auteur, exécutant et clôturant sont chacun un médecin **ou** un membre du personnel générique. |
| `patient_history` | Journal **append-only** du parcours, ancré sur la visite. `type` couvre aussi `consultation_conclusion`, `payment_confirmed`, `hospitalization_admitted`, `hospitalization_discharged` et `care_task_completed`. Signé par `doctor_id` **ou** `staff_member_id`. |
| `attachments` | Pièces jointes (PDF, JPG, PNG). `patient_id` est le point d'ancrage ; `referral_id` et `patient_history_id` ne sont renseignés qu'en contexte. |
| `payments` | Encaissements, en FCFA sans décimales : `ticket` (consultation) ou `service` (acte). |
| `prescriptions` | Ordonnances en texte libre, exportables en PDF. |
| `appointments` | Rendez-vous : `scheduled`, `checked_in`, `no_show`, `cancelled`. |
| `schedules` | Créneaux de travail du personnel. |
| `settings` | Réglages modifiables sans redéploiement (nom de l'établissement). |
| `activity_log` | Journal d'audit (`spatie/laravel-activitylog`). |

Trois garanties structurelles :

- **`patient_code` est attribué dans `PatientObserver::creating`**, jamais dans
  un contrôleur. Quel que soit le point d'entrée — formulaire, seeder, import,
  `tinker` — un patient ne peut pas exister sans identifiant unique.
- **`patient_history` est append-only** : `PatientHistoryObserver` lève une
  exception sur toute tentative de mise à jour ou de suppression. Le seul point
  d'écriture est `PatientHistoryRecorder`.
- **L'identité ne porte aucun état de passage.** Un patient qui revient six mois
  plus tard ouvre une nouvelle `visits` sous le même `patient_code` : l'épisode
  précédent reste intact et consultable, distinct du nouveau.

Les migrations `2025_04_01_*` ajoutent la couche v3.2.1 : les types de service
(avec conversion des trois valeurs de l'ancien `enum`), les types de personnel,
l'ouverture du parcours de soin au personnel générique, et l'hospitalisation.
Leur `down()` est fonctionnel et vérifié sur SQLite comme sur MariaDB — celui des
types de service restaure l'ancien `enum`, les types ajoutés par l'admin
retombant sur « clinique », faute d'équivalent.

Les migrations `2025_03_01_*` ajoutent la couche v3.2 : les caissiers, le
troisième type de service, le routage sous condition de paiement, le patient
visité et le portail. Leur `down()` est fonctionnel et vérifié sur SQLite comme
sur MariaDB — celui du type de service supprime au passage les caisses et leurs
files, sans quoi MariaDB refuserait de rétrécir la colonne.

### Migration depuis la v1

Les migrations `2025_02_01_*` font la bascule. Elles **reprennent les données
existantes** plutôt que de simplement supprimer des colonnes : chaque patient
déjà enregistré reçoit une `visits` portant son service, son ticket et son
statut d'origine, et les lignes `referrals` / `patient_history` sont rattachées
à cette visite. Le `down()` fait le chemin inverse et restaure fidèlement
l'ancien schéma — vérifié dans les deux sens.

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
   par la réceptionniste** — deux homonymes ne doivent jamais être confondus.
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
chemins de code séparés** qui n'appellent jamais la même logique de routage —
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

Une visite `closed` sort de la file active — « Appeler le suivant » ne la
propose plus — mais **son dossier reste intégralement lisible** : la clôture ne
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
sont revérifiés côté serveur dans `StoreAttachment`** — le type MIME réel du
fichier reçu, pas l'extension annoncée : un formulaire se contourne, pas une
action.

Stockage sur le disque `attachments` (`storage/app/attachments`), donc sur le
volume Docker **`keneya_storage`**, persistant entre redéploiements. Aucun
stockage cloud : la connectivité du site ne le permet pas.

**Lecture et écriture depuis le dossier.** Une pièce jointe s'ajoute
directement au dossier d'un patient — depuis « Mes patients » côté `/service`,
depuis la vue globale des patients côté `/admin` — et plus seulement en réponse
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
`window.print()` — pas de génération PDF côté serveur pour cela. Le PDF
dompdf de l'ordonnance reste disponible en téléchargement côté médecin.

### Caisse : un rôle, une interface, un passage obligé

Dans cet hôpital, **les médecins n'encaissent jamais**. Un patient règle à la
caisse avant d'être orienté vers le service qui doit le prendre en charge. La
caisse coordonne financièrement l'ensemble des services : elle a donc son propre
rôle (`cashier`) et sa propre interface (`/caisse`), cloisonnée comme les trois
autres.

Les deux caisses sont des **services à part entière** (`services.kind = caisse`,
créés par le seeder) : même file, même token, même « Appeler le suivant » que
partout ailleurs — aucune mécanique parallèle à maintenir.

| Caisse | Ce qu'on y règle |
|---|---|
| **Caisse Ticket** | Le ticket de consultation, avant de voir un praticien. |
| **Caisse Services** | Un acte de plateau technique (échographie, laboratoire…). |

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

La caisse n'est jamais proposée comme destination : ni dans la liste des
services de l'accueil, ni dans celle des renvois, ni comme service d'affectation
d'un médecin — la règle est appliquée côté serveur (`Service::careServices()`,
`SendReferral`, validation de `DoctorManager`), pas seulement dans les listes
déroulantes.

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
supplémentaire — la conclusion prend place dans la même frise que le reste.

### Rendez-vous

Fixés depuis `/service`, **pour tout patient de « Mes patients »** et plus
seulement en fin de consultation immédiate. La section « Mes rendez-vous »,
juste sous « Mes patients », liste les rendez-vous à venir du médecin connecté,
triés par date. La section « Rendez-vous du
jour » de `/reception` liste les rendez-vous, avec **« Orienter le patient »** :
à l'arrivée, une nouvelle `visits` s'ouvre directement dans la file du service
prévu, sous l'identité existante — pas de réenregistrement pour quelqu'un que
l'hôpital connaît déjà. `no_show` distingue le patient qui ne s'est pas
présenté d'une annulation volontaire.

## 11. Portail patient, impression et suppression de dossier

### Portail patient — lien permanent, code à quatre chiffres

Le patient consulte ses documents et ses rendez-vous depuis son téléphone, sur
`/mes-documents/{portal_token}`. **Le lien ne périme jamais**, c'est la demande
du porteur du projet ; la sécurité repose donc sur deux couches et non sur une :

1. `patients.portal_token` est un **UUID non devinable** — jamais le
   `patient_code`, jamais l'`id` auto-incrémenté. Il faut déjà connaître ce lien
   précis pour tenter quoi que ce soit.
2. `patients.access_code`, quatre chiffres générés à la création du dossier et
   **remis de vive voix à l'accueil** (et imprimés sur le ticket), n'a que
   10 000 combinaisons : le portail verrouille donc le dossier **15 minutes
   après 5 tentatives ratées** (`portal_access_attempts`), et la route est
   limitée par `throttle:10,1`.

Le verrouillage est volontairement **temporaire** : un patient qui se trompe
deux fois ne doit pas rester bloqué à vie devant ses propres documents.

La page **n'affiche rien** — pas même le nom du patient — tant que le code n'a
pas été validé, et l'accès accordé vit en session côté serveur, pas dans une
propriété Livewire qui voyagerait avec le client. Le SMS contenant le lien part
**sur demande explicite** depuis `/reception` ou `/service` (« Envoyer le lien de
mes documents »), jamais automatiquement à chaque événement du dossier ; le code
ne voyage jamais par SMS.

### Impression du ticket

Bouton « Imprimer le ticket » à deux endroits dans `/reception` : juste après
l'enregistrement, et sur chaque ligne des « Passages du jour » pour une
réimpression. La vue `resources/views/reception/print-ticket.blade.php` est une
page autonome — ni barre de navigation, ni navigation verticale — et l'impression
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
pour un service non clinique — une démarche administrative n'a pas de patient à
visiter. Le nom du patient visité apparaît sur `/board` et dans les passages du
jour, pour que le personnel sache qui orienter où.

### Suppression d'un dossier patient

Opération sensible, réservée à `admin` et à `/admin` — jamais atteignable depuis
`/reception`, `/service` ou `/caisse`, y compris en appelant le composant
directement.

- Suppression **réelle et en cascade** : `visits`, `referrals`,
  `patient_history`, `attachments` (fichiers compris), `payments`,
  `prescriptions`, `appointments`, `companions`. Un visiteur, lui, est
  **détaché** et non supprimé : sa fiche documente une entrée dans l'hôpital,
  ce n'est pas une donnée du dossier médical.
- Confirmation en deux temps : l'admin **retape le `patient_code` exact** et
  saisit un **motif obligatoire**. Un simple « Êtes-vous sûr ? » ne protège de
  rien — on clique oui par réflexe.
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

Trois types sont posés à l'installation — Clinique, Plateau technique, Caisse — et
restent structurants : le code les reconnaît par leur **`slug`**, jamais par leur
libellé. Ils sont donc renommables (« Plateau technique » peut devenir « Examens
complémentaires ») mais pas supprimables. Un type créé ensuite n'a aucun
comportement codé, et n'est supprimable que s'il n'est utilisé par aucun service.

### Types de personnel

Nouvelle section « Personnel → Types de personnel » dans `/admin`. **Deux chemins
distincts**, et l'admin voit lequel il emprunte :

1. **`matched_role` renseigné** (`doctor`, `receptionist`, `cashier` — jamais
   `admin`, qui n'a pas vocation à être multiplié) : le type réutilise telle
   quelle une des quatre interfaces déjà construites et testées. Rien ne change
   dans leur fonctionnement, et les personnes continuent d'être créées dans les
   tables `doctors` / `receptionists` / `cashiers` comme avant.

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

**Une seule route pour tous ces types** — `Route::get('/staff/{slug}', StaffInterfaceController::class)`
— jamais une route générée à la volée : `route:cache` ne verrait pas des routes
déclarées depuis la base. Le `slug` n'est qu'une valeur lue en base par une route
qui existe déjà dans le code.

`EnsureRoleScope` gère ce cas dynamiquement (`role.scope:staff`) : il compare le
type du compte connecté au slug demandé, avec la **même redirection propre** que
pour les quatre rôles fixes. Un slug inconnu est traité comme un refus, jamais
comme un 404 — on ne laisse pas deviner quels types existent en tapant des URL.

Une capacité non cochée n'affiche pas une section vide : elle n'affiche rien, et
**le composant correspondant refuse dès son montage**. La capacité est un
garde-fou serveur, pas un filtre d'affichage.

`staff_members` rattache une personne à son type et à son service, sur le modèle
de `doctors`. Un acte posé par un membre du personnel générique est signé par
`*_staff_member_id` — **jamais** par `*_doctor_id` : on ne fabrique pas de faux
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
test manuel par métier — sinon la surface fonctionnelle grandirait plus vite que
ce qui est vérifié.

## 13. Hospitalisation et planning de soins

Distinct du système de file d'attente : un patient hospitalisé n'attend pas un
tour avec un ticket, il occupe un lit et reçoit des soins programmés sur
plusieurs jours.

### Salles et capacité

Catalogue administrable dans « Hospitalisation → Salles ». **L'occupation n'est
jamais stockée** : elle se compte à la volée sur les hospitalisations actives —
un compteur en base finit toujours par mentir sur ce qu'il prétend compter.

À l'admission, une salle déjà pleine **avertit sans bloquer** : l'admission passe
après confirmation explicite. Une urgence hospitalière dépasse parfois la
capacité nominale, et un blocage strict serait dangereux plutôt que protecteur.
Une salle qui héberge encore quelqu'un n'est pas supprimable.

### Admission et sortie

L'admission se déclenche depuis `/service` sur un patient appelé. Elle **clôture
sa visite en cours** — il quitte la file d'attente — et ouvre une
`hospitalizations`, les deux dans la même transaction : un patient hospitalisé
qui resterait dans une file serait une incohérence visible au tableau
d'affichage. Une ligne `patient_history` (`hospitalization_admitted`) la place
sur la même frise chronologique que le reste du dossier.

La sortie est **bloquée tant qu'il reste des soins `pending`**, avec un message
explicite : clôturer silencieusement un plan de soins inachevé reviendrait à
effacer la trace de ce qui n'a pas été fait. Un soin peut être marqué « manqué »
— il reste alors visible dans le dossier, qualifié plutôt qu'effacé.

### Planning de soins

Le médecin prescrit le soin et sa récurrence ; **il n'assigne pas nommément un
infirmier à chaque occurrence**. La tâche devient visible pour tout membre du
personnel dont le type porte `has_care_tasks` et qui est **de garde sur ce
service au moment présent**, d'après les `schedules` déjà en place — s'appuyer
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
à l'affichage — pas de bascule automatique de statut, donc aucune dépendance à un
scheduler dans cette version. La traçabilité réelle vient de
`completed_by_user_id`, renseigné au moment du geste, et chaque soin réalisé
écrit une ligne `patient_history` (`care_task_completed`) sur la frise du dossier.

## 14. Journal d'audit et plannings

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
  log explicite et lisible en français — « Appeler le suivant », « Envoyer vers
  un service », « Clôturer un dossier », « Confirmer le paiement et orienter »,
  « Générer un planning », « Supprimer un dossier » — plutôt qu'un diff
  d'attributs illisible à la relecture.
- Ces logs sont écrits **après** le commit de leur transaction : un journal ne
  doit jamais mentionner une opération qui a fini par échouer.

Le tableau reste filtrable par utilisateur, par type d'action et par date, et
paginé — le volume est nettement plus important qu'avant. Il est
**en lecture seule sans exception** : le composant n'expose aucune méthode de
modification ni de suppression, y compris pour l'admin — un journal que l'on
peut retoucher ne prouve rien. Un test vérifie l'absence de ces méthodes.

### Plannings

La gestion complète — créer, modifier, supprimer — est **exclusivement dans
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
  Le placeholder livré est `public/images/login-background.jpg` — le remplacer
  par une photo de l'hôpital suffit, aucun code à toucher.
- Barre de marque sur les quatre interfaces : logo à gauche, **nom de
  l'établissement à droite, lu depuis la table `settings`** et modifiable par
  l'admin sans redéploiement. Aucun lien de navigation croisée n'y figure.

## 15. Vérification d'un déploiement

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

Il sort en code 1 dès qu'un contrôle est rouge — utilisable tel quel dans une
procédure de mise à jour.

## 16. Tests

```bash
php artisan test                          # sans Docker
docker compose exec app php artisan test  # avec Docker
```

**246 tests, 837 assertions.** La suite couvre :

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
| `AdminInterfaceTest` | Services, médecins, réaffectation, réceptionnistes, dossiers. |
| `NavigationLayoutTest` | Barre de marque, navigation verticale en arbre, présence de toutes les sections dans chaque interface. |
| `BrandLogoTest` | Logo aux deux variantes, favicon, absence de dépendance externe. |
| `ReferralReturnTest` | Retour d'un renvoi complété : le patient réapparaît dans la file du prescripteur, avec un nouveau ticket, **sans repasser par la caisse** ; un dossier clôturé entre-temps ne ressuscite pas. |
| `ServiceKindTest` | Types de service administrables, slug figé des trois types d'origine, garde-fous de suppression, type « Caisse » non proposable. |
| `StaffTypeInterfaceTest` | Types de personnel, cloisonnement de `/staff/{slug}`, et **suite paramétrée par combinaison de capacités** : seules les sections cochées apparaissent, et une action hors capacité est refusée côté serveur. |
| `HospitalizationTest` | Admission clôturant la visite, occupation calculée à la volée, salle pleine avertissant sans bloquer, génération groupée de soins, filtrage « de garde », marquage fait/manqué, sortie bloquée tant qu'un soin reste en attente. |
| `AcceptanceScenarioTest` | Le scénario d'acceptation de bout en bout, dans l'ordre. |
| `SmsGatewayTest` | Format international, passerelle désactivée ou injoignable. |

Les tests tournent sur SQLite en mémoire et n'envoient jamais de SMS. La suite
a également été passée **contre MariaDB 10.11** — 246 tests au vert — et les
36 migrations ont été vérifiées **dans les deux sens** sur les deux moteurs.

## 17. Organisation du code

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

© AXESs — KƐnƐya WorkFlow.
