<?php declare(strict_types=1);
namespace AccessResource\Form;

use AccessResource\Traits\ServiceLocatorAwareTrait;
use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class AccessRequestForm extends Form
{
    use ServiceLocatorAwareTrait;

    public function init(): void
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
