<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use Laminas\Mvc\Controller\Plugin\AbstractPlugin;

class ReservedAccessPropertyId extends AbstractPlugin
{
    /**
     * @var int
     */
    protected $reservedAccessPropertyId;

    public function __construct(int $reservedAccessPropertyId)
    {
        $this->reservedAccessPropertyId = $reservedAccessPropertyId;
    }

    public function __invoke(): int
    {
        return $this->reservedAccessPropertyId;
    }
}
