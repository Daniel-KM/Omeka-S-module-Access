<?php declare(strict_types=1);

namespace Access\Form\Admin;

use Access\Entity\AccessRequest;
use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element as OmekaElement;

class AccessRequestForm extends Form
{
    /**
     * @var bool
     */
    protected $fullAccess = false;

    /**
     * @var int
     */
    protected $resourceId = null;

    /**
     * @var string
     */
    protected $resourceType = null;

    /**
     * @var string
     */
    protected $requestStatus = null;

    public function __construct($name = null, array $options = [])
    {
        parent::__construct($name, $options ?? []);
        if (isset($options['full_access'])) {
            $this->fullAccess = (bool) $options['full_access'];
        }
        if (isset($options['resource_id'])) {
            $this->resourceId = $options['resource_id'];
        }
        if (isset($options['resource_type'])) {
            $this->resourceType = $options['resource_type'];
        }
        if (isset($options['request_status'])) {
            $this->requestStatus = $options['request_status'];
        }
    }

    public function init(): void
    {
        // This is in admin board:
        $statusLabels = [
            AccessRequest::STATUS_NEW => 'New', // @translate
            AccessRequest::STATUS_RENEW => 'Renew', // @translate
            AccessRequest::STATUS_ACCEPTED => 'Accepted', // @translate
            AccessRequest::STATUS_REJECTED => 'Rejected', // @translate
        ];

        $this
            ->setAttribute('id', 'form-access-request');

        if ($this->resourceId) {
            $this
                ->add([
                    'name' => 'o:resource',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'o-resource',
                        'value' => $this->resourceId,
                    ],
                ]);
        } else {
            // TODO Create a resource selector for any resource.
            $this
                ->add([
                    'name' => 'o:resource',
                    // 'type' => OmekaElement\ResourceSelect::class,
                    'type' => Element\Text::class,
                    'options' => [
                        'label' => 'Resources', // @translate
                    ],
                    'attributes' => [
                        'id' => 'o-resource',
                    ],
                ]);
        }

        if (in_array($this->resourceType, [null, 'items', 'item_sets'])) {
            $recursiveLabels = [
                'items' => 'Include medias', // @translate
                'item_sets' => 'Include items and medias', // @translate
                '' => 'Include items and media', // @translate
            ];
            $this
                ->add([
                    'name' => 'o-access:recursive',
                    'type' => Element\Checkbox::class,
                    'options' => [
                        'label' => $recursiveLabels[$this->resourceType] ?? $recursiveLabels[''],
                    ],
                    'attributes' => [
                        'id' => 'o-access-recursive',
                    ],
                ]);
        } else {
            // Medias are not recursive.
            $this
                ->add([
                    'name' => 'o-access:recursive',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'o-access-recursive',
                        'value' => '0',
                    ],
                ]);
        }

        $this
            // TODO Create a form view for the user selector like the query in order to simplify the template for the form.
            ->add([
                'name' => 'o:user',
                'type' => OmekaElement\UserSelect::class,
                'options' => [
                    'label' => 'User', // @translate
                    'empty_option' => '',
                ],
                'attributes' => [
                    'id' => 'o-user',
                    // TODO Chosen select css does not override firefox email.
                    'class' => 'chosen-select',
                    'multiple' => false,
                    // 'placeholder' => 'Select user…', // @translate
                    'data-placeholder' => 'Select user…', // @translate
                ],
            ])
            ->add([
                'name' => 'o:email',
                'type' => Element\Email::class,
                'options' => [
                    'label' => 'Or email', // @translate
                ],
                'attributes' => [
                    'id' => 'o-email',
                ],
            ])
            ->add([
                'name' => 'o-access:token',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Or token', // @translate
                ],
                'attributes' => [
                    'id' => 'o-access:token',
                ],
            ])
            // TODO Or group.
        ;

        if ($this->requestStatus) {
            $this
                ->add([
                    'name' => 'o:status',
                    'type' => Element\Hidden::class,
                    'attributes' => [
                        'id' => 'o-status',
                        'value' => $this->requestStatus,
                    ],
                ]);
        } else {
            $this
                ->add([
                    'name' => 'o:status',
                    'type' => CommonElement\OptionalRadio::class,
                    'options' => [
                        'label' => 'Status', // @translate
                        'value_options' => $statusLabels,
                    ],
                    'attributes' => [
                        'id' => 'o-status',
                        'value' => AccessRequest::STATUS_NEW,
                    ],
                ]);
        }

        $this
            /*
            ->add([
                'name' => 'o-access:enabled',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Enabled', // @translate
                ],
                'attributes' => [
                    'id' => 'o-access-enabled',
                ],
            ])
            ->add([
                'name' => 'o-access:temporal',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Limit to a period', // @translate
                ],
                'attributes' => [
                    'id' => 'o-access-temporal',
                ],
            ])
            */
            // Classes are not usable with DateTimeSelect.
            /*
            ->add([
                'name' => 'o-access:start',
                'type' => Element\DateTimeSelect::class,
                'options' => [
                    'label' => 'Start date', // @translate
                ],
                'attributes' => [
                    'id' => 'o-access-start',
                ],
            ])
            ->add([
                'name' => 'o-access:end',
                'type' => Element\DateTimeSelect::class,
                'options' => [
                    'label' => 'End date', // @translate
                ],
                'attributes' => [
                    'id' => 'o-access-end',
                ],
            ])
            */
            ->add([
                'name' => 'o-access:start-date',
                'type' => Element\Date::class,
                'options' => [
                    'label' => 'Start date', // @translate
                ],
                'attributes' => [
                    'id' => 'o-access-start-date',
                ],
            ])
            ->add([
                'name' => 'o-access:start-time',
                'type' => Element\Time::class,
                'options' => [
                    'label' => ' ',
                    'format' => 'H:i',
                ],
                'attributes' => [
                    'id' => 'o-access-start-time',
                ],
            ])
            ->add([
                'name' => 'o-access:end-date',
                'type' => Element\Date::class,
                'options' => [
                    'label' => 'End date', // @translate
                ],
                'attributes' => [
                    'id' => 'o-access-end-date',
                ],
            ])
            ->add([
                'name' => 'o-access:end-time',
                'type' => Element\Time::class,
                'options' => [
                    'label' => ' ',
                    'format' => 'H:i',
                ],
                'attributes' => [
                    'id' => 'o-access-end-time',
                ],
            ])

            ->add([
                'type' => Element\Submit::class,
                'name' => 'submit',
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Add access', // @translate
                ],
            ])
        ;

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->add([
                'name' => 'o:user',
                'required' => false,
            ])
            ->add([
                'name' => 'o:email',
                'required' => false,
            ])
            ->add([
                'name' => 'o:recursive',
                'required' => false,
            ])
            ->add([
                'name' => 'o-access:start-date',
                'required' => false,
            ])
            ->add([
                'name' => 'o-access:start-time',
                'required' => false,
            ])
            ->add([
                'name' => 'o-access:end-date',
                'required' => false,
            ])
            ->add([
                'name' => 'o-access:end-time',
                'required' => false,
            ])
        ;
    }
}
