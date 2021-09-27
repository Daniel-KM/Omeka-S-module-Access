<?php declare(strict_types=1);

namespace AccessResource\Form;

use Laminas\Form\Element;
use Laminas\Form\Fieldset;
use Laminas\Form\Form;

class AccessRequestForm extends Form
{
    public function init(): void
    {
        // TODO Convert into a standard Omeka form (with name as in json-ld).

        $this
            ->add([
                'name' => 'access_request_submit',
                'type' => Fieldset::class,
            ]);

        $this
            ->get('access_request_submit')
            ->add([
                'type' => Element\Submit::class,
                'name' => 'submit',
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Request', // @translate
                ],
            ]);
    }
}
