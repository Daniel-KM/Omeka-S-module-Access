<?php declare(strict_types=1);

namespace AccessResource\Mvc\Controller\Plugin;

use Laminas\Authentication\AuthenticationService;
use Laminas\Mvc\Controller\Plugin\AbstractPlugin;
use Omeka\Entity\User;
use Omeka\Settings\Settings;
use Omeka\Stdlib\Mailer;

class RequestMailer extends AbstractPlugin
{
    /**
     * @var \Omeka\Stdlib\Mailer
     */
    protected $mailer;

    /**
     * @var \Omeka\Settings;
     */
    protected $settings;

    /**
     * @var AuthenticationService
     */
    protected $authenticationService;

    /**
     * @var array
     */
    protected $config;

    /**
     * @var \Omeka\Entity\User
     */
    protected $adminUser;

    public function __construct(
        Mailer $mailer,
        Settings $settings,
        AuthenticationService $authenticationService,
        array $config,
        User $adminUser
    ) {
        $this->mailer = $mailer;
        $this->settings = $settings;
        $this->authenticationService = $authenticationService;
        $this->config = $config;
        $this->adminUser = $adminUser;
    }

    /**
     * @param string $action "created" or "updated".
     */
    public function sendMailToAdmin($action): void
    {
        if (!isset($action)) {
            return;
        }

        // Mail to administrator.
        $mail = [];
        $mail['from'] = $this->adminUser->getEmail();
        $mail['fromName'] = $this->adminUser->getName();
        $mail['to'] = $this->adminUser->getEmail();
        $mail['toName'] = $this->adminUser->getName();
        if ($action === 'created') {
            $mail['subject'] = $this->settings->get('accessresource_message_admin_subject', $this->config['accessresource']['settings']['accessresource_message_admin_subject']);
            $mail['body'] = $this->settings->get('accessresource_message_admin_request_created', $this->config['accessresource']['settings']['accessresource_message_admin_request_created']);
        } elseif ($action === 'updated') {
            $mail['subject'] = $this->settings->get('accessresource_message_admin_subject', $this->config['accessresource']['settings']['accessresource_message_admin_subject']);
            $mail['body'] = $this->settings->get('accessresource_message_admin_request_updated', $this->config['accessresource']['settings']['accessresource_message_admin_request_updated']);
        }

        $message = $this->mailer->createMessage();
        $message
            ->setFrom($mail['from'], $mail['fromName'])
            ->setTo($mail['to'], $mail['toName'])
            ->setSubject($mail['subject'])
            ->setBody($mail['body']);
        $this->mailer->send($message);
    }

    /**
     * @param string $action "created" or "updated".
     */
    public function sendMailToUser($action): void
    {
        if (!isset($action)) {
            return;
        }

        // Mail to user.
        $user = $this->authenticationService->getIdentity();
        $mail['from'] = $this->adminUser->getEmail();
        $mail['fromName'] = $this->adminUser->getName();
        $mail['to'] = $user->getEmail();
        $mail['toName'] = $user->getName();
        if ($action === 'created') {
            $mail['subject'] = $this->settings->get('accessresource_message_user_subject', $this->config['accessresource']['settings']['accessresource_message_user_subject']);
            $mail['body'] = $this->settings->get('accessresource_message_user_request_created', $this->config['accessresource']['settings']['accessresource_message_user_request_created']);
        } elseif ($action === 'updated') {
            $mail['subject'] = $this->settings->get('accessresource_message_user_subject', $this->config['accessresource']['settings']['accessresource_message_user_subject']);
            $mail['body'] = $this->settings->get('accessresource_message_user_request_updated', $this->config['accessresource']['settings']['accessresource_message_user_request_updated']);
        }

        $message = $this->mailer->createMessage();
        $message
            ->setFrom($mail['from'], $mail['fromName'])
            ->setTo($mail['to'], $mail['toName'])
            ->setSubject($mail['subject'])
            ->setBody($mail['body']);
        $this->mailer->send($message);
    }
}
