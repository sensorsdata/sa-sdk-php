<?php

define('SENSORS_ANALYTICS_SDK_VERSION', '2.0.0');

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


class SensorsAnalytics {

    private $_consumer;
    private $_super_properties;
    private $_is_win;
    private $_project_name;

    /*
     * 为兼容旧版，实现构造函数重载
     */
    public function __construct() {
        $a = func_get_args(); //获取构造函数中的参数
        $i = count($a);
        if (method_exists($this,$f='__construct'.$i)) {
            call_user_func_array(array($this,$f),$a);
        }
    }

    /**
     * 初始化一个 SensorsAnalytics 的实例用于数据发送。
     *
     * @param AbstractConsumer $consumer
     * @param AbstractConsumer $project_name
     */
    public function __construct2($consumer, $project_name) {
        $this->_is_win = false;
        // 不支持 Windows，因为 Windows 版本的 PHP 都不支持 long
        if (strtoupper(substr(PHP_OS, 0, 3)) == "WIN") {
            $this->_is_win = true;
        }
        $this->_consumer = $consumer;
        $this->_project_name = $project_name;
        $this->clear_super_properties();
    }

    /**
     * 初始化一个 SensorsAnalytics 的实例用于数据发送。
     *
     * @param AbstractConsumer $consumer
     * @param AbstractConsumer $project_name
     */
    public function __construct1($consumer) {
        $this->_is_win = false;
        // 不支持 Windows，因为 Windows 版本的 PHP 都不支持 long
        if (strtoupper(substr(PHP_OS, 0, 3)) == "WIN") {
            $this->_is_win = true;
        }
        $this->_consumer = $consumer;
        $this->_project_name = null;
        $this->clear_super_properties();
    }

    private function _assert_key_with_regex($key) {
        $name_pattern = "/^((?!^distinct_id$|^original_id$|^time$|^properties$|^id$|^first_id$|^second_id$|^users$|^events$|^event$|^user_id$|^date$|^datetime$|^user_group|^user_tag)[a-zA-Z_$][a-zA-Z\\d_$]{0,99})$/i";
        if (!preg_match($name_pattern, $key)) {
            throw new SensorsAnalyticsIllegalDataException("key must be a valid variable key. [key='${key}']");
        }
    }


    private function _assert_key($type, $key) {
        if ($key == null || strlen($key) == 0) {
            throw new SensorsAnalyticsIllegalDataException(sprintf("the %s key is empty or null.", $type));
        }
        $this->_assert_key_with_regex($key);
    }


    private function _assert_value($type, $value){
       if ($value == null || strlen($value) == 0){
           throw new SensorsAnalyticsIllegalDataException(sprintf("the %s value is empty or null.",$type));
       }
        if (strlen($value) > 255) {
            throw new SensorsAnalyticsIllegalDataException(sprintf("the %s value %s is too long, max length is 255.", $type, $value));
        }
    }

    private function _assert_properties($properties = array()) {
        $name_pattern = "/^((?!^distinct_id$|^original_id$|^time$|^properties$|^id$|^first_id$|^second_id$|^users$|^events$|^event$|^user_id$|^date$|^datetime$)[a-zA-Z_$][a-zA-Z\\d_$]{0,99})$/i";

        if (!$properties) {
            return;
        }

        foreach ($properties as $key => $value) {
            if (!is_string($key)) {
                throw new SensorsAnalyticsIllegalDataException("property key must be a str. [key=$key]");
            }
            if (strlen($key) > 255) {
                throw new SensorsAnalyticsIllegalDataException("the max length of property key is 256. [key=$key]");
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

            if (is_string($value) && strlen($value) > 8191) {
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
    }

    /**
     *  检验 id 集合
     * @param array $identities
     * @throws SensorsAnalyticsIllegalDataException
     */
    private function _assert_identities($type, $identities){
        foreach ($identities as $key => $value){
            $this->_assert_key($type, $key);
            $this->_assert_value($type, $value);
        }
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
        if ($this->_is_win) { // windows use string(windows 32bit do not support int64)
            if (!is_string($data['time'])) {
                throw new SensorsAnalyticsIllegalDataException("property [time] type must be string");
            }
            $ts = $data['time'];
            $ts_num = strlen($ts);
            if (strlen($ts_num) == 15) {
                $ts = substr($ts, 0, 13);
            }

            if ($ts_num < 10 || $ts_num > 13) {
                throw new SensorsAnalyticsIllegalDataException("property [time] must be a timestamp in microseconds");
            }

            if ($ts_num == 10) {
                $ts .= "000";
            }
        } else { // linux use int
            $ts = (int)($data['time']);
            $ts_num = strlen($ts);
            if ($ts_num < 10 || $ts_num > 13) {
                throw new SensorsAnalyticsIllegalDataException("property [time] must be a timestamp in microseconds");
            }

            if ($ts_num == 10) {
                $ts *= 1000;
            }
        }
        $data['time'] = $ts;

        // 检查 Event Name
        if (isset($data['event'])) {
            $this->_assert_key_with_regex($data['event']);
        }

        // 检查 properties
        if (isset($data['properties']) && is_array($data['properties'])) {
            $this->_assert_properties($data['properties']);

            // XXX: 解决 PHP 中空 array() 转换成 JSON [] 的问题
            if (count($data['properties']) == 0) {
                $data['properties'] = new \ArrayObject();
            }
        } else {
            throw new SensorsAnalyticsIllegalDataException("property must be an array.");
        }

        // 检查 identities

        if (isset($data['identities'])) {
            if (is_array($data['identities'])) {
                $this->_assert_identities($data['type'], $data['identities']);
            } else {
                throw new SensorsAnalyticsIllegalDataException("identities must be an array.");
            }
        }



        return $data;
    }

    /**
     * @param string $distinct_id
     * @param array $id_map
     * @return array
     * @throws SensorsAnalyticsIllegalDataException
     */
    public  function check_identities_and_generate_distinct_id($distinct_id, $id_map){
      $tmpId = null;
      foreach ($id_map as $key => $value){
          $this->_assert_key("track", $key);
          $this->_assert_value("track", $value);
          if ($tmpId == null && $distinct_id == null){
              $tmpId = strlen(sprintf("%s+%s", $key, $value)) > 255 ? null : sprintf("%s+%s", $key, $value) ;
          }
      }
      if ($distinct_id == null ){
          if (isset($id_map['$identity_login_id'])){
              $re_distinct_id =  $id_map['$identity_login_id'];
          }else{
              $re_distinct_id = $tmpId == null ? reset($id_map) : $tmpId ;
          }
      }else{
          $this->_assert_value('distinct_id', $distinct_id);
          $re_distinct_id = $distinct_id;
      }
      return array('distinct_id' => $re_distinct_id) ;
    }

    /**
     * 如果用户传入了 $time 字段，则不使用当前时间。
     *
     * @param array $properties
     * @return int/string
     */
    private function _extract_user_time(&$properties = array()) {
        if (array_key_exists('$time', $properties)) {
            $time = $properties['$time'];
            unset($properties['$time']);
            return $time;
        }
        if ($this->_is_win) { // windows return string
            return substr((microtime(true) * 1000), 0, 13);
        } else {
            return (int)(microtime(true) * 1000);
        }
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
            } else if (count($trace) > 3) {
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
     * @param bool $is_login_id 用户标识是否是登录 ID，false 表示该标识是一个匿名 ID。
     * @param string $event_name 事件名称。
     * @param array $properties 事件的属性。
     * @return bool
     */
    public function track($distinct_id, $is_login_id, $event_name, $properties = array()) {
        try {
            if (!is_string($event_name)) {
                throw new SensorsAnalyticsIllegalDataException("event name must be a str.");
            }
            if (!is_bool($is_login_id)) {
                throw new SensorsAnalyticsIllegalDataException("is_login_id must be a bool.");
            }
            if ($properties) {
                $all_properties = array_merge($this->_super_properties, $properties);
            } else {
                $all_properties = array_merge($this->_super_properties, array());
            }
            return $this->_track_event('track', $event_name, $distinct_id, $is_login_id, null, $all_properties);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 这个接口是一个较为复杂的功能，请在使用前先阅读相关说明:http://www.sensorsdata.cn/manual/track_signup.html，并在必要时联系我们的技术支持人员。
     *
     * @param string $distinct_id 用户注册之后的唯一标识。
     * @param string $original_id 用户注册前的唯一标识。
     * @param array $properties 事件的属性。
     * @return bool
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function track_signup($distinct_id, $original_id, $properties = array()) {
        try {
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
            return $this->_track_event('track_signup', '$SignUp', $distinct_id, false, $original_id, $all_properties);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 直接设置一个用户的 Profile，如果已存在则覆盖。
     *
     * @param string $distinct_id 用户的唯一标识。
     * @param bool $is_login_id 用户标识是否是登录 ID，false 表示该标识是一个匿名 ID。
     * @param array $profiles
     * @return bool
     */
    public function profile_set($distinct_id, $is_login_id, $profiles = array()) {
        try {
            if (!is_bool($is_login_id)) {
                throw new SensorsAnalyticsIllegalDataException("is_login_id must be a bool.");
            }
            return $this->_track_event('profile_set', null, $distinct_id, $is_login_id, null, $profiles);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 直接设置一个用户的 Profile，如果某个 Profile 已存在则不设置。
     *
     * @param string $distinct_id 用户的唯一标识。
     * @param bool $is_login_id 用户标识是否是登录 ID，false 表示该标识是一个匿名 ID。
     * @param array $profiles
     * @return bool
     */
    public function profile_set_once($distinct_id, $is_login_id, $profiles = array()) {
        try {
            if (!is_bool($is_login_id)) {
                throw new SensorsAnalyticsIllegalDataException("is_login_id must be a bool.");
            }
            return $this->_track_event('profile_set_once', null, $distinct_id, $is_login_id, null, $profiles);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 增减/减少一个用户的某一个或者多个数值类型的 Profile。
     *
     * @param string $distinct_id 用户的唯一标识。
     * @param bool $is_login_id 用户标识是否是登录 ID，false 表示该标识是一个匿名 ID。
     * @param array $profiles
     * @return bool
     */
    public function profile_increment($distinct_id, $is_login_id, $profiles = array()) {
        try {
            if (!is_bool($is_login_id)) {
                throw new SensorsAnalyticsIllegalDataException("is_login_id must be a bool.");
            }
            return $this->_track_event('profile_increment', null, $distinct_id, $is_login_id, null, $profiles);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 追加一个用户的某一个或者多个集合类型的 Profile。
     *
     * @param string $distinct_id 用户的唯一标识。
     * @param bool $is_login_id 用户标识是否是登录 ID，false 表示该标识是一个匿名 ID。
     * @param array $profiles
     * @return bool
     */
    public function profile_append($distinct_id, $is_login_id, $profiles = array()) {
        try {
            if (!is_bool($is_login_id)) {
                throw new SensorsAnalyticsIllegalDataException("is_login_id must be a bool.");
            }
            return $this->_track_event('profile_append', null, $distinct_id, $is_login_id, null, $profiles);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 删除一个用户的一个或者多个 Profile。
     *
     * @param string $distinct_id 用户的唯一标识。
     * @param bool $is_login_id 用户标识是否是登录 ID，false 表示该标识是一个匿名 ID。
     * @param array $profile_keys
     * @return bool
     */
    public function profile_unset($distinct_id, $is_login_id, $profile_keys = array()) {
        try{
            if (!is_bool($is_login_id)) {
                throw new SensorsAnalyticsIllegalDataException("is_login_id must be a bool.");
            }
            if ($profile_keys != null && array_key_exists(0, $profile_keys)) {
                $new_profile_keys = array();
                foreach ($profile_keys as $key) {
                    $new_profile_keys[$key] = true;
                }
                $profile_keys = $new_profile_keys;
            }
            return $this->_track_event('profile_unset', null, $distinct_id, $is_login_id, null, $profile_keys);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }


    /**
     * 删除整个用户的信息。
     *
     * @param string $distinct_id 用户的唯一标识。
     * @param bool $is_login_id 用户标识是否是登录 ID，false 表示该标识是一个匿名 ID。
     * @return bool
     */
    public function profile_delete($distinct_id, $is_login_id) {
        try{
            if (!is_bool($is_login_id)) {
                throw new SensorsAnalyticsIllegalDataException("is_login_id must be a bool.");
            }
            return $this->_track_event('profile_delete', null, $distinct_id, $is_login_id, null, array());
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 直接设置一个物品，如果已存在则覆盖。
     *
     * @param string $itemType item类型。
     * @param string $itemId item的唯一标识。
     * @param array $properties item属性
     * @return bool
     */
    public function item_set($item_type, $item_id, $properties = array()) {
        return $this->_track_item('item_set', $item_type, $item_id, $properties);
    }

    /**
     * 删除一个物品
     *
     * @param string $itemType item类型。
     * @param string $itemId item的唯一标识。
     * @param array $properties item属性
     * @return bool
     */
    public function item_delete($item_type, $item_id, $properties = array()) {
        return $this->_track_item('item_delete', $item_type, $item_id, $properties);
    }

    public function _track_item($action_type, $item_type, $item_id, $properties = array()) {
        $this->_assert_key_with_regex($item_type);
        $this->_assert_key("Item Type", $item_id);
        $this->_assert_properties($properties);

        $event_project = null;

        if ($properties && isset($properties['$project'])) {
            $event_project = $properties['$project'];
            unset($properties['$project']);
        }

        $data = array(
            'type' => $action_type,
            'time' => (int)(microtime(true) * 1000),
            'properties' => $properties,
            'lib' => $this->_get_lib_properties(),
            'item_type' => $item_type,
            'item_id' => $item_id,
        );

        if ($this->_project_name) {
            $data['project'] = $this->_project_name;
        }

        if ($event_project) {
            $data['project'] = $event_project;
        }

        if (count($data['properties']) == 0) {
            $data['properties'] = new \ArrayObject();
        }

        return $this->_consumer->send($this->_json_dumps($data));
    }


    /**
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function bind(){
        try {
            $identities = func_get_args();
            $identity_map = array() ;
            foreach ( $identities as $key => $identity) {
                if (!is_null($identity)){
                    $identity_map = array_merge($identity_map , $identity -> get_identity_map());
                }
            }
            $identity_map = array_filter($identity_map, function($v, $k) {
                if (is_numeric($k) && is_null($v) ){
                    return false;
                }
                return true;
            }, ARRAY_FILTER_USE_BOTH);
            if ( count($identity_map) < 2) {
                throw new SensorsAnalyticsIllegalDataException("the identities is invalid，you should have at least two identities.");
            }
            $this->_idm_track_event('track_id_bind', '$BindID', new SensorsAnalyticsIdentity($identity_map));
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * @param SensorsAnalyticsIdentity $identity
     * @return void
     */
    public function unbind($identity){
        try {
            $this->_idm_track_event('track_id_unbind', '$UnbindID', $identity);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * @param SensorsAnalyticsIdentity $identity
     * @param string $event_name 事件
     * @param array $properties
     * @return void
     */
    public function track_by_id($identity, $event_name, $properties = array()){
        try {
            if ($identity == null ){
                throw  new SensorsAnalyticsIllegalDataException('the identity is invalid');
            }
            if ($event_name == null || !is_string($event_name) ){
                throw  new SensorsAnalyticsIllegalDataException('the event_name is invalid');
            }
            if ($properties) {
                $all_properties = array_merge($this->_super_properties, $properties);
            } else {
                $all_properties = array_merge($this->_super_properties, array());
            }
            $this->_idm_track_event('track', $event_name, $identity, $all_properties);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 直接设置一个用户的 Profile，如果已存在则覆盖。
     *
     * @param SensorsAnalyticsIdentity $identity 用户标识 ID
     * @param array $profiles 用户属性
     * @return bool
     */
    public function profile_set_by_id($identity, $profiles = array()) {
        try {
            return $this -> _idm_track_event('profile_set', null, $identity, $profiles);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 直接设置一个用户的 Profile，如果某个 Profile 已存在则不设置。
     * @param SensorsAnalyticsIdentity $identity 用户标识 ID
     * @param array $profiles 用户属性
     * @return bool
     */
    public function profile_set_once_by_id($identity, $profiles = array()) {
        try {
            return $this -> _idm_track_event('profile_set_once', null, $identity, $profiles);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 增减/减少一个用户的某一个或者多个数值类型的 Profile。
     * @param SensorsAnalyticsIdentity $identity 用户标识 ID
     * @param array $profiles 用户属性
     * @return bool
     */
    public function profile_increment_by_id($identity , $profiles = array()) {
        try {
            return $this -> _idm_track_event('profile_increment', null, $identity, $profiles);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 追加一个用户的某一个或者多个集合类型的 Profile。
     * @param SensorsAnalyticsIdentity $identity 用户标识 ID
     * @param array $profiles 用户属性
     * @return bool
     */
    public function profile_append_by_id($identity , $profiles = array()) {
        try {
            return $this -> _idm_track_event('profile_append', null, $identity, $profiles);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 删除一个用户的一个或者多个 Profile。
     * @param SensorsAnalyticsIdentity $identity 用户标识 ID
     * @param array $profile_keys 用户属性
     * @return bool
     */
    public function profile_unset_by_id($identity , $profile_keys = array()) {
        try {
            if ($profile_keys != null && array_key_exists(0, $profile_keys)) {
                $new_profile_keys = array();
                foreach ($profile_keys as $key) {
                    $new_profile_keys[$key] = true;
                }
                $profile_keys = $new_profile_keys;
            }
            return $this -> _idm_track_event('profile_unset', null, $identity, $profile_keys);
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
    }

    /**
     * 删除整个用户的信息。
     *
     * @param SensorsAnalyticsIdentity $identity 用户标识 ID
     * @return bool
     */
    public function profile_delete_by_id($identity) {
        try {
            return $this -> _idm_track_event('profile_delete', null, $identity, array());
        } catch (Exception $e){
            echo '<br>'.$e.'<br>';
        }
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
        return $this->_consumer->flush();
    }

    /**
     * 在进程结束或者数据发送完成时，应当调用此接口，以保证所有数据被发送完毕。
     * 如果发生意外，此方法将抛出异常。
     */
    public function close() {
        return $this->_consumer->close();
    }

    /**
     * @param string $update_type
     * @param string $event_name
     * @param string $distinct_id
     * @param bool $is_login_id
     * @param string $original_id
     * @param array $properties
     * @param array $identities
     * @return bool
     * @internal param array $profiles
     */
    public function _track_event($update_type, $event_name, $distinct_id, $is_login_id, $original_id, $properties, $identities = array()) {
        $event_time = $this->_extract_user_time($properties);

        if ($is_login_id) {
            $properties['$is_login_id'] = true;
        }

        $data = array(
            'type' => $update_type,
            'properties' => $properties,
            'time' => $event_time,
            'distinct_id' => $distinct_id,
            'lib' => $this->_get_lib_properties(),
        );

        if ($this->_project_name) {
            $data['project'] = $this->_project_name;
        }

        if (strcmp($update_type, "track") == 0 or strcmp($update_type, "track_id_bind") == 0
           or strcmp($update_type, "track_id_unbind") == 0) {
            $data['event'] = $event_name;
        } else if (strcmp($update_type, "track_signup") == 0) {
            $data['event'] = $event_name;
            $data['original_id'] = $original_id;
        }

        if (count($identities) > 0){
            $data['identities'] = $identities ;
        }

        $data = $this->_normalize_data($data);
        return $this->_consumer->send($this->_json_dumps($data));
    }

    /**
     * @param string $update_type
     * @param string $event_name
     * @param SensorsAnalyticsIdentity $identity
     * @param array $property_map
     * @return bool
     * @throws SensorsAnalyticsIllegalDataException
     */
    private function _idm_track_event($update_type, $event_name, $identity, $property_map = array()){
        if (is_null($identity)){
            throw new SensorsAnalyticsIllegalDataException("the identity is invalid.");
        }else if (count($identity -> get_identity_map()) < 1){
            throw new SensorsAnalyticsIllegalDataException("the identity is empty.");
        }
       $pair =  $this->check_identities_and_generate_distinct_id(null, $identity -> get_identity_map());
       return $this->_track_event($update_type, $event_name, $pair['distinct_id'], false, null, $property_map, $identity -> get_identity_map()) ;
    }

}

class SensorsAnalyticsIdentity{
    protected  $identity_map ;
    public function __construct($identity_map = array())
    {
        $this -> identity_map = $identity_map ;
    }
    public function get_identity_map(){
        return $this->identity_map;
    }
    public function add_identity($key,$value){
        $this->identity_map[$key] = $value;
    }


}



abstract class AbstractConsumer {

    /**
     * 发送一条消息。
     *
     * @param string $msg 发送的消息体
     * @return bool
     */
    public abstract function send($msg);

    /**
     * 立即发送所有未发出的数据。
     *
     * @return bool
     */
    public function flush() {
    }

    /**
     * 关闭 Consumer 并释放资源。
     *
     * @return bool
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

        //judge https
        $pos = strpos($this->_debug_url_prefix, "https");
        if ($pos === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }
        
        $http_response_header = curl_exec($ch);
        if (!$http_response_header) {
            throw new SensorsAnalyticsDebugException(
                   "Failed to connect to SensorsAnalytics. [error='".curl_error($ch)."']");
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
     * @param array $msg_list
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
    private $file_handler;

    /**
     * @param string $url_prefix 服务器的 URL 地址。
     * @param int $max_size 批量发送的阈值。
     * @param int $request_timeout 请求服务器的超时时间，单位毫秒。
     * @param boolean $response_info 发送数据请求是否返回详情 默认 false。
     * @param string $filename 发送数据请求的返回状态及数据落盘记录，必须同时 $response_info 为 ture 时，才会记录。
     */
    public function __construct($url_prefix, $max_size = 50, $request_timeout = 1000, $response_info = false, $filename = false) {
        $this->_buffers = array();
        $this->_max_size = $max_size;
        $this->_url_prefix = $url_prefix;
        $this->_request_timeout = $request_timeout;
        $this->_response_info = $response_info;
        try {
            if($filename !== false && $this->_response_info !== false) {
                $this->file_handler = fopen($filename, 'a+');
            }
        } catch (\Exception $e) {
            echo $e;
        }
    }
    

    public function send($msg) {
        $this->_buffers[] = $msg;
        if (count($this->_buffers) >= $this->_max_size) {
            return $this->flush();
        }
        // data into cache buffers，back some log
        if($this->_response_info){
            $result = array(
                "ret_content" => "data into cache buffers",
                "ret_origin_data" => "",
                "ret_code" => 900,
            );
            if ($this->file_handler !== null) {
                // need to write log
                fwrite($this->file_handler, stripslashes(json_encode($result)) . "\n");      
            }
            return $result; 
        }
        return true;
    }

    public function flush() {
        if (empty($this->_buffers)) {
            $ret = false;
        } else {
            $ret = $this->_do_request(array(
                "data_list" => $this->_encode_msg_list($this->_buffers),
                "gzip" => 1
            ),$this->_buffers);
        }
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
    protected function _do_request($data,$origin_data) {
        $params = array();
        foreach ($data as $key => $value) {
            $params[] = $key . '=' . urlencode($value);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($ch, CURLOPT_URL, $this->_url_prefix);
        curl_setopt($ch, CURLOPT_HEADER, 0);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $this->_request_timeout);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, $this->_request_timeout);
        curl_setopt($ch, CURLOPT_POST, 1);
        curl_setopt($ch, CURLOPT_POSTFIELDS, implode('&', $params));
        curl_setopt($ch, CURLOPT_USERAGENT, "PHP SDK");

        //judge https
        $pos = strpos($this->_url_prefix, "https");
        if ($pos === 0) {
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        }

        $ret = curl_exec($ch);

        // judge back detail response
        if($this->_response_info){
            $result = array(
                "ret_content" => $ret,
                "ret_origin_data" => $origin_data,
                "ret_code" => curl_getinfo($ch, CURLINFO_HTTP_CODE),
            );
            if ($this->file_handler !== null) {
                // need to write log
                fwrite($this->file_handler, stripslashes(json_encode($result)) . "\n");
            }
            curl_close($ch);
            return $result;
        }
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
     * @param array $msg_list
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
        $closeResult = $this->flush();
        if ($this->file_handler !== null) {
            fclose($this->file_handler);
        }
        return $closeResult;
    }
}
