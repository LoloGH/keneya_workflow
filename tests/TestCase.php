<?php

namespace Tests;

use App\Models\Cashier;
use App\Models\Doctor;
use App\Models\Patient;
use App\Models\Receptionist;
use App\Models\Service;
use App\Models\ServiceKind;
use App\Models\StaffType;
use App\Models\User;
use App\Models\Visit;
use App\Support\Roles;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Spatie\Permission\Models\Role;

abstract class TestCase extends BaseTestCase
{
    /**
     * La suite ne parle qu'a une base jetable, en memoire. Le reglage est pose
     * ici, en PHP, et non laisse aux variables d'environnement.
     *
     * Pourquoi ce verrou existe : `phpunit.xml` demandait bien SQLite, mais
     * docker-compose injecte le fichier `.env` comme variables d'environnement
     * reelles du conteneur, et Laravel lit sa configuration depuis `$_SERVER`
     * — ou docker a pose `DB_CONNECTION=mysql`. Les balises `<env>` de PHPUnit
     * n'y changeaient rien, meme avec `force="true"` : elles corrigent
     * `getenv()`, pas ce que Laravel consulte.
     *
     * La suite tournait donc contre la base de developpement. Un test comptait
     * trois entrees de journal et en trouvait sept ; surtout, `demo:reset` —
     * que la suite execute pour le verifier — y a vide les patients, les
     * passages et les ordonnances d'une installation de travail.
     *
     * `refreshApplication()` est le bon endroit : il s'execute avant
     * `setUpTraits()`, donc avant que RefreshDatabase ne migre quoi que ce
     * soit. `DB::purge()` jette la connexion deja resolue, sans quoi la
     * nouvelle configuration ne s'appliquerait qu'a la suivante.
     */
    protected function refreshApplication(): void
    {
        parent::refreshApplication();

        // L'environnement d'abord : docker laisse `APP_ENV=production`, et
        // `migrate:fresh` — que RefreshDatabase lance au premier test — refuse
        // de tourner en production. Il abandonnait donc en silence, aucune
        // table n'etait creee, et chaque test echouait sur « no such table ».
        //
        // Les deux reglages vont ensemble et jamais l'un sans l'autre : forcer
        // l'environnement seul autoriserait `migrate:fresh` a s'executer sur la
        // base pointee par docker, c'est-a-dire celle de developpement.
        $this->app['env'] = 'testing';

        config([
            'app.env' => 'testing',
            'database.default' => 'sqlite',
            'database.connections.sqlite.database' => ':memory:',

            // La passerelle SMS est active, mais adressee dans le vide.
            //
            // Active, parce que la suite verifie que l'application inscrit
            // bien ses messages en file : desactivee, elle n'en inscrit aucun
            // et cinq tests constatent une file vide.
            //
            // Dans le vide, parce que `SMSGATE_URL` designe en clientele le
            // telephone qui heberge la passerelle, sur le reseau local de
            // l'hopital. Un test qui oublierait de simuler la couche HTTP
            // enverrait de vrais SMS. `.invalid` ne resout nulle part, par
            // definition — c'est le filet sous le filet.
            'services.smsgate.enabled' => true,
            'services.smsgate.url' => 'http://passerelle.invalid',
            'services.smsgate.login' => 'test',
            'services.smsgate.password' => 'test',

            // Tout ce qui, en clientele, passe par la base ou par le reseau
            // reste en memoire le temps des tests. `.env` les regle tous sur
            // `database` : sans ces lignes, une session de test s'ecrirait
            // dans la base de l'hopital, et un travail de file y resterait en
            // attente apres l'execution.
            'cache.default' => 'array',
            'session.driver' => 'array',
            'queue.default' => 'sync',
            'mail.default' => 'array',
            'broadcasting.default' => 'null',
            'hashing.bcrypt.rounds' => 4,

            // La file des echecs nomme sa connexion separement, et la lisait
            // dans `DB_CONNECTION` : un travail definitivement echoue pendant
            // les tests s'inscrivait donc dans `failed_jobs` de la base de
            // developpement, pendant que le test cherchait la ligne dans la
            // sienne. `database.default` ne suffit pas a la ramener ici.
            'queue.failed.database' => 'sqlite',
        ]);

        DB::purge();

        $this->assertBaseJetable();
    }

    /**
     * Garde-fou de derniere ligne : la suite s'arrete si elle se retrouve
     * malgre tout devant autre chose qu'une base en memoire.
     *
     * Une exception ici est brutale, et c'est voulu. Le mode de defaillance
     * qu'elle previent est silencieux : des tests qui passent en detruisant
     * des donnees reelles ne se signalent pas d'eux-memes.
     */
    private function assertBaseJetable(): void
    {
        $nom = (string) DB::connection()->getDatabaseName();

        if ($nom !== ':memory:') {
            throw new RuntimeException(sprintf(
                'La suite de tests refuse de tourner sur la base « %s ». '
                .'Seule une base SQLite en memoire est acceptee : ces tests '
                .'vident des tables et executent demo:reset.',
                $nom,
            ));
        }
    }

    /**
     * Cree les quatre roles cloisonnes, comme le fait RoleSeeder au deploiement.
     * A appeler dans les tests ou c'est le code applicatif — et non le test —
     * qui attribue un role.
     */
    protected function seedRoles(): void
    {
        foreach (Roles::all() as $role) {
            Role::findOrCreate($role, 'web');
        }
    }

    protected function makeAdmin(): User
    {
        return $this->makeUserWithRole(Roles::ADMIN);
    }

    protected function makeReceptionist(): User
    {
        $user = $this->makeUserWithRole(Roles::RECEPTIONIST);

        Receptionist::create(['user_id' => $user->getKey()]);

        return $user;
    }

    /**
     * Un des trois types de service d'origine, cree a la demande : les tests
     * partent d'une base vide, la migration ne les a pas semes ici.
     */
    protected function serviceKind(string $slug): ServiceKind
    {
        return ServiceKind::firstOrCreate(
            ['slug' => $slug],
            [
                'name' => ucfirst(str_replace('_', ' ', $slug)),
                'requires_payment_gate' => $slug === ServiceKind::SLUG_PLATEAU_TECHNIQUE,
            ],
        );
    }

    /**
     * Le type de personnel d'origine adosse a un role, pose par la migration
     * des `staff_types`. La section « Personnels » exige un type : c'est lui
     * qui designe le role et la table de rattachement.
     */
    protected function staffTypeFor(string $role): StaffType
    {
        return StaffType::where('matched_role', $role)->orderBy('id')->firstOrFail();
    }

    protected function makeCashier(): User
    {
        $user = $this->makeUserWithRole(Roles::CASHIER);

        Cashier::create(['user_id' => $user->getKey()]);

        return $user;
    }

    /**
     * Les deux caisses du seeder, indispensables au routage sous condition de
     * paiement : sans elles, RouteThroughCaisse laisse passer en direct.
     *
     * @return array{0: Service, 1: Service}
     */
    protected function makeCaisses(): array
    {
        return [
            Service::factory()->caisse()->create(['name' => Service::CAISSE_TICKET]),
            Service::factory()->caisse()->create(['name' => Service::CAISSE_SERVICES]),
        ];
    }

    protected function makeDoctor(Service $service, ?string $phone = null): Doctor
    {
        return Doctor::create([
            'user_id' => $this->makeUserWithRole(Roles::DOCTOR)->getKey(),
            'service_id' => $service->getKey(),
            'phone' => $phone,
        ]);
    }

    /**
     * Ouvre un passage pour un patient (nouveau par defaut) dans un service.
     */
    protected function makeVisit(Service $service, array $attributes = [], ?Patient $patient = null): Visit
    {
        return Visit::factory()->create(array_merge([
            'patient_id' => ($patient ?? Patient::factory()->create())->getKey(),
            'service_id' => $service->getKey(),
        ], $attributes));
    }

    private function makeUserWithRole(string $role): User
    {
        Role::findOrCreate($role, 'web');

        $user = User::factory()->create();
        $user->syncRoles([$role]);

        return $user;
    }
}
