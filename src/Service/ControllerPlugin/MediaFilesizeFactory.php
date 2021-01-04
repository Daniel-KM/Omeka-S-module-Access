<?php declare(strict_types=1);
namespace AccessResource\Service\ControllerPlugin;

use AccessResource\Mvc\Controller\Plugin\MediaFilesize;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class MediaFilesizeFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $basePath = $services->get('Config')['file_store']['local']['base_path'] ?: (OMEKA_PATH . '/files');
        return new MediaFilesize($basePath);
    }
}
