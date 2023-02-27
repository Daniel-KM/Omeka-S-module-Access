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
        'factories' => [
            'accessStatus' => Service\ControllerPlugin\AccessStatusFactory::class,
            'isForbiddenFile' => Service\ControllerPlugin\IsForbiddenFileFactory::class,
            'isUnderEmbargo' => Service\ControllerPlugin\IsUnderEmbargoFactory::class,
            'mediaFilesize' => Service\ControllerPlugin\MediaFilesizeFactory::class,
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
        // The access right can be set via a property to simplify some workflows,
        // in particular for import.
        // When the access right is set via a property, there are two modes:
        // - either the property has a value or not, whatever it is;
        // - either the property defines three status (free, reserved, forbidden)
        // to set as a value (default status is the visibility one).
        // In the first case, the default property is "curation:reserved".
        // In the second case, the default property is "curation:access".
        // In all cases, the access right is stored in the table "access_reserved".
        // So this option can be "false" (managed by a specific data), "status"
        // or "reserved".
        'access_via_property' => false,
        // In the case the option is property status, the three possible values
        // should be defined here.
        'access_via_property_statuses' => [
            'free' => 'free',
            'reserved' => 'reserved',
            'forbidden' => 'forbidden',
        ],
        'config' => [
            // The three first settings are just for info: they are overriden by
            // the value set above or by config/local.config.php.
            'accessresource_access_mode' => ACCESS_MODE_GLOBAL,
            'accessresource_access_via_property' => false,
            'accessresource_access_via_property_statuses' => [
                ACCESS_STATUS_FREE => 'free',
                ACCESS_STATUS_RESERVED => 'reserved',
                ACCESS_STATUS_FORBIDDEN => 'forbidden',
            ],
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
