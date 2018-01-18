<?php

namespace app\push\controller;

use think\cache\driver\Redis;
use think\worker\Server;

class Worker extends Server
{
    protected $socket = 'websocket://0.0.0.0:55151';
    private $redis = null;
    protected $processes = 1;
    /**
     * 收到信息
     * @param $connection
     * @param $data
     */
    public function onMessage($connection, $data)
    {
        // 客户端传递的是json数据
        $message_data = json_decode($data, true);
        $worker=$this->worker;
        switch ($message_data['type']) {
            case 'login':
                // 判断当前客户端是否已经验证,即是否设置了uid
                //验证是否已存在
                $errorreturndata = "{'type':'loginerror', 'message':'".$message_data['username']."已存在'}";
                $registered = false;
                foreach($worker->connections as $conn) {
                    if (isset($conn->uid)) {
                        if ($conn->uid == $message_data['username']) {
                            $registered = true;
                            break;
                        }
                    }
                }
                if ($registered) {
                    $connection->send($errorreturndata);
                } else {
                    if(!isset($connection->uid)) {
                        $connection->uid = $message_data['username'];
                    }
                    //新用户数据
                    $logintime = date('Y-m-d H:i:s');
                    $userinfo = "{'username':'".$message_data['username']."','sex':'".$message_data['sex']."', 'headimg':'".$message_data['headimg']."', 'time':'".$logintime."'}";
                    $this->redis->set($connection->uid, $userinfo);
                    $userlist = $this->redis->keys('*');
                    $jsondata = [];
                    foreach ($userlist as $item) {
                        //排除自己
                        if ($item != $connection->uid) {
                            $value = $this->redis->get($item);
                            $jsondata[$item] = $value;
                        }
                    }
                    $jsonlist = json_encode($jsondata);
                    //向当前用户推送登录成功消息 以及好友列表
                    $returnuserlistdata = "{'type':'login', 'data':'".$message_data['username']."登陆成功', 'userinfo':[".$jsonlist."]}";
                    $connection->send($returnuserlistdata);

                    //系统向所有人推送 进入聊天室消息
                    $systemInfo = "{'username':'系统', 'headimg':'system.jpg', 'time':'".date('Y-m-d H:i:s')."'}";
                    $returndata = "{'type':'welcome', 'message':'".$message_data['username']."加入群聊', 'userinfo':[".$systemInfo."]}";

                    //向所有人推送新加入好友
                    $newuserdata = "{'type':'adduser', 'userinfo': [".$userinfo."]}";

                    foreach($worker->connections as $conn) {
                        $conn->send($returndata);
                    }
                    foreach($worker->connections as $conn) {
                        $conn->send($newuserdata);
                    }
                }
                break;
            case 'message':
                if (isset($message_data['touser'])) {
                    //对某个人说
                    if (isset($connection->uid)) {
                        $userinfo = $this->redis->get($connection->uid);
                        if (!empty($userinfo)) {
                            $returndata = "{'type':'message', 'message':'".$message_data['message']."','uid':'".$message_data['touser']."', 'userinfo':[".$userinfo."]}";
                            foreach($worker->connections as $conn) {
                                if (isset($conn->uid)) {
                                    if ($conn->uid == $message_data['touser']) {
                                        $conn->send($returndata);
                                    }
                                }
                            }
                            $connection->send($returndata);
                        }
                    }
                } else {
                    //对所有人说
                    $userinfo = $this->redis->get($connection->uid);
                    $returndata = "{'type':'message', 'message':'".$message_data['message']."', 'userinfo':[".$userinfo."]}";
                    foreach($worker->connections as $conn) {
                        $conn->send($returndata);
                    }
                }
                break;
            default:
            return;
        }
    }

    /**
     * 当连接建立时触发的回调函数
     * @param $connection
     */
    public function onConnect($connection)
    {
        if (is_null($this->redis)) {
            $this->redis = new \Redis();
            $this->redis->connect('127.0.0.1', 6379);
            $this->redis->select(2);
        }
    }

    /**
     * 当连接断开时触发的回调函数
     * @param $connection
     */
    public function onClose($connection)
    {
        if (isset($connection->uid)) {
            $uid = $connection->uid;
            $userinfo = $this->redis->get($uid);
            $returndata = "{'type':'logout', 'uid':'".$uid."', 'userinfo':[".$userinfo."]}";
            //移除redis用户
            $this->redis->del($uid);
            $worker=$this->worker;
            //向所有人推送
            foreach($worker->connections as $conn) {
                $conn->send($returndata);
            }

        }
    }

    /**
     * 当客户端的连接上发生错误时触发
     * @param $connection
     * @param $code
     * @param $msg
     */
    public function onError($connection, $code, $msg)
    {
        echo "error $code $msg\n";
    }

    /**
     * 每个进程启动
     * @param $worker
     */
    public function onWorkerStart($worker)
    {
        $this->worker = $worker;
    }
}