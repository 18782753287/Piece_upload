<?php
class upload {
    private $uploadDir = "./upload";
    private $redis;
    public  function __construct()
    {
        $this->redis = new Redis();
        $this->redis->connect('127.0.0.1');
    }
    public function upload()
    {
        // 1. 记录文件是否上传
        $totalKey = $_POST['fileName'] . ':' . $_POST['totalSize'];
        // 根据文件名和文件大小综合判断是不是同一个文件，靠谱吗？？？
        if ($this->redis->get($totalKey)) // 获取的参数只有 2 种值，0 为未上传或者未完全上传， 1 为全部上传完成
        {
            exit(json_encode(['code' => 1, 'msg' => '文件已经上传过']));
        }
        $this->redis->set($totalKey, 0);  // redis 中记录文件的上传状态， 0 未上传，1 已上传
        $totalList = $_POST['fileName'] . ':' . $_POST['totalSize'] . ':' . 'list';
        $totalChunkKey = $_POST['fileName'] . ':' . $_POST['totalSize'] . ':' . $_POST['totalChunk'];
        // Redis Sadd 命令将一个或多个成员元素加入到集合中，已经存在于集合的成员元素将被忽略。
        $this->redis->sadd($totalList, $totalChunkKey);
        // 2. 判断传递的每片分片是否正确（通过分片文件大小 size 来判断, 主要判断 h5 中 slice 切割是否有误）
        $res = $this->checkChunk();
        if (!$res)
        {
            // data 是分片号
            exit(json_encode(['code' => 400, 'msg' => '分片需要重新上传', 'data' => $_POST['index']]));
        }
        // 3. 存储分片
        $res = $this->saveChunk($totalChunkKey);
        if ($res['code'] != 1)
        {
            exit(json_encode($res));
        }
        // 4. 重组文件
        // 全部分片都上传完成才会重组分片
        $this->reMakeFile($totalChunkKey);
        // 5. 移除 redis 中数据，分片文件全部上传完成后才会执行，否则不执行，因为未全部上传完成，reMakeFile（） 中会 exit（）
        $this->clearRedis($totalKey, $totalList);
        exit(json_encode(['code'=> 1, 'msg' => '上传完成']));


    }
    // 判断分片文件大小是否等于当前文件大小
    private function checkChunk()
    {
        if ($_POST['totalChunk'] == $_POST['index'])
        {
            // 如果是最后一片 $_POST['totalSize'] 和 $_POST['chunkSize'] 类型是字符串，但是不影响计算
            $mode = $_POST['totalSize'] % $_POST['chunkSize'];  // 取余不是商, 取余的是每个分片的大小 chunkSize，而不是 totalChunk 总分片数
//            $mode = $_POST['totalSize'] % $_POST['totalChunk'];  // 取余不是商, 取余的是每个分片的大小 chunkSize，而不是 totalChunk 总分片数
//            file_put_contents('./log.php', var_export([55555, $_POST['totalChunk'], $_POST['index'], $mode, $_FILES['data']['size'], $_POST['totalSize']], true), FILE_APPEND);

            if ($mode == $_FILES['data']['size'] || $mode == 0)  //$mode == 0 不可忘记
            {
                return true;
            } else {
                return false;
            }
        } else {
            if ($_POST['chunkSize'] == $_FILES['data']['size'])
            {
                return true;
            } else {
                return false;
            }
        }
    }


    // 存储分片，若文件上传一部分后刷新页面再次重新上传，文件还会重新再上传一次而不是在断点后接着上传，
    //因为前端分片还是从 0 开始，后端 sadd 存储时已经存在的元素将被忽略，
    //move_uploaded_file（）将会重新移动，如果目标文件已经存在，将会被覆盖。
    private function saveChunk($totalChunkKey)
    {
        $dest = implode('_', explode(':', $totalChunkKey));
        // 若 ./upload 目录不存在新建该目录
        (! is_dir($this->uploadDir)) && mkdir($this->uploadDir, 757, true);
        $destFile = $this->uploadDir . '/' . $dest . '_' . $_POST['index'];  // 虽然上传文件时请求的是upload() 方法，并且未给该函数传递$_POST['index'] 参数，但是因为upload 方法中有调用该方法，所以该方法中可以通过 $_POST['index'] 接收相应的参数

        $res = move_uploaded_file($_FILES['data']['tmp_name'], $destFile);
        if (!$res)
        {
            return ['code' => 400,
                    'msg' => '文件移动失败',
                    'data' => $_POST['index']
                ];
        }
        $this->redis->sadd($totalChunkKey, $_POST['index']);
        return ['code' => 1];  // 用该状态码判断当前分片是否上传成功，不成功不执行下面重组文件

    }
    // 重组文件
    private function reMakeFile($totalChunkKey)
    {
        // 返回 redis 集合存储的 key 的基数(集合中存储的元素的个数)
        $length = $this->redis->scard($totalChunkKey);  // 确定分片文件移动成功后才会将分片标示 index 存入 redis 集合，因此用redis 集合中的 元素个数来和总分片数比较
        file_put_contents('./log.php', var_export([$length, $_POST['totalChunk']], true), FILE_APPEND);
        if ($length != $_POST['totalChunk'])  //$length !== $_POST['totalChunk'] 此处用严格不等于会出错，因$_POST['totalChunk']为字符串，$length 为整型
        {
            exit(json_encode(['code' => 200, 'msg' => 'index ' . $_POST['index'] . ' length ' . $length . ', totalChunk ' . $_POST['totalChunk'] . ' 分片上传完成']));
        }
        // 使用 try{} catch(){} 避免读取写入文件出错
        try{
            // 该文件以只写的方式打开
            $source = fopen($this->uploadDir . '/' . $_POST['fileName'], 'w+b');
            // 循环将分片文件依次写入目标文件
            for ($i = 0; $i < $length; $i++)
            {
                // 拼接分片文件名, 连带目录 $this->uploadDir . '/' 都要拼接，否则报错  failed to open stream: No such file or directory
                $openFileName = $this->uploadDir . '/' . implode('_', explode(':', $totalChunkKey)) . '_' . ($i + 1);
                $readSource = fopen($openFileName, 'r+b');
                // 每次读取 1M 写入目标文件
                while ($content = fread($readSource, 1024))
                {
                    fwrite($source, $content);
                }
                // 关闭已经读取写入目标文件的分片文件
                fclose($readSource);
                unlink($openFileName);  // 删除已经读取写入目标文件的分片文件
            }
            // 当全部分片都写入目标文件后关闭目标文件
            fclose($source);
        }
        catch(Exception $e){
            exit($e->getMessage());
        }
        return ['code' => 1];
    }

    // 清除redis 中不需要的数据
    private function  clearRedis($totalKey, $totalList)
    {
        // 设置为 1 表示文件已经上传过而且是完全上传完成
        $this->redis->set($totalKey, 1);
        // 返回key 集合中的所有元素
        $list = $this->redis->sMembers($totalList);
        foreach($list as $k => $v)
        {
            // $v 为键为 $totalList 的值 $totalChunkKey， 删除集合 $totalChunkKey 中的所有元素
            $this->redis->delete($v);
        }
        $this->redis->delete($totalList);   // 删除 $totalList 集合中的所有元素
        // 因此文件正常上传完成之后，redis 中仅存储 键为 $totalKey 的值。
    }
}

(new upload())->upload();