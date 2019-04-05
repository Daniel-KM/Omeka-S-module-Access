<?php
namespace AccessResource\Mail;

use Omeka\Entity\User;
use AccessResource\Traits\ServiceLocatorAwareTrait;
use Zend\ServiceManager\ServiceLocatorInterface;

class RequestMailer
{
    use ServiceLocatorAwareTrait;
    protected $config;
    protected $entityManager;
    protected $admin_user;
    protected $mailer;

    public function __construct(ServiceLocatorInterface $serviceLocator)
    {
        $this->setServiceLocator($serviceLocator);
        $this->config = $this->getServiceLocator()->get('Config');
        $this->entityManager = $this->getServiceLocator()->get('Omeka\EntityManager');
        if ($site_admin = $this->entityManager->getRepository(User::class)->findOneByRole('site_admin')) {
            $this->admin_user = $site_admin;
        } else {
            $this->admin_user = $this->entityManager->getRepository(User::class)->findOneByRole('global_admin');
        }
        $this->mailer = $this->getServiceLocator()->get('Omeka\Mailer');
    }

    /**
     * @param string $action "created" or "updated"".
     */
    public function sendMailToAdmin($action)
    {
        if (!isset($action)) {
            return;
        }

        // mail to administrator
        $mail = [];
        $mail['from'] = $this->admin_user->getEmail();
        $mail['fromName'] = $this->admin_user->getName();
        $mail['to'] = $this->admin_user->getEmail();
        $mail['toName'] = $this->admin_user->getName();
        if ($action === "created") {
            $mail['subject'] = $this->config['accessresource']['config']['accessresource_mail_subject'];
            $mail['body'] = $this->config['accessresource']['config']['accessresource_admin_message_request_created'];
        } elseif ($action === "updated") {
            $mail['subject'] = $this->config['accessresource']['config']['accessresource_mail_subject'];
            $mail['body'] = $this->config['accessresource']['config']['accessresource_admin_message_request_updated'];
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
     * @param string $action "created" or "updated"".
     */
    public function sendMailToUser($action)
    {
        if (!isset($action)) {
            return;
        }

        // mail to user
        $user = $this->serviceLocator->get('Omeka\AuthenticationService')->getIdentity();
        $mail = [];
        $mail['from'] = $this->admin_user->getEmail();
        $mail['fromName'] = $this->admin_user->getName();
        $mail['to'] = $user->getEmail();
        $mail['toName'] = $user->getName();
        if ($action === "created") {
            $mail['subject'] = $this->config['accessresource']['config']['accessresource_mail_subject'];
            $mail['body'] = $this->config['accessresource']['config']['accessresource_user_message_request_created'];
        } elseif ($action === "updated") {
            $mail['subject'] = $this->config['accessresource']['config']['accessresource_mail_subject'];
            $mail['body'] = $this->config['accessresource']['config']['accessresource_user_message_request_updated'];
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
