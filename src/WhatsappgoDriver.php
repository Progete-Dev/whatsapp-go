<?php

namespace BotMan\Drivers\Whatsappgo;

use Illuminate\Support\Collection;
use BotMan\BotMan\Drivers\HttpDriver;
use BotMan\BotMan\Users\User;
use BotMan\BotMan\Messages\Incoming\Answer;
use BotMan\BotMan\Messages\Attachments\File;
use BotMan\BotMan\Messages\Attachments\Audio;
use BotMan\BotMan\Messages\Attachments\Image;
use BotMan\BotMan\Messages\Attachments\Video;
use BotMan\BotMan\Messages\Outgoing\Question;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use BotMan\Drivers\Waboxapp\Exceptions\WaboxappException;
use BotMan\BotMan\Messages\Attachments\Location;
use Symfony\Component\HttpFoundation\ParameterBag;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\Drivers\Waboxapp\Exceptions\UnsupportedAttachmentException;

class WaboxappDriver extends HttpDriver
{
    protected $headers = [];

    const DRIVER_NAME = 'Whatsappgo';

    const API_BASE_URL = 'http://localhost:3000/';

    /**
     * @param Request $request
     * @return void
     */
    public function buildPayload(Request $request)
    {
        parse_str($request->getContent(), $output);
        $this->payload = new ParameterBag($output ?? []);
        $this->headers = $request->headers->all();
        $this->event = Collection::make($this->payload);
        $this->config = Collection::make($this->config->get('whatsappgo', []));
    }

    /**
     * Determine if the request is for this driver.
     *
     * @return bool
     */
    public function matchesRequest()
    {

        $matches = Collection::make(['event', 'token', 'uid', 'contact', 'message'])->diffAssoc($this->event->keys())->isEmpty();

        // catch only incoming messages
        return $matches && $this->event->get('message')['dir'] == 'i';
    }

    /**
     * Retrieve the chat message(s).
     *
     * @return array
     */
    public function getMessages()
    {
        $message = (array) $this->event->all();

        $incomingMessage = new IncomingMessage($message['Text'], $message['Info']['RemoteJid'], $message['Info']['Id'], $message);


        return [$incomingMessage];
    }

    /**
     * @return bool
     */
    public function isBot()
    {
        return false;
    }

    /**
     * @return bool
     */
    public function isConfigured()
    {
        return !empty($this->config->get('token'));
    }

    /**
     * Retrieve User information.
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return User
     */
    public function getUser(IncomingMessage $matchingMessage)
    {
        return new User($matchingMessage->getSender(), $this->payload->get('Info')['RemoteJid'], null, $matchingMessage->getSender());
    }


    /**
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $message
     * @return Answer
     */
    public function getConversationAnswer(IncomingMessage $message)
    {
        return Answer::create($message->getText())->setMessage($message);
    }

    /**
     * Convert a Question object into a valid message
     *
     *
     * @param \BotMan\BotMan\Messages\Outgoing\Question $question
     * @return array
     */
    private function convertQuestion(Question $question)
    {
        $buttons = $question->getButtons();
        if ($buttons) {
            $options =  Collection::make($buttons)->transform(function ($button) {
                return $button['value'] . ' - ' . $button['text'];
            })->toArray();

            return $question->getText() . "\nOptions: " . implode(', ', $options);
        }
    }

    /**
     * @param OutgoingMessage|\BotMan\BotMan\Messages\Outgoing\Question $message
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @param array $additionalParameters
     * @return array
     * @throws UnsupportedAttachmentException
     */
    public function buildServicePayload($message, $matchingMessage, $additionalParameters = [])
    {
        $payload = [
            'msisdn' => $matchingMessage->getSender()
        ];

        if ($message instanceof OutgoingMessage) {
            $payload['message'] = $message->getText();
        } elseif ($message instanceof Question) {
            $payload['message'] = $this->convertQuestion($message);
        }

        return $payload;
    }

    protected function getRequestCredentials()
    {
        return $this->config->get('token');
    }

    /**
     * @param mixed $payload
     * @return Response
     */
    public function sendPayload($payload)
    {

        $endpoint = null;

        switch ($payload['type']) {
            case 'text':
                $endpoint = '/send/text';
                break;
                // case 'picture':
                //     $endpoint = '/send/image';
                //     break;
                // case 'video':
                //     $endpoint = '/send/media';
                //     break;
                // default:
                throw new \Exception('Payload type not implemented!');
        }

        return $this->http->post(
            self::API_BASE_URL . $endpoint,
            [],
            $payload,
            ['Content-Type:application/json', 'Authorization Bearer' . $this->getRequestCredentials()],
            true
        );
    }

    /**
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return Response
     */
    public function types(IncomingMessage $matchingMessage)
    {
        // Do nothing
    }

    /**
     * Low-level method to perform driver specific API requests.
     *
     * @param string $endpoint
     * @param array $parameters
     * @param \BotMan\BotMan\Messages\Incoming\IncomingMessage $matchingMessage
     * @return Response
     */
    public function sendRequest($endpoint, array $parameters, IncomingMessage $matchingMessage)
    {
        $payload = array_merge_recursive([
            'msisdn' => $matchingMessage->getRecipient(),
        ], $parameters);


        return $this->sendPayload($payload);
    }
}
