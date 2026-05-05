<?php
/*
 * JCI-EP-WEB-Patch — login pagina (gepatchte styling + flow).
 * Copyright (C) 2026 Dymotica B.V. <info@dymotica.nl>
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <https://www.gnu.org/licenses/>.
 */
//vim: ts=2 sw=2

set_include_path(get_include_path() . PATH_SEPARATOR . '../plugins/DataServiceConfig');

include_once "db.php";
include_once "base_controller.php";
include_once "mqtt_service_base.php";
include_once "jci_patch_version.php";

class SigninController extends BaseController {

  public function doGet() {
    if ($this->isSignedin()) {
      if ($_SESSION['user_name'] != 'Kiosk') {
        $this->redirect($this->makeUrl("app/graphic.php"));
      }
    } else if ($this->isProxied() || platformName() == "FI") {
      //TODO: handle 'success_url' and 'url_required' parameters in EMS
      $this->redirect("/login");
    } else {
      if (!empty($_GET['success_url'])) {
        $_SESSION['url_required'] = $this->sanitizeURL($_GET['success_url']);
      } else if (!empty($_GET['url_required'])) {
        $_SESSION['url_required'] = $this->sanitizeURL($_GET['url_required']);
      }
    }
  }
  
  public function doAjaxGet() {
    $response = array();
    $u = new User();
    $name = $_GET['user']['name'];
    if (!$u->find($name)) {
      $this->renderAjaxError($response, L("user name or password is incorrect"));
    }
    $randPart = randStr();
    $_SESSION['authTokenRand'] = $randPart;
    $authToken = $u->attr('salt') . '_' . $randPart;
    $response['authToken'] = $authToken;
    $this->renderAjaxSuccess($response);
  }
  
  protected function doAuth($u) {
    if (isset($_POST['user']['password'])) {
      $password = $_POST['user']['password'];
      return $u->authenticate($password);
    } else if (isset($_POST['user']['authHash'])) {
      $authHash = $_POST['user']['authHash'];
      $authRand = $_SESSION['authTokenRand'];
      unset($_SESSION['authTokenRand']);
      return $u->authenticate2($authHash, $authRand);
    } else {
      return false;
    }
  }

  protected function getFailedAuthCount($name, $clientIP) {
    $model = new FailedAuthCounter();
    return $model->getCounter($name, $clientIP); 
  }

  protected function recordFailedAuth($name, $clientIP) {
    $model = new FailedAuthCounter();
    $model->incrCounter($name, $clientIP);
  }

  protected function resetFailedAuthCount($name, $clientIP) {
    $model = new FailedAuthCounter();
    $model->delRecord($name, $clientIP);
  }

  protected function lockOutAccount($name, $clientIP, $failedAuthCount) {
    if ($failedAuthCount < 3)
      return false;

    $now = time();
    $model = new FailedAuthCounter();
    $startTime = $model->getLockoutTime($name, $clientIP);
    $lockout = false;
    if ($startTime == 0) {
      $lockout = true;
      $model->updateLockoutTime($name, $clientIP, $now);
    }
    else {
      $maxLockoutTime = FailedAuthCounter::lockoutDuration($failedAuthCount);
      $lockout = ($now - $startTime <= $maxLockoutTime+rand(1, 5)); //continue to lockout account
    }

    return $lockout;
  }

  public function doAjaxPost() {
    $response = array();
    $u = new User();
    $name = $_POST['user']['name'];
    if (!$u->find($name)) {
      $this->renderAjaxError($response, L("user name or password is incorrect"));
    }

    $clientIP = clientIP();
    $failedAuthCount = $this->getFailedAuthCount($name, $clientIP);
    if ($failedAuthCount >= 3 && $this->lockOutAccount($name, $clientIP, $failedAuthCount))
    {
      //GOTCHA: let user know that he is locked out, usibility wins over security here
      // $this->renderAjaxError($response, L("user name or password is incorrect"));
      $this->renderAjaxError($response, sprintf(L("Login has been suspended due to too many failed attempts, please retry after about %d seconds"), FailedAuthCounter::lockoutDuration($failedAuthCount)));
      return;
    }

    if ($this->doAuth($u)) {
      $mqtt = new MQTTServiceBase();
      $mqtt->notifyLoginSucceed();
      $this->resetFailedAuthCount($name, $clientIP);

      $remember_me = isset($_POST['remember_me']) ? $_POST['remember_me'] : '';
      $this->sm()->afterLogin($u, $remember_me == 'true', $this->isCORS(), $this->isHttps());

      $_SESSION['user_id'] = $u->attr("id");
      $_SESSION['user_name'] = $u->attr("name");
      $_SESSION['last_visit_time'] = time();
      $_SESSION['should_change_pwd'] = $u->shouldChangePassword();

      $this->signinPhpLiteAdmin($u->attr("checksum"));

      $redirectUrl = $this->userHomePage();
      if (!empty($_SESSION['url_required']))
      {
        $redirectUrl = $this->makeUrl($_SESSION['url_required']);
        unset($_SESSION['url_required']);
      }
      die(<<<EOS
{"redirectUrl": "$redirectUrl" }
EOS
    );
    } else {
      $mqtt = new MQTTServiceBase();
      $mqtt->notifyLoginFail();
      $this->recordFailedAuth($name, $clientIP);
      $failedAuthCount = $this->getFailedAuthCount($name, $clientIP);
      if ($failedAuthCount >= 3 && $this->lockOutAccount($name, $clientIP, $failedAuthCount))
      {
        $this->renderAjaxError($response, sprintf(L("Login has been suspended due to too many failed attempts, please retry after about %d seconds"), FailedAuthCounter::lockoutDuration($failedAuthCount)));
      } else {
        $this->renderAjaxError($response, L("user name or password is incorrect"));
      }
    }
  }
}

$controller = new SigninController();
$controller->run();

?>

<!DOCTYPE html>
<html lang="en">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="">
	
  	<meta name="mobile-web-app-capable" content="yes">
  	<meta name="apple-mobile-web-app-capable" content="yes">

    <meta name="theme-color" content="#ffffff">
    
    <title><?php echo L('Signin') ?> <?php echo Config::$webPageTitle ?></title>

    <!-- Bootstrap core CSS -->
    <link rel="stylesheet" href="<?php A('../css/bootstrap.min.css') ?>" media="screen" />

    <!-- Custom styles for this template -->
    <link href="<?php A('../css/mdi-icons.css') ?>" rel="stylesheet">
    <link href="<?php A('../css/jci-theme.css') ?>" rel="stylesheet">
    <link href="<?php A('../css/branding.css') ?>" rel="stylesheet">
    <link href="<?php A('../css/signin.css') ?>" rel="stylesheet">
    <!--[if lt IE 9]>
      <script src="../js/html5shiv.js"></script>
      <script src="../js/respond.min.js"></script>
    <![endif]-->
  </head>

  <body class="jci-signin">

    <div class="container">
      <div class="jci-signin-layout">
        <div class="jci-signin-pane">
          <img class="jci-signin-logo" src="<?php A('../img/login-bg.png') ?>" alt="Johnson Controls" />
          <h1>EasyIO Neo Series EC-controller</h1>
          <h2><?php echo L('Log In') ?></h2>
          <div id="signinForm">
          </div>
          <div class="jci-signin-footer">v<?php echo defined('JCI_PATCH_VERSION') ? JCI_PATCH_VERSION : '1.0.0'; ?></div>
        </div>
        <div class="jci-signin-image" role="presentation"></div>
      </div>
    </div>

    <!-- Backbone templates -->
    <div id='signinFormContent' class="hide">
      <form class="form-signin" method="post" >
        <img id="profile-img" class="profile-img-card" src="images/brand_logo.png" />
        <p id="profile-name" class="profile-name-card"><?php echo Config::$webPageTitle ?></p>
        <div class="alert hide"></div>
        <label class="field-label" for="username"><?php echo L('Enter Login Name') ?></label>
        <input type="text" name="user[name]" id="username" class="input-block-level" placeholder="" autofocus>
        <label class="field-label" for="password"><?php echo L('Enter Password') ?></label>
        <div class="input-prepend input-append">
          <input type="password" name="user[password]" id="password" class="input-block-level" placeholder="">
          <span class="add-on" id="show-password" title="<?php echo L('Show password') ?>">
            <i class="mdi mdi-eye"></i>
          </span>
        </div>
        <label class="checkbox">
          <input type="checkbox" name="remember_me" > <?php echo L('Remember me') ?>
        </label>
        <button name="commit" id="signinBtn" class="btn btn-primary" type="submit"><?php echo L('Log In') ?></button>
      </form>
    </div>

    <!-- Bootstrap core JavaScript
    ================================================== -->
    <!-- Placed at the end of the document so the pages load faster -->

    <?php frontendLangJson() ?>
    <?php include ("check_browser.php") ?>

    <script type="text/javascript" src="<?php A('../js/l18n.js') ?>"></script>

    <script type="text/javascript" src="<?php A('../js/json2.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery-3.7.1.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery-migrate-3.5.0.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/underscore-min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/underscore.string.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/backbone-min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/sha.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/signin.js') ?>"></script>

  </body>
</html>

