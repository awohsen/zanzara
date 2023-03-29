<?php

declare(strict_types=1);

namespace Zanzara\UpdateMode;

use Psr\Container\ContainerInterface;
use Psr\Http\Message\ServerRequestInterface;
use React\EventLoop\LoopInterface;
use React\Http\HttpServer;
use React\Http\Message\Response;
use Zanzara\Config;
use Zanzara\Context;
use Zanzara\Telegram\Telegram;
use Zanzara\Telegram\Type\Update;
use Zanzara\Telegram\Type\Webhook\WebhookInfo;
use Zanzara\Zanzara;
use Zanzara\ZanzaraLogger;
use Zanzara\ZanzaraMapper;

/**
 *
 */
class ReactPHPWebhook extends BaseWebhook
{

    /**
     * @var HttpServer
     */
    private $server;

    public function __construct(ContainerInterface $container, Zanzara $zanzara, Telegram $telegram, Config $config,
                                ZanzaraLogger $logger, LoopInterface $loop, ZanzaraMapper $zanzaraMapper)
    {
        parent::__construct($container, $zanzara, $telegram, $config, $logger, $loop, $zanzaraMapper);
        $this->init();
    }

    private function init()
    {
        $processingUpdate = null;
        $server = new HttpServer($this->loop, function (ServerRequestInterface $request) use (&$processingUpdate) {
            $token = $this->resolveTokenFromPath($request->getUri()->getPath());
            if (!$this->isWebhookAuthorized($token)) {
                $this->logger->errorNotAuthorized();
                return new Response(403, [], $this->logger->getNotAuthorizedMessage());
            }
            $json = (string)$request->getBody();
            /** @var Update $processingUpdate */
            $processingUpdate = $this->zanzaraMapper->mapJson($json, Update::class);
            $this->processUpdate($processingUpdate);
            return new Response();
        });
        $server->on('error', function ($e) use (&$processingUpdate) {
            $contextClass = $this->config->getContextClass();
            $context = new $contextClass($processingUpdate, $this->container);
            if (!$this->zanzara->callOnException($context, $e)) {
                $this->logger->errorUpdate($e, $processingUpdate);
            }
        });
        $this->setServer($server);
    }

    /**
     * @inheritDoc
     */
    public function run()
    {
        $this->telegram->getWebhookInfo()->then(
            function (WebhookInfo $webhookInfo) {
                if (!$webhookInfo->getUrl()) {
                    $message = "Your bot doesn't have a webhook set, please set one before running Zanzara in webhook" .
                        " mode. See https://github.com/badfarm/zanzara/wiki#set-webhook";
                    $this->logger->error($message);
                    return;
                }
                $this->startListening();
            }
        );
    }

    private function startListening()
    {
        $socket = new \React\Socket\SocketServer($this->config->getServerUri(), $this->config->getServerContext(), $this->loop);
        $this->server->listen($socket);
        $this->logger->logIsListening();
    }

    /**
     * @return HttpServer
     */
    public function getServer(): HttpServer
    {
        return $this->server;
    }

    /**
     * @param HttpServer $server
     */
    public function setServer(HttpServer $server): void
    {
        $this->server = $server;
    }

}
