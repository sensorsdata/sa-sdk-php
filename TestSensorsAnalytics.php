<?php
require_once("SensorsAnalytics.php");

class NormalTest extends PHPUnit_Framework_TestCase {
    public function setUp() {
    }

    public function tearDown() {
    }

    public function testFileConsumer() {
        $test_file = "php_sdk_test";
        $consumer = new FileConsumer($test_file);
        $sa = new SensorsAnalytics($consumer);
        $now = (int)(microtime(true) * 1000);
        $sa->track('1234', 'Test', array('From' => 'Baidu', '$time' => $now));
        $sa->track_signup('1234', 'abcd', 'Signup', array('Channel' => 'Hongbao'));
        $sa->profile_delete('1234');
        $sa->profile_append('1234', array('Gender' => 'Male'));
        $sa->profile_increment('1234', array('CardNum' => 1));
        $sa->profile_set('1234', array('City' => '北京'));
        $sa->profile_unset('1234', array('City'));
        $sa->profile_unset('1234', array('Province' => true));
        $dt = new DateTime();
        $dt->setTimestamp($now / 1000.0);
        $sa->profile_set('1234', array('$signup_time' => $dt));
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

    public function testNormal() {
        $stub_consumer = $this->getMockBuilder('BatchConsumer')
            ->setConstructorArgs(array(""))
            ->setMethods(array("_do_request"))
            ->getMock();
        $stub_consumer->method('_do_request')->will(
            $this->returnCallback(array($this, '_mock_do_request')));
        $sa = new SensorsAnalytics($stub_consumer);
        $this->_msg_count = 0;
        $sa->track(1234, 'Test', array('From' => 'Baidu'));
        $sa->track(1234, 'Test', array('From' => 'Baidu', '$time' => 1437816376));
        $sa->track(1234, 'Test', array('From' => 'Baidu', '$time' => 1437816376000));
        $sa->track(1234, 'Test', array('From' => 'Baidu', '$time' => '1437816376'));
        $sa->track(1234, 'Test', array('From' => 'Baidu', '$time' => '1437816376000'));
        $sa->track(1234, 'Tes123_$t', array('From' => 'Baidu', '$time' => '1437816376000'));
        $sa->track(1234, 'Tes123_$t', array('From' => 'Baidu', '$time' => '1437816376000', 'Test' => 1437816376000999933));
        $sa->profile_set(1234, array('From' => 'Baidu'));
        $sa->profile_set(1234, array('From' => 'Baidu', 'asd' => array("asd", "bbb")));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property \[distinct_id\] must not be empty.*#
     */
    public function testException1() {
        $sa = new SensorsAnalytics(null);
        $sa->track(null, 'test', array('from' => 'baidu'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*must be a timestamp in microseconds.*#
     */
    public function testException2() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, 'Test', array('From' => 'Baidu', '$time' => 1234));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property key must be a str.*#
     */
    public function testException3() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, 'Test', array(123 => 'Baidu'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*event name must be a valid variable nam.*#
     */
    public function testException4() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, 'Test 123', array(123 => 'Baidu'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property value must be a str.*#
     */
    public function testException5() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, 'TestEvent', array('TestProperty' => new SensorsAnalytics(null)));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property key must be a valid variable name.*#
     */
    public function testException6() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, 'Test', array('distincT_id' => 'SensorsData'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property key must be a valid variable name.*#
     */
    public function testException7() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, 'TestEvent', array('a123456789a123456789a123456789a123456789a123456789a123456789a123456789a123456789a123456789a1234567890' => 'SensorsData'));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property's value must be a str.*#
     */
    public function testException8() {
        $sa = new SensorsAnalytics(null);
        $sa->track(1234, 'TestEvent', array('TestProperty' => array(123)));
    }

    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #.*property must not be associative.*#
     */
    public function testException9() {
        $sa = new SensorsAnalytics(null);
        $a = array("b" => 123);
        $c = array(123);
        $sa->track(1234, 'TestEvent', array('TestProperty' => array("a" => 123)));
    }


    /**
     * @expectedException    SensorsAnalyticsIllegalDataException
     * @expectedExceptionMessageRegExp #the max length of \[distinct_id\] is 255#
     */
    public function testException10() {
        $sa = new SensorsAnalytics(null);
        $sa->track('abcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyzabcdefghijklmnopqrstuvwxyz', 'TestEvent', array('test_key' => 'SensorsData'));
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
        $sa->track('1234', 'Test', array('From' => 'Baidu'));
        $sa->track_signup('1234', 'abcd', 'Signup', array('Channel' => 'Hongbao'));
        $sa->profile_delete('1234');
        $sa->profile_append('1234', array('Gender' => 'Male'));
        $sa->profile_increment('1234', array('CardNum' => 1));
        $sa->profile_set('1234', array('City' => '北京'));
        $sa->profile_unset('1234', array('City'));
        $this->assertEquals($this->_msg_count, 0);
        $sa->flush();
        $this->assertEquals($this->_msg_count, 7);
        for ($i = 0; $i < 49; $i++) {
            $sa->profile_set('1234', array('City' => '北京'));
        }
        $this->assertEquals($this->_msg_count, 7);
        $sa->profile_set('1234', array('City' => '北京'));
        $this->assertEquals($this->_msg_count, 57);
    }

    public function testBatchConsumer() {
        $consumer = new BatchConsumer("http://git.sensorsdata.cn/test");
        $sa = new SensorsAnalytics($consumer);
        $sa->track('1234', 'Test', array('From' => 'Baidu'));
        $sa->track_signup('1234', 'abcd', 'Signup', array('Channel' => 'Hongbao'));
        $sa->profile_delete('1234');
        $sa->profile_append('1234', array('Gender' => 'Male'));
        $sa->profile_increment('1234', array('CardNum' => 1));
        $sa->profile_set('1234', array('City' => '北京'));
        $sa->profile_unset('1234', array('City'));
        $sa->flush();
        for ($i = 0; $i < 49; $i++) {
            $sa->profile_set('1234', array('City' => '北京'));
        }
        $sa->profile_set('1234', array('City' => '北京'));
        $sa->close();
    }

    public function testDebugConsumer() {
        $consumer = new DebugConsumer('http://10.10.229.134:8001/debug', false);
        $sa = new SensorsAnalytics($consumer);
        $sa->track('1234', 'Test', array('PhpTestProperty' => 'Baidu'));
        $consumer = new DebugConsumer('http://10.10.229.134:8001/debug', true);
        $sa = new SensorsAnalytics($consumer);
        $sa->track('1234', 'Test', array('PhpTestProperty' => 123));
        $sa->track('1234', 'Test', array('PhpTestProperty' => 'Baidu'));
    }
}
