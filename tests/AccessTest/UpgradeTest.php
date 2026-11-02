<?php declare(strict_types=1);

namespace AccessTest;

use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the upgrade script of the module.
 *
 * @group upgrade
 */
class UpgradeTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
    }

    /**
     * The upgrade from 3.4.49 rebuilds the index to create the missing
     * statuses.
     */
    public function testUpgradeFrom3449DispatchesRebuild(): void
    {
        $connection = $this->getConnection();
        $lastJobId = (int) $connection->fetchOne('SELECT MAX(`id`) FROM `job`');

        $module = new \Access\Module();
        $module->upgrade('3.4.49', '3.4.50', $this->getServiceLocator());

        $this->assertSame(1, (int) $connection->fetchOne(
            'SELECT COUNT(*) FROM `job` WHERE `id` > ? AND `class` = ?',
            [$lastJobId, \Access\Job\AccessStatusRebuild::class]
        ));
    }
}
