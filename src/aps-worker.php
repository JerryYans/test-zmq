<?php
require_once dirname(__FILE__) . '/aps-functions.php';

/**
 */
class APSWorker {
    const VERSION = 'APS10';
    public $file = "";
    private $log_info = "";
    private $socket_id = 0;
    /**
     * @var ZMQSocket
     */
    private $socket = null;

    public function __construct($context, $endpoint) {
        $this->file = fopen("/var/log/zmq/test.txt","a+");

        $this->socket_id = strval(posix_getpid());
        $socket = new ZMQSocket($context, ZMQ::SOCKET_XREQ);
        $socket->setsockopt(ZMQ::SOCKOPT_LINGER, 0);
        $socket->setsockopt(ZMQ::SOCKOPT_IDENTITY, $this->socket_id);
        $socket->connect($endpoint);
        define("worker_id", $this->socket_id);

        $str = "work ".worker_id." new socket:{$this->socket_id}, endpoint:{$endpoint}; \n";
        fwrite($this->file, $str);

        $this->socket = $socket;

        $this->interval = 1000 * 1000;

        $this->interrupted = false;
    }

    public function run() {
        $r_info = "";
        fwrite($this->file, "work ".worker_id." run start ;\n");

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
            fwrite($this->file, "work ".worker_id." polling ;\n");
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
        $now_time = date("Y-m-d H:i:s",time());
        $this->log_info = " work ".worker_id." send_heartbeat_frames, time: $now_time ;\n";
        aps_send_frames($this->socket, array('', self::VERSION, chr(0x01)));
    }

    protected function process() {

        $frames = aps_recv_frames($this->socket);


        list($envelope, $message) = aps_envelope_unwrap($frames);

        $this->log_info .= "work ".worker_id."; recv socket info, envelope: ".json_encode($envelope).";\n";
        $this->log_info .= "work ".worker_id."; recv socket info, message: ".json_encode($message).";\n";

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
        $this->log_info .= "work ".worker_id."; sequence:$sequence,timestamp:$timestamp, envelope:".json_encode($envelope).";\n";
        $this->log_info .= "work ".worker_id."; call_user_func:$method, parame:".json_encode($params).";\n";
        $reply = call_user_func_array(array($this->delegate, $method), $params);
        $this->log_info .= "work ".worker_id."; deal back info:$reply;\n";
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
        $this->log_info .= "work ".worker_id."; send result to device..\n\n";
        aps_send_frames($this->socket, $frames);
    }
}

