<?php declare(strict_types=1);

namespace Access\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;
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
            ->setLabel('Rebuild the access index') // @translate

            ->add([
                'name' => 'rebuild_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'text' => 'The effective access level of every resource is materialized automatically on each save, so this task is normally not needed. Run it to repair the index after a bulk import, a direct database edit, or a bulk edit of the access property values (in property-storage mode), or after changing the "Cascade embargo dates" option. In property-storage mode, the task first resyncs the set columns from the property values, then recomputes the effective columns from the hierarchy item set > item > media. Settings must be saved before running the task.', // @translate
                ],
            ])
            ->add([
                'name' => 'process_rebuild',
                'type' => Element\Submit::class,
                'options' => [
                    'label' => 'Rebuild access index', // @translate
                ],
                'attributes' => [
                    'id' => 'process_rebuild',
                    'value' => 'Rebuild access index', // @translate
                ],
            ])

            ->add([
                'name' => 'reset_note',
                'type' => CommonElement\Note::class,
                'options' => [
                    'text' => 'Neutralize the access status (level and embargo) of whole resource types, then rebuild the effective index. Check "item sets" to switch to a "by document" logic (the access is driven by items and medias). Check "items" and "medias" to switch to a "by collection" logic (the access is driven by the item sets). This is a one-time operation to align an existing base with the chosen management logic; it clears the checked decisions and cannot be undone.', // @translate
                ],
            ])
            ->add([
                'name' => 'reset',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Reset the access status of', // @translate
                    'value_options' => [
                        'item_sets' => 'Item sets (to manage access by document)', // @translate
                        'items' => 'Items (to manage access by collection)', // @translate
                        'media' => 'Medias (to manage access by collection)', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'access_reset',
                ],
            ])
            ->add([
                'name' => 'process_reset',
                'type' => Element\Submit::class,
                'options' => [
                    'label' => 'Reset and rebuild', // @translate
                ],
                'attributes' => [
                    'id' => 'process_reset',
                    'value' => 'Reset and rebuild', // @translate
                ],
            ])
        ;
    }
}
