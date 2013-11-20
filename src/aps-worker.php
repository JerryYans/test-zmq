<?php
require_once dirname(__FILE__) . '/aps-functions.php';

/**
 */
class APSWorker {
    const VERSION = 'APS10';
    public $file = "";
    private $log_info = "";

    public function __construct($context, $endpoint) {
        $this->file = fopen("/var/log/zmq/test.txt","a+");

        $socket = new ZMQSocket($context, ZMQ::SOCKET_XREQ);
        $socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->setsockopt(ZMQ::SOCKOPT_IDENTITY, strval(posix_getpid()));
        $socket->connect($endpoint);
        $this->socket = $socket;

        $this->interval = 1000 * 1000;

        $this->interrupted = false;
    }

    public function run() {
        $r_info = "";
        fwrite($this->file, "work ".worker_id." run start \n;");

        $this->send_heartbeat_frames();
        $poll = new ZMQPoll();
        $poll->add($this->socket, ZMQ::POLL_IN);
        while (!$this->interrupted) {
            $this->log_info = "";
            $readable = $writeable = array();
            $events = $poll->poll($readable, $writeable, $this->interval);
            if (posix_getppid() == 1) {
                break;
            }
            if ($events) {
                $this->process();
            } else {
                $this->send_heartbeat_frames();
            }
            fwrite($this->file, $this->log_info);
        }
        fwrite($this->file, "work ".worker_id." run end \n;");
        fclose($this->file);
    }

    protected function send_heartbeat_frames() {
        $this->log_info = " work ".worker_id." send_heartbeat_frames; \n";
        aps_send_frames($this->socket, array('', self::VERSION, chr(0x01)));
    }

    protected function process() {

        $frames = aps_recv_frames($this->socket);

        list($envelope, $message) = aps_envelope_unwrap($frames);

        $version = array_shift($message);
        $command = array_shift($message);
        if ($command == 0x00) {
            $this->process_request($message);
        }
    }

    protected function process_request($message) {
        list($envelope, $message) = aps_envelope_unwrap($message);
        list($sequence, $timestamp, $expiry) = msgpack_unpack(array_shift($message));

        $now = aps_millitime();
        if ($timestamp + $expiry < $now) {
            $this->send_reply_frames($envelope, $sequence, $now, 503, NULL);
            return;
        }

        $method = array_shift($message);
        if ($method === NULL) {
            $this->send_reply_frames($envelope, $sequence, $now, 400, NULL);
            return;
        }
        $params = array_shift($message);
        if ($params !== NULL) {
            $params = msgpack_unpack($params);
        }

        $reply = call_user_func_array(array($this->delegate, $method), $params);

        $this->log_info .= "work ".worker_id."; sequence:$sequence,timestamp:$timestamp, envelope:".json_encode($envelope).";\n";
        $this->log_info .= "call_user_func:$method, parame:".json_encode($params).";";
        $this->log_info .= "deal back info:$reply,send result to client..\n";

        $now = aps_millitime();
        $this->send_reply_frames($envelope, $sequence, $now, 200, $reply);
    }

    protected function send_reply_frames($envelope, $sequence, $timestamp, $status, $reply) {
        $frames = array_merge(array('', self::VERSION, chr(0x00)), $envelope);
        $frames[] = '';
        $frames[] = msgpack_pack(array($sequence, $timestamp, $status));
        if ($reply !== NULL) {
            $frames[] = msgpack_pack($reply);
        }
        aps_send_frames($this->socket, $frames);
    }
}

