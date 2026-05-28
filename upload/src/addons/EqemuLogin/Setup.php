<?php namespace EqemuLogin;

use XF\AddOn\AbstractSetup;
use XF\Db\Schema\Create;

class Setup extends AbstractSetup
{
    public function install(array $stepParams = [])
    {
        $this->createTables();
    }

    public function upgrade(array $stepParams = [])
    {
        $this->createTables();
    }

    public function uninstall(array $stepParams = [])
    {
        $this->dropTables();
    }

    protected function createTables()
    {
        if (!$this->schemaManager()->tableExists('xf_eqemu_migration_log')) {
            $this->schemaManager()->createTable('xf_eqemu_migration_log', function(Create $table) {
                $table->addColumn('log_id', 'int')->autoIncrement();
                $table->addColumn('user_id', 'int');
                $table->addColumn('character_name', 'varchar', 50);
                $table->addColumn('source_account_id', 'int');
                $table->addColumn('target_account_id', 'int');
                $table->addColumn('migration_date', 'int');
                $table->addPrimaryKey('log_id');
                $table->addKey('user_id');
                $table->addKey('migration_date');
            });
        }
    }

    protected function dropTables()
    {
        $this->schemaManager()->dropTable('xf_eqemu_migration_log');
    }
}
