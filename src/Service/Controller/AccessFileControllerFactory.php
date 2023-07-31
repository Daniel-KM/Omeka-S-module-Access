<?php declare(strict_types=1);

namespace Access\Service\Controller;

use Access\Controller\AccessFileController;
use Interop\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;

class AccessFileControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, array $options = null)
    {
        $config = $services->get('Config');
        $basePath = $config['file_store']['local']['base_path'] ?: OMEKA_PATH . '/files';
        return new AccessFileController(
            $services->get('Omeka\EntityManager'),
            $services->get('Omeka\ApiAdapterManager')->get('media'),
            $services->get('Omeka\Acl'),
            $basePath
        );
    }
}
