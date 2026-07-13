<?php declare(strict_types=1);

namespace Access\Form\Admin;

use Common\Form\Element as CommonElement;
use Laminas\Form\Element;

/**
 * One sso idp access-scope rule: an identity provider (a configured idp or the
 * "federation" fallback, or a federation idp entered manually) and the
 * collections it reaches.
 *
 * The source select options (the configured idps) are populated at render time,
 * because they depend on settings and must be baked into the cloned template
 * too.
 */
class AccessSsoIdpRuleFieldset extends AbstractAccessScopeRuleFieldset
{
    public function init(): void
    {
        $this
            ->setAttribute('class', 'form-fieldset-element access-scope-rule')
            ->setName('rule')
            ->add([
                'name' => 'source',
                'type' => CommonElement\OptionalSelect::class,
                'options' => [
                    'label' => 'Identity provider', // @translate
                    'info' => 'An idp configured in the Single Sign-On module, or "federation" as a fallback for any federated idp not listed. To target a federation idp not listed, use the field below.', // @translate
                    'value_options' => ['' => ''],
                    'disable_inarray_validator' => true,
                ],
                'attributes' => [
                    'class' => 'chosen-select access-scope-source',
                    'data-placeholder' => 'Select an identity provider…', // @translate
                ],
            ])
            ->add([
                'name' => 'source_manual',
                'type' => Element\Text::class,
                'options' => [
                    'label' => 'Or a federation idp entered manually', // @translate
                    'info' => 'Entity id of a federation idp not in the list above. It takes precedence over the select and becomes a selectable option once saved.', // @translate
                ],
                'attributes' => [
                    'class' => 'access-scope-source-manual',
                    'placeholder' => 'https://idp.example.org/idp/shibboleth', // @translate
                ],
            ]);
        $this->addScopeCollections();
    }

    public function getInputFilterSpecification(): array
    {
        return [
            'source' => ['required' => false],
            'source_manual' => ['required' => false],
        ] + parent::getInputFilterSpecification();
    }
}
