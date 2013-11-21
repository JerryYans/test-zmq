<?php

class ApsClient{

    private $socket = "";

    private static $sequence = 0;
    const VERSION = 'APS10';

    public function __construct($endpoints){
        $context = new ZMQContext();
        $socket = new ZMQSocket($context, ZMQ::SOCKET_XREQ);
        $socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->setsockopt(ZMQ::SOCKOPT_HWM, 1000);
        $socket->connect($endpoint);
        $this->socket = $socket;
        $this->expiry = 1000;
    }

    public function __destruct () {
        unset($this->socket);
    }

    public function start_request($method, $params, $callback = NULL, $expiry = NULL){
        $sequence = ++self::$sequence;
        $timestamp = round(microtime(true)*1000);
        $frames[] = '';
        $frames[] = self::VERSION;
        $frames[] = msgpack_pack(array($sequence, $timestamp, $expiry));
        $frames[] = $method;
        $frames[] = msgpack_pack($params);
        $this->sendMsg($frames);
    }

    protected function sendMsg($frames){
        $last = array_pop($frames);
        foreach ($frames as $frame) {
            $this->socket->send($frame, ZMQ::MODE_SNDMORE);
        }
        $this->socket->send($last);
    }

    public function get_response($wait_timt = 0){
        $frames = array();
        do {
            $frames[] = $this->socket->recv();
        } while ($this->socket->getsockopt(ZMQ::SOCKOPT_RCVMORE));

        $i = array_search('', $frames, true);
        if ($i === NULL) {
            return array(array(), $frames);
        }
        $envelope = array_slice($frames, 0, $i);
        $message = array_slice($frames, $i + 1);

        $version = array_shift($message);
        list($sequence, $timestamp, $status) = msgpack_unpack(array_shift($message));

        $reply = array_shift($message);
        if ($reply !== NULL) {
            $reply = msgpack_unpack($reply);
        }
        $rt = array(
            "status"	=>	$status,
            "info"		=>	$reply
        );
        return $rt;
    }

}



