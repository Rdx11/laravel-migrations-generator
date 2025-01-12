<?php

namespace KitLoong\MigrationsGenerator;

use Carbon\Carbon;
use Exception;
use Illuminate\Console\Command;
use Illuminate\Database\Migrations\MigrationRepositoryInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use KitLoong\MigrationsGenerator\Enum\Driver;
use KitLoong\MigrationsGenerator\Migration\MigrationInterface;
use KitLoong\MigrationsGenerator\Schema\Models\View;
use KitLoong\MigrationsGenerator\Schema\MySQLSchema;
use KitLoong\MigrationsGenerator\Schema\PgSQLSchema;
use KitLoong\MigrationsGenerator\Schema\Schema;
use KitLoong\MigrationsGenerator\Schema\SQLSrvSchema;

class MigrateGenerateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'migrate:generate
                            {tables? : A list of Tables or Views you wish to Generate Migrations for separated by a comma: users,posts,comments}
                            {--c|connection= : The database connection to use}
                            {--t|tables= : A list of Tables or Views you wish to Generate Migrations for separated by a comma: users,posts,comments}
                            {--i|ignore= : A list of Tables or Views you wish to ignore, separated by a comma: users,posts,comments}
                            {--p|path= : Where should the file be created?}
                            {--tp|template-path= : The location of the template for this generator}
                            {--date= : Migrations will be created with specified date. Views and Foreign keys will be created with + 1 second. Date should be in format suitable for Carbon::parse}
                            {--table-filename= : Define table migration filename, default pattern: [datetime_prefix]_create_[table]_table.php}
                            {--view-filename= : Define view migration filename, default pattern: [datetime_prefix]_create_[table]_view.php}
                            {--fk-filename= : Define foreign key migration filename, default pattern: [datetime_prefix]_add_foreign_keys_to_[table]_table.php}
                            {--default-index-names : Don\'t use db index names for migrations}
                            {--default-fk-names : Don\'t use db foreign key names for migrations}
                            {--use-db-collation : Follow db collations for migrations}
                            {--skip-views : Don\'t generate views}
                            {--squash : Generate all migrations into a single file}';

    /**
     * The console command description.
     */
    protected $description = 'Generate a migration from an existing table structure.';

    protected $repository;

    protected $shouldLog = false;

    protected $nextBatchNumber = 0;

    /**
     * @var \KitLoong\MigrationsGenerator\Schema\Schema
     */
    protected $schema;

    protected $migration;

    public function __construct(
        MigrationRepositoryInterface $repository,
        MigrationInterface $migration
    ) {
        parent::__construct();

        $this->migration  = $migration;
        $this->repository = $repository;
    }

    /**
     * Execute the console command.
     *
     * @return void
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     * @throws \Exception
     */
    public function handle(): void
    {
        $previousConnection = DB::getDefaultConnection();
        try {
            $this->setup();

            $connection = $this->option('connection') ?: $previousConnection;

            DB::setDefaultConnection($connection);

            $this->schema = $this->makeSchema();

            $this->info('Using connection: ' . $connection . "\n");

            $tables       = $this->filterTables();
            $views        = $this->filterViews();
            $generateList = $tables->merge($views)->unique();

            $this->info('Generating migrations for: ' . $generateList->implode(',') . "\n");

            $this->askIfLogMigrationTable($previousConnection);

            $this->generate($tables, $views);

            $this->info("\nFinished!\n");
        } finally {
            DB::setDefaultConnection($previousConnection);
        }
    }

    /**
     * Setup by setting configuration + command options into Setting.
     * Setting is a singleton and will be used as generator configuration.
     */
    protected function setup(): void
    {
        $setting = app(Setting::class);
        $setting->setUseDBCollation($this->option('use-db-collation'));
        $setting->setIgnoreIndexNames($this->option('default-index-names'));
        $setting->setIgnoreForeignKeyNames($this->option('default-fk-names'));
        $setting->setSquash((bool) $this->option('squash'));

        $setting->setPath(
            $this->option('path') ?? Config::get('generators.config.migration_target_path')
        );

        $setting->setStubPath(
            $this->option('template-path') ?? Config::get('generators.config.migration_template_path')
        );

        $setting->setDate(
            $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::now()
        );

        $setting->setTableFilename(
            $this->option('table-filename') ?? Config::get('generators.config.filename_pattern.table')
        );

        $setting->setViewFilename(
            $this->option('view-filename') ?? Config::get('generators.config.filename_pattern.view')
        );

        $setting->setFkFilename(
            $this->option('fk-filename') ?? Config::get('generators.config.filename_pattern.foreign_key')
        );
    }

    /**
     * Get all tables from schema or return table list provided in option.
     * Then filter and exclude tables in --ignore option if any.
     * Also exclude migrations table
     *
     * @return \Illuminate\Support\Collection<string> Filtered table names.
     */
    protected function filterTables(): Collection
    {
        $allTables = $this->schema->getTableNames();

        return $this->filterAndExcludeAsset($allTables);
    }

    /**
     * Get all views from schema or return table list provided in option.
     * Then filter and exclude tables in --ignore option if any.
     * Return empty if --skip-views
     *
     * @return \Illuminate\Support\Collection<string> Filtered view names.
     */
    protected function filterViews(): Collection
    {
        if ($this->option('skip-views')) {
            return new Collection([]);
        }

        $allViews = $this->schema->getViewNames();

        return $this->filterAndExcludeAsset($allViews);
    }

    /**
     * Filter and exclude tables in --ignore option if any.
     *
     * @param  \Illuminate\Support\Collection<string>  $allAssets  Names before filter.
     * @return \Illuminate\Support\Collection<string> Filtered names.
     */
    protected function filterAndExcludeAsset(Collection $allAssets): Collection
    {
        $tables = $allAssets;

        $tableArg = (string) $this->argument('tables');
        if ($tableArg !== '') {
            $tables = $allAssets->intersect(explode(',', $tableArg));
            return $tables->diff($this->getExcludedTables());
        }

        $tableOpt = (string) $this->option('tables');
        if ($tableOpt !== '') {
            $tables = $allAssets->intersect(explode(',', $tableOpt));
            return $tables->diff($this->getExcludedTables());
        }

        return $tables->diff($this->getExcludedTables());
    }

    /**
     * Get a list of tables to be excluded.
     *
     * @return string[]
     */
    protected function getExcludedTables(): array
    {
        $prefix         = DB::getTablePrefix();
        $migrationTable = $prefix . Config::get('database.migrations');

        $excludes = [$migrationTable];
        $ignore   = (string) $this->option('ignore');
        if (!empty($ignore)) {
            return array_merge([$migrationTable], explode(',', $ignore));
        }

        return $excludes;
    }

    /**
     * Asks user for log migration permission.
     *
     * @param  string  $defaultConnection
     * @return void
     */
    protected function askIfLogMigrationTable(string $defaultConnection): void
    {
        if (!$this->option('no-interaction')) {
            $this->shouldLog = $this->confirm('Do you want to log these migrations in the migrations table?', true);
        }

        if ($this->shouldLog) {
            $this->repository->setSource(DB::getName());
            if ($defaultConnection !== DB::getName()) {
                if (
                    !$this->confirm(
                        'Log into current connection: ' . DB::getName() . '? [Y = ' . DB::getName() . ', n = ' . $defaultConnection . ' (default connection)]',
                        true
                    )
                ) {
                    $this->repository->setSource($defaultConnection);
                }
            }

            if (!$this->repository->repositoryExists()) {
                $this->repository->createRepository();
            }

            $this->nextBatchNumber = $this->askInt(
                'Next Batch Number is: ' . $this->repository->getNextBatchNumber() . '. We recommend using Batch Number 0 so that it becomes the "first" migration',
                0
            );
        }
    }

    /**
     * Ask user for a Numeric Value, or blank for default.
     *
     * @param  string  $question  Question to ask
     * @param  int|null  $default  Default Value (optional)
     * @return int Answer
     */
    protected function askInt(string $question, int $default = null): int
    {
        $ask = 'Your answer needs to be a numeric value';

        if (!is_null($default)) {
            $question .= ' [Default: ' . $default . ']';
            $ask      .= ' or blank for default. [Default: ' . $default . ']';
        }

        $answer = $this->ask($question, (string) $default);
        while (!ctype_digit($answer) && !($answer === '' && !is_null($default))) {
            $answer = $this->ask($ask, (string) $default);
        }

        if ($answer === '') {
            $answer = $default;
        }

        return (int) $answer;
    }

    /**
     * Generates table, view and foreign key migrations.
     *
     * @param  \Illuminate\Support\Collection<string>  $tables  Table names.
     * @param  \Illuminate\Support\Collection<string>  $views  View names.
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function generate(Collection $tables, Collection $views): void
    {
        if (app(Setting::class)->isSquash()) {
            $this->generateSquashedMigrations($tables, $views);
            return;
        }

        $this->generateMigrations($tables, $views);
    }

    /**
     * Generates table, view and foreign key migrations.
     *
     * @param  \Illuminate\Support\Collection<string>  $tables  Table names.
     * @param  \Illuminate\Support\Collection<string>  $views  View names.
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function generateMigrations(Collection $tables, Collection $views): void
    {
        $this->info('Setting up Tables and Index Migrations');
        $this->generateTables($tables);

        if (!$this->option('skip-views')) {
            $this->info("\nSetting up Views Migrations");
            $this->generateViews($views);
        }

        $this->info("\nSetting up Foreign Key Migrations");
        $this->generateForeignKeys($tables);
    }

    /**
     * Generate all migrations in a single file.
     *
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function generateSquashedMigrations(Collection $tables, Collection $views): void
    {
        $this->info('Remove old temporary files if any.');
        $this->migration->cleanTemps();

        $this->info('Preparing Tables and Index Migrations');
        $this->generateTablesToTemp($tables);

        $this->info("\nPreparing Views Migrations");
        $this->generateViewsToTemp($views);

        $this->info("\nPreparing Foreign Key Migrations");
        $this->generateForeignKeysToTemp($tables);

        $migrationFilepath = $this->migration->squashMigrations();

        $this->info("\nAll migrations squashed.");

        if ($this->shouldLog) {
            $this->logMigration($migrationFilepath);
        }
    }

    /**
     * Generates table migrations.
     *
     * @param  \Illuminate\Support\Collection<string>  $tables  Table names.
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function generateTables(Collection $tables): void
    {
        $tables->each(function (string $table) {
            $path = $this->migration->writeTable(
                $this->schema->getTable($table)
            );

            $this->info("Created: $path");

            if ($this->shouldLog) {
                $this->logMigration($path);
            }
        });
    }

    /**
     * Generates table migrations.
     *
     * @param  \Illuminate\Support\Collection<string>  $tables  Table names.
     */
    protected function generateTablesToTemp(Collection $tables): void
    {
        $tables->each(function (string $table) {
            $this->migration->writeTableToTemp(
                $this->schema->getTable($table)
            );

            $this->info("Prepared: $table");
        });
    }

    /**
     * Generate view migrations.
     *
     * @param  \Illuminate\Support\Collection<string>  $views  View names.
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function generateViews(Collection $views): void
    {
        $schemaViews = $this->schema->getViews();
        $schemaViews->each(function (View $view) use ($views) {
            if (!$views->contains($view->getName())) {
                return;
            }

            $path = $this->migration->writeView($view);

            $this->info("Created: $path");

            if ($this->shouldLog) {
                $this->logMigration($path);
            }
        });
    }

    /**
     * Generate view migrations.
     *
     * @param  \Illuminate\Support\Collection<string>  $views  View names.
     */
    protected function generateViewsToTemp(Collection $views): void
    {
        $schemaViews = $this->schema->getViews();
        $schemaViews->each(function (View $view) use ($views) {
            if (!$views->contains($view->getName())) {
                return;
            }

            $this->migration->writeViewToTemp($view);

            $this->info('Prepared: ' . $view->getName());
        });
    }

    /**
     * Generates foreign key migrations.
     *
     * @param  \Illuminate\Support\Collection<string>  $tables  Table names.
     * @throws \Illuminate\Contracts\Filesystem\FileNotFoundException
     */
    protected function generateForeignKeys(Collection $tables): void
    {
        $tables->each(function (string $table) {
            $foreignKeys = $this->schema->getTableForeignKeys($table);
            if ($foreignKeys->isNotEmpty()) {
                $path = $this->migration->writeTableForeignKeys(
                    $table,
                    $foreignKeys
                );

                $this->info("Created: $path");

                if ($this->shouldLog) {
                    $this->logMigration($path);
                }
            }
        });
    }

    /**
     * Generates foreign key migrations.
     *
     * @param  \Illuminate\Support\Collection<string>  $tables  Table names.
     */
    protected function generateForeignKeysToTemp(Collection $tables): void
    {
        $tables->each(function (string $table) {
            $foreignKeys = $this->schema->getTableForeignKeys($table);
            if ($foreignKeys->isNotEmpty()) {
                $this->migration->writeForeignKeysToTemp(
                    $table,
                    $foreignKeys
                );

                $this->info('Prepared: ' . $table);
            }
        });
    }

    /**
     * Logs migration repository.
     *
     * @param  string  $migrationFilepath
     */
    protected function logMigration(string $migrationFilepath): void
    {
        $file = basename($migrationFilepath, '.php');
        $this->repository->log($file, $this->nextBatchNumber);
    }

    /**
     * Get DB schema by the database connection name.
     *
     * @return \KitLoong\MigrationsGenerator\Schema\Schema
     * @throws \Exception
     */
    protected function makeSchema(): Schema
    {
        $driver = DB::getDriverName();

        if (!$driver) {
            throw new Exception('Failed to find database driver.');
        }

        switch ($driver) {
            case Driver::MYSQL():
                return $this->schema = app(MySQLSchema::class);
            case Driver::PGSQL():
                return $this->schema = app(PgSQLSchema::class);
            case Driver::SQLSRV():
                return $this->schema = app(SQLSrvSchema::class);
            default:
                throw new Exception('The database driver in use is not supported.');
        }
    }
}
