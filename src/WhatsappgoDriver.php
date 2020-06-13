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
use BotMan\Drivers\Whatsappgo\Exceptions\WhatsappgoException;
use BotMan\BotMan\Messages\Attachments\Location;
use Symfony\Component\HttpFoundation\ParameterBag;
use BotMan\BotMan\Messages\Incoming\IncomingMessage;
use BotMan\BotMan\Messages\Outgoing\OutgoingMessage;
use BotMan\Drivers\Whatsappgo\Exceptions\UnsupportedAttachmentException;
use Exception;
use Illuminate\Support\Facades\Storage;

class WhatsappgoDriver extends HttpDriver
{
    protected $headers = [];
    protected $client;
    protected $clientHeaders;
    const DRIVER_NAME = 'Whatsappgo';

    const API_BASE_URL = 'https://whatsapp-go.herokuapp.com/';

    /**
     * @param Request $request
     * @return void
     */
    public function buildPayload(Request $request)
    {

        $this->payload = json_decode($request->getContent());
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

        
        return ! is_null($this->event->get('Info')) || ! is_null($this->event->get('Context'));
    }

    /**
     * Retrieve the chat message(s).
     *
     * @return array
     */
    public function getMessages()
    {
        $message = (array) $this->event->all();
        
        switch($message["Info"]->MessageType){
            case "data/image":
                $response = new IncomingMessage(Image::PATTERN, $message['Info']->RemoteJid, $message['Info']->RemoteJid, $message);
                $response->setImages($this->getImages());
                
                return [$response];
            case "text":
                return [new IncomingMessage($message['Text'], $message['Info']->RemoteJid, $message['Info']->RemoteJid, $message)];
            case "location":
                $response =  [new IncomingMessage(Location::PATTERN, $message['Info']->RemoteJid, $message['Info']->RemoteJid, $message)];
                $response->setLocation(new Location($this->event->get('Latitude'),$this->event->get('Longitude')));
                return [$response];
            
        }
    
    }
    private function getImages(){
        $url = WhatsappgoDriver::API_BASE_URL."file/".$this->event->get('Data');
        $payload = $this->http->post($url,[],[], [
            "Authorization: Bearer {$this->getRequestCredentials()}",
        ],false);
        
        return [new Image($url,"data:image/png;base64,".base64_encode($payload->getContent()))];
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
        return Answer::create($message->getText())
            ->setMessage($message)
            ->setInteractiveReply(true);
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
                return "*{$button['value']}* - " .str_replace($button['value'],"*{$button['value']}*",$button['text']);
            })->toArray();
            return $question->getText() . "\nOptions: " . implode("\n", $options);
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
            'msisdn' => $matchingMessage->getSender(),

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
        $response = $this->http->post(WhatsappgoDriver::API_BASE_URL.'send/text', $payload,[], [
            "Authorization: Bearer {$this->getRequestCredentials()}",
            'Content-Type: application/json',
            'Accept: application/json',
        ], true);
        return $payload;
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
