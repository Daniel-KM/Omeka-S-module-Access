<?php declare(strict_types=1);

namespace Access\Form\View\Helper;

use Laminas\Form\Element\Collection;
use Laminas\Form\ElementInterface;
use Laminas\View\Helper\AbstractHelper;
use Omeka\Settings\Settings;

/**
 * Render a collection of scope rules as a standard setting row.
 *
 * The label is displayed on the left (field-meta) and the list of rules on the
 * right (inputs), like the other settings, and not as a full-width section with
 * a legend.
 *
 * The markup is the one the admin javascript relies on to switch between the
 * rule view and the text view, to add and remove rows, and to rebuild the rows
 * on submit.
 *
 * @see \Access\Form\ConfigForm
 * @see asset/js/access-admin.js
 */
class FormScopeRules extends AbstractHelper
{
    /**
     * @var \Omeka\Settings\Settings
     */
    protected $settings;

    public function __construct(Settings $settings)
    {
        $this->settings = $settings;
    }

    public function __invoke(ElementInterface $element): string
    {
        if (!$element instanceof Collection) {
            return '';
        }

        $view = $this->getView();
        $escapeHtml = $view->plugin('escapeHtml');
        $translate = $view->plugin('translate');

        // Populate the idp source select options here and not in the fieldset,
        // where init() runs before the options are known: on every existing row
        // and on the template, so cloned rows get them too.
        if ($element->getName() === 'access_auth_sso_idp_rules') {
            $idpOptions = ['' => ''] + $this->idpValueOptions();
            foreach ($element->getFieldsets() as $rule) {
                if ($rule->has('source')) {
                    $rule->get('source')->setValueOptions($idpOptions);
                }
            }
            $template = $element->getTemplateElement();
            if ($template && $template->has('source')) {
                $template->get('source')->setValueOptions($idpOptions);
            }
        }

        $label = $translate($element->getLabel());
        $info = $element->getOption('info');

        // Empty the label so formCollection renders the wrapping fieldset (with
        // the id and the add-template) but no legend; the label is shown in
        // field-meta instead.
        $element->setLabel('');
        $rules = $view->plugin('formCollection')->setShouldWrap(true)->render($element);

        $meta = '<label>' . $escapeHtml($label) . '</label>';
        if ($info) {
            $meta .= '<div class="field-description">' . $escapeHtml($translate($info)) . '</div>';
        }

        $textPlaceholder = $escapeHtml($translate($this->placeholderExample($element->getName())));

        return <<<HTML
            <div class="field access-scope-field" role="group">
                <div class="field-meta">$meta</div>
                <div class="inputs"><div class="access-scope-rules" data-text-placeholder="$textPlaceholder">$rules</div></div>
            </div>
            HTML;
    }

    /**
     * Example for the "edit as a text list" view, specific to the source type:
     * an ip/cidr for the ip rules, an idp entity id for the sso idp rules.
     */
    protected function placeholderExample(string $name): string
    {
        return $name === 'access_ip_rules'
            ? <<<'TXT'
                12.34.56.78
                124.8.16.32 = 17 89 -1940
                65.43.21.0/24 = -2005
                TXT // @translate
            : <<<'TXT'
                idp.example.org =
                shibboleth.another-example.org = 17 89 -1940
                federation = -2005
                TXT; // @translate
    }

    /**
     * Value options for the sso idp source select: the idps configured in the
     * Single Sign-On module, the sources already used in a saved rule (so
     * manual federation idps persist as options), and the "federation"
     * fallback.
     */
    protected function idpValueOptions(): array
    {
        $options = [];
        foreach ($this->settings->get('singlesignon_idps') ?: [] as $idp) {
            $entityId = is_array($idp) ? trim((string) ($idp['entity_id'] ?? '')) : '';
            if ($entityId === '') {
                continue;
            }
            $name = is_array($idp) ? trim((string) ($idp['entity_name'] ?? '')) : '';
            $options[$entityId] = $name !== '' ? sprintf('%s (%s)', $name, $entityId) : $entityId;
        }
        foreach ($this->settings->get('access_auth_sso_idp_rules') ?: [] as $rule) {
            $source = is_array($rule) ? trim((string) ($rule['source'] ?? '')) : '';
            if ($source !== '' && $source !== 'federation' && !isset($options[$source])) {
                $options[$source] = $source;
            }
        }
        $options['federation'] = 'federation'; // @translate
        return $options;
    }
}
