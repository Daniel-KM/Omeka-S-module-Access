<?php declare(strict_types=1);

namespace AccessResource\Form\Admin;

use const AccessResource\ACCESS_STATUS_FREE;
use const AccessResource\ACCESS_STATUS_RESERVED;
use const AccessResource\ACCESS_STATUS_FORBIDDEN;

use AccessResource\Form\Element as AccessResourceElement;
use Laminas\Form\Fieldset;

class BatchEditFieldset extends Fieldset
{
    public function init(): void
    {
        $this
            ->setName('access_resource')
            ->setLabel('Access resource') // @translate
            ->setAttributes([
                'id' => 'accessresource',
                'class' => 'field-container',
                // This attribute is required to make "batch edit all" working.
                'data-collection-action' => 'replace',
            ])

            ->add([
                'name' => 'status',
                'type' => AccessResourceElement\OptionalRadio::class,
                'options' => [
                    'label' => 'New status', // @translate
                    'value_options' => [
                        '' => 'No change', // @translate
                        ACCESS_STATUS_FREE => 'Free', // @translate
                        ACCESS_STATUS_RESERVED => 'Restricted', // @translate
                        ACCESS_STATUS_FORBIDDEN => 'Private', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_status',
                    'class' => 'accessresource',
                    // This attribute is required to make "batch edit all" working.
                    'data-collection-action' => 'replace',
                ],
            ])
        ;

        $accessApply = $this->getOption('access_apply');
        if ($accessApply) {
            $resourceTypesLabels = [
                'items' => 'Item', // @translate
                'media' => 'Media', // @translate
                'item_sets' => 'Item set', // @translate
            ];
            $this
                ->add([
                    'name' => 'apply',
                    'type' => AccessResourceElement\OptionalRadio::class,
                    'options' => [
                        'label' => 'Apply access to resource', // @translate
                        'value_options' => array_intersect_key($resourceTypesLabels, array_flip($accessApply)),
                    ],
                    'attributes' => [
                        'id' => 'accessresource_apply',
                        'class' => 'accessresource',
                        // This attribute is required to make "batch edit all" working.
                        'data-collection-action' => 'replace',
                    ],
                ]);
        }
    }
}
