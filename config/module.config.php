<?php declare(strict_types=1);

namespace AccessResource;

// Access mode may be "global", "ip" or "individual".
use const AccessResource\ACCESS_MODE;

if (ACCESS_MODE === ACCESS_MODE_INDIVIDUAL) {
    return include __DIR__ . '/module.config.individual.php';
}

return [
    'entity_manager' => [
        // Only for AccessReserved.
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
        'filters' => [
            // Override Omeka core resource visibility with a new condition.
            'resource_visibility' => Db\Filter\ReservedResourceVisibilityFilter::class,
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'accessStatus' => Service\ViewHelper\AccessStatusFactory::class,
            /** @deprecated Since 3.4.0.14. */
            'isReservedResource' => Service\ViewHelper\IsReservedResourceFactory::class,
            'isUnderEmbargo' => Service\ViewHelper\IsUnderEmbargoFactory::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\OptionalRadio::class => Form\Element\OptionalRadio::class,
            Form\ConfigForm::class => Form\ConfigForm::class,
        ],
    ],
    'controllers' => [
        'factories' => [
            'AccessResource\Controller\AccessResource' => Service\Controller\AccessResourceControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'invokables' => [
            'isUnderEmbargo' => Mvc\Controller\Plugin\IsUnderEmbargo::class,
        ],
        'factories' => [
            'accessStatus' => Service\ControllerPlugin\AccessStatusFactory::class,
            'isForbiddenFile' => Service\ControllerPlugin\IsForbiddenFileFactory::class,
            /** @deprecated Since 3.4.0.14. */
            'isReservedResource' => Service\ControllerPlugin\IsReservedResourceFactory::class,
            'mediaFilesize' => Service\ControllerPlugin\MediaFilesizeFactory::class,
            // TODO Store the reserved access property id as a constant to avoid to get it each request.
            'reservedAccessPropertyId' => Service\ControllerPlugin\ReservedAccessPropertyIdFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'access-resource-file' => [
                'type' => \Laminas\Router\Http\Segment::class,
                'options' => [
                    // See module Statistics too.
                    // Manage module Archive repertory, that can use real names and subdirectories.
                    // For any filename, either use `:filename{?}`, or add a constraint `'filename' => '.+'`.
                    'route' => '/access/files/:type/:filename{?}',
                    'constraints' => [
                        'type' => '[^/]+',
                        'filename' => '.+',
                    ],
                    'defaults' => [
                        '__NAMESPACE__' => 'AccessResource\Controller',
                        'controller' => 'AccessResource',
                        'action' => 'file',
                        'access_mode' => ACCESS_MODE,
                    ],
                ],
            ],
        ],
    ],
    'translator' => [
        'translation_file_patterns' => [
            [
                'type' => 'gettext',
                'base_dir' => dirname(__DIR__) . '/language',
                'pattern' => '%s.mo',
                'text_domain' => null,
            ],
        ],
    ],
    'accessresource' => [
        // Access mode may be "global", "ip" or "individual".
        'access_mode' => 'global',
        // The access right can be set via a property (curation:reserved by default)
        // to simplify some workflows, in particular for import.
        // In all cases, the access right is stored in the table "access_reserved".
        'access_via_property' => false,
        'config' => [
            // This setting is just for info: it is overridden by [accessresource][access_mode]
            // that should be set in config/local.config.php.
            'accessresource_access_mode' => ACCESS_MODE_GLOBAL,
            // This setting is just for info: it is overridden by [accessresource][access_via_property]
            // that should be set in config/local.config.php.
            'accessresource_access_via_property' => false,
            'accessresource_embargo_bypass' => false,
            'accessresource_embargo_auto_update' => false,
            'accessresource_ip_item_sets' => [],
            // Hidden settings automatically filled after saving config.
            // It contains the same data than "accessresource_ip_item_sets", but
            // with numberized ip ranges (cidr) in order to do a quicker control
            // of rights.
            'accessresource_ip_reserved' => [
                /*
                '123.45.67.89' => [
                    'low' => 2066563929,
                    'high' => 2066563929,
                    'reserved' => [],
                ],
                '123.45.68.0/24' => [
                    'low' => 2066564096,
                    'high' => 2066564351,
                    'reserved' => [2],
                ],
                */
            ],
        ],
        'settings' => [
            'accessresource_message_send' => true,
            'accessresource_message_admin_subject' => 'New request status!', //@translate
            'accessresource_message_admin_request_created' => 'User created new request to AccessResource. Please, check request dashboard.', //@translate
            'accessresource_message_admin_request_updated' => 'User request to resource access is updated.', //@translate
            'accessresource_message_user_subject' => 'New request status!', //@translate
            'accessresource_message_user_request_created' => 'Your request is sent to administrator. You will be inform when your request will change.', //@translate
            'accessresource_message_user_request_updated' => 'Your request to resource access is updated. You can check guest user requests dashboard.', //@translate
        ],
    ],
];
