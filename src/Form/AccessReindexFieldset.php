<?php declare(strict_types=1);

namespace Access\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Fieldset;

class AccessReindexFieldset extends Fieldset
{
    protected $label = 'Access'; // @translate

    protected $elementGroups = [
        'access' => 'Access', // @translate
    ];

    public function init(): void
    {
        $this
            ->setName('access_reindex')
            ->setAttribute('id', 'access')
            ->setOption('element_groups', $this->elementGroups)
            ->setLabel('Jobs to create missing access status of all resources') // @translate

            ->add([
                'name' => 'recursive',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Copy level and embargo', // @translate
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'from_item_sets_to_items_and_media' => 'From item sets to items and medias', // @translate
                        'from_items_to_media' => 'From items to medias', // @translate
                        // TODO Add "when not set".
                    ],
                ],
                'attributes' => [
                    'id' => 'recursive',
                ],
            ])
            ->add([
                'name' => 'sync',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Copy access level and embargo', // @translate
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'skip' => 'Skip', // @translate
                        'from_properties_to_index' => 'Copy data from property values into indexes', // @translate
                        'from_index_to_properties' => 'Copy data from indexes into property values', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'sync',
                    'value' => 'skip',
                ],
            ])
            ->add([
                'name' => 'missing',
                'type' => CommonElement\OptionalRadio::class,
                'options' => [
                    'label' => 'Fill missing statuses', // @translate
                    'label_attributes' => ['style' => 'display: inline-block'],
                    'value_options' => [
                        'skip' => 'Skip', // @translate
                        'free' => 'Set access level free for all resources without status', // @translate
                        'reserved' => 'Set access level reserved for all resources without status', // @translate
                        'protected' => 'Set access level protected for all resources without status', // @translate
                        'forbidden' => 'Set access level forbidden for all resources without status', // @translate
                        'visibility_reserved' => 'Set access level free when public and reserved when private', // @translate
                        'visibility_protected' => 'Set access level free when public and protected when private', // @translate
                        'visibility_forbidden' => 'Set access level free when public and forbidden when private', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'missing',
                    'value' => 'skip',
                ],
            ])
        ;
    }
}
