<?php declare(strict_types=1);

namespace AccessResource\Form;

use Laminas\Form\Element;
use Laminas\Form\Form;
use Omeka\Form\Element\ArrayTextarea;

class ConfigForm extends Form
{
    public function init(): void
    {
        $this
            ->add([
                'name' => 'accessresource_access_mode',
                'type' => Element\Radio::class,
                'options' => [
                    'label' => 'Restricted access mode (to be set in config/local.config.php)', // @translate
                    'info' => 'Access to a media is "reserved" when it has the property "curation:reserved" filled, whatever it is.', // @translate
                    'documentation' => 'https://gitlab.com/Daniel-KM/Omeka-S-module-AccessResource#access-mode',
                    'value_options' => [
                        'global' => 'Global: all users, included guests, have access to all reserved medias', // @translate
                        'ip' => 'IP: visitors with specified ips have access to all reserved medias', // @translate
                        'individual' => 'Individual: guests should request access to each reserved media', // @translate
                    ],
                ],
                'attributes' => [
                    'id' => 'accessresource_access_mode',
                    'required' => true,
                    'disabled' => 'disabled',
                    'style' => 'display: block;',
                ],
            ])
            ->add([
                'name' => 'accessresource_embargo_bypass',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Bypass embargo dates for restricted resources', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_bypass',
                ],
            ])
            ->add([
                'name' => 'accessresource_embargo_auto_update',
                'type' => Element\Checkbox::class,
                'options' => [
                    'label' => 'Automatically update visibility status', // @translate
                ],
                'attributes' => [
                    'id' => 'accessresource_embargo_auto_update',
                ],
            ])
            ->add([
                'name' => 'accessresource_ip_sites',
                'type' => ArrayTextarea::class,
                'options' => [
                    'label' => 'List of ips with open access', // @translate
                    'info' => 'These ips will have unrestricted access to the associated sites. List them separated by a "=", one by line. Range ip are allowed (formatted as cidr).', // @translate
                    'as_key_value' => true,
                ],
                'attributes' => [
                    'id' => 'accessresource_ip_sites',
                    'rows' => 12,
                    'placeholder' => '12.34.56.78 = main-site
87.65.43.0/24 = second-site',
                ],
            ])
        ;

        $this->getInputFilter()
            ->add([
                'name' => 'accessresource_access_mode',
                'required' => false,
            ])
        ;
    }
}
