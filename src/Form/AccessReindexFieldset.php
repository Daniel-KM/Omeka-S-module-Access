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
        ;
    }
}
