<?php declare(strict_types=1);

namespace Access\Service\ViewHelper;

use Access\Form\View\Helper\FormScopeRules;
use Laminas\ServiceManager\Factory\FactoryInterface;
use Psr\Container\ContainerInterface;

class FormScopeRulesFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new FormScopeRules(
            $services->get('Omeka\Settings')
        );
    }
}
