<?php

/**
 * Generic class for just-in-time daemonized services.
 */

abstract class BackgroundService {
  protected $pid_file_name;
  protected $output_file_name;

  protected function startProcess($command) {
    echo 'Starting background service: ' . $command . PHP_EOL;

    $this->pid_file_name = tempnam(sys_get_temp_dir(), 'background-service-') . '.pid';
    $this->output_file_name = tempnam(sys_get_temp_dir(), 'background-service-') . '.out';
    exec(sprintf("%s > %s 2>&1 & echo $! > %s", $command, $this->output_file_name, $this->pid_file_name));

    $remaining_tries = 45;
    while ($remaining_tries > 0 && !$this->isReady()) {
      sleep(1);
      --$remaining_tries;
    }

    if ($remaining_tries == 0) {
      echo 'Background service failed:' . PHP_EOL;
      echo $this->getOutput();
      throw new Exception('Background service did not start.');
    }
  }

  protected function isReady() {
    return TRUE;
  }

  public function getOutput() {
    return file_get_contents($this->output_file_name);
  }

  public function getPid() {
    return isset($this->pid_file_name) ? file_get_contents($this->pid_file_name) : NULL;
  }

  public function __destruct() {
    $pid = $this->getPid();
    if (isset($pid)) {
      exec('kill ' . $pid);
      unlink($this->pid_file_name);
      unlink(sys_get_temp_dir() .'/'. basename($this->pid_file_name, '.pid'));
    }
    unlink(sys_get_temp_dir() .'/'. basename($this->output_file_name, '.out'));
    unlink($this->output_file_name);
  }
}

/**
 * Interface for an X Windows server.
 */
interface XWindowsServiceInterface {
  public function getDisplay();

  public function getWidth();

  public function getHeight();
}

/**
 * Starts an X Windows virtual frame buffer.
 */
class XvfbBackgroundService extends BackgroundService implements XWindowsServiceInterface {
  protected $displayNumber;
  protected $width;
  protected $height;

  public function __construct($display_number, $width = 1600, $height = 1200) {
    $this->displayNumber = $display_number;
    $this->width         = $width;
    $this->height        = $height;

    $command = '/usr/bin/Xvfb :' . $this->displayNumber . ' -ac -screen 0 ' . $this->width . 'x' . $this->height . 'x24';
    $this->startProcess($command);
  }

  public function getDisplay() {
    return ':' . $this->displayNumber;
  }

  public function getWidth() {
    return $this->width;
  }

  public function getHeight() {
    return $this->height;
  }
}

interface SeleniumServiceInterface {
  public function getHost();

  public function getPort();
}

/**
 * Starts an instance of Selenium RC.
 */
class SeleniumBackgroundService extends BackgroundService implements SeleniumServiceInterface {
  protected $port;

  public function __construct(XWindowsServiceInterface $display, $port) {
    $this->port = $port;

    $command = 'DISPLAY="' . $display->getDisplay() . '" && java -jar ~/selenium-server/selenium-server-standalone.jar -firefoxProfileTemplate '. __DIR__ .'/xuxlsd9y.selenium -browserSideLog -log '. $_SERVER['argv'][2] .'/selenium.log -port ' . $this->port; 

    $this->startProcess($command);
  }

  public function getHost() {
    return '127.0.0.1';
  }

  public function getPort() {
    return $this->port;
  }

  protected function isReady() {
    return selenium_is_running($this->getHost(), $this->getPort());
  }
}

class SeleniumExternalService implements SeleniumServiceInterface {
  protected $host;
  protected $port;

  public function __construct($host, $port) {
    $this->host = $host;
    $this->port = $port;
  }

  public function getHost() {
    return $this->host;
  }

  public function getPort() {
    return $this->port;
  }
}

/**
 * Starts an X Windows virtual frame buffer.
 */
class ScreencastBackgroundService extends BackgroundService {
  public function __construct(XWindowsServiceInterface $display, $video_file_name) {
    $command = 'ffmpeg -an -f x11grab -y -r 50 -s ' . $display->getWidth() . 'x' . $display->getHeight() . ' -i ' . $display->getDisplay() . '.0+0,0 -vcodec mpeg4 -sameq ' . $video_file_name;
    $this->startProcess($command);
  }
  public function __destruct() {
    $pid = $this->getPid();
    if (isset($pid)) {
      sleep(3);
      exec('kill ' . $pid);
      unlink($this->pid_file_name);
      unlink(sys_get_temp_dir() .'/'. basename($this->pid_file_name, '.pid'));
      sleep(5);
    }
    unlink(sys_get_temp_dir() .'/'. basename($this->output_file_name, '.out'));
    unlink($this->output_file_name);
  }
}

class SeleniumInvalidTestException extends Exception {}

class SeleniumTest {
  protected $processedTestFile;
  protected $testClassName;

  public function __construct(SeleniumServiceInterface $selenium_server, $test_file, $base_url, $browser = '*firefox') {
    $text = file_get_contents($test_file);

    //echo 'Raw test content:' . PHP_EOL;
    //echo $text;

    if (strpos($text, 'class Example extends PHPUnit_Extensions_SeleniumTestCase') === FALSE) {
      throw new SeleniumInvalidTestException('Specified test file is not a Selenium test.');
    }

    //echo 'Processing test file...';

    $set_up_seleniumrc = '$this->setHost(\'' . $selenium_server->getHost() . '\');$this->setPort(' . $selenium_server->getPort() . ');';
    $filename = basename($test_file);
    $this->testClassName = str_replace('.php', '', $filename);
    $text = str_replace('PHPUnit/Extensions/SeleniumTestCase.php', __DIR__ .'/pantheon_overrides.php', $text);
    $text = str_replace('class Example extends PHPUnit_Extensions_SeleniumTestCase', 'class ' . $this->testClassName . ' extends Pantheon_Overrides', $text);
    $text = preg_replace('/protected \$screenshotPath = \'.*?\';/', 'protected $screenshotPath = \'' . $_SERVER['argv'][2] . '\';', $text);
    $text = preg_replace('/protected \$screenshotUrl = \'.*?\';/', 'protected $screenshotUrl = \'' . $_SERVER['BUILD_URL'] . 'artifact/results/\';', $text);
    $text = preg_replace('/\$this->setBrowser\(.*?\);/', '$this->setBrowser(\'' . $browser . '\');', $text);
    $text = preg_replace('/\$this->setBrowserUrl\(.*?\);/', '$this->setBrowserUrl(\'' . $base_url . '\');' . $set_up_seleniumrc, $text);

    $directory = sys_get_temp_dir() . '/' . mt_rand();
    mkdir($directory);
    $this->processedTestFile = $directory . '/' . $this->testClassName . '.php';

    //echo 'Storing test: ' . $this->processedTestFile . PHP_EOL;
    //echo $text;
    //print_r($text);

    file_put_contents($this->processedTestFile, $text);
  }

  public function run($results_file) {
    $old_working_directory = getcwd();
    chdir(dirname($this->processedTestFile));
    $command = 'phpunit --verbose --process-isolation --log-junit ' . $results_file . ' ' . $this->testClassName .'.php';
    echo 'Running: ' . $command . PHP_EOL;
    $output = shell_exec($command);
    chdir($old_working_directory);
    return $output;
  }

  public function getTestClassName() {
    return $this->testClassName;
  }

  public function __destruct() {
    unlink($this->processedTestFile);
    rmdir(dirname($this->processedTestFile));
  }
}

function selenium_get_all_tests($directory, SeleniumServiceInterface $selenium, $base_url) {
  $tests = array();
  $test_files = explode(PHP_EOL, trim(shell_exec('find ' . $directory . ' | grep Test.php')));
  foreach($test_files as $test_file) {
    try {
      $tests[] = new SeleniumTest($selenium, $test_file, $base_url);
    }
    catch (SeleniumInvalidTestException $exception) {
      continue;
    }
  }
  return $tests;
}

function selenium_is_running($host, $port) {
  echo 'Checking if Selenium is active at ' . $host . ':' . $port . PHP_EOL;
  sleep(5);
  $success = FALSE;
  $ch = curl_init();
  curl_setopt($ch, CURLOPT_URL, 'http://' . $host . ':' . $port . '/selenium-server/driver/?cmd=testComplete');
  curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 2);
  curl_setopt($ch, CURLOPT_HEADER, 0);
  curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
  if (curl_exec($ch) !== FALSE) {
    $success = TRUE;
  }
  curl_close($ch);
  return $success;
}
