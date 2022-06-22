<?php
require_once("SensorsAnalytics.php");
date_default_timezone_set("Asia/Shanghai");

class NormalTest extends PHPUnit_Framework_TestCase {

    private $saTmp;
    private $data_list;
    private $key_arr = array("123id", "用户名", "abc@#%^&*", "date", "datetime", "distinct_id", "event", "events", "first_id", "id", "original_id",
        "properties", "second_id", "time", "user_id", "users", "user_group123", "user_tag456");
    public function setUp() {
        $stub_consumer = $this->getMockBuilder('BatchConsumer')
            ->setConstructorArgs(array(""))
            ->setMethods(array("_do_request"))
            ->getMock();
        $stub_consumer->method('_do_request')->will(
            $this->returnCallback(array($this, '_idm_mock_do_request')));
        $this -> saTmp = new SensorsAnalytics($stub_consumer);
    }

    public function tearDown() {

    }

    public function testFileConsumer() {
        $test_file = "php_sdk_test";
        $consumer = new FileConsumer($test_file);
        $sa = new SensorsAnalytics($consumer);
        $now = (int)(microtime(true) * 1000);
        $sa->track('1234', true, 'Test', array('From' => 'Baidu', '$time' => $now));
        $sa->track_signup('1234', 'abcd', array('Channel' => 'Hongbao'));
        $sa->profile_delete('1234', true);
        $sa->profile_append('1234', true, array('Gender' => 'Male'));
        $sa->profile_increment('1234', true, array('CardNum' => 1));
        $sa->profile_set('1234', true, array('City' => '北京'));
        $sa->profile_unset('1234', true, array('City'));
        $sa->profile_unset('1234', true, array('Province' => true));
        $dt = new DateTime();
        $dt->setTimestamp($now / 1000.0);
        $sa->profile_set('1234', true, array('$signup_time' => $dt));
        $file_contents = file_get_contents($test_file);

        $list = explode("\n", $file_contents);
        $i = 0;
        foreach ($list as $key => $item) {
            if (strlen($item) > 0) {
                $i++;
                $list[$key] = json_decode($item, true);
            }
        }
        unlink($test_file);
        $this->assertEquals($now, $list[0]['time']);
        $this->assertArrayNotHasKey('time', $list[0]['properties']);
        $this->assertTrue(microtime(true) * 1000 - $list[1]['time'] < 1000);
        $this->assertTrue($list[6]['properties']['City'] === true);
        $this->assertTrue($list[7]['properties']['Province'] === true);
        $this->assertEquals($i, 9);
    }


    function my_gzdecode($string) {
        $string = substr($string, 10);
        return gzinflate($string);
    }

    function _mock_do_request($msg) {
        $data = json_decode($this->my_gzdecode(base64_decode($msg['data_list'])));
        $this->_msg_count += count($data);
        return true;
    }

    function _idm_mock_do_request($msg) {
        $this-> data_list  = json_decode($this->my_gzdecode(base64_decode($msg['data_list'])));
        return true;
    }

    public function testNormal() {
        $stub_consumer = $this->getMockBuilder('BatchConsumer')
            ->setConstructorArgs(array(""))
            ->setMethods(array("_do_request"))
            ->getMock();
        $stub_consumer->method('_do_request')->will(
            $this->returnCallback(array($this, '_mock_do_request')));
        $sa = new SensorsAnalytics($stub_consumer);
        $this->_msg_count = 0;
        $sa->track(1234, true, 'Test', array('From' => 'Baidu'));
        $sa->track(1234, true, 'Test', array('From' => 'Baidu', '$time' => 1437816376));
        $sa->track(1234, true, 'Test', array('From' => 'Baidu', '$time' => 1437816376000));
        $sa->track(1234, true, 'Test', array('From' => 'Baidu', '$time' => '1437816376'));
        $sa->track(1234, true, 'Test', array('From' => 'Baidu', '$time' => '1437816376000'));
        $sa->track(1234, true, 'Tes123_$t', array('From' => 'Baidu', '$time' => '1437816376000'));
        $sa->track(1234, true, 'Tes123_$t', array('From' => 'Baidu', '$time' => '1437816376000', 'Test' => 1437816376000999933));
        $sa->profile_set(1234, true, array('From' => 'Baidu'));
        $sa->profile_set(1234, true, array('From' => 'Baidu', 'asd' => array("asd", "bbb")));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property \[distinct_id\] must not be empty.*#
     */
    public function testException1() {
        $sa = new SensorsAnalytics(null);
        $sa->track(null, true, 'test', array('from' => 'baidu'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*must be a timestamp in microseconds.*#
     */
    public function testException2() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, true, 'Test', array('From' => 'Baidu', '$time' => 1234));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property key must be a str.*#
     */
    public function testException3() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, true, 'Test', array(123 => 'Baidu'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*event name must be a valid variable nam.*#
     */
    public function testException4() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, true, 'Test 123', array(123 => 'Baidu'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property value must be a str.*#
     */
    public function testException5() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, true, 'TestEvent', array('TestProperty' => new SensorsAnalytics(null)));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property key must be a valid variable name.*#
     */
    public function testException6() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, true, 'Test', array('distincT_id' => 'SensorsData'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property key must be a valid variable name.*#
     */
    public function testException7() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, true, 'TestEvent', array('a123456789a123456789a123456789a123456789a123456789a123456789a123456789a123456789a123456789a1234567890' => 'SensorsData'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property's value must be a str.*#
     */
    public function testException8() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, true, 'TestEvent', array('TestProperty' => array(123)));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property must not be associative.*#
     */
    public function testException9() {
        $sa = new SensorsAnalytics(null);
        $a = array("b" => 123);
        $c = array(123);
        $sa->track(1234, true, 'TestEvent', array('TestProperty' => array("a" => 123)));
    }


    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #the max length of \[distinct_id\] is 255#
     */
    public function testException10() {
        $sa = new SensorsAnalytics(null);
        $sa->track('abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz', true, 'TestEvent', array('test_key' => 'SensorsData'));
    }

    public function testBatchConsumerMock() {
        $stub_consumer = $this->getMockBuilder('BatchConsumer')
            ->setConstructorArgs(array(""))
            ->setMethods(array("_do_request"))
            ->getMock();
        $stub_consumer->method('_do_request')->will(
            $this->returnCallback(array($this, '_mock_do_request')));

        $sa = new SensorsAnalytics($stub_consumer);
        $this->_msg_count = 0;
        $sa->track('1234', true, 'Test', array('From' => 'Baidu'));
        $sa->track_signup('1234', 'abcd', array('Channel' => 'Hongbao'));
        $sa->profile_delete('1234', true);
        $sa->profile_append('1234', true, array('Gender' => 'Male'));
        $sa->profile_increment('1234', true, array('CardNum' => 1));
        $sa->profile_set('1234', true, array('City' => '北京'));
        $sa->profile_unset('1234', true, array('City'));
        $this->assertEquals($this->_msg_count, 0);
        $sa->flush();
        $this->assertEquals($this->_msg_count, 7);
        for ($i = 0; $i < 49; $i++) {
            $sa->profile_set('1234', true, array('City' => '北京'));
        }
        $this->assertEquals($this->_msg_count, 7);
        $sa->profile_set('1234', true, array('City' => '北京'));
        $this->assertEquals($this->_msg_count, 57);
    }

    public function testBatchConsumer() {
        $consumer = new BatchConsumer("http://git.sensorsdata.cn/test");
        $sa = new SensorsAnalytics($consumer);
        $sa->track('1234', true, 'Test', array('From' => 'Baidu'));
        $sa->track_signup('1234', 'abcd', array('Channel' => 'Hongbao'));
        $sa->profile_delete('1234', true);
        $sa->profile_append('1234', true, array('Gender' => 'Male'));
        $sa->profile_increment('1234', true, array('CardNum' => 1));
        $sa->profile_set('1234', true, array('City' => '北京'));
        $sa->profile_unset('1234', true, array('City'));
        $sa->flush();
        for ($i = 0; $i < 49; $i++) {
            $sa->profile_set('1234', true, array('City' => '北京'));
        }
        $sa->profile_set('1234', true, array('City' => '北京'));
        $sa->close();
    }

    public function testDebugConsumer() {
        $consumer = new DebugConsumer('http://10.10.11.209:8006/debug?project=default&token=bbb', false);
        $sa = new SensorsAnalytics($consumer);
        $sa->track('1234', true, 'Test', array('PhpTestProperty' => 'Baidu'));
        $consumer = new DebugConsumer('http://10.10.11.209:8006/debug?project=default&token=bbb', true);
        $sa = new SensorsAnalytics($consumer);
        $sa->track('1234', true, 'Test', array('PhpTestProperty' => 123));
        $sa->track('1234', true, 'Test', array('PhpTestProperty' => 'Baidu'));
    }

    public function testFileConsumerItem() {
        $test_file = "php_sdk_test_item";
        $consumer = new FileConsumer($test_file);
        $sa = new SensorsAnalytics($consumer);

        $sa->item_set('book', '1234', array('From' => 'Baidu', 'price' => 30));
        $sa->item_delete('book', '1234');

        $file_contents = file_get_contents($test_file);

        $list = explode("\n", $file_contents);
        $i = 0;
        foreach ($list as $key => $item) {
            if (strlen($item) > 0) {
                $i++;
                $list[$key] = json_decode($item, true);
            }
        }
        unlink($test_file);

        $this->assertEquals($list[0]['item_type'], 'book');
        $this->assertEquals($list[0]['type'], 'item_set');
        $this->assertEquals($list[1]['type'], 'item_delete');
    }

    /**
     *  校验：bind 接口传入合法参数
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function testIdMappingBindNormal(){
        $identity = new SensorsAnalyticsIdentity(array('$identity_login_id' => 'iu001','$identity_mobile' => '123','$identity_email' => 'iu@163.com',null)) ;
        $identity2 = new SensorsAnalyticsIdentity(array('id_test_1' => 'iu')) ;
        $identity2 -> add_identity('$identity_mobile','中文id_value123$\@%&^*');
        $this->saTmp -> bind($identity, $identity2);
        $this->saTmp -> flush();
        print_r($this->data_list[0]);
        $properties = json_decode(json_encode($this->data_list[0] -> properties),true) ;
        $identities = json_decode(json_encode($this->data_list[0] -> identities),true);
        $this -> assertTrue($properties['$is_login_id']);
        $this -> assertEquals(count($identities), 4);
        $this -> assertEquals($identities['$identity_login_id'],$this->data_list[0] -> distinct_id);
        $this -> assertEquals($identities['$identity_login_id'],'iu001');
        $this -> assertEquals($identities['$identity_mobile'],'中文id_value123$\@%&^*');
    }

    /**
     *  校验：bind 接口传入不合法参数，identity = null
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*the identities is invalid，you should have at least two identities.*#
     */
    public function testIdMappingBindException1(){
        $identity = new SensorsAnalyticsIdentity(array(null, '$identity_mobile' => '123')) ;
        $this->saTmp -> bind($identity);
    }

    /**
     *  校验：bind 接口传入不合法参数，key = null
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*the track_id_bind key is empty or null.*#
     */
    public function testIdMappingBindException2(){
        $identity = new SensorsAnalyticsIdentity(array(null => '123', '$identity_mobile' => '123')) ;
        $this->saTmp -> bind($identity);
    }

    /**
     *  校验：bind 接口传入不合法参数，key = ''
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*the track_id_bind key is empty or null.*#
     */
    public function testIdMappingBindException3(){
        $identity = new SensorsAnalyticsIdentity(array('' => '123', '$identity_mobile' => '123')) ;
        $this->saTmp -> bind($identity);
    }


    /**
     *  校验：bind 接口传入不合法参数，key 不合法
     * @expectedException    SensorsAnalyticsIllegalDataException
     */
    public function testIdMappingBindException4(){
        foreach ($this->key_arr as $key => $value){
            $identity = new SensorsAnalyticsIdentity(array($value => 'value', '$identity_mobile' => '123')) ;
            $this->saTmp -> bind($identity);
        }
    }

    /**
     *  校验：bind 接口传入不合法参数，value 不合法
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*the track value is empty or null.*#
     */
    public function testIdMappingBindException5(){
        $identity = new SensorsAnalyticsIdentity(array( '$lib' => '123', '$identity_mobile' => null)) ;
        $this->saTmp -> bind($identity);
    }


    /**
     *  校验：unbind 接口传入合法参数
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function testIdMappingUnBindNormal(){
        $identity = new SensorsAnalyticsIdentity(array('$identity_login_id' => 'iu001','$identity_mobile' => '中文id_value123$\@%&^*','$identity_email' => 'iu@163.com')) ;
        $this->saTmp -> unbind($identity);
        $this->saTmp -> flush();
        print_r($this->data_list[0]);
        $properties = json_decode(json_encode($this->data_list[0] -> properties),true) ;
        $identities = json_decode(json_encode($this->data_list[0] -> identities),true);
        $this -> assertTrue($properties['$is_login_id']);
        $this -> assertEquals($identities['$identity_login_id'],$this->data_list[0] -> distinct_id);
        $this -> assertEquals($identities['$identity_login_id'],'iu001');
        $this -> assertEquals($identities['$identity_mobile'],'中文id_value123$\@%&^*');
    }

    /**
     *  校验：unbind 接口传入 null
     *  @expectedException    SensorsAnalyticsIllegalDataException
     */
    public function testIdMappingUnBindException1(){
         $this->saTmp -> unbind(null);
    }

    /**
     *  校验：track_by_id 接口传入正常参数
     */
    public function testIdMappingTrackByIdNormal(){
        $identity = new SensorsAnalyticsIdentity(array('$identity_login_id' => 'iu001','$identity_mobile' => '中文id_value123$\@%&^*','$identity_email' => 'iu@163.com')) ;
        $properties = array('test' =>'test', '$project' => 'abc', '$token' => '123') ;
        $identity -> add_identity('test' , 'test');
        $this-> saTmp -> track_by_id($identity, 'testMultiIdentity', $properties);
        $this->saTmp->flush();
        print_r($this->data_list);
    }

    /**
     *  校验：track_by_id 接口传入错误 key 值
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function testIdMappingTrackByIdKey(){
        foreach ($this->key_arr as $key => $value){
            $identity = new SensorsAnalyticsIdentity(array($value => 'value', '$identity_mobile' => '123')) ;
            try{
                $this-> saTmp -> track_by_id($identity, 'testMultiIdentity', null);
                $this->fail('No Exception has been raised.');
            }catch (SensorsAnalyticsIllegalDataException $e){
                $this -> assertEquals(sprintf("key must be a valid variable key. [key='%s']",$value),$e -> getMessage());
            }
        }
    }

    /**
     *  校验：track_by_id 接口传入错误 value 值
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function testIdMappingTrackByIdValue(){
        $identity = new SensorsAnalyticsIdentity(array( '$lib' => '123', '$identity_mobile' => null)) ;
        try{
            $this->saTmp -> track_by_id($identity , 'testIdMappingTrackByIdValue', null);
            $this->fail('No Exception has been raised.');
        }catch (SensorsAnalyticsIllegalDataException $e){
            $this -> assertEquals('the track value is empty or null.',$e -> getMessage());
        }
        $identity = new SensorsAnalyticsIdentity(array( '$lib' => '123', '$identity_mobile' => '')) ;
        try{
            $this->saTmp -> track_by_id($identity , 'testIdMappingTrackByIdValue', null);
            $this->fail('No Exception has been raised.');
        }catch (SensorsAnalyticsIllegalDataException $e){
            $this -> assertEquals('the track value is empty or null.',$e -> getMessage());
        }
    }

    /**
     *  校验：profile_set_by_id 接口传入正常参数
     */
    public function testIdMappingProfileSetByIdNormal(){
        $identity = new SensorsAnalyticsIdentity(array('$identity_login_id' => 'iu001','$identity_mobile' => '中文id_value123$\@%&^*','$identity_email' => 'iu@163.com')) ;
        $properties = array('test' =>'test', '$project' => 'abc', '$token' => '123') ;
        $this-> saTmp -> profile_set_by_id($identity, $properties);
        $this->saTmp->flush();
        print_r($this->data_list);
    }

    /**
     *  校验：profile_set_by_id 接口传入 null
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function testIdMappingProfileSetByIdException(){
        $identity = new SensorsAnalyticsIdentity(array('$identity_login_id' => 'iu001','$identity_mobile' => '中文id_value123$\@%&^*','$identity_email' => 'iu@163.com')) ;
        $properties = array('test' =>'test', '$project' => 'abc', '$token' => '123') ;
        // identity = null
        try{
            $this-> saTmp -> profile_set_by_id(null, $properties);
            $this->fail('No Exception has been raised.');
        }catch (SensorsAnalyticsIllegalDataException $e){
            $this -> assertEquals('the identity is invalid.',$e -> getMessage());
        }
        // properties = null
        try{
            $this-> saTmp -> profile_set_by_id($identity, null);
            $this->fail('No Exception has been raised.');
        }catch (Exception $e){
            $this -> assertEquals('array_key_exists() expects parameter 2 to be array, null given',$e -> getMessage());
        }
    }

    /**
     *  校验：profile_set_once_by_id 接口传入正常参数
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function testIdMappingProfileSetOnceByIdNormal(){
        $identity = new SensorsAnalyticsIdentity(array('$identity_login' => 'iu001','$anonymous_id' => '中文id_value123$\@%&^*','$identity_email' => 'iu@163.com')) ;
        $properties = array('test' =>'test', '$project' => 'abc', '$token' => '123') ;
        $this-> saTmp -> profile_set_once_by_id($identity, $properties);
        $this->saTmp->flush();
        print_r($this->data_list);
        $identities = json_decode(json_encode($this->data_list[0] -> identities),true);
        $this -> assertEquals('$identity_login+iu001',$this->data_list[0] -> distinct_id);
        $this -> assertEquals($identities['$identity_login'],'iu001');
        $this -> assertEquals($identities['$anonymous_id'],'中文id_value123$\@%&^*');
    }

    /**
     *  校验：profile_increment_by_id 接口传入正常参数
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function testIdMappingProfileIncrementByIdNormal(){
        $identity = new SensorsAnalyticsIdentity(array('$identity_login' => 'iu001','$anonymous_id' => '中文id_value123$\@%&^*','$identity_email' => 'iu@163.com')) ;
        $properties = array('test' =>'test', '$project' => 'abc', '$token' => '123') ;
        $this-> saTmp -> profile_increment_by_id($identity, $properties);
        $this->saTmp->flush();
        print_r($this->data_list);
        $identities = json_decode(json_encode($this->data_list[0] -> identities),true);
        $this -> assertEquals('$identity_login+iu001',$this->data_list[0] -> distinct_id);
        $this -> assertEquals($identities['$identity_login'],'iu001');
        $this -> assertEquals($identities['$anonymous_id'],'中文id_value123$\@%&^*');
    }


    /**
     *  校验：IDM 接口上报
     * @throws SensorsAnalyticsIllegalDataException
     */
    public function testIdMapping(){
        $sa = new SensorsAnalytics(new BatchConsumer('http://10.120.51.226:8106/sa?project=default',50,1000,true));
        $identity = new SensorsAnalyticsIdentity(array('$identity_login_id' => 'iu001','$identity_mobile' => '中文id_value123$\@%&^*','$identity_email' => 'iu@163.com')) ;
        $unbind_identity = new SensorsAnalyticsIdentity(array('$identity_email' => 'iu@163.com'));
        $profile_identity = new SensorsAnalyticsIdentity(array('$identity_login_id' => 'iu001'));
        // 旧接口方法
        $sa ->track('iu001', true, 'PHP_SDK', array('f' => 'Baidu'));
        $sa->track_signup('iu001', 'abcd', array('Channel' => 'Hongbao'));
        $sa->profile_delete('iu001', true);
        $sa->profile_set('iu001', true, array('iu' => 'fz', 'food' => array('noddle')));
        $sa->profile_append('iu001', true, array('food' => array('rice')));
        $sa->profile_increment('iu001', true, array('CardNum' => 1));
        $sa->profile_unset('iu001', true, array('iu'));
        // IDM
//        $sa = new SensorsAnalytics(new FileConsumer('idm.log'));
        $sa -> profile_delete_by_id($identity);
        $sa -> bind($identity);
        $sa -> unbind($unbind_identity);
        $sa -> track_by_id($identity,'idm_test',array('food' => 'rice'));
        $sa -> profile_set_by_id($profile_identity, array('iu' => 'fz', 'food' => array('noddle')));
        $sa -> profile_set_once_by_id($profile_identity, array('food' => array('rice')));
        $sa -> profile_append_by_id($profile_identity,array('food' => array('rice')));
        $sa -> profile_increment_by_id($profile_identity, array('CardNum' => 1));
        $sa -> profile_unset_by_id($profile_identity, array('iu'));
        print_r( $sa->flush());
    }




}
