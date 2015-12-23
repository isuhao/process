<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Process\Tests;

use Symfony\Component\Process\Exception\LogicException;
use Symfony\Component\Process\PhpExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Process\Exception\RuntimeException;
use Symfony\Component\Process\ProcessPipes;

/**
 * @author Robert Schönthal <seroscho@googlemail.com>
 */
class ProcessTest extends \PHPUnit_Framework_TestCase
{
    private static $phpBin;
    private static $sigchild;
    private static $notEnhancedSigchild = false;

    public static function setUpBeforeClass()
    {
        $phpBin = new PhpExecutableFinder();
        self::$phpBin = 'phpdbg' === PHP_SAPI ? 'php' : $phpBin->find();
        if ('\\' !== DIRECTORY_SEPARATOR) {
            // exec is mandatory to deal with sending a signal to the process
            // see https://github.com/symfony/symfony/issues/5030 about prepending
            // command with exec
            self::$phpBin = 'exec '.self::$phpBin;
        }

        ob_start();
        phpinfo(INFO_GENERAL);
        self::$sigchild = false !== strpos(ob_get_clean(), '--enable-sigchild');
    }

    public function testThatProcessDoesNotThrowWarningDuringRun()
    {
        @trigger_error('Test Error', E_USER_NOTICE);
        $process = $this->getProcess(self::$phpBin." -r 'sleep(3)'");
        $process->run();
        $actualError = error_get_last();
        $this->assertEquals('Test Error', $actualError['message']);
        $this->assertEquals(E_USER_NOTICE, $actualError['type']);
    }

    /**
     * @expectedException \Symfony\Component\Process\Exception\InvalidArgumentException
     */
    public function testNegativeTimeoutFromConstructor()
    {
        $this->getProcess('', null, null, null, -1);
    }

    /**
     * @expectedException \Symfony\Component\Process\Exception\InvalidArgumentException
     */
    public function testNegativeTimeoutFromSetter()
    {
        $p = $this->getProcess('');
        $p->setTimeout(-1);
    }

    public function testFloatAndNullTimeout()
    {
        $p = $this->getProcess('');

        $p->setTimeout(10);
        $this->assertSame(10.0, $p->getTimeout());

        $p->setTimeout(null);
        $this->assertNull($p->getTimeout());

        $p->setTimeout(0.0);
        $this->assertNull($p->getTimeout());
    }

    /**
     * @requires extension pcntl
     */
    public function testStopWithTimeoutIsActuallyWorking()
    {
        $p = $this->getProcess(self::$phpBin.' '.__DIR__.'/NonStopableProcess.php 30');
        $p->start();

        while (false === strpos($p->getOutput(), 'received')) {
            usleep(1000);
        }
        $start = microtime(true);
        $p->stop(0.1);

        $p->wait();

        $this->assertLessThan(15, microtime(true) - $start);
    }

    public function testAllOutputIsActuallyReadOnTermination()
    {
        // this code will result in a maximum of 2 reads of 8192 bytes by calling
        // start() and isRunning().  by the time getOutput() is called the process
        // has terminated so the internal pipes array is already empty. normally
        // the call to start() will not read any data as the process will not have
        // generated output, but this is non-deterministic so we must count it as
        // a possibility.  therefore we need 2 * ProcessPipes::CHUNK_SIZE plus
        // another byte which will never be read.
        $expectedOutputSize = ProcessPipes::CHUNK_SIZE * 2 + 2;

        $code = sprintf('echo str_repeat(\'*\', %d);', $expectedOutputSize);
        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg($code)));

        $p->start();

        // Don't call Process::run nor Process::wait to avoid any read of pipes
        $h = new \ReflectionProperty($p, 'process');
        $h->setAccessible(true);
        $h = $h->getValue($p);
        $s = proc_get_status($h);

        while ($s['running']) {
            usleep(1000);
            $s = proc_get_status($h);
        }

        $o = $p->getOutput();

        $this->assertEquals($expectedOutputSize, strlen($o));
    }

    public function testCallbacksAreExecutedWithStart()
    {
        $process = $this->getProcess('echo foo');
        $process->start(function ($type, $buffer) use (&$data) {
            $data .= $buffer;
        });

        $process->wait();

        $this->assertSame('foo'.PHP_EOL, $data);
    }

    /**
     * tests results from sub processes.
     *
     * @dataProvider responsesCodeProvider
     */
    public function testProcessResponses($expected, $getter, $code)
    {
        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg($code)));
        $p->run();

        $this->assertSame($expected, $p->$getter());
    }

    /**
     * tests results from sub processes.
     *
     * @dataProvider pipesCodeProvider
     */
    public function testProcessPipes($code, $size)
    {
        $expected = str_repeat(str_repeat('*', 1024), $size).'!';
        $expectedLength = (1024 * $size) + 1;

        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg($code)));
        $p->setStdin($expected);
        $p->run();

        $this->assertEquals($expectedLength, strlen($p->getOutput()));
        $this->assertEquals($expectedLength, strlen($p->getErrorOutput()));
    }

    /**
     * @expectedException Symfony\Component\Process\Exception\LogicException
     * @expectedExceptionMessage STDIN can not be set while the process is running.
     */
    public function testSetStdinWhileRunningThrowsAnException()
    {
        $process = $this->getProcess(self::$phpBin.' -r "sleep(30);"');
        $process->start();
        try {
            $process->setStdin('foobar');
            $process->stop();
            $this->fail('A LogicException should have been raised.');
        } catch (LogicException $e) {
        }
        $process->stop();

        throw $e;
    }

    /**
     * @dataProvider provideInvalidStdinValues
     * @expectedException \Symfony\Component\Process\Exception\InvalidArgumentException
     * @expectedExceptionMessage Symfony\Component\Process\Process::setStdin only accepts strings.
     */
    public function testInvalidStdin($value)
    {
        $process = $this->getProcess('foo');
        $process->setStdin($value);
    }

    public function provideInvalidStdinValues()
    {
        return array(
            array(array()),
            array(new NonStringifiable()),
            array(fopen('php://temporary', 'w')),
        );
    }

    /**
     * @dataProvider provideStdinValues
     */
    public function testValidStdin($expected, $value)
    {
        $process = $this->getProcess('foo');
        $process->setStdin($value);
        $this->assertSame($expected, $process->getStdin());
    }

    public function provideStdinValues()
    {
        return array(
            array(null, null),
            array('24.5', 24.5),
            array('input data', 'input data'),
            // to maintain BC, supposed to be removed in 3.0
            array('stringifiable', new Stringifiable()),
        );
    }

    public function chainedCommandsOutputProvider()
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            return array(
                array("2 \r\n2\r\n", '&&', '2'),
            );
        }

        return array(
            array("1\n1\n", ';', '1'),
            array("2\n2\n", '&&', '2'),
        );
    }

    /**
     * @dataProvider chainedCommandsOutputProvider
     */
    public function testChainedCommandsOutput($expected, $operator, $input)
    {
        $process = $this->getProcess(sprintf('echo %s %s echo %s', $input, $operator, $input));
        $process->run();
        $this->assertEquals($expected, $process->getOutput());
    }

    public function testCallbackIsExecutedForOutput()
    {
        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg('echo \'foo\';')));

        $called = false;
        $p->run(function ($type, $buffer) use (&$called) {
            $called = $buffer === 'foo';
        });

        $this->assertTrue($called, 'The callback should be executed with the output');
    }

    public function testGetErrorOutput()
    {
        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg('$n = 0; while ($n < 3) { file_put_contents(\'php://stderr\', \'ERROR\'); $n++; }')));

        $p->run();
        $this->assertEquals(3, preg_match_all('/ERROR/', $p->getErrorOutput(), $matches));
    }

    public function testGetIncrementalErrorOutput()
    {
        // use a lock file to toggle between writing ("W") and reading ("R") the
        // error stream
        $lock = tempnam(sys_get_temp_dir(), get_class($this).'Lock');
        file_put_contents($lock, 'W');

        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg('$n = 0; while ($n < 3) { if (\'W\' === file_get_contents('.var_export($lock, true).')) { file_put_contents(\'php://stderr\', \'ERROR\'); $n++; file_put_contents('.var_export($lock, true).', \'R\'); } usleep(100); }')));

        $p->start();
        while ($p->isRunning()) {
            if ('R' === file_get_contents($lock)) {
                $this->assertLessThanOrEqual(1, preg_match_all('/ERROR/', $p->getIncrementalErrorOutput(), $matches));
                file_put_contents($lock, 'W');
            }
            usleep(100);
        }

        unlink($lock);
    }

    public function testGetEmptyIncrementalErrorOutput()
    {
        // use a lock file to toggle between writing ("W") and reading ("R") the
        // output stream
        $lock = tempnam(sys_get_temp_dir(), get_class($this).'Lock');
        file_put_contents($lock, 'W');

        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg('$n = 0; while ($n < 3) { if (\'W\' === file_get_contents('.var_export($lock, true).')) { file_put_contents(\'php://stderr\', \'ERROR\'); $n++; file_put_contents('.var_export($lock, true).', \'R\'); } usleep(100); }')));

        $p->start();

        $shouldWrite = false;

        while ($p->isRunning()) {
            if ('R' === file_get_contents($lock)) {
                if (!$shouldWrite) {
                    $this->assertLessThanOrEqual(1, preg_match_all('/ERROR/', $p->getIncrementalOutput(), $matches));
                    $shouldWrite = true;
                } else {
                    $this->assertSame('', $p->getIncrementalOutput());

                    file_put_contents($lock, 'W');
                    $shouldWrite = false;
                }
            }
            usleep(100);
        }

        unlink($lock);
    }

    public function testGetOutput()
    {
        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg('$n = 0; while ($n < 3) { echo \' foo \'; $n++; }')));

        $p->run();
        $this->assertEquals(3, preg_match_all('/foo/', $p->getOutput(), $matches));
    }

    public function testGetIncrementalOutput()
    {
        // use a lock file to toggle between writing ("W") and reading ("R") the
        // output stream
        $lock = tempnam(sys_get_temp_dir(), get_class($this).'Lock');
        file_put_contents($lock, 'W');

        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg('$n = 0; while ($n < 3) { if (\'W\' === file_get_contents('.var_export($lock, true).')) { echo \' foo \'; $n++; file_put_contents('.var_export($lock, true).', \'R\'); } usleep(100); }')));

        $p->start();
        while ($p->isRunning()) {
            if ('R' === file_get_contents($lock)) {
                $this->assertLessThanOrEqual(1, preg_match_all('/foo/', $p->getIncrementalOutput(), $matches));
                file_put_contents($lock, 'W');
            }
            usleep(100);
        }

        unlink($lock);
    }

    public function testGetEmptyIncrementalOutput()
    {
        // use a lock file to toggle between writing ("W") and reading ("R") the
        // output stream
        $lock = tempnam(sys_get_temp_dir(), get_class($this).'Lock');
        file_put_contents($lock, 'W');

        $p = $this->getProcess(sprintf('%s -r %s', self::$phpBin, escapeshellarg('$n = 0; while ($n < 3) { if (\'W\' === file_get_contents('.var_export($lock, true).')) { echo \' foo \'; $n++; file_put_contents('.var_export($lock, true).', \'R\'); } usleep(100); }')));

        $p->start();

        $shouldWrite = false;

        while ($p->isRunning()) {
            if ('R' === file_get_contents($lock)) {
                if (!$shouldWrite) {
                    $this->assertLessThanOrEqual(1, preg_match_all('/foo/', $p->getIncrementalOutput(), $matches));
                    $shouldWrite = true;
                } else {
                    $this->assertSame('', $p->getIncrementalOutput());

                    file_put_contents($lock, 'W');
                    $shouldWrite = false;
                }
            }
            usleep(100);
        }

        unlink($lock);
    }

    public function testZeroAsOutput()
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            // see http://stackoverflow.com/questions/7105433/windows-batch-echo-without-new-line
            $p = $this->getProcess('echo | set /p dummyName=0');
        } else {
            $p = $this->getProcess('printf 0');
        }

        $p->run();
        $this->assertSame('0', $p->getOutput());
    }

    public function testExitCodeCommandFailed()
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('Windows does not support POSIX exit code');
        }
        $this->skipIfNotEnhancedSigchild();

        // such command run in bash return an exitcode 127
        $process = $this->getProcess('nonexistingcommandIhopeneversomeonewouldnameacommandlikethis');
        $process->run();

        $this->assertGreaterThan(0, $process->getExitCode());
    }

    public function testTTYCommand()
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('Windows does not have /dev/tty support');
        }

        $process = $this->getProcess('echo "foo" >> /dev/null && '.self::$phpBin.' -r "usleep(100000);"');
        $process->setTty(true);
        $process->start();
        $this->assertTrue($process->isRunning());
        $process->wait();

        $this->assertSame(Process::STATUS_TERMINATED, $process->getStatus());
    }

    public function testTTYCommandExitCode()
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('Windows does have /dev/tty support');
        }
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess('echo "foo" >> /dev/null');
        $process->setTty(true);
        $process->run();

        $this->assertTrue($process->isSuccessful());
    }

    /**
     * @expectedException \Symfony\Component\Process\Exception\RuntimeException
     * @expectedExceptionMessage TTY mode is not supported on Windows platform.
     */
    public function testTTYInWindowsEnvironment()
    {
        if ('\\' !== DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('This test is for Windows platform only');
        }

        $process = $this->getProcess('echo "foo" >> /dev/null');
        $process->setTty(false);
        $process->setTty(true);
    }

    public function testExitCodeTextIsNullWhenExitCodeIsNull()
    {
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess('');
        $this->assertNull($process->getExitCodeText());
    }

    public function testExitCodeText()
    {
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess('');
        $r = new \ReflectionObject($process);
        $p = $r->getProperty('exitcode');
        $p->setAccessible(true);

        $p->setValue($process, 2);
        $this->assertEquals('Misuse of shell builtins', $process->getExitCodeText());
    }

    public function testStartIsNonBlocking()
    {
        $process = $this->getProcess(self::$phpBin.' -r "usleep(500000);"');
        $start = microtime(true);
        $process->start();
        $end = microtime(true);
        $this->assertLessThan(0.4, $end - $start);
        $process->stop();
    }

    public function testUpdateStatus()
    {
        $process = $this->getProcess('echo foo');
        $process->run();
        $this->assertTrue(strlen($process->getOutput()) > 0);
    }

    public function testGetExitCodeIsNullOnStart()
    {
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess(self::$phpBin.' -r "usleep(100000);"');
        $this->assertNull($process->getExitCode());
        $process->start();
        $this->assertNull($process->getExitCode());
        $process->wait();
        $this->assertEquals(0, $process->getExitCode());
    }

    public function testGetExitCodeIsNullOnWhenStartingAgain()
    {
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess(self::$phpBin.' -r "usleep(100000);"');
        $process->run();
        $this->assertEquals(0, $process->getExitCode());
        $process->start();
        $this->assertNull($process->getExitCode());
        $process->wait();
        $this->assertEquals(0, $process->getExitCode());
    }

    public function testGetExitCode()
    {
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess('echo foo');
        $process->run();
        $this->assertSame(0, $process->getExitCode());
    }

    public function testStatus()
    {
        $process = $this->getProcess(self::$phpBin.' -r "usleep(100000);"');
        $this->assertFalse($process->isRunning());
        $this->assertFalse($process->isStarted());
        $this->assertFalse($process->isTerminated());
        $this->assertSame(Process::STATUS_READY, $process->getStatus());
        $process->start();
        $this->assertTrue($process->isRunning());
        $this->assertTrue($process->isStarted());
        $this->assertFalse($process->isTerminated());
        $this->assertSame(Process::STATUS_STARTED, $process->getStatus());
        $process->wait();
        $this->assertFalse($process->isRunning());
        $this->assertTrue($process->isStarted());
        $this->assertTrue($process->isTerminated());
        $this->assertSame(Process::STATUS_TERMINATED, $process->getStatus());
    }

    public function testStop()
    {
        $process = $this->getProcess(self::$phpBin.' -r "sleep(31);"');
        $process->start();
        $this->assertTrue($process->isRunning());
        $process->stop();
        $this->assertFalse($process->isRunning());
    }

    public function testIsSuccessful()
    {
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess('echo foo');
        $process->run();
        $this->assertTrue($process->isSuccessful());
    }

    public function testIsSuccessfulOnlyAfterTerminated()
    {
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess(self::$phpBin.' -r "usleep(100000);"');
        $process->start();

        $this->assertFalse($process->isSuccessful());

        $process->wait();

        $this->assertTrue($process->isSuccessful());
    }

    public function testIsNotSuccessful()
    {
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess(self::$phpBin.' -r "throw new \Exception(\'BOUM\');"');
        $process->run();
        $this->assertFalse($process->isSuccessful());
    }

    public function testProcessIsNotSignaled()
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('Windows does not support POSIX signals');
        }
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess('echo foo');
        $process->run();
        $this->assertFalse($process->hasBeenSignaled());
    }

    public function testProcessWithoutTermSignal()
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('Windows does not support POSIX signals');
        }
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess('echo foo');
        $process->run();
        $this->assertEquals(0, $process->getTermSignal());
    }

    public function testProcessIsSignaledIfStopped()
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('Windows does not support POSIX signals');
        }
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess(self::$phpBin.' -r "sleep(32);"');
        $process->start();
        $process->stop();
        $this->assertTrue($process->hasBeenSignaled());
        $this->assertEquals(15, $process->getTermSignal()); // SIGTERM
    }

    /**
     * @expectedException \Symfony\Component\Process\Exception\RuntimeException
     * @expectedExceptionMessage The process has been signaled
     */
    public function testProcessThrowsExceptionWhenExternallySignaled()
    {
        if (!function_exists('posix_kill')) {
            $this->markTestSkipped('Function posix_kill is required.');
        }
        $this->skipIfNotEnhancedSigchild(false);

        $process = $this->getProcess(self::$phpBin.' -r "sleep(32.1)"');
        $process->start();
        posix_kill($process->getPid(), 9); // SIGKILL

        $process->wait();
    }

    public function testRestart()
    {
        $process1 = $this->getProcess(self::$phpBin.' -r "echo getmypid();"');
        $process1->run();
        $process2 = $process1->restart();

        $process2->wait(); // wait for output

        // Ensure that both processed finished and the output is numeric
        $this->assertFalse($process1->isRunning());
        $this->assertFalse($process2->isRunning());
        $this->assertTrue(is_numeric($process1->getOutput()));
        $this->assertTrue(is_numeric($process2->getOutput()));

        // Ensure that restart returned a new process by check that the output is different
        $this->assertNotEquals($process1->getOutput(), $process2->getOutput());
    }

    /**
     * @expectedException Symfony\Component\Process\Exception\RuntimeException
     * @expectedExceptionMessage The process timed-out.
     */
    public function testRunProcessWithTimeout()
    {
        $timeout = 0.1;
        $process = $this->getProcess(self::$phpBin.' -r "sleep(1);"');
        $process->setTimeout($timeout);
        $start = microtime(true);
        try {
            $process->run();
            $this->fail('A RuntimeException should have been raised');
        } catch (RuntimeException $e) {
        }
        $duration = microtime(true) - $start;

        if ('\\' !== DIRECTORY_SEPARATOR) {
            // On Windows, timers are too transient
            $maxDuration = $timeout + Process::TIMEOUT_PRECISION;
            $this->assertLessThan($maxDuration, $duration);
        }

        throw $e;
    }

    public function testCheckTimeoutOnNonStartedProcess()
    {
        $process = $this->getProcess('echo foo');
        $this->assertNull($process->checkTimeout());
    }

    public function testCheckTimeoutOnTerminatedProcess()
    {
        $process = $this->getProcess('echo foo');
        $process->run();
        $this->assertNull($process->checkTimeout());
    }

    /**
     * @expectedException Symfony\Component\Process\Exception\RuntimeException
     * @expectedExceptionMessage The process timed-out.
     */
    public function testCheckTimeoutOnStartedProcess()
    {
        $process = $this->getProcess(self::$phpBin.' -r "sleep(33);"');
        $process->setTimeout(0.1);

        $process->start();
        $start = microtime(true);

        try {
            while ($process->isRunning()) {
                $process->checkTimeout();
                usleep(100000);
            }
            $this->fail('A RuntimeException should have been raised');
        } catch (RuntimeException $e) {
        }

        $this->assertLessThan(15, microtime(true) - $start);

        throw $e;
    }

    /**
     * @expectedException Symfony\Component\Process\Exception\RuntimeException
     * @expectedExceptionMessage The process timed-out.
     */
    public function testStartAfterATimeout()
    {
        $process = $this->getProcess(self::$phpBin.' -r "sleep(35);"');
        $process->setTimeout(0.1);
        try {
            $process->run();
            $this->fail('A RuntimeException should have been raised.');
        } catch (RuntimeException $e) {
        }
        $this->assertFalse($process->isRunning());
        $process->start();
        $process->stop(0);

        throw $e;
    }

    public function testGetPid()
    {
        $process = $this->getProcess(self::$phpBin.' -r "sleep(36);"');
        $process->start();
        $this->assertGreaterThan(0, $process->getPid());
        $process->stop(0);
    }

    public function testGetPidIsNullBeforeStart()
    {
        $process = $this->getProcess('foo');
        $this->assertNull($process->getPid());
    }

    public function testGetPidIsNullAfterRun()
    {
        $process = $this->getProcess('echo foo');
        $process->run();
        $this->assertNull($process->getPid());
    }

    /**
     * @requires extension pcntl
     */
    public function testSignal()
    {
        $process = $this->getProcess(self::$phpBin.' '.__DIR__.'/SignalListener.php');
        $process->start();

        while (false === strpos($process->getOutput(), 'Caught')) {
            usleep(1000);
        }
        $process->signal(SIGUSR1);
        $process->wait();

        $this->assertEquals('Caught SIGUSR1', $process->getOutput());
    }

    /**
     * @requires extension pcntl
     */
    public function testExitCodeIsAvailableAfterSignal()
    {
        $this->skipIfNotEnhancedSigchild();

        $process = $this->getProcess('sleep 4');
        $process->start();
        $process->signal(SIGKILL);

        while ($process->isRunning()) {
            usleep(10000);
        }

        $this->assertFalse($process->isRunning());
        $this->assertTrue($process->hasBeenSignaled());
        $this->assertFalse($process->isSuccessful());
        $this->assertEquals(137, $process->getExitCode());
    }

    /**
     * @expectedException \Symfony\Component\Process\Exception\LogicException
     * @expectedExceptionMessage Can not send signal on a non running process.
     */
    public function testSignalProcessNotRunning()
    {
        $process = $this->getProcess('foo');
        $process->signal(1); // SIGHUP
    }

    /**
     * @dataProvider provideMethodsThatNeedARunningProcess
     */
    public function testMethodsThatNeedARunningProcess($method)
    {
        $process = $this->getProcess('foo');
        $this->setExpectedException('Symfony\Component\Process\Exception\LogicException', sprintf('Process must be started before calling %s.', $method));
        $process->{$method}();
    }

    public function provideMethodsThatNeedARunningProcess()
    {
        return array(
            array('getOutput'),
            array('getIncrementalOutput'),
            array('getErrorOutput'),
            array('getIncrementalErrorOutput'),
            array('wait'),
        );
    }

    /**
     * @dataProvider provideMethodsThatNeedATerminatedProcess
     * @expectedException Symfony\Component\Process\Exception\LogicException
     * @expectedExceptionMessage Process must be terminated before calling
     */
    public function testMethodsThatNeedATerminatedProcess($method)
    {
        $process = $this->getProcess(self::$phpBin.' -r "sleep(37);"');
        $process->start();
        try {
            $process->{$method}();
            $process->stop(0);
            $this->fail('A LogicException must have been thrown');
        } catch (\Exception $e) {
        }
        $process->stop(0);

        throw $e;
    }

    public function provideMethodsThatNeedATerminatedProcess()
    {
        return array(
            array('hasBeenSignaled'),
            array('getTermSignal'),
            array('hasBeenStopped'),
            array('getStopSignal'),
        );
    }

    /**
     * @dataProvider provideWrongSignal
     * @expectedException \Symfony\Component\Process\Exception\RuntimeException
     */
    public function testWrongSignal($signal)
    {
        if ('\\' === DIRECTORY_SEPARATOR) {
            $this->markTestSkipped('POSIX signals do not work on Windows');
        }

        $process = $this->getProcess(self::$phpBin.' -r "sleep(38);"');
        $process->start();
        try {
            $process->signal($signal);
            $this->fail('A RuntimeException must have been thrown');
        } catch (RuntimeException $e) {
            $process->stop(0);
        }

        throw $e;
    }

    public function provideWrongSignal()
    {
        return array(
            array(-4),
            array('Céphalopodes'),
        );
    }

    public function testStopTerminatesProcessCleanly()
    {
        $process = $this->getProcess(self::$phpBin.' -r "echo 123; sleep(42);"');
        $process->run(function () use ($process) {
            $process->stop();
        });
        $this->assertTrue(true, 'A call to stop() is not expected to cause wait() to throw a RuntimeException');
    }

    public function testKillSignalTerminatesProcessCleanly()
    {
        $process = $this->getProcess(self::$phpBin.' -r "echo 123; sleep(43);"');
        $process->run(function () use ($process) {
            $process->signal(9); // SIGKILL
        });
        $this->assertTrue(true, 'A call to signal() is not expected to cause wait() to throw a RuntimeException');
    }

    public function testTermSignalTerminatesProcessCleanly()
    {
        $process = $this->getProcess(self::$phpBin.' -r "echo 123; sleep(44);"');
        $process->run(function () use ($process) {
            $process->signal(15); // SIGTERM
        });
        $this->assertTrue(true, 'A call to signal() is not expected to cause wait() to throw a RuntimeException');
    }

    public function responsesCodeProvider()
    {
        return array(
            //expected output / getter / code to execute
            //array(1,'getExitCode','exit(1);'),
            //array(true,'isSuccessful','exit();'),
            array('output', 'getOutput', 'echo \'output\';'),
        );
    }

    public function pipesCodeProvider()
    {
        $variations = array(
            'fwrite(STDOUT, $in = file_get_contents(\'php://stdin\')); fwrite(STDERR, $in);',
            'include \''.__DIR__.'/PipeStdinInStdoutStdErrStreamSelect.php\';',
        );

        if ('\\' === DIRECTORY_SEPARATOR) {
            // Avoid XL buffers on Windows because of https://bugs.php.net/bug.php?id=65650
            $sizes = array(1, 2, 4, 8);
        } else {
            $sizes = array(1, 16, 64, 1024, 4096);
        }

        $codes = array();
        foreach ($sizes as $size) {
            foreach ($variations as $code) {
                $codes[] = array($code, $size);
            }
        }

        return $codes;
    }

    /**
     * provides default method names for simple getter/setter.
     */
    public function methodProvider()
    {
        $defaults = array(
            array('CommandLine'),
            array('Timeout'),
            array('WorkingDirectory'),
            array('Env'),
            array('Stdin'),
            array('Options'),
        );

        return $defaults;
    }

    /**
     * @param string $commandline
     * @param null   $cwd
     * @param array  $env
     * @param null   $stdin
     * @param int    $timeout
     * @param array  $options
     *
     * @return Process
     */
    private function getProcess($commandline, $cwd = null, array $env = null, $stdin = null, $timeout = 60, array $options = array())
    {
        $process = new Process($commandline, $cwd, $env, $stdin, $timeout, $options);

        if (false !== $enhance = getenv('ENHANCE_SIGCHLD')) {
            try {
                $process->setEnhanceSigchildCompatibility(false);
                $process->getExitCode();
                $this->fail('ENHANCE_SIGCHLD must be used together with a sigchild-enabled PHP.');
            } catch (RuntimeException $e) {
                $this->assertSame('This PHP has been compiled with --enable-sigchild. You must use setEnhanceSigchildCompatibility() to use this method.', $e->getMessage());
                if ($enhance) {
                    $process->setEnhanceSigChildCompatibility(true);
                } else {
                    self::$notEnhancedSigchild = true;
                }
            }
        }

        return $process;
    }

    private function skipIfNotEnhancedSigchild($expectException = true)
    {
        if (self::$sigchild) {
            if (!$expectException) {
                $this->markTestSkipped('PHP is compiled with --enable-sigchild.');
            } elseif (self::$notEnhancedSigchild) {
                $this->setExpectedException('Symfony\Component\Process\Exception\RuntimeException', 'This PHP has been compiled with --enable-sigchild.');
            }
        }
    }
}

class Stringifiable
{
    public function __toString()
    {
        return 'stringifiable';
    }
}

class NonStringifiable
{
}
