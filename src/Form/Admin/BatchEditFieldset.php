<?php declare(strict_types=1);

namespace AccessResource\Form\Admin;

use AccessResource\Entity\AccessStatus;
use AccessResource\Form\Element as AccessResourceElement;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;

class BatchEditFieldset extends Fieldset
{
    protected $fullAccess = false;
    protected $resourceType = null;
    protected $accessViaProperty = false;
    protected $embargoViaProperty = false;

    public function __construct($name = null, array $options = [])
    {
        parent::__construct($name, $options);
        if (isset($options['full_access'])) {
            $this->fullAccess = (bool) $options['full_access'];
        }
        if (isset($options['resource_type'])) {
            $this->resourceType = (string) $options['resource_type'];
        }
        if (isset($options['access_via_property'])) {
            $this->accessViaProperty = (bool) $options['access_via_property'];
        }
        if (isset($options['embargo_via_property'])) {
            $this->embargoViaProperty = (bool) $options['embargo_via_property'];
        }
    }

    public function init(): void
    {
        $valueOptions = [
            AccessStatus::FREE => 'Free', // @translate'
            AccessStatus::RESERVED => 'Restricted', // @translate
            AccessStatus::PROTECTED => 'Protected', // @translate
            AccessStatus::FORBIDDEN => 'Forbidden', // @translate
        ];
        // There is no difference between reserved and protected when only the
        // file is protected.
        if (!$this->fullAccess) {
            unset($valueOptions[AccessStatus::PROTECTED]);
        }

        $this
            ->setName('accessresource')
            ->setLabel('Access resource') // @translate
            ->setAttributes([
                'id' => 'accessresource',
                'class' => 'field-container',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ])

            ->add([
                'name' => 'o-access:status',
                'type' => AccessResourceElement\OptionalRadio::class,
                'options' => [
                    'label' => 'New status', // @translate
                    'value_options' => [
                        '' => 'No change', // @translate
                    ] + $valueOptions,
                ],
                'attributes' => [
                    'id' => 'accessresource_o_access_status',
                    'class' => 'accessresource',
                    'disabled' => $this->accessViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])

            ->add([
                'name' => 'o-access:status',
                'type' => AccessResourceElement\OptionalRadio::class,
                'options' => [
                    'label' => 'New status', // @translate
                    'value_options' => [
                        '' => 'No change', // @translate
                    ] + $valueOptions,
                ],
                'attributes' => [
                    'id' => 'accessresource_o_access_status',
                    'class' => 'accessresource',
                    'disabled' => $this->accessViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])

            ->add([
                'name' => 'embargo_start_update',
                'type' => AccessResourceElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Embargo start', // @translate
                    'value_options' => [
                        '' => 'No change', // @translate
                        'remove' => 'Remove', // @translate
                        'set' => 'Set date below', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_start_update',
                    'class' => 'accessresource',
                    'disabled' => $this->embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'embargo_start_date',
                'type' => Element\Date::class,
                'options' => [
                    'label' => 'Embargo start date', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_start_date',
                    'class' => 'accessresource',
                    'disabled' => $this->embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'embargo_start_time',
                'type' => Element\Time::class,
                'options' => [
                    'label' => ' ',
                    'format' => 'H:i',
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_start_time',
                    'class' => 'accessresource',
                    'disabled' => $this->embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'embargo_end_update',
                'type' => AccessResourceElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Embargo end', // @translate
                    'value_options' => [
                        '' => 'No change', // @translate
                        'remove' => 'Remove', // @translate
                        'set' => 'Set date below', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_end_update',
                    'class' => 'accessresource',
                    'disabled' => $this->embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'embargo_end_date',
                'type' => Element\Date::class,
                'options' => [
                    'label' => 'Embargo end date', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_end_date',
                    'class' => 'accessresource',
                    'disabled' => $this->embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
            ->add([
                'name' => 'embargo_end_time',
                'type' => Element\Time::class,
                'options' => [
                    'label' => ' ',
                    'format' => 'H:i',
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_end_time',
                    'class' => 'accessresource',
                    'disabled' => $this->embargoViaProperty ? 'disabled' : false,
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
        ;

        if (!($this->accessViaProperty && $this->embargoViaProperty)
            && in_array($this->resourceType, ['itemSet', 'item'])
        ) {
            $this
                ->add([
                    'name' => 'access_recursive',
                    'type' => Element\Checkbox::class,
                    'options' => [
                        'label' => $this->resourceType === 'itemSet'
                            ? 'Apply access level and embargo to items and medias' // @translate
                            : 'Apply access level and embargo to medias', // @translate
                    ],
                    'attributes' => [
                        'id' => 'access_recursive',
                        'class' => 'accessresource',
                        'disabled' => $this->accessViaProperty ? 'disabled' : false,
                        // This attribute is required to make "batch edit all" working.
                        'data-collection-action' => 'replace',
                    ],
                ])
            ;
        }
    }
}
