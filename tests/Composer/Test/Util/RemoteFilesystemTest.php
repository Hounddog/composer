<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Test\Util;

use Composer\Util\RemoteFilesystem;
use Composer\Test\TestCase;

class RemoteFilesystemTest extends \PHPUnit_Framework_TestCase
{
    public function testGetOptionsForUrl()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthorization')
            ->will($this->returnValue(false))
        ;

        $res = $this->callGetOptionsForUrl($io, array('http://example.org'));
        $this->assertTrue(isset($res['http']['header']) && false !== strpos($res['http']['header'], 'User-Agent'), 'getOptions must return an array with a header containing a User-Agent');
    }

    public function testGetOptionsForUrlWithAuthorization()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('hasAuthorization')
            ->will($this->returnValue(true))
        ;
        $io
            ->expects($this->once())
            ->method('getAuthorization')
            ->will($this->returnValue(array('username' => 'login', 'password' => 'password')))
        ;

        $options = $this->callGetOptionsForUrl($io, array('http://example.org'));
        $this->assertContains('Authorization: Basic', $options['http']['header']);
    }

    public function testCallbackGetFileSize()
    {
        $fs = new RemoteFilesystem($this->getMock('Composer\IO\IOInterface'));
        $this->callCallbackGet($fs, STREAM_NOTIFY_FILE_SIZE_IS, 0, '', 0, 0, 20);
        $this->assertAttributeEquals(20, 'bytesMax', $fs);
    }

    public function testCallbackGetNotifyProgress()
    {
        $io = $this->getMock('Composer\IO\IOInterface');
        $io
            ->expects($this->once())
            ->method('overwrite')
        ;

        $fs = new RemoteFilesystem($io);
        $this->setAttribute($fs, 'bytesMax', 20);
        $this->setAttribute($fs, 'progress', true);

        $this->callCallbackGet($fs, STREAM_NOTIFY_PROGRESS, 0, '', 0, 10, 20);
        $this->assertAttributeEquals(50, 'lastProgress', $fs);
    }

    public function testCallbackGetNotifyFailure404()
    {
        $fs = new RemoteFilesystem($this->getMock('Composer\IO\IOInterface'));

        try {
            $this->callCallbackGet($fs, STREAM_NOTIFY_FAILURE, 0, 'HTTP/1.1 404 Not Found', 404, 0, 0);
            $this->fail();
        } catch (\Exception $e) {
            $this->assertInstanceOf('Composer\Downloader\TransportException', $e);
            $this->assertEquals(404, $e->getCode());
            $this->assertContains('HTTP/1.1 404 Not Found', $e->getMessage());
        }
    }

    public function testGetContents()
    {
        $fs = new RemoteFilesystem($this->getMock('Composer\IO\IOInterface'));

        $this->assertContains('testGetContents', $fs->getContents('http://example.org', 'file://'.__FILE__));
    }

    public function testCopy()
    {
        $fs = new RemoteFilesystem($this->getMock('Composer\IO\IOInterface'));

        $file = tempnam(sys_get_temp_dir(), 'c');
        $this->assertTrue($fs->copy('http://example.org', 'file://'.__FILE__, $file));
        $this->assertFileExists($file);
        $this->assertContains('testCopy', file_get_contents($file));
        unlink($file);
    }

    protected function callGetOptionsForUrl($io, array $args = array())
    {
        $fs = new RemoteFilesystem($io);
        $ref = new \ReflectionMethod($fs, 'getOptionsForUrl');
        $ref->setAccessible(true);

        return $ref->invokeArgs($fs, $args);
    }

    protected function callCallbackGet(RemoteFilesystem $fs, $notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax)
    {
        $ref = new \ReflectionMethod($fs, 'callbackGet');
        $ref->setAccessible(true);
        $ref->invoke($fs, $notificationCode, $severity, $message, $messageCode, $bytesTransferred, $bytesMax);
    }

    protected function setAttribute($object, $attribute, $value)
    {
        $attr = new \ReflectionProperty($object, $attribute);
        $attr->setAccessible(true);
        $attr->setValue($object, $value);
    }
}
