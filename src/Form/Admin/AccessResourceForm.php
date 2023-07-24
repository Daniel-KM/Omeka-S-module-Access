<?php declare(strict_types=1);

namespace AccessResource\Form\Admin;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class AccessResourceForm extends Form
{
    public function init(): void
    {
        // TODO Convert into a standard Omeka form (with name as in json-ld).

        $this
            ->add([
                'name' => 'enabled',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Enabled', // @translate
                ],
                'attributes' => [
                    'id' => 'enabled',
                ],
            ])
            ->add([
                'name' => 'token',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Token', // @translate
                ],
                'attributes' => [
                    'id' => 'token',
                    'readonly' => true,
                    'placeholder' => 'Will be automatically set', // @translate
                ],
            ])
            ->add([
                'name' => 'temporal',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Limit to a period', // @translate
                ],
                'attributes' => [
                    'id' => 'temporal',
                ],
            ])
            ->add([
                'name' => 'startDate',
                'type' => Element\DateTime::class,
                'options' => [
                    'label' => 'Start date', // @translate
                    'info' => ' Format: 2000-01-01 00:00', // @translate
                    'format' => 'Y-m-d H:i',
                ],
                'attributes' => [
                    'id' => 'start_date',
                    'min' => '2000-01-01 00:00',
                    'max' => '2099-31-12 00:00',
                    'step' => '1',
                ],
            ])
            ->add([
                'name' => 'endDate',
                'type' => Element\DateTime::class,
                'options' => [
                    'label' => 'End date', // @translate
                    'info' => 'Format: 2000-01-01 00:00', // @translate
                    'format' => 'Y-m-d H:i',
                ],
                'attributes' => [
                    'id' => 'end_date',
                    'min' => '2000-01-01 00:00',
                    'max' => '2099-31-12 00:00',
                    'step' => '1',
                ],
            ])

            ->add([
                'name' => 'resource_access_submit',
                'type' => Fieldset::class,
            ]);

        $this->get('resource_access_submit')
            ->add([
                'type' => Element\Submit::class,
                'name' => 'submit',
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Save', // @translate
                ],
            ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter
            ->add([
                'name' => 'startDate',
                'required' => false,
            ])
            ->add([
                'name' => 'endDate',
                'required' => false,
            ]);
    }
}
