<?php declare(strict_types=1);

namespace Access;

use Access\Entity\AccessStatus;

return [
    'api_adapters' => [
        'invokables' => [
            'access_requests' => Api\Adapter\AccessRequestAdapter::class,
        ],
    ],
    'entity_manager' => [
        'mapping_classes_paths' => [
            dirname(__DIR__) . '/src/Entity',
        ],
        'proxy_paths' => [
            dirname(__DIR__) . '/data/doctrine-proxies',
        ],
        /* TODO To be reused when records will be protected. Use another filter or a delegator instead of overriding default one, so it will remain compatible with other modules (group).
        'filters' => [
            // Override Omeka core resource visibility with a new condition.
            'resource_visibility' => Db\Filter\AccessVisibilityFilter::class,
        ],
        */
    ],
    'view_manager' => [
        'template_path_stack' => [
            dirname(__DIR__) . '/view',
        ],
        'strategies' => [
            'ViewJsonStrategy',
        ],
    ],
    'view_helpers' => [
        'factories' => [
            'accessLevel' => Service\ViewHelper\AccessLevelFactory::class,
            'accessRequest' => Service\ViewHelper\AccessRequestFactory::class,
            'accessStatus' => Service\ViewHelper\AccessStatusFactory::class,
            'isAccessRequestable' => Service\ViewHelper\IsAccessRequestableFactory::class,
            'isAllowedMediaContent' => Service\ViewHelper\IsAllowedMediaContentFactory::class,
            'isUnderEmbargo' => Service\ViewHelper\IsUnderEmbargoFactory::class,
        ],
    ],
    'resource_page_block_layouts' => [
        'invokables' => [
            'accessRequest' => Site\ResourcePageBlockLayout\AccessRequest::class,
            'accessRequestText' => Site\ResourcePageBlockLayout\AccessRequestText::class,
            'accessStatus' => Site\ResourcePageBlockLayout\AccessStatus::class,
        ],
    ],
    'form_elements' => [
        'invokables' => [
            Form\Element\OptionalRadio::class => Form\Element\OptionalRadio::class,
            Form\ConfigForm::class => Form\ConfigForm::class,
            Form\SettingsFieldset::class => Form\SettingsFieldset::class,
        ],
        // TODO In Omeka, forms with options should be passed as factory to avoid a warning for now.
        'factories' => [
            Form\Admin\AccessRequestForm::class => \Omeka\Form\Factory\InvokableFactory::class,
            Form\Admin\BatchEditFieldset::class => \Omeka\Form\Factory\InvokableFactory::class,
            Form\Site\AccessRequestForm::class => \Omeka\Form\Factory\InvokableFactory::class,
        ],
    ],
    'controllers' => [
        'invokables' => [
            Controller\Site\GuestBoardController::class => Controller\Site\GuestBoardController::class,
            Controller\Site\RequestController::class => Controller\Site\RequestController::class,
        ],
        'factories' => [
            Controller\AccessFileController::class => Service\Controller\AccessFileControllerFactory::class,
            Controller\Admin\LogController::class => Service\Controller\LogControllerFactory::class,
            Controller\Admin\RequestController::class => Service\Controller\RequestControllerFactory::class,
        ],
    ],
    'controller_plugins' => [
        'factories' => [
            'accessLevel' => Service\ControllerPlugin\AccessLevelFactory::class,
            'accessStatus' => Service\ControllerPlugin\AccessStatusFactory::class,
            'isAllowedMediaContent' => Service\ControllerPlugin\IsAllowedMediaContentFactory::class,
            'isExternalUser' => Service\ControllerPlugin\IsExternalUserFactory::class,
            'isUnderEmbargo' => Service\ControllerPlugin\IsUnderEmbargoFactory::class,
        ],
    ],
    'router' => [
        'routes' => [
            'access-file' => [
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
                        '__NAMESPACE__' => 'Access\Controller',
                        'controller' => Controller\AccessFileController::class,
                        'action' => 'file',
                    ],
                ],
            ],
            'site' => [
                'child_routes' => [
                    'access-request' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/access-request',
                            'defaults' => [
                                '__NAMESPACE__' => 'Access\Controller\Site',
                                'controller' => Controller\Site\RequestController::class,
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => 'browse|submit',
                                    ],
                                    'defaults' => [
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'guest' => [
                        // The default values for the guest user route are kept
                        // to avoid issues for visitors when an upgrade of
                        // module Guest occurs or when it is disabled.
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/guest',
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'access-request' => [
                                'type' => \Laminas\Router\Http\Literal::class,
                                'options' => [
                                    'route' => '/access-request',
                                    'defaults' => [
                                        '__NAMESPACE__' => 'Access\Controller\Site',
                                        'controller' => Controller\Site\GuestBoardController::class,
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
            'admin' => [
                'child_routes' => [
                    'access-request' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/access-request',
                            'defaults' => [
                                '__NAMESPACE__' => 'Access\Controller\Admin',
                                'controller' => Controller\Admin\RequestController::class,
                                'action' => 'browse',
                            ],
                        ],
                        'may_terminate' => true,
                        'child_routes' => [
                            'default' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:action',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                    ],
                                    'defaults' => [
                                        'action' => 'browse',
                                    ],
                                ],
                            ],
                            'id' => [
                                'type' => \Laminas\Router\Http\Segment::class,
                                'options' => [
                                    'route' => '/:id[/:action]',
                                    'constraints' => [
                                        'action' => '[a-zA-Z][a-zA-Z0-9_-]*',
                                        'id' => '\d+',
                                    ],
                                    'defaults' => [
                                        'action' => 'show',
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'access-log' => [
                        'type' => \Laminas\Router\Http\Literal::class,
                        'options' => [
                            'route' => '/access-log',
                            'defaults' => [
                                '__NAMESPACE__' => 'Access\Controller\Admin',
                                'controller' => Controller\Admin\LogController::class,
                                'action' => 'browse',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            'access' => [
                'label' => 'Accesses', // @translate
                'class' => 'o-icon- fa-key',
                'route' => 'admin/access-request',
                'pages' => [
                    [
                        'label' => 'Requests', // @translate
                        'route' => 'admin/access-request/default',
                        'pages' => [
                            [
                                'route' => 'admin/access-request/default',
                                'visible' => false,
                            ],
                            [
                                'route' => 'admin/access-request/id',
                                'visible' => false,
                            ],
                        ],
                    ],
                    [
                        'label' => 'Logs', // @translate
                        'route' => 'admin/access-log',
                    ],
                ],
            ],
        ],
    ],
    'column_types' => [
        'invokables' => [
            'accessLevel' => ColumnType\AccessLevel::class,
            'embargoDate' => ColumnType\EmbargoDate::class,
            'isUnderEmbargo' => ColumnType\IsUnderEmbargo::class,
        ],
    ],
    'browse_defaults' => [
        'admin' => [
            'access_requests' => [
                'sort_by' => 'created',
                'sort_order' => 'desc',
            ],
        ],
        'site' => [
            'access_requests' => [
                'sort_by' => 'created',
                'sort_order' => 'desc',
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
    'js_translate_strings' => [
        'Access', // @translate
        'The resource or the access doesn’t exist.', // @translate
        'Something went wrong', // @translate
        '[Untitled]', // @translate
    ],
    'access' => [
        'config' => [
            // True means that recods are protected, not only media contents (files).
            'access_full' => false,

            'access_access_modes' => [
                // 'ip',
                'guest',
                // 'external',
                // 'user',
                // 'email',
                // 'token',
            ],

            'access_ip_item_sets' => [],

            'access_property' => false,

            'access_property_level' => 'curation:access',
            'access_property_levels' => [
                AccessStatus::FREE => 'free',
                AccessStatus::RESERVED => 'reserved',
                AccessStatus::PROTECTED => 'protected',
                AccessStatus::FORBIDDEN => 'forbidden',
            ],
            'access_property_level_datatype' => null,

            'access_property_embargo_start' => 'curation:start',
            'access_property_embargo_end' => 'curation:end',

            'access_property_show_in_advanced_tab' => false,

            'access_embargo_bypass' => false,

            // Hidden settings automatically filled after saving config.
            // It contains the same data than "access_ip_item_sets", but
            // with numberized ip ranges (cidr) in order to do a quicker control
            // of rights.
            'access_ip_reserved' => [
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
            'access_message_send' => true,
            'access_message_admin_subject' => 'New request status!', //@translate
            'access_message_admin_request_created' => 'A user or visitor requested to access a resource. Please, check request dashboard.', //@translate
            'access_message_admin_request_updated' => 'A user or visitor updated the request to access a resource. Please, check request dashboard.', //@translate
            'access_message_user_subject' => 'New request status!', //@translate
            'access_message_user_request_created' => 'Your request to access resource is sent to administrator. You will be inform when your request will change.', //@translate
            'access_message_user_request_updated' => 'Your request to access resource is updated. You can check guest user requests dashboard.', //@translate
            'access_message_access_text' => 'This resource is not available for now. Contact the webmaster.', //@translate
        ],
    ],
];
