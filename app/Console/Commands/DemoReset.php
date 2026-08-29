<?php

namespace App\Console\Commands;

use App\Models\Setting;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * Remet l'environnement de demonstration a un etat propre avant de le montrer
 * a un nouveau prospect.
 *
 * Cette commande est volontairement reservee a la ligne de commande : aucune
 * route ne l'expose, et elle refuse de s'executer hors console. Un appel via
 * Artisan::call() depuis une requete HTTP est donc rejete, ce qui evite qu'un
 * declenchement accidentel — ou malveillant — vide la base pendant une
 * demonstration.
 *
 * Le VPS de demonstration ne contient que des donnees fictives : c'est la
 * condition qui rend ce vidage acceptable. Il ne doit jamais etre lance sur
 * l'installation reelle d'un hopital.
 */
class DemoReset extends Command
{
    protected $signature = 'demo:reset
                            {--force : Ne pas demander de confirmation (usage non interactif)}
                            {--complet : Vider aussi les services, le personnel et les comptes avant de les recreer}';

    protected $description = 'Reinitialise les donnees de demonstration : vide les donnees et relance les seeders.';

    /**
     * Nom retabli a chaque reinitialisation, quel que soit celui saisi pendant
     * la demonstration precedente : sans cela, le nom d'un autre etablissement
     * teste la veille resterait affiche devant le prospect suivant.
     */
    public const NOM_HOPITAL = 'Hopital Fousseyni Daou';

    /**
     * Donnees produites par l'usage de l'application : c'est ce qu'une
     * demonstration laisse derriere elle.
     *
     * @var array<int, string>
     */
    private const TABLES_TRANSACTIONNELLES = [
        'attachments',
        'care_tasks',
        'hospitalizations',
        'portal_access_attempts',
        'appointments',
        'prescriptions',
        'payments',
        'patient_history',
        'referrals',
        'visits',
        'companions',
        'visitors',
        'patients',
        'activity_log',
    ];

    /**
     * Configuration de l'etablissement : conservee par defaut, videe avec
     * --complet.
     *
     * Deux tables en sont volontairement absentes : `service_kinds` et
     * `staff_types` sont peuplees par leurs migrations, pas par un seeder.
     * Les vider les laisserait vides jusqu'a un `migrate:fresh`, et les
     * services comme le personnel deviendraient increables.
     *
     * `rooms` et `care_task_types` sont, elles, videes : ce sont des
     * catalogues saisis en demonstration, susceptibles de porter le
     * vocabulaire d'un prospect precedent. Aucun seeder ne les recree, elles
     * se resaisissent depuis l'administration.
     *
     * @var array<int, string>
     */
    private const TABLES_CONFIGURATION = [
        'schedules',
        'rooms',
        'care_task_types',
        'doctors',
        'receptionists',
        'cashiers',
        'staff_members',
        'model_has_roles',
        'model_has_permissions',
        'users',
        'sessions',
        'services',
    ];

    public function handle(): int
    {
        if (! $this->getLaravel()->runningInConsole()) {
            $this->error('demo:reset ne peut etre lancee qu en ligne de commande.');

            return self::FAILURE;
        }

        $complet = (bool) $this->option('complet');
        $tables = $complet
            ? array_merge(self::TABLES_TRANSACTIONNELLES, self::TABLES_CONFIGURATION)
            : self::TABLES_TRANSACTIONNELLES;

        $this->afficherInventaire($tables);

        if (! $this->option('force') && ! $this->confirm('Vider ces tables et relancer les seeders de demonstration ?', false)) {
            $this->line('Abandon : aucune donnee touchee.');

            return self::SUCCESS;
        }

        $this->viderLesTables($tables);
        $this->viderLesPiecesJointes();

        // Les seeders recreent roles, services, personnel et comptes de demo.
        // Ils sont idempotents : relances sans vidage, ils ne creent que ce
        // qui manque.
        $this->callSilent('db:seed', ['--class' => DatabaseSeeder::class, '--force' => true]);

        // SettingSeeder utilise firstOrCreate : il ne corrigerait pas un nom
        // deja present. On l'impose donc explicitement.
        Setting::put(Setting::HOSPITAL_NAME, self::NOM_HOPITAL);

        Cache::flush();

        $this->info(sprintf(
            'Demonstration reinitialisee (%s). Etablissement : %s.',
            $complet ? 'vidage complet' : 'donnees transactionnelles',
            self::NOM_HOPITAL,
        ));

        if ($complet) {
            $this->line('Chambres et types de soins vides : a resaisir depuis l\'administration si la demonstration doit les montrer.');
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function afficherInventaire(array $tables): void
    {
        $lignes = [];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $nombre = DB::table($table)->count();

            if ($nombre > 0) {
                $lignes[] = [$table, $nombre];
            }
        }

        if ($lignes === []) {
            $this->line('Aucune donnee a supprimer.');

            return;
        }

        $this->table(['Table', 'Lignes supprimees'], $lignes);
    }

    /**
     * @param  array<int, string>  $tables
     */
    private function viderLesTables(array $tables): void
    {
        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tables as $table) {
                if (Schema::hasTable($table)) {
                    DB::table($table)->truncate();
                }
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }
    }

    /**
     * Les fichiers deposes pendant une demonstration doivent disparaitre en
     * meme temps que leurs enregistrements : une piece jointe orpheline reste
     * lisible par quiconque connait son chemin.
     */
    private function viderLesPiecesJointes(): void
    {
        $disque = Storage::disk('attachments');

        foreach ($disque->allFiles() as $fichier) {
            $disque->delete($fichier);
        }

        foreach ($disque->directories() as $dossier) {
            $disque->deleteDirectory($dossier);
        }
    }
}
