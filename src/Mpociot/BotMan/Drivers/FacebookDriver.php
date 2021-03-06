<?php

namespace Mpociot\BotMan\Drivers;

use Mpociot\BotMan\User;
use Mpociot\BotMan\Answer;
use Mpociot\BotMan\Message;
use Mpociot\BotMan\Question;
use Illuminate\Support\Collection;
use Mpociot\BotMan\Facebook\Template;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\ParameterBag;
use Mpociot\BotMan\Messages\Message as IncomingMessage;

class FacebookDriver extends Driver
{
    /** @var Collection|ParameterBag */
    protected $payload;

    /** @var Collection */
    protected $event;

    /** @var string */
    protected $signature;

    /** @var string */
    protected $content;

    const DRIVER_NAME = 'Facebook';

    /**
     * @param Request $request
     */
    public function buildPayload(Request $request)
    {
        $this->payload = new ParameterBag((array) json_decode($request->getContent(), true));
        $this->event = Collection::make((array) $this->payload->get('entry')[0]);
        $this->signature = $request->headers->get('X_HUB_SIGNATURE', '');
        $this->content = $request->getContent();
    }

    /**
     * Return the driver name.
     *
     * @return string
     */
    public function getName()
    {
        return self::DRIVER_NAME;
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {
        $validSignature = ! $this->config->has('facebook_app_secret') || $this->validateSignature();
        $messages = Collection::make($this->event->get('messaging'))->filter(function ($msg) {
            return isset($msg['message']) && isset($msg['message']['text']);
        });

        return ! $messages->isEmpty() && $validSignature;
    }

    /**
     * @return bool
     */
    protected function validateSignature()
    {
        return hash_equals($this->signature, 'sha1='.hash_hmac('sha1', $this->content, $this->config->get('facebook_app_secret')));
    }

    /**
     * @param Message $matchingMessage
     * @return void
     */
    public function types(Message $matchingMessage)
    {
        $parameters = [
            'recipient' => [
                'id' => $matchingMessage->getChannel(),
            ],
            'access_token' => $this->config->get('facebook_token'),
            'sender_action' => 'typing_on',
        ];

        return $this->http->post('https://graph.facebook.com/v2.6/me/messages', [], $parameters);
    }


    /**
     * @param  Message $message
     *
     * @return Answer
     */
    public function getConversationAnswer(Message $message)
    {
        $payload = $message->getPayload();
        if (isset($payload['message']['quick_reply'])) {
            return Answer::create($message->getMessage())
                ->setInteractiveReply(true)
                ->setValue($payload['message']['quick_reply']['payload']);
        }

        return Answer::create($message->getMessage());
    }

    /**
     * Retrieve the chat message.
     *
     * @return array
     */
    public function getMessages()
    {
        $messages = Collection::make($this->event->get('messaging'));
        $messages = $messages->transform(function ($msg) {
            if (isset($msg['message']) && isset($msg['message']['text'])) {
                return new Message($msg['message']['text'], $msg['recipient']['id'], $msg['sender']['id'], $msg);
            }

            return new Message('', '', '');
        })->toArray();

        if (count($messages) === 0) {
            return [new Message('', '', '')];
        }

        return $messages;
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        // Facebook bot replies don't get returned
        return false;
    }

    /**
     * Convert a Question object into a valid Facebook
     * quick reply response object.
     *
     * @param Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        $questionData = $question->toArray();

        $replies = Collection::make($question->getButtons())->map(function ($button) {
            return [
                'content_type' => 'text',
                'title' => $button['text'],
                'payload' => $button['value'],
                'image_url' => $button['image_url'],
            ];
        });

        return [
            'text' => $questionData['text'],
            'quick_replies' => $replies->toArray(),
        ];
    }

    /**
     * @param string|Question|IncomingMessage $message
     * @param Message $matchingMessage
     * @param array $additionalParameters
     * @return $this
     */
    public function reply($message, $matchingMessage, $additionalParameters = [])
    {
        $parameters = array_merge([
            'recipient' => [
                'id' => $matchingMessage->getChannel(),
            ],
            'message' => [
                'text' => $message,
            ],
        ], $additionalParameters);
        /*
         * If we send a Question with buttons, ignore
         * the text and append the question.
         */
        if ($message instanceof Question) {
            $parameters['message'] = $this->convertQuestion($message);
        } elseif ($message instanceof Template) {
            $parameters['message'] = $message->toArray();
        } elseif ($message instanceof IncomingMessage) {
            if (! is_null($message->getImage())) {
                unset($parameters['message']['text']);
                $parameters['message']['attachment'] = [
                    'type' => 'image',
                    'payload' => [
                        'url' => $message->getImage(),
                    ],
                ];
            } elseif (! is_null($message->getVideo())) {
                unset($parameters['message']['text']);
                $parameters['message']['attachment'] = [
                    'type' => 'video',
                    'payload' => [
                        'url' => $message->getVideo(),
                    ],
                ];
            } else {
                $parameters['message']['text'] = $message->getMessage();
            }
        }

        $parameters['access_token'] = $this->config->get('facebook_token');

        return $this->http->post('https://graph.facebook.com/v2.6/me/messages', [], $parameters);
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return ! is_null($this->config->get('facebook_token'));
    }

    /**
     * Retrieve User information.
     * @param Message $matchingMessage
     * @return User
     */
    public function getUser(Message $matchingMessage)
    {
        $req = $this->http->get('https://graph.facebook.com/v2.6/'.$matchingMessage->getChannel(),
                ['fields'=>'first_name,last_name,profile_pic,locale,timezone,gender',
                 'access_token' => $this->config->get('facebook_token')
                ]);
        if($req->getStatusCode()!=200)
            return new User($matchingMessage->getChannel());
        $data = json_decode($req->getContent());
        $u=new User($matchingMessage->getChannel(),$data->first_name, $data->last_name);
        $u->locale = $data->locale;
        $u->gender = $data->gender;
        $u->timezone = $data->timezone;
        $u->profile_pic = $data->profile_pic;
        return $u;
    }
}
