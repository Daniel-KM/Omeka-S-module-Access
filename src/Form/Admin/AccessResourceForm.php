<?php
namespace AccessResource\Form\Admin;

use AccessResource\Traits\ServiceLocatorAwareTrait;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;

class AccessResourceForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init()
    {
        parent::init();

        // TODO Convert into a standard Omeka form (with name as in json-ld).

        $this->add([
            'name' => 'enabled',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Enabled', // @translate
            ],
        ]);

        $this->add([
            'name' => 'token',
            'type' => Element\Text::class,
            'options' => [
                'label' => 'Token', // @translate
            ],
            'attributes'=> [
                'readonly' => true,
                'placeholder' => 'Will be automatically set', // @translate
            ],
        ]);
        $this->add([
            'name' => 'temporal',
            'type' => Element\Checkbox::class,
            'options' => [
                'label' => 'Temporal', // @translate
            ],
        ]);
        $this->add([
            'name' => 'startDate',
            'type' => Element\DateTime::class,
            'options' => [
                'label' => 'Start date', // @translate
                'info' => ' Format: 2000-01-01 00:00', // @translate
                'format' => 'Y-m-d H:i',
            ],
            'attributes' => [
                'min' => '2000-01-01 00:00',
                'max' => '2200-01-01 00:00',
                'step' => '1',
            ],
        ]);
        $this->add([
            'name' => 'endDate',
            'type' => Element\DateTime::class,
            'options' => [
                'label' => 'End date', // @translate
                'info' => 'Format: 2000-01-01 00:00', // @translate
                'format' => 'Y-m-d H:i',
            ],
            'attributes' => [
                'min' => '2000-01-01 00:00',
                'max' => '2200-01-01 00:00',
                'step' => '1',
            ],
        ]);

        $this->add([
            'name' => 'resource_access_submit',
            'type' => Fieldset::class,
        ]);

        $this->get('resource_access_submit')->add([
            'type' => Element\Submit::class,
            'name' => 'submit',
            'attributes' => [
                'value' => 'Save', // @translate
                'id' => 'submitbutton',
            ],
        ]);

        $inputFilter = $this->getInputFilter();
        $inputFilter->add([
            'name' => 'startDate',
            'required' => false,
        ]);
        $inputFilter->add([
            'name' => 'endDate',
            'required' => false,
        ]);
    }
}
