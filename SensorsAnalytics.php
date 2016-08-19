<?php

define('SENSORS_ANALYTICS_SDK_VERSION', '1.5.0');

class SensorsAnalyticsException extends \Exception {
}

// 在发送的数据格式有误时，SDK会抛出此异常，用户应当捕获并处理。
class SensorsAnalyticsIllegalDataException extends SensorsAnalyticsException {
}

// 在因为网络或者不可预知的问题导致数据无法发送时，SDK会抛出此异常，用户应当捕获并处理。
class SensorsAnalyticsNetworkException extends SensorsAnalyticsException {
}

// 当且仅当DEBUG模式中，任何网络错误、数据异常等都会抛出此异常，用户可不捕获，用于测试SDK接入正确性
class SensorsAnalyticsDebugException extends \Exception {
}

// 不支持 Windows，因为 Windows 版本的 PHP 都不支持 long
if (strtoupper(substr(PHP_OS, 0, 3)) == "WIN") {
    throw new SensorsAnalyticsException("Sensors Analytics PHP SDK dons't not support Windows");
}

class SensorsAnalytics {

    private $_consumer;
    private $_super_properties;

    /**
     * 初始化一个 SensorsAnalytics 的实例用于数据发送。
     *
     * @param AbstractConsumer $consumer
     */
    public function __construct($consumer) {
        $this->_consumer = $consumer;
        $this->clear_super_properties();
    }
 
    private function _normalize_data($data) {
        // 检查 distinct_id
        if (!isset($data['distinct_id']) or strlen($data['distinct_id']) == 0) {
            throw new SensorsAnalyticsIllegalDataException("property [distinct_id] must not be empty");
        }
        if (strlen($data['distinct_id']) > 255) {
            throw new SensorsAnalyticsIllegalDataException("the max length of [distinct_id] is 255");
        }
        $data['distinct_id'] = strval($data['distinct_id']);

        // 检查 time
        $ts = (int)($data['time']);
        $ts_num = strlen($ts);
        if ($ts_num < 10 || $ts_num > 13) {
            throw new SensorsAnalyticsIllegalDataException("property [time] must be a timestamp in microseconds");
        }

        if ($ts_num == 10) {
            $ts *= 1000;
        }
        $data['time'] = $ts;

        $name_pattern = "/^((?!^distinct_id$|^original_id$|^time$|^properties$|^id$|^first_id$|^second_id$|^users$|^events$|^event$|^user_id$|^date$|^datetime$)[a-zA-Z_$][a-zA-Z\\d_$]{0,99})$/i";
        // 检查 Event Name
        if (isset($data['event']) && !preg_match($name_pattern, $data['event'])) {
            throw new SensorsAnalyticsIllegalDataException("event name must be a valid variable name. [name='${data['event']}']");
        }

        // 检查 properties
        if (isset($data['properties']) && is_array($data['properties'])) {
            foreach ($data['properties'] as $key => $value) {
                if (!is_string($key)) {
                    throw new SensorsAnalyticsIllegalDataException("property key must be a str. [key=$key]");
                }
                if (strlen($data['distinct_id']) > 255) {
                    throw new SensorsAnalyticsIllegalDataException("the max length of property key is 256. [key=$key]");
                }

                if (!preg_match($name_pattern, $key)) {
                    throw new SensorsAnalyticsIllegalDataException("property key must be a valid variable name. [key='$key']]");
                }

                // 只支持简单类型或数组或DateTime类
                if (!is_scalar($value) && !is_array($value) && !$value instanceof DateTime) {
                    throw new SensorsAnalyticsIllegalDataException("property value must be a str/int/float/datetime/list. [key='$key' value='$value']");
                }

                // 如果是 DateTime，Format 成字符串
                if ($value instanceof DateTime) {
                    $data['properties'][$key] = $value->format("Y-m-d H:i:s.0");
                }

                if (is_string($value) && strlen($data['distinct_id']) > 8191) {
                    throw new SensorsAnalyticsIllegalDataException("the max length of property value is 8191. [key=$key]");
                }

                // 如果是数组，只支持 Value 是字符串格式的简单非关联数组
                if (is_array($value)) {
                    if (array_values($value) !== $value) {
                        throw new SensorsAnalyticsIllegalDataException("[list] property must not be associative. [key='$key']");
                    }

                    foreach ($value as $lvalue) {
                        if (!is_string($lvalue)) {
                            throw new SensorsAnalyticsIllegalDataException("[list] property's value must be a str. [value='$lvalue']");
                        }
                    }
                }
            }
            // XXX: 解决 PHP 中空 array() 转换成 JSON [] 的问题
            if (count($data['properties']) == 0) {
                $data['properties'] = new \ArrayObject();
            }
        } else {
            throw new SensorsAnalyticsIllegalDataException("property must be an array.");
        }
        return $data;
    }

    /**
     * 如果用户传入了 $time 字段，则不使用当前时间。
     *
     * @param array $properties
     * @return int
     */
    private function _extract_user_time(&$properties = array()) {
        if (array_key_exists('$time', $properties)) {
            $time = $properties['$time'];
            unset($properties['$time']);
            return $time;
        }
        return (int)(microtime(true) * 1000);
    }

    /**
     * 返回埋点管理相关属性，由于该函数依赖函数栈信息，因此修改调用关系时，一定要谨慎
     */
    private function _get_lib_properties() {
        $lib_properties = array(
                '$lib' => 'php',
                '$lib_version' => SENSORS_ANALYTICS_SDK_VERSION,
                '$lib_method' => 'code',
                );
        
        if (isset($this->_super_properties['$app_version'])) {
            $lib_properties['$app_version'] = $this->_super_properties['$app_version']; 
        }
        
        try {
            throw new \Exception("");
        } catch (\Exception $e) {
            $trace = $e->getTrace();
            if (count($trace) == 3) {
                // 脚本内直接调用
                $file = $trace[2]['file'];
                $line = $trace[2]['line'];
                
                $lib_properties['$lib_detail'] = "####$file##$line";
            } else if (count($trace > 3)) {
                if (isset($trace[3]['class'])) {
                    // 类成员函数内调用
                    $class = $trace[3]['class'];
                } else {
                    // 全局函数内调用
                    $class = '';
                }
                
                // XXX: 此处使用 [2] 非笔误，trace 信息就是如此
                $file = $trace[2]['file'];
                $line = $trace[2]['line'];
                $function = $trace[3]['function'];

                $lib_properties['$lib_detail'] = "$class##$function##$file##$line";
            }
        }
        
        return $lib_properties; 
    }

    /**
     * 序列化 JSON
     *
     * @param $data
     * @return string
     */
    private function _json_dumps($data) {
        return json_encode($data);
    }

    /**
     * 跟踪一个用户的行为。
     *
     * @param string $distinct_id 用户的唯一标识。
     * @param string $event_name 事件名称。
     * @param array $properties 事件的属性。
     */
    public function track($distinct_id, $event_name, $properties = array()) {
        if ($properties) {
            $all_properties = array_merge($this->_super_properties, $properties);
        } else {
            $all_properties = array_merge($this->_super_properties, array());
        }
        return $this->_track_event('track', $event_name, $distinct_id, null, $all_properties);
    }

    /**
     * 这个接口是一个较为复杂的功能，请在使用前先阅读相关说明:http://www.sensorsdata.cn/manual/track_signup.html，并在必要时联系我们的技术支持人员。
     *
     * @param string $distinct_id 用户注册之后的唯一标识。
     * @param string $original_id 用户注册前的唯一标识。
     * @param array $properties 事件的属性。
     */
    public function track_signup($distinct_id, $original_id, $properties = array()) {
        if ($properties) {
            $all_properties = array_merge($this->_super_properties, $properties);
        } else {
            $all_properties = array_merge($this->_super_properties, array());
        }
        // 检查 original_id
        if (!$original_id or strlen($original_id) == 0) {
            throw new SensorsAnalyticsIllegalDataException("property [original_id] must not be empty");
        }
        if (strlen($original_id) > 255) {
            throw new SensorsAnalyticsIllegalDataException("the max length of [original_id] is 255");
        }
        return $this->_track_event('track_signup', '$SignUp', $distinct_id, $original_id, $all_properties);
    }

    /**
     * 直接设置一个用户的 Profile，如果已存在则覆盖。
     *
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function profile_set($distinct_id, $profiles = array()) {
        return $this->_track_event('profile_set', null, $distinct_id, null, $profiles);
    }

    /**
     * 直接设置一个用户的 Profile，如果某个 Profile 已存在则不设置。
     *
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function profile_set_once($distinct_id, $profiles = array()) {
        return $this->_track_event('profile_set_once', null, $distinct_id, null, $profiles);
    }
    
    /**
     * 增减/减少一个用户的某一个或者多个数值类型的 Profile。
     *
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function profile_increment($distinct_id, $profiles = array()) {
        return $this->_track_event('profile_increment', null, $distinct_id, null, $profiles);
    }

    /**
     * 追加一个用户的某一个或者多个集合类型的 Profile。
     *
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function profile_append($distinct_id, $profiles = array()) {
        return $this->_track_event('profile_append', null, $distinct_id, null, $profiles);
    }

    /**
     * 删除一个用户的一个或者多个 Profile。
     *
     * @param string $distinct_id
     * @param array $profile_keys
     * @return boolean
     */
    public function profile_unset($distinct_id, $profile_keys = array()) {
        if ($profile_keys != null && array_key_exists(0, $profile_keys)) {
            $new_profile_keys = array();
            foreach ($profile_keys as $key) {
                $new_profile_keys[$key] = true;
            }
            $profile_keys = $new_profile_keys;
        }
        return $this->_track_event('profile_unset', null, $distinct_id, null, $profile_keys);
    }


    /**
     * 删除整个用户的信息。
     *
     * @param $distinct_id
     * @return boolean
     */
    public function profile_delete($distinct_id) {
        return $this->_track_event('profile_delete', null, $distinct_id, null, array());
    }

    /**
     * 设置每个事件都带有的一些公共属性
     *
     * @param super_properties 
     */
    public function register_super_properties($super_properties) {
        $this->_super_properties = array_merge($this->_super_properties, $super_properties);
    }

    /**
     * 删除所有已设置的事件公共属性
     */
    public function clear_super_properties() {
        $this->_super_properties = array(
                '$lib' => 'php',
                '$lib_version' => SENSORS_ANALYTICS_SDK_VERSION,
                );
    }

    /**
     * 对于不立即发送数据的 Consumer，调用此接口应当立即进行已有数据的发送。
     *
     */
    public function flush() {
        $this->_consumer->flush();
    }

    /**
     * 在进程结束或者数据发送完成时，应当调用此接口，以保证所有数据被发送完毕。
     * 如果发生意外，此方法将抛出异常。
     */
    public function close() {
        $this->_consumer->close();
    }

    /**
     * @param string $update_type
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function _track_event($update_type, $event_name, $distinct_id, $original_id, $properties) {
        $event_time = $this->_extract_user_time($properties);

        $data = array(
            'type' => $update_type,
            'properties' => $properties,
            'time' => $event_time,
            'distinct_id' => $distinct_id,
            'lib' => $this->_get_lib_properties(),
        );

        if (strcmp($update_type, "track") == 0) {
            $data['event'] = $event_name;
        } else if (strcmp($update_type, "track_signup") == 0) {
            $data['event'] = $event_name;
            $data['original_id'] = $original_id;
        }

        $data = $this->_normalize_data($data);
        return $this->_consumer->send($this->_json_dumps($data));
    }

}


abstract class AbstractConsumer {

    /**
     * 发送一条消息。
     *
     * @param string $msg 发送的消息体
     * @return boolean
     */
    public abstract function send($msg);

    /**
     * 立即发送所有未发出的数据。
     *
     * @return boolean
     */
    public function flush() {
    }

    /**
     * 关闭 Consumer 并释放资源。
     *
     * @return boolean
     */
    public function  close() {
    }
}


class FileConsumer extends AbstractConsumer {

    private $file_handler;

    public function __construct($filename) {
        $this->file_handler = fopen($filename, 'a+');
    }

    public function send($msg) {
        if ($this->file_handler === null) {
            return false;
        }
        return fwrite($this->file_handler, $msg . "\n") === false ? false : true;
    }

    public function close() {
        if ($this->file_handler === null) {
            return false;
        }
        return fclose($this->file_handler);
    }
}

class DebugConsumer extends AbstractConsumer {

    private $_debug_url_prefix;
    private $_request_timeout;
    private $_debug_write_data;

    /**
     * DebugConsumer constructor,用于调试模式.
     * 具体说明可以参照:http://www.sensorsdata.cn/manual/debug_mode.html
     * 
     * @param string $url_prefix 服务器的URL地址
     * @param bool $write_data 是否把发送的数据真正写入
     * @param int $request_timeout 请求服务器的超时时间,单位毫秒.
     * @throws SensorsAnalyticsDebugException
     */
    public function __construct($url_prefix, $write_data = True, $request_timeout = 1000) {
        $parsed_url = parse_url($url_prefix);
        if ($parsed_url === false) {
            throw new SensorsAnalyticsDebugException("Invalid server url of Sensors Analytics.");
        }

        // 将 URI Path 替换成 Debug 模式的 '/debug'
        $parsed_url['path'] = '/debug';

        $this->_debug_url_prefix = ((isset($parsed_url['scheme'])) ? $parsed_url['scheme'] . '://' : '')
            .((isset($parsed_url['user'])) ? $parsed_url['user'] . ((isset($parsed_url['pass'])) ? ':' . $parsed_url['pass'] : '') .'@' : '')
            .((isset($parsed_url['host'])) ? $parsed_url['host'] : '')
            .((isset($parsed_url['port'])) ? ':' . $parsed_url['port'] : '')
            .((isset($parsed_url['path'])) ? $parsed_url['path'] : '')
            .((isset($parsed_url['query'])) ? '?' . $parsed_url['query'] : '')
            .((isset($parsed_url['fragment'])) ? '#' . $parsed_url['fragment'] : '')
            ;

        $this->_request_timeout = $request_timeout;
        $this->_debug_write_data = $write_data;

    }

    public function send($msg) {
        $buffers = array();
        $buffers[] = $msg;
        $response = $this->_do_request(array(
            "data_list" => $this->_encode_msg_list($buffers),
            "gzip" => 1
        ));
        printf("\n=========================================================================\n");
        if ($response['ret_code'] === 200) {
            printf("valid message: %s\n", $msg);
        } else {
            printf("invalid message: %s\n", $msg);
            printf("ret_code: %d\n", $response['ret_code']);
            printf("ret_content: %s\n", $response['ret_content']);
        }

        if ($response['ret_code'] >= 300) {
            throw new SensorsAnalyticsDebugException("Unexpected response from SensorsAnalytics.");
        }
    }

    /**
     * 发送数据包给远程服务器。
     *
     * @param array $data
     * @return array
     * @throws SensorsAnalyticsDebugException
     */
    protected function _do_request($data) {
        $params = array();
        foreach ($data as $key => $value) {
            $params[] = $key . '=' . urlencode($value);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $this->_debug_url_prefix);
        if($this->_debug_write_data === false) {
            // 这个参数为 false, 说明只需要校验,不需要真正写入
            print("\ntry Dry-Run\n");
            curl_setopt($ch, CURLOPT_HTTPHEADER, Array (
                "Dry-Run:true"
            ) );


        }
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $this->_request_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->_request_timeout);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $params));
        curl_setopt($ch, CURLOPT_USERAGENT, "PHP SDK");
        
        $http_response_header = curl_exec($ch);
        if (!$http_response_header) {
            throw new SensorsAnalyticsDebugException(
                   "Failed to connect to SensorsAnalytics. [error='" + curl_error($ch) + "']"); 
        }
        
        $result = array(
            "ret_content" => $http_response_header,
            "ret_code" => curl_getinfo($ch, CURLINFO_HTTP_CODE)
        );
        curl_close($ch);
        return $result;
    }

    /**
     * 对待发送的数据进行编码
     *
     * @param string $msg_list
     * @return string
     */
    private function _encode_msg_list($msg_list) {
        return base64_encode($this->_gzip_string("[" . implode(",", $msg_list) . "]"));
    }

    /**
     * GZIP 压缩一个字符串
     *
     * @param string $data
     * @return string
     */
    private function _gzip_string($data) {
        return gzencode($data);
    }
}

class BatchConsumer extends AbstractConsumer {

    private $_buffers;
    private $_max_size;
    private $_url_prefix;
    private $_request_timeout;

    /**
     * @param string $url_prefix 服务器的 URL 地址。
     * @param int $max_size 批量发送的阈值。
     * @param int $request_timeout 请求服务器的超时时间，单位毫秒。
     */
    public function __construct($url_prefix, $max_size = 50, $request_timeout = 1000) {
        $this->_buffers = array();
        $this->_max_size = $max_size;
        $this->_url_prefix = $url_prefix;
        $this->_request_timeout = $request_timeout;
    }

    public function send($msg) {
        $this->_buffers[] = $msg;
        if (count($this->_buffers) >= $this->_max_size) {
            return $this->flush();
        }
        return true;
    }

    public function flush() {
        $ret = $this->_do_request(array(
            "data_list" => $this->_encode_msg_list($this->_buffers),
            "gzip" => 1
        ));
        if ($ret) {
            $this->_buffers = array();
        }
        return $ret;
    }

    /**
     * 发送数据包给远程服务器。
     *
     * @param array $data
     * @return bool 请求是否成功
     */
    protected function _do_request($data) {
        $params = array();
        foreach ($data as $key => $value) {
            $params[] = $key . '=' . urlencode($value);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $this->_url_prefix);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $this->_request_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->_request_timeout);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $params));
        curl_setopt($ch, CURLOPT_USERAGENT, "PHP SDK");
        $ret = curl_exec($ch);

        if (false === $ret) {
            curl_close($ch);
            return false;
        } else {
            curl_close($ch);
            return true;
        }
    }

    /**
     * 对待发送的数据进行编码
     *
     * @param string $msg_list
     * @return string
     */
    private function _encode_msg_list($msg_list) {
        return base64_encode($this->_gzip_string("[" . implode(",", $msg_list) . "]"));
    }

    /**
     * GZIP 压缩一个字符串
     *
     * @param string $data
     * @return string
     */
    private function _gzip_string($data) {
        return gzencode($data);
    }

    public function close() {
        return $this->flush();
    }
}
