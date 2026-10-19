<?php declare(strict_types=1);

namespace AccessTest\Controller;

use Laminas\Log\Writer\Mock;
use Omeka\Test\AbstractHttpControllerTestCase;

/**
 * A missing file is a page not found, logged only when its resource exists.
 */
class AccessFileNotFoundTest extends AbstractHttpControllerTestCase
{
    protected ?int $itemId = null;

    protected Mock $writer;

    public function setUp(): void
    {
        parent::setUp();
        $this->writer = new Mock();
        $this->getApplicationServiceLocator()->get('Omeka\Logger')->addWriter($this->writer);
    }

    public function tearDown(): void
    {
        if ($this->itemId) {
            $this->loginAdmin();
            $this->getApplicationServiceLocator()->get('Omeka\ApiManager')->delete('items', $this->itemId);
        }
        parent::tearDown();
    }

    public function testFileOfDeletedMediaIsNotFoundWithoutLog(): void
    {
        $this->dispatch('/access/files/original/' . str_repeat('0', 40) . '.pdf');
        $this->assertResponseStatusCode(404);
        $this->assertSame([], $this->errors());
    }

    public function testMissingFileOfExistingMediaIsLogged(): void
    {
        $this->loginAdmin();
        $services = $this->getApplicationServiceLocator();
        $item = $services->get('Omeka\ApiManager')->create('items', [
            'o:is_public' => true,
            'o:media' => [['o:ingester' => 'html', 'html' => '<p>Page</p>']],
        ])->getContent();
        $this->itemId = (int) $item->id();
        $storageId = str_repeat('f', 40);
        $services->get('Omeka\Connection')->executeStatement(
            'UPDATE `media` SET `storage_id` = ?, `extension` = "pdf", `has_original` = 1 WHERE `id` = ?',
            [$storageId, $item->media()[0]->id()]
        );
        $services->get('Omeka\AuthenticationService')->clearIdentity();

        $this->dispatch('/access/files/original/' . $storageId . '.pdf');
        $this->assertResponseStatusCode(404);
        $errors = $this->errors();
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('is invalid or not available', $errors[0]);
    }

    /**
     * @return string[]
     */
    protected function errors(): array
    {
        $errors = [];
        foreach ($this->writer->events as $event) {
            if ($event['priority'] <= \Laminas\Log\Logger::ERR) {
                $errors[] = (string) $event['message'];
            }
        }
        return $errors;
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
