<?php declare(strict_types=1);

namespace AccessTest;

use Omeka\Module\Manager as ModuleManager;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Install of the module Access over the tables of the module AccessResource,
 * even when the module AccessResource is not present.
 *
 * Each test uninstalls Access, creates the tables of AccessResource, then
 * installs Access, so Access is installed again at the end.
 *
 * @group upgrade
 */
class UpgradeFromAccessResourceTest extends AbstractHttpControllerTestCase
{
    protected array $itemIds = [];

    protected ?string $htaccess = null;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        // The uninstall and the install of the module update the htaccess.
        $this->htaccess = @file_get_contents(OMEKA_PATH . '/.htaccess') ?: null;
    }

    public function tearDown(): void
    {
        $this->loginAdmin();
        $manager = $this->moduleManager();
        $module = $manager->getModule('Access');
        if ($module->getState() === ModuleManager::STATE_NOT_INSTALLED) {
            $manager->install($module);
        }
        $this->connection()->executeStatement('DELETE FROM `module` WHERE `id` = "AccessResource"');
        if ($this->htaccess !== null) {
            file_put_contents(OMEKA_PATH . '/.htaccess', $this->htaccess);
        }
        foreach ($this->itemIds as $id) {
            try {
                $this->api()->delete('items', $id);
            } catch (\Throwable $e) {
            }
        }
        parent::tearDown();
    }

    /**
     * Tables of AccessResource 3.4.16 only: no module, no row in table module.
     */
    public function testInstallOverTablesOfAccessResource3416WithoutModule(): void
    {
        // A private item set, for example a technical one, should not restrict
        // its public items.
        $privateItemSetId = (int) $this->api()->create('item_sets', ['o:is_public' => false])->getContent()->id();
        $publicId = (int) $this->api()->create('items', ['o:is_public' => true, 'o:item_set' => [['o:id' => $privateItemSetId]]])->getContent()->id();
        $this->itemIds[] = $publicId;
        $privateId = $this->createItem(false);
        $reservedId = $this->createItem(false);
        $adminId = (int) $this->connection()->fetchOne('SELECT `id` FROM `user` ORDER BY `id` LIMIT 1');

        $this->uninstallAccess();
        $this->createTables('accessresource-3.4.16.sql');
        $connection = $this->connection();
        $connection->executeStatement('INSERT INTO `access_reserved` (`id`) VALUES (?)', [$reservedId]);
        // The anonymous access had a null user id, that blocked the upgrade.
        $connection->executeStatement(
            'INSERT INTO `access_log` (`user_id`, `action`, `record_id`, `type`, `date`) VALUES (NULL, "accessed", ?, "access", NOW()), (?, "accessed", ?, "access", NOW())',
            [$reservedId, $adminId, $reservedId]
        );
        $settings = $this->getApplicationServiceLocator()->get('Omeka\Settings');
        $settings->set('accessresource_message_access_text', '');
        $settings->set('accessresource_embargo_bypass', true);

        $this->installAccess();

        $levels = $connection->fetchAllKeyValue('SELECT `id`, `level` FROM `access_status` WHERE `id` IN (?, ?, ?)', [$publicId, $privateId, $reservedId]);
        $this->assertSame('free', $levels[$publicId]);
        $this->assertSame('forbidden', $levels[$privateId]);
        $this->assertSame('reserved', $levels[$reservedId]);
        $this->assertSame('free', $connection->fetchOne('SELECT `level` FROM `access_status` WHERE `id` = ?', [$privateItemSetId]));
        // The reserved resource was visible in AccessResource.
        $this->assertSame(1, (int) $connection->fetchOne('SELECT `is_public` FROM `resource` WHERE `id` = ?', [$reservedId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT `is_public` FROM `resource` WHERE `id` = ?', [$privateId]));
        $this->api()->delete('item_sets', $privateItemSetId);

        $this->assertSame([0, $adminId], array_map('intval', $connection->fetchFirstColumn('SELECT `user_id` FROM `access_log` ORDER BY `id`')));
        $columns = $connection->fetchFirstColumn('SHOW COLUMNS FROM `access_log`');
        $this->assertContains('access_id', $columns);
        $this->assertContains('access_type', $columns);

        $settings = $this->getApplicationServiceLocator()->get('Omeka\Settings');
        $this->assertNotEmpty($settings->get('access_message_access_text'), 'The access message is set');
        $this->assertSame(['auth_guest'], $settings->get('access_modes'), 'The default mode "guest" was renamed');
        $this->assertFalse((bool) $connection->fetchOne('SELECT 1 FROM `setting` WHERE `id` LIKE "accessresource#_%" ESCAPE "#"'));
        $this->assertFalse((bool) $connection->fetchOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "access_reserved"'));
    }

    /**
     * Tables and settings of AccessResource 3.4.17.1, with its row in the table
     * module, but without its files.
     */
    public function testInstallOverAccessResource34171WithoutFiles(): void
    {
        $reservedId = $this->createItem(true);
        $itemSetId = (int) $this->api()->create('item_sets', [])->getContent()->id();

        $this->uninstallAccess();
        $this->createTables('accessresource-3.4.17.1.sql');
        $connection = $this->connection();
        $connection->executeStatement('INSERT INTO `module` (`id`, `is_active`, `version`) VALUES ("AccessResource", 0, "3.4.17.1")');
        $connection->executeStatement('INSERT INTO `access_status` (`id`, `level`) VALUES (?, "reserved")', [$reservedId]);
        $settings = $this->getApplicationServiceLocator()->get('Omeka\Settings');
        $settings->set('accessresource_access_modes', ['ip']);
        $settings->set('accessresource_ip_item_sets', ['192.0.2.10' => (string) $itemSetId]);
        $settings->set('accessresource_message_access_text', 'Custom message.');

        $this->installAccess();

        $this->assertSame('reserved', $connection->fetchOne('SELECT `level_set` FROM `access_status` WHERE `id` = ?', [$reservedId]));
        $this->assertFalse((bool) $connection->fetchOne('SELECT 1 FROM `module` WHERE `id` = "AccessResource"'));

        $settings = $this->getApplicationServiceLocator()->get('Omeka\Settings');
        // The upgraded settings are not overwritten by the default ones.
        $this->assertSame(['ip'], $settings->get('access_modes'));
        $this->assertSame('Custom message.', $settings->get('access_message_access_text'));
        $rules = $settings->get('access_ip_rules');
        $this->assertSame([$itemSetId], $rules['192.0.2.10']['allow'] ?? null);
        $this->api()->delete('item_sets', $itemSetId);
    }

    /**
     * An upgrade of AccessResource to 3.4.17.1 that stopped on the anonymous
     * logs, after the removal of their foreign key and index, but marked as
     * done in the table module.
     */
    public function testInstallResumesFailedUpgradeOfAccessResource(): void
    {
        $reservedId = $this->createItem(false);

        $this->uninstallAccess();
        $this->createTables('accessresource-3.4.16.sql');
        $connection = $this->connection();
        $connection->executeStatement('ALTER TABLE `access_log` DROP FOREIGN KEY FK_EF7F3510A76ED395');
        $connection->executeStatement('DROP INDEX IDX_EF7F3510A76ED395 ON `access_log`');
        $connection->executeStatement('INSERT INTO `access_reserved` (`id`) VALUES (?)', [$reservedId]);
        $connection->executeStatement(
            'INSERT INTO `access_log` (`user_id`, `action`, `record_id`, `type`, `date`) VALUES (NULL, "accessed", ?, "access", NOW())',
            [$reservedId]
        );
        $connection->executeStatement('INSERT INTO `module` (`id`, `is_active`, `version`) VALUES ("AccessResource", 1, "3.4.17.1")');

        $this->installAccess();

        $this->assertSame('reserved', $connection->fetchOne('SELECT `level` FROM `access_status` WHERE `id` = ?', [$reservedId]));
        $this->assertSame(0, (int) $connection->fetchOne('SELECT `user_id` FROM `access_log`'));
        $this->assertFalse((bool) $connection->fetchOne('SELECT 1 FROM `module` WHERE `id` = "AccessResource"'));
    }

    /**
     * The vocabulary curation of AccessResource 3.4.16 had the properties
     * "dateStart" and "dateEnd", renamed via sql during the upgrade, so the
     * update of the vocabulary should not create them again.
     */
    public function testInstallOverOldCurationVocabulary(): void
    {
        $reservedId = $this->createItem(false);

        $this->uninstallAccess();
        $this->createTables('accessresource-3.4.16.sql');
        $connection = $this->connection();
        $connection->executeStatement('INSERT INTO `access_reserved` (`id`) VALUES (?)', [$reservedId]);
        $vocabularyId = (int) $connection->fetchOne('SELECT `id` FROM `vocabulary` WHERE `prefix` = "curation"');
        $this->assertNotEmpty($vocabularyId, 'The vocabulary curation exists');
        $connection->executeStatement('UPDATE `property` SET `local_name` = "dateStart" WHERE `vocabulary_id` = ? AND `local_name` = "start"', [$vocabularyId]);
        $connection->executeStatement('UPDATE `property` SET `local_name` = "dateEnd" WHERE `vocabulary_id` = ? AND `local_name` = "end"', [$vocabularyId]);

        $this->installAccess();

        $names = $connection->fetchFirstColumn('SELECT `local_name` FROM `property` WHERE `vocabulary_id` = ? ORDER BY `local_name`', [$vocabularyId]);
        $this->assertContains('start', $names);
        $this->assertContains('end', $names);
        $this->assertNotContains('dateStart', $names);
        $this->assertNotContains('dateEnd', $names);
        $this->assertSame('reserved', $connection->fetchOne('SELECT `level` FROM `access_status` WHERE `id` = ?', [$reservedId]));
    }

    /**
     * The tables of Access exist with their data, but the module is not marked
     * as installed, for example after an install that failed once the tables
     * were upgraded: the install keeps them.
     */
    public function testInstallKeepsExistingTablesOfAccess(): void
    {
        $reservedId = $this->createItem(true);
        $connection = $this->connection();
        $connection->executeStatement('UPDATE `access_status` SET `level` = "reserved", `level_set` = "reserved" WHERE `id` = ?', [$reservedId]);
        $settings = $this->getApplicationServiceLocator()->get('Omeka\Settings');
        $settings->set('access_message_access_text', 'Custom message.');
        $connection->executeStatement('DELETE FROM `module` WHERE `id` = "Access"');

        // Reload the modules, so Access is not installed.
        $this->reset();
        $this->loginAdmin();
        $manager = $this->moduleManager();
        $this->assertSame(ModuleManager::STATE_NOT_INSTALLED, $manager->getModule('Access')->getState());

        $this->installAccess();

        $this->assertSame(ModuleManager::STATE_ACTIVE, $this->moduleManager()->getModule('Access')->getState());
        $this->assertSame('reserved', $connection->fetchOne('SELECT `level_set` FROM `access_status` WHERE `id` = ?', [$reservedId]));
        $settings = $this->getApplicationServiceLocator()->get('Omeka\Settings');
        $this->assertSame('Custom message.', $settings->get('access_message_access_text'));
    }

    /**
     * The tables of Access are upgraded, but an old AccessResource remains with
     * the data of a failed upgrade: the tables are kept and the data removed.
     */
    public function testInstallSkipsAccessResourceWhenTablesOfAccessExist(): void
    {
        $reservedId = $this->createItem(true);
        $connection = $this->connection();
        $connection->executeStatement('UPDATE `access_status` SET `level` = "reserved", `level_set` = "reserved" WHERE `id` = ?', [$reservedId]);
        $connection->executeStatement('DELETE FROM `module` WHERE `id` = "Access"');
        $connection->executeStatement('INSERT INTO `module` (`id`, `is_active`, `version`) VALUES ("AccessResource", 1, "3.3.0.10")');
        $connection->executeStatement('CREATE TABLE `access_reserved` (`id` INT NOT NULL, `start_date` DATETIME DEFAULT NULL, `end_date` DATETIME DEFAULT NULL, PRIMARY KEY(`id`)) ENGINE = InnoDB');
        $connection->executeStatement('INSERT INTO `access_reserved` (`id`) VALUES (?)', [$reservedId]);
        $settings = $this->getApplicationServiceLocator()->get('Omeka\Settings');
        $settings->set('accessresource_ip_item_sets', []);
        $adminId = (int) $connection->fetchOne('SELECT `id` FROM `user` ORDER BY `id` LIMIT 1');
        $connection->executeStatement('INSERT INTO `user_setting` (`id`, `user_id`, `value`) VALUES ("accessresource_test", ?, "1")', [$adminId]);
        // The module directory is a symbolic link, like on a managed server: the
        // link is removed, not its target.
        $target = sys_get_temp_dir() . '/AccessResource-' . getmypid();
        @mkdir($target);
        file_put_contents($target . '/Module.php', '<?php');
        $link = OMEKA_PATH . '/modules/AccessResource';
        $this->assertFalse(file_exists($link) || is_link($link), 'No module AccessResource in modules/');
        symlink($target, $link);

        $lastJobId = (int) $connection->fetchOne('SELECT MAX(`id`) FROM `job`');

        $this->reset();
        $this->loginAdmin();
        $this->installAccess();

        $this->assertSame(ModuleManager::STATE_ACTIVE, $this->moduleManager()->getModule('Access')->getState());
        $this->assertSame('reserved', $connection->fetchOne('SELECT `level_set` FROM `access_status` WHERE `id` = ?', [$reservedId]));
        $this->assertTrue((bool) $connection->fetchOne('SELECT 1 FROM `job` WHERE `id` > ? AND `class` = ?', [$lastJobId, \Access\Job\AccessStatusRebuild::class]), 'The rebuild of the access statuses is dispatched');
        $this->assertFalse((bool) $connection->fetchOne('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = "access_reserved"'));
        $this->assertFalse((bool) $connection->fetchOne('SELECT 1 FROM `setting` WHERE `id` LIKE "accessresource#_%" ESCAPE "#"'));
        $this->assertFalse((bool) $connection->fetchOne('SELECT 1 FROM `module` WHERE `id` = "AccessResource"'));
        $this->assertFalse((bool) $connection->fetchOne('SELECT 1 FROM `user_setting` WHERE `id` LIKE "accessresource#_%" ESCAPE "#"'));
        $this->assertFalse(is_link($link), 'The link to the module AccessResource is removed');
        $this->assertFileExists($target . '/Module.php', 'The target of the link is kept');
        @unlink($target . '/Module.php');
        @rmdir($target);
    }

    protected function createItem(bool $isPublic): int
    {
        $id = (int) $this->api()->create('items', ['o:is_public' => $isPublic])->getContent()->id();
        $this->itemIds[] = $id;
        return $id;
    }

    protected function uninstallAccess(): void
    {
        $manager = $this->moduleManager();
        $manager->uninstall($manager->getModule('Access'));
    }

    protected function installAccess(): void
    {
        $manager = $this->moduleManager();
        $manager->install($manager->getModule('Access'));
        $this->getApplicationServiceLocator()->get('Omeka\EntityManager')->clear();
    }

    protected function createTables(string $fixture): void
    {
        $sql = (string) file_get_contents(dirname(__DIR__) . '/fixtures/' . $fixture);
        foreach (array_filter(array_map('trim', explode(";\n", $sql))) as $statement) {
            $this->connection()->executeStatement($statement);
        }
    }

    protected function moduleManager(): ModuleManager
    {
        return $this->getApplicationServiceLocator()->get('Omeka\ModuleManager');
    }

    protected function connection(): \Doctrine\DBAL\Connection
    {
        return $this->getApplicationServiceLocator()->get('Omeka\Connection');
    }

    protected function api(): \Omeka\Api\Manager
    {
        return $this->getApplicationServiceLocator()->get('Omeka\ApiManager');
    }

    protected function loginAdmin(): void
    {
        $auth = $this->getApplicationServiceLocator()->get('Omeka\AuthenticationService');
        $adapter = $auth->getAdapter();
        $adapter->setIdentity('admin@example.com');
        $adapter->setCredential('root');
        $auth->authenticate();
    }
}
