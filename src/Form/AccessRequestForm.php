<?php
namespace AccessResource\Form;

use AccessResource\Traits\ServiceLocatorAwareTrait;
use Zend\Form\Element;
use Zend\Form\Fieldset;
use Zend\Form\Form;

class AccessRequestForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init()
    {
        parent::init();

        // TODO Convert into a standard Omeka form (with name as in json-ld).

        $this->add([
            'name' => 'access_request_submit',
            'type' => Fieldset::class,
        ]);

        $this->get('access_request_submit')->add([
            'type' => Element\Submit::class,
            'name' => 'submit',
            'attributes' => [
                'value' => 'Request', // @translate
                'id' => 'submitbutton',
            ],
        ]);
    }
}
