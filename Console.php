<?php
/**
 * Created by PhpStorm.
 * User: 乌鸦
 * Date: 2016/4/3
 * Time: 17:05
 */

namespace xiaofeng\cli;
error_reporting(E_ALL);

class Console
{
    const OUT_PRINT             = 1;
    const OUT_PRINT_R           = 2;
    const OUT_VAR_DUMP          = 3;
    const OUT_DEBUG_ZVAL_DUMP   = 4;

    private $enablecolor;
    private $outputtype;

    public function __construct($enablecolor =  true, $outtype = 2) {
        $this->enablecolor = $enablecolor;
        $this->outputtype = $outtype;
    }

    private function stringfy($data) {
        ob_start();
        switch($this->outputtype) {
            case self::OUT_PRINT;
                print $data;
                break;
            case self::OUT_PRINT_R;
                print_r($data);
                break;
            case self::OUT_VAR_DUMP;
                var_dump($data);
                break;
            case self::OUT_DEBUG_ZVAL_DUMP;
                debug_zval_dump($data);
                break;
            default;
                print_r($data);
                break;
        }
        return ob_get_clean();
    }

    private function _log($data, $color, $ret = false) {
        if($this->enablecolor) {
            if($ret) {
                return chr(27) . "$color" . $this->stringfy($data) . chr(27) . "[0m";
            }
            echo chr(27) . "$color" . $this->stringfy($data) . chr(27) . "[0m";
        } else {
            if($ret) {
                return $this->stringfy($data);
            }
            echo $this->stringfy($data);
        }
        return null;
    }

    public function log($data, $ret = false) {
        return $this->_log($data, "[42m", $ret); // 绿色
    }

    public function info($data, $ret = false) {
        return $this->_log($data, "[44m", $ret); // 蓝色
    }

    public function warn($data, $ret = false) {
        return $this->_log($data, "[43m", $ret); // 黄色
    }

    public function error($data, $ret = false) {
        return $this->_log($data, "[41m", $ret); // 红色
    }
}