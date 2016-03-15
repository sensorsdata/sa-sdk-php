<?php

define('SENSORS_ANALYTICS_SDK_VERSION', '1.3.2');

class SensorsAnalyticsException extends Exception {
}

// 在发送的数据格式有误时，SDK会抛出此异常，用户应当捕获并处理。
class SensorsAnalyticsIllegalDataException extends SensorsAnalyticsException {
}

// 在因为网络或者不可预知的问题导致数据无法发送时，SDK会抛出此异常，用户应当捕获并处理。
class SensorsAnalyticsNetworkException extends SensorsAnalyticsException {
}

// 当且仅当DEBUG模式中，任何网络错误、数据异常等都会抛出此异常，用户可不捕获，用于测试SDK接入正确性
class SensorsAnalyticsDebugException extends Exception {
}

class SensorsAnalytics {

    private $_consumer;

    /**
     * 初始化一个 SensorsAnalytics 的实例用于数据发送。
     *
     * @param AbstractConsumer $consumer
     */
    public function __construct($consumer) {
        $this->_consumer = $consumer;
    }

    /**
     * 跟踪一个用户的行为。
     *
     * @param string $distinct_id 用户的唯一标识。
     * @param string $event_name 事件名称。
     * @param array $properties 事件的属性。
     */
    public function track($distinct_id, $event_name, $properties = array()) {
        $event_time = $this->_extract_user_time($properties);
        $all_properties = $this->_get_common_properties();
        if ($properties) {
            $all_properties = array_merge($all_properties, $properties);
        }
        $data = array(
            'type' => 'track',
            'event' => $event_name,
            'time' => $event_time,
            'distinct_id' => $distinct_id,
            'properties' => $all_properties,
        );
        $data = $this->_normalize_data($data);
        $this->_consumer->send($this->_json_dumps($data));
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
        if (isset($data['properties'])) {
            foreach ($data['properties'] as $key => $value) {
                if (!is_string($key)) {
                    throw new SensorsAnalyticsIllegalDataException("property key must be a str. [key=$key]");
                }
                if (!preg_match($name_pattern, $key)) {
                    throw new SensorsAnalyticsIllegalDataException("property key must be a valid variable name. [key='$key']]");
                }

                // 只支持简单类型或数组或DateTime类
                if (!is_scalar($value) && !is_array($value) && !$value instanceof DateTime) {
                    throw new SensorsAnalyticsIllegalDataException("property value must be a str/int/float/datetime/list. [key='$key']");
                }

                // 如果是 DateTime，Format 成字符串
                if ($value instanceof DateTime) {
                    $data['properties'][$key] = $value->format("Y-m-d H:i:s.0");
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
     * 返回公共的属性。
     *
     * @return array
     */
    private function _get_common_properties() {
        return array(
            '$lib' => 'php',
            '$lib_version' => SENSORS_ANALYTICS_SDK_VERSION,
        );
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
     * 这个接口是一个较为复杂的功能，请在使用前先阅读相关说明:http://www.sensorsdata.cn/manual/track_signup.html，并在必要时联系我们的技术支持人员。
     *
     * @param string $distinct_id 用户注册之后的唯一标识。
     * @param string $original_id 用户注册前的唯一标识。
     * @param array $properties 事件的属性。
     */
    public function track_signup($distinct_id, $original_id, $properties = array()) {
        $event_time = $this->_extract_user_time($properties);
        $all_properties = $this->_get_common_properties();
        if ($properties) {
            $all_properties = array_merge($all_properties, $properties);
        }
        $data = array(
            'type' => 'track_signup',
            'event' => '$SignUp',
            'time' => $event_time,
            'distinct_id' => $distinct_id,
            'original_id' => $original_id,
            'properties' => $all_properties,
        );
        // 检查 original_id
        if (!isset($data['original_id']) or strlen($data['original_id']) == 0) {
            throw new SensorsAnalyticsIllegalDataException("property [original_id] must not be empty");
        }
        if (strlen($data['original_id']) > 255) {
            throw new SensorsAnalyticsIllegalDataException("the max length of [original_id] is 255");
        }
        $data = $this->_normalize_data($data);
        $this->_consumer->send($this->_json_dumps($data));
    }

    /**
     * 直接设置一个用户的 Profile，如果已存在则覆盖。
     *
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function profile_set($distinct_id, $profiles = array()) {
        return $this->_profile_update('profile_set', $distinct_id, $profiles);
    }

    /**
     * 直接设置一个用户的 Profile，如果某个 Profile 已存在则不设置。
     *
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function profile_set_once($distinct_id, $profiles = array()) {
        return $this->_profile_update('profile_set_once', $distinct_id, $profiles);
    }

    /**
     * @param string $update_type
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function _profile_update($update_type, $distinct_id, $profiles) {
        $event_time = $this->_extract_user_time($profiles);
        $data = array(
            'type' => $update_type,
            'properties' => $profiles,
            'time' => $event_time,
            'distinct_id' => $distinct_id
        );
        $data = $this->_normalize_data($data);
        return $this->_consumer->send($this->_json_dumps($data));
    }

    /**
     * 增减/减少一个用户的某一个或者多个数值类型的 Profile。
     *
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function profile_increment($distinct_id, $profiles = array()) {
        return $this->_profile_update('profile_increment', $distinct_id, $profiles);
    }

    /**
     * 追加一个用户的某一个或者多个集合类型的 Profile。
     *
     * @param string $distinct_id
     * @param array $profiles
     * @return boolean
     */
    public function profile_append($distinct_id, $profiles = array()) {
        return $this->_profile_update('profile_append', $distinct_id, $profiles);
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
        return $this->_profile_update('profile_unset', $distinct_id, $profile_keys);
    }


    /**
     * 删除整个用户的信息。
     *
     * @param $distinct_id
     * @return boolean
     */
    public function profile_delete($distinct_id) {
        return $this->_profile_update('profile_delete', $distinct_id, array());
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
