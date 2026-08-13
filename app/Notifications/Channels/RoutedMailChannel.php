<?php

namespace App\Notifications\Channels;

use App\Services\Mail\SmtpMailRouter;
use Illuminate\Contracts\Mail\Mailable;
use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Channels\MailChannel;
use Illuminate\Notifications\Notification;

/** Keeps Laravel's notification rendering and signed URLs while centralising transport selection. */
class RoutedMailChannel extends MailChannel
{
    public function __construct(
        private readonly SmtpMailRouter $router,
        \Illuminate\Contracts\Mail\Factory $mailer,
        Markdown $markdown,
    ) {
        parent::__construct($mailer, $markdown);
    }

    public function send($notifiable, Notification $notification)
    {
        $message = $notification->toMail($notifiable);
        if (! $notifiable->routeNotificationFor('mail', $notification) && ! $message instanceof Mailable) {
            return;
        }

        if ($message instanceof Mailable) {
            return $message->send($this->router->mailerFor());
        }

        return $this->router->mailerFor()->send(
            $this->buildView($message),
            array_merge($message->data(), $this->additionalMessageData($notification)),
            $this->messageBuilder($notifiable, $notification, $message),
        );
    }
}
