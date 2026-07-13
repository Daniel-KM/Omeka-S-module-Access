<?php declare(strict_types=1);

namespace Access\Form\Admin;

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
                    'text' => 'Access levels are updated automatically on each save, so this task is normally not needed. Run it to repair the access levels after a bulk import, a direct database edit, a bulk edit of the access property values, or after changing the "Cascade embargo dates" option. Save the settings before running the task.', // @translate
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
                    'text' => 'Clear the access levels and embargoes set on whole resource types, then update the access. Check "item sets" to manage access by document (the access is set on items and medias). Check "items" and "medias" to manage access by item set (the access is set on the item sets). This clears the checked levels and cannot be undone.', // @translate
                ],
            ])
            ->add([
                'name' => 'reset',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'label' => 'Reset the access status of', // @translate
                    'value_options' => [
                        'item_sets' => 'Item sets (to manage access by document)', // @translate
                        'items' => 'Items (to manage access by item set)', // @translate
                        'media' => 'Medias (to manage access by item set)', // @translate
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
