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

    public function __construct($name = null, array $options = [])
    {
        parent::__construct($name, $options);
        if (isset($options['full_access'])) {
            $this->fullAccess = (bool) $options['full_access'];
        }
        if (isset($options['resource_type'])) {
            $this->resourceType = (string) $options['resource_type'];
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

        /**
         * @fixme There is a warning on php 8 on date and time validator that is not fixed in version 2.25, the last version supporting 7.4.
         * @see \Laminas\Validator\DateStep::convertString() ligne 207: output may be false.
         */
        error_reporting(error_reporting() & ~E_WARNING);

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
                'name' => 'o-access:level',
                'type' => AccessResourceElement\OptionalRadio::class,
                'options' => [
                    'label' => 'New level', // @translate
                    'value_options' => [
                        '' => 'No change', // @translate
                    ] + $valueOptions,
                ],
                'attributes' => [
                    'id' => 'accessresource_o_access_level',
                    'class' => 'accessresource',
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
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
        ;

        if (in_array($this->resourceType, ['itemSet', 'item'])) {
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
                        // This attribute is required to make "batch edit all" working.
                        'data-collection-action' => 'replace',
                    ],
                ])
            ;
        }
    }
}
