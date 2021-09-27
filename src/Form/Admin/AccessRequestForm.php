<?php declare(strict_types=1);

namespace AccessResource\Form\Admin;

use AccessResource\Entity\AccessRequest;
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
                'name' => 'status',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => ' Status', // @translate
                    'value_options' => $this->getStatusOptions(),
                ],
                'attributes' => [
                    'id' => 'status',
                    'value' => AccessRequest::STATUS_NEW,
                ],
            ])
            ->add([
                'name' => 'access_request_submit',
                'type' => Fieldset::class,
            ]);

        $this->get('access_request_submit')
            ->add([
                'type' => Element\Submit::class,
                'name' => 'submit',
                'attributes' => [
                    'id' => 'submitbutton',
                    'value' => 'Save', // @translate
                ],
            ]);
    }

    protected function getStatusOptions()
    {
        return [
            AccessRequest::STATUS_NEW => 'New', // @translate
            AccessRequest::STATUS_RENEW => 'Renew', // @translate
            AccessRequest::STATUS_ACCEPTED => 'Accepted', // @translate
            AccessRequest::STATUS_REJECTED => 'Rejected', // @translate
        ];
    }
}
