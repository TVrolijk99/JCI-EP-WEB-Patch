<?php
/*
 * JCI-EP-WEB-Patch — post-login landing-page redirect handler.
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

include_once "db.php";
include_once "base_controller.php";

class LandingPageController extends BaseController {
  protected function signinRequired() {
    return true;
  }
  
  protected function doGet() {
    if (!isset($_GET['permission_error'])) {
      if ($_SESSION['user_name'] != 'Kiosk') {
        $this->redirect($this->userHomePage());
      } else {
        $this->redirect($this->makeUrl('app/signin.php'));
      }
    }
  }
}

$controller = new LandingPageController();
$controller->run();

?>

<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8" />
    <title><?php echo Config::$webPageTitle ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="<?php A('../css/bootstrap.min.css') ?>" media="screen" />
  </head>
  <body>
    <div class="container">
      <?php 
        if(!is_null($controller->flash()))
          echo '<div class="alert" style="margin-top: 30px;">' . $controller->flash() . '</div>';
      ?>

      <div class="row">
        <div class="span1 offset8"><a href="signout.php"><?php echo L('Sign Out') ?></a></div>
      </div>
    </div>
  </body>
</html>

