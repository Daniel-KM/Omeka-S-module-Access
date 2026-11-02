<?php declare(strict_types=1);

namespace AccessTest\Service;

use AccessTest\AccessTestTrait;
use Doctrine\DBAL\Logging\DebugStack;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * Tests for the preload of the access statuses for the json-ld.
 */
class JsonLdPreloadTest extends AbstractHttpControllerTestCase
{
    use AccessTestTrait;

    public function setUp(): void
    {
        parent::setUp();
        $this->loginAdmin();
        if ($this->getSettings()->get('access_property')) {
            $this->markTestSkipped('The status is not appended to the json-ld in property mode.');
        }
    }

    public function tearDown(): void
    {
        $this->getConnection()->getConfiguration()->setSQLLogger(null);
        $this->cleanupResources();
        $this->logout();
        parent::tearDown();
    }

    public function testStatusesAreLoadedWithOneQuery(): void
    {
        $ids = [];
        for ($i = 0; $i < 5; ++$i) {
            $ids[] = $this->createItem()->id();
        }
        $this->getEntityManager()->clear();

        $logger = new DebugStack();
        $this->getConnection()->getConfiguration()->setSQLLogger($logger);
        $items = $this->api()->search('items', ['id' => $ids])->getContent();
        foreach ($items as $item) {
            $jsonLd = json_decode(json_encode($item), true);
            $this->assertArrayHasKey('o-access:status', $jsonLd);
        }
        $this->getConnection()->getConfiguration()->setSQLLogger(null);

        $statusQueries = array_filter($logger->queries, fn ($query) => stripos($query['sql'], 'access_status') !== false);
        $this->assertCount(1, $statusQueries);
    }
}
