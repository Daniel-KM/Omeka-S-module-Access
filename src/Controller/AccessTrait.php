<?php declare(strict_types=1);

namespace Access\Controller;

use Access\Api\Representation\AccessRequestRepresentation;

trait AccessTrait
{
    /**
     * Send email to admin/user when a request is created.
     */
    protected function sendRequestEmailCreate(AccessRequestRepresentation $accessRequest, array $post): bool
    {
        if (!$this->settings()->get('access_message_send')) {
            return true;
        }
        // If no user, it should be already checked.
        if ($user = $accessRequest->user()) {
            $post['o:email'] = $user->email();
            $post['o:name'] = $user->name();
        }
        // Simplify process (for placeholders).
        $post['access_request'] = $accessRequest;
        if (!is_array($post['o:resource'])) {
            $post['o:resource'] = empty($post['o:resource']) ? [] : [$post['o:resource']];
        }
        $isVisitor = $this->isVisitor($accessRequest, $post);
        $result1 = $this->sendMailToAdmin('created', $post);
        $result2 = $this->sendMailToUser('created', $post, $isVisitor);
        return $result1 && $result2;
    }

    /**
     * Send email to admin/user when a request is updated.
     */
    protected function sendRequestEmailUpdate(AccessRequestRepresentation $accessRequest, array $post): bool
    {
        if (!$this->settings()->get('access_message_send')) {
            return true;
        }
        // If no user, it should be already checked.
        if ($user = $accessRequest->user()) {
            $post['o:email'] = $user->email();
            $post['o:name'] = $user->name();
        }
        // Simplify process (for placeholders).
        $post['access_request'] = $accessRequest;
        if (!is_array($post['o:resource'])) {
            $post['o:resource'] = empty($post['o:resource']) ? [] : [$post['o:resource']];
        }
        $isVisitor = $this->isVisitor($accessRequest, $post);
        $isRejected = $accessRequest->status() === \Access\Entity\AccessRequest::STATUS_REJECTED;
        // $this->sendMailToAdmin('updated', $post);
        $result = $this->sendMailToUser('updated', $post, $isVisitor, $isRejected);
        return $result;
    }

    /**
     * Send a mail to administrator
     *
     * @param string $action "created" or "updated".
     */
    protected function sendMailToAdmin(?string $action, array $post): bool
    {
        if (!in_array($action, ['created', 'updated'])) {
            return false;
        }

        $settings = $this->settings();
        $moduleConfig = require dirname(__DIR__, 2) . '/config/module.config.php';

        $adminEmail = $settings->get('administrator_email');

        // Mail to administrator.
        // Sender: Don't use user mail: the server may not be able to send it.
        // See mailer.
        $mail = [];
        $mail['from'] = $adminEmail;
        $mail['fromName'] = null;
        $mail['to'] = $adminEmail;
        $mail['toName'] = null;
        // $mail['toName'] = 'Omeka S Admin';
        if ($action === 'created') {
            $mail['subject'] = $settings->get('access_message_admin_subject', $this->translate($moduleConfig['access']['settings']['access_message_admin_subject']));
            $mail['body'] = $settings->get('access_message_admin_request_created', $this->translate($moduleConfig['access']['settings']['access_message_admin_request_created']));
        } elseif ($action === 'updated') {
            $mail['subject'] = $settings->get('access_message_admin_subject', $this->translate($moduleConfig['access']['settings']['access_message_admin_subject']));
            $mail['body'] = $settings->get('access_message_admin_request_updated', $this->translate($moduleConfig['access']['settings']['access_message_admin_request_updated']));
        }

        $mail['body'] = $this->replaceText($mail['body'], $post);

        /** @uses \Access\Mvc\Controller\Plugin\MailerHtml */
        return $this->mailerHtml($mail['to'], $mail['subject'], $mail['body'], $mail['toName']);
    }

    /**
     * Send a email to user or visitor.
     *
     * @param string $action "created" or "updated".
     */
    protected function sendMailToUser(?string $action, array $post, bool $isVisitor, ?bool $isRejected = null): bool
    {
        if (!in_array($action, ['created', 'updated'])) {
            return false;
        }

        $settings = $this->settings();
        $moduleConfig = require dirname(__DIR__, 2) . '/config/module.config.php';

        $adminEmail = $settings->get('administrator_email');

        // Messages are not the same for users and visitors.
        $userVisitor = $isVisitor ? 'visitor' : 'user';

        // Mail to user.
        // Sender: Don't use user mail: the server may not be able to send it.
        // See mailer.
        $mail = [];
        $mail['from'] = $adminEmail;
        $mail['fromName'] = null;
        $mail['to'] = $post['o:email'];
        $mail['toName'] = $post['o:name'] ?? null;
        if ($action === 'created') {
            $mail['subject'] = $settings->get("access_message_{$userVisitor}_subject", $this->translate($moduleConfig['access']['settings']["access_message_{$userVisitor}_subject"]));
            $mail['body'] = $settings->get("access_message_{$userVisitor}_request_created", $this->translate($moduleConfig['access']['settings']["access_message_{$userVisitor}_request_created"]));
        } elseif ($action === 'updated') {
            $mail['subject'] = $settings->get("access_message_{$userVisitor}_subject", $this->translate($moduleConfig['access']['settings']["access_message_{$userVisitor}_subject"]));
            $acceptedRejected = $isRejected ? 'rejected' : 'accepted';
            $mail['body'] = $settings->get("access_message_{$userVisitor}_request_{$acceptedRejected}", $this->translate($moduleConfig['access']['settings']["access_message_{$userVisitor}_request_{$acceptedRejected}"]));
        }

        $mail['body'] = $this->replaceText($mail['body'], $post);

        /** @uses \Access\Mvc\Controller\Plugin\MailerHtml */
        return $this->mailerHtml($mail['to'], $mail['subject'], $mail['body'], $mail['toName']);
    }

    protected function replaceText(string $string, array $post): string
    {
        $plugins = $this->getPluginManager();
        $url = $plugins->get('url');
        $helpers = $this->viewHelpers();
        $escape = $helpers->get('escapeHtml');

        try {
            $site = $plugins->get('currentSite')();
        } catch (\Exception $e) {
            $site = null;
        }

        $replace = [
            '{main_title}' => $this->mailer()->getInstallationTitle(),
            '{main_url}' => $url->fromRoute('top', [], ['force_canonical' => true]),
            '{site_title}' => $site ? $site->title() : null,
            '{site_url}' => $site ? $site->siteUrl() : null,
            '{email}' => $post['o:email'] ?? null,
            '{name}' => $post['o:name'] ?? null,
            '{message}' => $post['o:message'] ?? null,
            '{resources}' => empty($post['o:resource']) ? '' : implode(', ', array_map('intval', $post['o:resource'])),
            '{resource}' => empty($post['o:resource']) ? '' : (int) reset($post['o:resource']),
            '{session_url}' => '',
        ];

        // The session url is the resource url with an argument.
        if (strpos($string, '{session_url}') !== false && !empty($post['o:resource'])) {
            $siteSlug = $this->defaultSiteSlug();
            $accessRequest = $post['access_request'];
            // TODO Use a hash of the email with some data (created, etc.) for security.
            $tokenOrEmail = $accessRequest->token() ?: $accessRequest->email();
            $replace['{session_url}'] = $this->url()->fromRoute('site/access-request', ['site-slug' => $siteSlug], ['query' => ['access' => $tokenOrEmail], 'force_canonical' => true]);
            /* // TODO Use an event to manage direct access (store the session before processing).
             $resourceId = (int) reset($post['o:resource']);
            /** @var \Omeka\Api\Representation\AbstractResourceEntityRepresentation $resource * /
            $resource = $this->api()->searchOne('resources', ['id' => $resourceId])->getContent();
            if ($resource) {
                $accessRequest = $post['access_request'];
                $tokenOrEmail = $accessRequest->token() ?: $accessRequest->email();
                $replace['{session_url}'] = $resource->siteUrl($siteSlug, true) . '?access=' . $tokenOrEmail;
            }
            */
        }

        // Post is already checked, except fields, so they are escaped.
        foreach ($post['fields'] ?? [] as $key => $value) {
            if (!isset($replace[$key])) {
                $replace['{' . $key . '}'] = $escape($value);
            }
        }

        return str_replace(array_keys($replace), array_values($replace), $string);
    }

    /**
     * Check if the requester is  a user or a visitor.
     *
     *  A visitor is not authenticated and can be identified by email or token.
     */
    protected function isVisitor(AccessRequestRepresentation $accessRequest, array $post): bool
    {
        $modes = $this->settings()->get('access_modes');
        $individualModes = array_intersect(['user', 'email', 'token'], $modes);
        if (!$individualModes) {
            return false;
        }
        $user = $accessRequest->user();
        return $user === null;
    }

    /**
     * Get the default site slug.
     *
     * @todo Store the source site in the access request.
     */
    protected function defaultSiteSlug(): string
    {
        $api = $this->api();
        $mainSite = (int) $this->settings()->get('default_site');
        if ($mainSite) {
            return $api->read('sites', ['id' => $mainSite])->getContent()->slug();
        }
        // Search first public site first.
        $slugs = $api->search('sites', ['is_public' => true, 'limit' => 1], ['initialize' => false, 'returnScalar' => 'slug'])->getContent();
        if ($slugs) {
            return reset($slugs);
        }
        // Else first site.
        $slugs = $api->search('sites', ['limit' => 1], ['initialize' => false, 'returnScalar' => 'slug'])->getContent();
        return $slugs ? reset($slugs) : '';
    }
}
