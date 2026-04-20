<?php declare(strict_types=1);

namespace Access\Form;

use Common\Form\Element as CommonElement;
use Laminas\Form\Fieldset;

class SiteSettingsFieldset extends Fieldset
{
    protected $label = 'Access'; // @translate

    protected $elementGroups = [
        'themes_old' => 'Old themes', // @translate
    ];

    public function init(): void
    {
        $this
            ->setAttribute('id', 'access')
            ->setOption('element_groups', $this->elementGroups)
            ->add([
                'name' => 'access_placement',
                'type' => CommonElement\OptionalMultiCheckbox::class,
                'options' => [
                    'element_group' => 'themes_old',
                    'label' => 'Access', // @translate
                    'value_options' => [
                        'after/items' => 'Item show', // @translate
                        'after/media' => 'Media show', // @translate
                        'after/item_sets' => 'Item set show', // @translate
                        'browse/items' => 'Item browse', // @translate
                        'browse/media' => 'Media browse', // @translate
                        'browse/item_sets' => 'Item set browse', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'access_placement',
                    'required' => false,
                ],
            ]);
    }
}
