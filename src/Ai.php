<?php
namespace usualtool\Lib;
/**
 * AI对话
 * 必要依赖：composer require usualtool/ut-ai
 */
class Ai{
    public static $tty="UTF-8";
    public static $pending="";
    public static function EnvFile(){
        $rootfile=UTF_ROOT."/.ut-ai.env";
        if(file_exists($rootfile)){
            return $rootfile;
        }
        return UTF_ROOT."/vendor/usualtool/ut-ai/src/.env";
    }
    /**
     * 写入配置
     * @param string $file
     * @param string $key
     * @param string $value
     * @param string $after
     * @return bool
     */
    public static function EnvSet($file,$key,$value,$after=""){
        $content=file_exists($file)?file_get_contents($file):"";
        $eol=strpos($content,"\r\n")!==false?"\r\n":"\n";
        $lines=preg_split('/\r\n|\n/',$content);
        $pattern='/^\s*'.preg_quote($key,'/').'\s*=/';
        $nowline=-1;
        $anchor=-1;
        foreach($lines as $i=>$line){
            if(preg_match($pattern,$line)){
                $nowline=$i;
            }elseif($after!=="" && preg_match('/^\s*'.preg_quote($after,'/').'\s*=/',$line)){
                $anchor=$i;
            }
        }
        if($after!=="" && $anchor>=0){
            if($nowline===$anchor+1){
                $lines[$nowline]=$key."=".$value;
                return file_put_contents($file,implode($eol,$lines))!==false;
            }
            if($nowline>=0){
                if($nowline>0 && preg_match('/^\s*#.*'.preg_quote($key,'/').'/',$lines[$nowline-1])){
                    array_splice($lines,$nowline-1,2);
                    if($anchor>$nowline){
                        $anchor-=2;
                    }
                }else{
                    array_splice($lines,$nowline,1);
                    if($anchor>$nowline){
                        $anchor--;
                    }
                }
            }
            array_splice($lines,$anchor+1,0,$key."=".$value);
            return file_put_contents($file,implode($eol,$lines))!==false;
        }
        if($nowline>=0){
            $lines[$nowline]=$key."=".$value;
            return file_put_contents($file,implode($eol,$lines))!==false;
        }
        while(count($lines)>0 && trim($lines[count($lines)-1])===""){
            array_pop($lines);
        }
        $lines[]=$key."=".$value;
        $lines[]="";
        return file_put_contents($file,implode($eol,$lines))!==false;
    }
    /**
     * 将模型加入白名单
     * @param string $file
     * @param string $model
     * @return bool
     */
    public static function EnvAllow($file,$model){
        $config=self::Config();
        $models=$config["allowed_models"];
        if(in_array($model,$models)){
            return true;
        }
        $models[]=$model;
        return self::EnvSet($file,"ALLOWED_MODELS",implode(",",array_values(array_unique($models))));
    }
    /**
     * 获取UT-AI配置
     * @return array
     */
    public static function EnvAll(){
        $env=array();
        $files=array(UTF_ROOT."/vendor/usualtool/ut-ai/src/.env",UTF_ROOT."/.ut-ai.env");
        foreach($files as $file){
            if(!file_exists($file)){
                continue;
            }
            $lines=file($file,FILE_IGNORE_NEW_LINES|FILE_SKIP_EMPTY_LINES);
            foreach($lines as $line){
                $line=trim($line);
                if($line==="" || strpos($line,"#")===0 || strpos($line,"=")===false){
                    continue;
                }
                $pair=explode("=",$line,2);
                $key=trim($pair[0]);
                $val=trim($pair[1]);
                if($key==="" || $val===""){
                    continue;
                }
                $env[$key]=$val;
            }
        }
        return $env;
    }
    /**
     * 校验UT-AI配置
     * @param array $env
     * @return array 缺失的配置项名称
     */
    public static function EnvMissing($env){
        $need=array("UPSTREAM_BASE_URL","UPSTREAM_API_KEY","INDEX_MODEL","ALLOWED_MODELS");
        if(!empty($env["ENABLE_THINKING"])){
            $need=array_merge($need,array("KNOWLEDGE_BASE_URL","KNOWLEDGE_API_KEY","KNOWLEDGE_BASE_IDS","KNOWLEDGE_MODEL"));
        }
        $missing=array();
        foreach($need as $key){
            if(!isset($env[$key]) || trim($env[$key])===""){
                $missing[]=$key;
            }
        }
        return $missing;
    }
    /**
     * 输出UT-AI配置
     * @return array
     */
    public static function Config(){
        $env=self::EnvAll();
        $models=isset($env["ALLOWED_MODELS"])?array_values(array_filter(array_map("trim",explode(",",$env["ALLOWED_MODELS"])),'strlen')):array();
        if(empty($models)){
            $models=array("glm-4-flash","deepseek-chat","qwen-turbo");
        }
        return array(
            "upstream_base_url"=>$env["UPSTREAM_BASE_URL"]??"",
            "upstream_api_key"=>$env["UPSTREAM_API_KEY"]??"",
            "my_api_key"=>$env["MY_API_KEY"]??"",
            "allowed_models"=>$models,
            "index_model"=>$env["INDEX_MODEL"]??"",
            "enable_thinking"=>!empty($env["ENABLE_THINKING"]),
            "knowledge_enabled"=>!empty($env["KNOWLEDGE_ENABLED"]),
            "knowledge_base_url"=>$env["KNOWLEDGE_BASE_URL"]??"",
            "knowledge_api_key"=>$env["KNOWLEDGE_API_KEY"]??"",
            "knowledge_base_ids"=>isset($env["KNOWLEDGE_BASE_IDS"])?array_values(array_filter(array_map("trim",explode(",",$env["KNOWLEDGE_BASE_IDS"])),'strlen')):array(),
            "knowledge_top_k"=>(int)($env["KNOWLEDGE_TOP_K"]??8),
            "knowledge_top_n"=>(int)($env["KNOWLEDGE_TOP_N"]??5),
            "knowledge_enable_rerank"=>!empty($env["KNOWLEDGE_ENABLE_RERANK"]),
            "knowledge_model"=>$env["KNOWLEDGE_MODEL"]??"",
            "knowledge_enable_thinking"=>!empty($env["KNOWLEDGE_ENABLE_THINKING"])
        );
    }
    /**
     * 流式对话
     * @param array $messages
     * @param string $model
     * @param callable $callback
     * @return void
     */
    public static function Stream($messages,$model,$callback){
        $config=self::Config();
        $useKnowledge=$config["knowledge_enabled"] && $config["knowledge_base_url"]!=="" && !empty($config["knowledge_base_ids"]);
        if($useKnowledge){
            $model=$config["knowledge_model"]!==""?$config["knowledge_model"]:$model;
            $url=rtrim($config["knowledge_base_url"],"/")."/chat";
            $apikey=$config["knowledge_api_key"]!==""?$config["knowledge_api_key"]:$config["upstream_api_key"];
            $body=array(
                "model"=>$model,
                "messages"=>array_values($messages),
                "stream"=>true,
                "enable_thinking"=>$config["knowledge_enable_thinking"],
                "retrieval"=>array(
                    "know_ids"=>$config["knowledge_base_ids"],
                    "top_k"=>$config["knowledge_top_k"],
                    "top_n"=>$config["knowledge_top_n"],
                    "enable_rerank"=>$config["knowledge_enable_rerank"]
                )
            );
        }else{
            $url=rtrim($config["upstream_base_url"],"/")."/chat/completions";
            $apikey=$config["upstream_api_key"];
            $body=array(
                "model"=>$model,
                "messages"=>array_values($messages),
                "stream"=>true,
                "thinking"=>array("type"=>$config["enable_thinking"]?"enabled":"disabled")
            );
        }
        if($url==="" || $apikey===""){
            $callback("error","上游地址或密钥为空，请检查配置",array());
            $callback("done","",array());
            return;
        }
        $payload=json_encode($body,JSON_UNESCAPED_UNICODE);
        if($payload===false){
            $callback("error","请求体编码失败（".json_last_error_msg()."），跳过本次请求以免上游收到空body",array());
            $callback("done","",array());
            return;
        }
        $buffer="";
        $plain="";
        $usage=array();
        $ch=curl_init($url);
        curl_setopt_array($ch,array(
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$payload,
            CURLOPT_HTTPHEADER=>array(
                "Content-Type: application/json",
                "Accept: text/event-stream",
                "Authorization: Bearer ".$apikey
            ),
            CURLOPT_TIMEOUT=>300,
            CURLOPT_WRITEFUNCTION=>function($ch,$chunk) use (&$buffer,&$plain,&$usage,$callback){
                if(strlen($plain)<2000){
                    $plain.=$chunk;
                }
                $buffer.=$chunk;
                while(($pos=strpos($buffer,"\n"))!==false){
                    $line=rtrim(substr($buffer,0,$pos),"\r");
                    $buffer=substr($buffer,$pos+1);
                    $line=trim($line);
                    if($line==="" || strpos($line,"data:")!==0){
                        continue;
                    }
                    $raw=trim(substr($line,5));
                    if($raw==="" || $raw==="[DONE]"){
                        continue;
                    }
                    $event=json_decode($raw,true);
                    if(!is_array($event)){
                        continue;
                    }
                    if(isset($event["error"])){
                        $msg=is_array($event["error"])?(isset($event["error"]["message"])?$event["error"]["message"]:$raw):$raw;
                        $callback("error",$msg,array());
                        continue;
                    }
                    if(isset($event["usage"])){
                        $usage=$event["usage"];
                    }
                    $delta=isset($event["choices"][0]["delta"])?$event["choices"][0]["delta"]:array();
                    if(isset($delta["reasoning_content"]) && $delta["reasoning_content"]!==""){
                        $callback("reasoning",$delta["reasoning_content"],array());
                    }
                    if(isset($delta["content"]) && $delta["content"]!==""){
                        $callback("content",$delta["content"],array());
                    }
                }
                return strlen($chunk);
            }
        ));
        curl_exec($ch);
        $error=curl_error($ch);
        $httpcode=(int)curl_getinfo($ch,CURLINFO_HTTP_CODE);
        curl_close($ch);
        $trimmed=ltrim($plain);
        if($error!==""){
            $callback("error","上游请求失败：".$error,array());
        }elseif(trim($plain)!=="" && strpos($trimmed,"data:")!==0){
            $callback("error","上游返回 HTTP ".$httpcode."：".trim($plain),array());
        }
        $callback("done","",$usage);
    }
    /**
     * 清洗
     * @param string $line
     * @return string
     */
    public static function Clean($line){
        $clean=preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{2060}\x{FEFF}]/u','',$line);
        if($clean!==null){
            $line=$clean;
        }
        $line=str_replace(array("／","＼","＃"),array("/","\\","#"),$line);
        return trim($line);
    }
    /**
     * 终端编码
     * @return string
     */
    public static function TtyEncoding(){
        $force=strtolower(trim((string)getenv("UT_AI_ENCODING")));
        if($force==="utf8" || $force==="utf-8"){
            return "UTF-8";
        }
        if($force==="gbk" || $force==="gb2312" || $force==="cp936"){
            return "GBK";
        }
        if(stripos(PHP_OS,"WIN")===0){
            $lang=(string)getenv("LC_ALL");
            if($lang===""){
                $lang=(string)getenv("LC_CTYPE");
            }
            if($lang===""){
                $lang=(string)getenv("LANG");
            }
            return ($lang!=="" && stripos($lang,"utf")!==false)?"UTF-8":"GBK";
        }
        return "UTF-8";
    }
    /**
     * 安全转码字节长度
     * @param string $s
     * @return int
     */
    public static function SafeLen($s){
        $len=strlen($s);
        for($i=1;$i<=4 && $i<=$len;$i++){
            $b=ord($s[$len-$i]);
            if(($b & 0xC0)===0x80){
                continue;
            }
            $need=$b<0x80?1:(($b<0xE0)?2:(($b<0xF0)?3:4));
            return $need>$i?($len-$i):$len;
        }
        return $len;
    }
    /**
     * 终端编码适配
     * @return array
     */
    public static function Tty(){
        self::$tty=self::TtyEncoding();
        self::$pending="";
        $fix=function($text){
            if($text==="" || !function_exists("mb_check_encoding") || mb_check_encoding($text,"UTF-8")){
                return $text;
            }
            if(function_exists("mb_convert_encoding")){
                return mb_convert_encoding($text,"UTF-8","GBK");
            }
            if(function_exists("iconv")){
                return @iconv("GBK","UTF-8//IGNORE",$text);
            }
            return $text;
        };
        $echo=function($text){
            if(self::$tty==="UTF-8"){
                echo $text;
            }else{
                $text=self::$pending.$text;
                self::$pending="";
                $safe=self::SafeLen($text);
                if($safe<strlen($text)){
                    self::$pending=substr($text,$safe);
                    $text=substr($text,0,$safe);
                }
                if($text!==""){
                    echo function_exists("mb_convert_encoding")?mb_convert_encoding($text,"GBK","UTF-8"):$text;
                }
            };
            if(ob_get_level()>0){
                @ob_flush();
            }
            @flush();
        };
        return array($fix,$echo);
    }
    /**
     * 内置命令
     * @param string $line 用户输入原文
     * @param array $config 配置数组
     * @param array $messages 上下文（/new 时清空，按引用修改）
     * @param string $model 当前模型（/model 时切换，按引用修改）
     * @param array $tokens token统计（/new 时重置，按引用修改）
     * @param callable $echo 输出函数
     * @return bool
     */
    public static function Command($line,$config,&$messages,&$model,&$tokens,$echo){
        $lower=strtolower($line);
        if($lower==="/help"){
            $echo("/new 清空上下文\r\n  /model [名称] 查看或临时切换模型\r\n  /enc [编码] 查看或切换输出编码\r\n  /usage 查看token用量\r\n  /exit 退出\r\n");
            $echo("永久更换默认模型：退出后执行 php usualtool ai 模型名\r\n");
            return true;
        }
        if($lower==="/enc" || strpos($lower,"/enc ")===0){
            $arg=strtolower(trim(substr($line,4)));
            if($arg===""){
                $echo("输出编码：".self::$tty."\r\n");
                $echo("中文乱码时：/enc utf8 或 /enc gbk 切换，/enc auto 按系统重新判定\r\n");
            }elseif(in_array($arg,array("utf8","utf-8","u"))){
                self::$tty="UTF-8";
                $echo("输出编码已设为 UTF-8\r\n");
            }elseif(in_array($arg,array("gbk","gb2312","cp936","g"))){
                self::$tty="GBK";
                $echo("输出编码已设为 GBK\r\n");
            }elseif($arg==="auto"){
                self::$tty=self::TtyEncoding();
                $echo("输出编码：重新判定 -> ".self::$tty."\r\n");
            }else{
                $echo("用法：/enc 查看，/enc utf8，/enc gbk，/enc auto\r\n");
            }
            return true;
        }
        if($lower==="/new"){
            $messages=array();
            $tokens=array("prompt"=>0,"completion"=>0);
            $echo("上下文已清空\r\n");
            return true;
        }
        if($lower==="/usage"){
            $echo("累计tokens：输入".$tokens["prompt"]."，输出".$tokens["completion"]."，上下文消息数".count($messages)."\r\n");
            return true;
        }
        if($lower==="/model" || strpos($lower,"/model ")===0){
            $target=trim(substr($line,6));
            if($target===""){
                $echo("当前模型：".$model."\r\n");
                $echo("默认模型(INDEX_MODEL)：".($config["index_model"]!==""?$config["index_model"]:"未设置")."\r\n");
                $echo("白名单：".implode(", ",$config["allowed_models"])."\r\n");
            }elseif(in_array($target,$config["allowed_models"])){
                $model=$target;
                $echo("已切换模型：".$model."（仅本次会话）\r\n");
            }else{
                $echo("不支持的模型：".$target."\r\n白名单：".implode(", ",$config["allowed_models"])."\r\n");
                $echo("如需新增，退出后执行 php usualtool ai ".$target."（会自动加入ALLOWED_MODELS）\r\n");
            }
            return true;
        }
        return false;
    }
    /**
     * 一轮流式问答
     * @param array $messages 上下文（含本次提问）
     * @param string $model
     * @param array $tokens token统计（按引用累加）
     * @param callable $echo 输出函数
     * @return string 助手回复
     */
    public static function Ask($messages,$model,&$tokens,$echo){
        $reply="";
        $failed=false;
        $thinking=false;
        $echo("AI> ");
        self::Stream($messages,$model,function($type,$text,$usage) use (&$reply,&$failed,&$thinking,&$tokens,$echo){
            if($type==="content"){
                if($thinking){
                    $echo("\033[0m\r\n");
                    $thinking=false;
                }
                $echo($text);
                $reply.=$text;
            }elseif($type==="reasoning"){
                if(!$thinking){
                    $echo("\033[90m[思考] ");
                    $thinking=true;
                }
                $echo($text);
            }elseif($type==="error"){
                if($thinking){
                    $echo("\033[0m");
                    $thinking=false;
                }
                $failed=true;
                $echo($text);
            }elseif($type==="done"){
                if(is_array($usage) && !empty($usage)){
                    $tokens["prompt"]+=(int)($usage["prompt_tokens"]??0);
                    $tokens["completion"]+=(int)($usage["completion_tokens"]??0);
                }
            }
        });
        if($thinking){
            $echo("\033[0m");
        }
        self::FlushTty();
        $echo("\r\n");
        return ($reply!=="" && !$failed)?$reply:"";
    }
    /**
     * 刷出输出转码残留的尾字节
     * @return void
     */
    public static function FlushTty(){
        if(self::$pending===""){
            return;
        }
        $text=self::$pending;
        self::$pending="";
        if(self::$tty==="UTF-8"){
            echo $text;
        }else{
            echo function_exists("mb_convert_encoding")?mb_convert_encoding($text,"GBK","UTF-8"):$text;
        }
    }
}