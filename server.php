<?php

class Server extends swoole_websocket_server{
    /** @var swoole_websocket_server  */
    protected $server;

    // 基础
    /** @var int 游戏状态 0、等待，1、开始，2、进行中，3、结束*/
    protected $gameStatus = 0;
    /** @var int 游戏轮数 */
    protected $gameRound = 0;
    /** @var array 用户池 */
    protected $user = [];
    /** @var array 投票池*/
    protected $vode = [];
    /** @var array 队长池 */
    protected $caption = [];
    /** @var array 队伍池 */
    protected $team = [];

    // 配置
    /** @var array 角色 */
    protected $role = [
        "梅林" => ["init_see" => ["莫甘娜", "刺客"], "action" => []],
        "派西维尔" => ["init_see" => ["梅林", "莫甘娜"], "action" => []],
        "忠臣" => ["init_see" => [], "action" => []],
        "莫甘娜" => ["init_see" => ["刺客"], "action" => []],
        "刺客" => ["init_see" => ["莫甘娜"], "action" => ["kill"]],
    ];
    /** @var array 每轮任务数 */
    protected $doTaskNum = [1 => 2, 2 => 3, 3 => 4, 4 => 3, 5 => 4];
    /** @var array 可访问方法列表 */
    protected $funcList = [];

    /**
     * 构造函数
     *
     * Server constructor.
     * @param $host
     * @param $port
     */
    public function __construct($host, $port)
    {
        $this->server = new  swoole_websocket_server($host, $port);
        $this->server->set(array(
            // worker_num一般配置cpu核数的1-4倍
            'worker_num' => 1,
            // daemonize 守护进程
            'daemonize' => 1
        ));

        // open事件 客户端打开websocket事件
        // 参考 https://wiki.swoole.com/wiki/page/397.html
        $this->server->on('open', function ($ser, $request) {
            echo "成功连接，fd:{$request->fd}\n";
            $userId = count($this->user);
            if ($userId > 6) {
                echo "超过人数上线\n";
                $this->server->close($request->fd);
                return;
            }
            $data = array(
                'uid' => $userId,
                'fd' => $request->fd,
                'game_status' => 0
            );
            $this->user[$userId] = $data;
            // 通知对应用户个人信息
            $this->unicast($request->fd, "user_info", $data);
            // 广播除了当前客户端的所有用户，其他玩家进入
            $this->broadcast("other_login", $data, [$request->fd]);
        });

        // 消息事件，客户端的操作
        $this->server->on('message', function ($ser, $frame) {
            $data = json_decode($frame->data, true);
            $action = $data['action'];
            $argv = $data['argv'];
            $uid = $this->get_uid($frame->fd);
            if (!$uid) {
                echo "找不到该用户id\n";
            } else {
                if (in_array($action, $this->funcList) &&method_exists($this->server, $action)) {
                    $this->$action($uid, $argv);
                } else {
                    echo "找不到该方法！\n";
                }
            }
        });

        // 关闭事件，客户端关闭websocket事件
        $this->server->on('close', function ($ser, $fd) {
            $uid = $this->get_uid($fd);
            if (!$uid) {
                echo "找不到该用户id\n";
            } else {
                unset($this->user[$uid]);
                // 广播除了当前客户端的所有用户，其他玩家登出
                $this->broadcast("other_login", []);
                // 客户端需要刷新操作
                $this->game_init();
            }

        });

        $this->game_init();
    }

    /**
     * 初始化游戏
     */
    protected function game_init()
    {
        $this->gameStatus = 0;
        $this->gameRound = 0;
        $this->user = [];
        $this->vode = [];
        $this->caption = [];
        $this->team = [];
    }

    /**
     * 获取用户id
     *
     * @param $fd
     * @return int|string
     */
    protected function get_uid($fd){
        foreach($this->user as $k => $v){
            if($v['fd'] == $fd){
                return $k;
            }
        }
        return 0;
    }


    protected function doMessage($ser, $frame)
    {

    }

    protected function doClose($ser, $fd)
    {

    }


    /**
     * 单播
     *
     * 连接标示 @param $fd
     * 行为 @param $action
     * 数据 @param $data
     */
    protected function unicast($fd, $action, $data)
    {
        $info = ["action" => $action, "data" => $data];
        $this->server->push($fd, json_encode($info));
    }

    /**
     * 广播
     *
     * 行为 @param $action
     * 数据 @param array $data
     * 排除的fd @param array $shieldFd
     */
    protected function broadcast($action, $data = [], $shieldFd = [])
    {
        $info = ["action" => $action, "data" => $data];
        array_map(function($v) use ($info, $shieldFd) {
            if ($shieldFd) {
                if (!in_array($v['fd'], $shieldFd)) {
                    $this->server->push($v['fd'], json_encode($info));
                }
            } else {
                $this->server->push($v['fd'], json_encode($info));
            }
        }, $this->user);
    }
}
