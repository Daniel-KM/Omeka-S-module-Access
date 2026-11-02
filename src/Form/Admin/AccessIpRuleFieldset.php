<?php declare(strict_types=1);

namespace Access\Form\Admin;

use Laminas\Form\Element;

/**
 * One ip access-scope rule: an ip or cidr range and the collections it reaches.
 */
class AccessIpRuleFieldset extends AbstractAccessScopeRuleFieldset
{
    public function init(): void
    {
        $this
            ->setAttribute('class', 'form-fieldset-element access-scope-rule')
            ->setName('rule')
            ->add([
                'name' => 'source',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'IP addresses', // @translate
                    'info' => 'One or more ips (v4 or v6) or ranges in cidr notation, separated by spaces, for example the ipv4 and the ipv6 ranges of an institution.', // @translate
                ],
                'attributes' => [
                    'class' => 'access-scope-source',
                    'placeholder' => '124.8.16.32  or  65.43.21.0/24', // @translate
                ],
            ]);
        $this->addScopeCollections();
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'source' => [
                'required' => false,
            ],
        ] + parent::getInputFilterSpecification();
    }
}
