<?php

namespace PHPPM\Bridges;

use React\Http\Request as ReactRequest;
use PHPPM\React\HttpResponse as ReactResponse;
use Zend\Http\PhpEnvironment\Request as ZendRequest;
use Zend\Http\PhpEnvironment\Response as ZendResponse;
use Zend\Http\Headers as ZendHeaders;
use Zend\Stdlib\Parameters;
use Zend\Mvc\MvcEvent;
use Zend\Mvc\SendResponseListener;
use Zend\Mvc\ResponseSender\SendResponseEvent;
use React\EventLoop\LoopInterface;
use PHPPM\Bridges\Psr\Http\Message\ServerRequestInterface;

class Yii2 implements BridgeInterface
{
    /**
     * @var \yii\web\Application
     */
    protected $application;

    /**
     * @param string $appBootstrap
     * @param string $appenv
     * @param boolean $debug
     */
    public function bootstrap($appBootstrap, $appenv, $debug)
    {
        /* @var $bootstrap \PHPPM\Bootstraps\Yii2 */
        $bootstrap = new \PHPPM\Bootstraps\Yii2($appenv);
        $this->application = $bootstrap->getApplication();
    }

    public function getStaticDirectory()
    {
        return '';
    }

    /**
     * Handle a request using Zend\Mvc\Application.
     *
     * @param ReactRequest $request
     * @param ReactResponse $response
     */
    public function onRequest(ReactRequest $request, ReactResponse $response)
    {
        if (null === ($app = $this->application)) {
            return;
        }

        /* @var $sm \Zend\ServiceManager\ServiceManager */
        $sm = $app->getServiceManager();

        $zfRequest = new ZendRequest();
        $zfResponse = new ZendResponse();

        self::mapRequest($request, $zfRequest);

        $sm->setAllowOverride(true);
        $sm->setService('Request', $zfRequest);
        $sm->setService('Response', $zfResponse);
        $sm->setAllowOverride(false);

        $event = $app->getMvcEvent();
        $event->setRequest($zfRequest);
        $event->setResponse($zfResponse);

        try {
            $app->run($zfRequest, $zfResponse);
        } catch (\Exception $exception) {
            $response->writeHead(500); // internal server error
            $response->end();
            return;
        }

        self::mapResponse($response, $zfResponse);
    }

    /**
     * @param ReactRequest $reactRequest
     * @param ZendRequest $zfRequest
     */
    protected static function mapRequest(ReactRequest $reactRequest,
        ZendRequest $zfRequest)
    {
        $headers = new ZendHeaders();
        $headers->addHeaders($reactRequest->getHeaders());

        $query = new Parameters();
        $query->fromArray($reactRequest->getQuery());

        $zfRequest->setHeaders($headers);
        $zfRequest->setQuery($query);
        $zfRequest->setMethod($reactRequest->getMethod());
        $zfRequest->setUri($reactRequest->getPath());
        $zfRequest->setRequestUri($reactRequest->getPath());

        $server = $zfRequest->getServer();
        $server->set('REQUEST_URI', $reactRequest->getPath());
        $server->set('SERVER_NAME', $zfRequest->getHeader('Host'));
    }

    /**
     * @param ReactResponse $reactResponse
     * @param ZendResponse $zfResponse
     */
    protected static function mapResponse(ReactResponse $reactResponse,
        ZendResponse $zfResponse)
    {
        $headers = array_map('current', $zfResponse->getHeaders()->toArray());
        $reactResponse->writeHead($zfResponse->getStatusCode(), $headers);
        $reactResponse->end($zfResponse->getContent());
    }
	
	    /**
     * {@inheritdoc}
     */
    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (null === $this->application) {
            // internal server error
            return new Psr7\Response(500, ['Content-type' => 'text/plain'], 'Application not configured during bootstrap');
        }
        $syRequest = $this->mapRequest($request);
        // start buffering the output, so cgi is not sending any http headers
        // this is necessary because it would break session handling since
        // headers_sent() returns true if any unbuffered output reaches cgi stdout.
        ob_start();
        if ($this->bootstrap instanceof HooksInterface) {
            $this->bootstrap->preHandle($this->application);
        }
        $syResponse = $this->application->handle($syRequest);
        $out = ob_get_clean();
        $response = $this->mapResponse($syResponse, $out);
        if ($this->application instanceof TerminableInterface) {
            $this->application->terminate($syRequest, $syResponse);
        }
        if ($this->application instanceof Kernel) {
            $this->application->terminate($syRequest, $syResponse);
        }
        if ($this->bootstrap instanceof HooksInterface) {
            $this->bootstrap->postHandle($this->application);
        }
        return $response;
    }
}