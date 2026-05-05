<?php
/*
 * JCI-EP-WEB-Patch — hoofd graphic-viewer met topbar en zijbalk.
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
// vim: ts=2 sw=2

// error_log(print_r(opcache_get_status(), true));

include_once "db.php";
include_once "settings.php";
include_once "feature_control.php";
include_once "base_controller.php";
include_once "branding_helpers.php";

class GraphicController extends BaseController {
  protected function signinRequired() {
    return true;
  }

  public function cptToolsBackupUrl() {
    return $this->makeUrl("../CPT-Tools.zip");
  }
  public function sptToolsBackupUrl() {
    return $this->makeUrl("../SPT-Tools.zip");
  }

  protected function doGet() {
    if (FeatureControl::instance()->isEnabled("Graphic"))
      return;

    $u = $this->curUser();
    if (FeatureControl::instance()->isEnabled("Dashboard") && isDashboardExisted()
      && isset($u) && $u->isDashboardEnabled() && $u->isDashboardLanding())
      $this->redirect($this->makeUrl("dashboard/index.php"));
    else
      $this->redirect($this->makeUrl("app/landing_page.php"));
  }

  public function plugins() {
    $access_model = new FeatureAccessControl();

    $plugins = collectPlugins();
    foreach($plugins as &$plugin) {
      if (!isset($plugin['indexFile']))
        continue;

      if (!$access_model->canUserAccess($_SESSION['user_id'], $plugin['id']))
        $plugin['show'] = false;

      $plugin['url'] = $this->makeUrl($plugin['indexFile']);
    }
    return $plugins;
  }

}

$controller = new GraphicController();
$controller->run();
$u = $controller->curUser();
$_SESSION['should_change_pwd'] = $u->shouldChangePassword();
$shouldChangePassword = $_SESSION['should_change_pwd'];
unset($_SESSION['should_change_pwd']);
?>

<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8" />
    <title><?php $controller->echoPageTitle(Config::$webPageTitle) ?></title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

  	<meta name="mobile-web-app-capable" content="yes">
  	<meta name="apple-mobile-web-app-capable" content="yes">

    <meta name="theme-color" content="#ffffff">
	
    <link rel="stylesheet" href="<?php A('../css/bootstrap.min.css') ?>" media="screen" />
    <!-- <link rel="stylesheet" href="../css/slider.css" /> -->
    <link rel="stylesheet" href="<?php A('../css/bootstrap-datetimepicker.min.css') ?>" />
    <link rel="stylesheet" href="<?php A('../css/graphics.css') ?>" />
    <link rel="stylesheet" href="<?php A('../css/d3-gauge_simple.css') ?>" />
    <link rel="stylesheet" href="<?php A('../css/daterangepicker-bs2.css') ?>" />

    <link rel="stylesheet" href="<?php A('../js/jstree/themes/default/style.min.css') ?>" />

	<?php //$controller->faviconHTMLInfo() ?>
	
    <script language="javascript" type="text/javascript">
      "use strict";
      window.cur_account_info = {
        'name': '<?php echo htmlspecialchars($u->attr('name'), ENT_QUOTES|ENT_HTML401) ?>',
        'is_admin': <?php echo $u->isAdmin() ? 1 : 0 ?>,
      };

      function is_admin() {
        return cur_account_info && ('is_admin' in cur_account_info) && cur_account_info['is_admin'];
      }
      window.shouldChangePassword = <?php echo $shouldChangePassword ? 'true' : 'false' ?>;
      window.platformName = '<?php echo platformName() ?>';
      window.firmwareFileName = '<?php echo firmwareFileName() ?>';
      window.firmwareVersion = '<?php echo firmwareVersion() ?>';
      window.firmwarePatchLevel = '<?php echo firmwarePatchLevel() ?>';
      window.sdcardExisted = <?php echo does_sdcard_exist() ? 'true' : 'false'; ?>;

      window.isOEM = <?php echo isOEM() ? 'true' : 'false' ?>;
      window.appName = "<?php echo appName() ?>";
      window.freeDiskSpace = Number.parseInt('<?php echo disk_free_space(firmwareUpgradePath()) ?>');

      window.mqttVersion = '<?php echo mqttVersion() ?>';
      window.webAppVersion = '<?php echo webAppVersion() ?>';
      window.sedonaComponentVersion = '<?php echo json_encode(sedonaComponentVersion()) ?>';
    </script>

    <script type="text/javascript" src="<?php A('../js/l18n.js') ?>"></script>

<?php
  if (isset($_GET["dev"])) {
?>
    <script type="text/javascript" src="<?php A('../js/json2.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery-3.7.1.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery-migrate-3.5.0.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/purify.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/bootstrap.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/bootstrap-contextmenu.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/underscore.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/underscore.string.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/backbone.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/spin.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jstree/jstree.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery.form.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/graphic.js') ?>"></script>
<?php
  } else {
?>
    <script type="text/javascript" src="<?php A('../js/json2.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery-3.7.1.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery-migrate-3.5.0.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/purify.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/bootstrap.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/bootstrap-contextmenu.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/underscore-min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/underscore.string.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/backbone-min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/spin.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jstree/jstree.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery.form.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/graphic.min.js') ?>"></script>
<?php
  }
?>
  <link rel="stylesheet" type="text/css" href="<?php A('../css/nanoscroller.min.css') ?>" />
  <link rel="stylesheet" href="<?php A('../css/user.css') ?>" />
  <link rel="stylesheet" href="<?php A('../css/mdi-icons.css') ?>" />
  <link rel="stylesheet" href="<?php A('../css/jci-theme.css') ?>" />
  <link rel="stylesheet" href="<?php A('../css/branding.css') ?>" />
  <script type="text/javascript" src="<?php A('../js/jquery.nanoscroller.min.js') ?>"></script>
  <script type="text/javascript" src="<?php A('../js/menu_load.js') ?>"></script>
  <script type="text/javascript" src="<?php A('../js/jci-ui.js') ?>"></script>
  <script type="text/javascript" src="<?php A('../js/jci-graphic-chrome.js') ?>"></script>

  </head>
  <body class="theme-h31 jci-graphic-body">
    <div id="header_panel">
		<div id="header_container">
			<div class="mt-menuSwitch" title="Toggle menu"><i class="mdi mdi-menu"></i></div>
<?php
  $jciBranding = jciBrandingLoad();
  $jciBrandingLogoUrl = jciBrandingLogoUrl();
  $jciBrandingTitleEsc = htmlspecialchars($jciBranding['title'], ENT_QUOTES|ENT_HTML401);
?>
			<div id="logo" data-app-title="<?php echo $jciBrandingTitleEsc ?>">
<?php if ($jciBrandingLogoUrl): ?>
				<img class="jci-brand-logo" src="<?php echo htmlspecialchars($jciBrandingLogoUrl, ENT_QUOTES|ENT_HTML401) ?>" alt="">
<?php endif; ?>
				<span class="jci-brand-title"><?php echo $jciBrandingTitleEsc ?></span>
<?php if ($u && $u->isAdmin()): ?>
				<button type="button" class="jci-brand-edit" title="<?php echo L('Edit') ?>" data-toggle="modal" data-target="#brandingEditModal"><i class="mdi mdi-pencil-outline"></i></button>
<?php endif; ?>
			</div>
			<div id="title"><h3 id="hlinks-title"></h3></div>
			<div id="right_menu">
          <a id="helpBtn" class="jci-topbar-icon" href="javascript:void(0)" data-toggle="modal" data-target="#helpModal" title="Help"><i class="mdi mdi-help-circle-outline"></i></a>
          <a id="alarmBell" class="jci-topbar-icon" href="../app/alarmdb.php" title="<?php echo L('Alarms') ?>"><i class="mdi mdi-bell"></i></a>
          <div class="btn-group" id="userBtn">
            <a class="btn btn-inverse" data-toggle="dropdown" href="#" data-initial="<?php echo htmlspecialchars(strtoupper(substr($_SESSION['user_name'],0,1)), ENT_QUOTES|ENT_HTML401) ?>">
              <span class="user-pill-name"><?php echo htmlspecialchars($_SESSION['user_name'], ENT_QUOTES|ENT_HTML401) ?></span>
            </a>
            <a class="btn btn-inverse dropdown-toggle" data-toggle="dropdown" href="#"><span class="caret"></span></a>
 					<ul class="dropdown-menu" style="left: inherit;right: 0;">
<?php
  $accountMenuItem = 0;
  if ($u->isPasswordChangeEnabled()) {
    $accountMenuItem = $accountMenuItem + 1;
?>
  <li><a href="#" id="userProfile" data-username="<?php echo htmlspecialchars($_SESSION['user_name'], ENT_QUOTES|ENT_HTML401) ?>" data-userid="<?php echo $_SESSION['user_id'] ?>"><i class="icon-pencil"></i> <?php echo L('Change Password...') ?></a></li>
<?php } ?>
<?php
  if (isset($u) && $u->isAccountManagementEnabled()) {
    $accountMenuItem = $accountMenuItem + 1;
    $manageStr = L('Manage...');
    echo <<<EOS
              <li><a href="#" id="userManagement"><i class="icon-th-list"></i> $manageStr</a></li>
EOS
    ;
  }
?>
<?php if ($accountMenuItem > 0) { ?>
              <li class="divider"></li>
<?php } ?>
<?php if ($controller->isProxied() || platformName() == "FI") : ?>
              <li><a href="/logout"><i class="i"></i> <?php echo L('Signout') ?></a></li>
<?php else : ?>
              <li><a href="signout.php"><i class="i"></i> <?php echo L('Signout') ?></a></li>
<?php endif; ?>
            </ul>
          </div>

        </div>
      </div>
    </div>
	
	<div class="mt-left-sidebar mt-scroller">
				<ul class="sidebar-elements" id="leftXmlMenu">
					<li class="jci-sidebar-section"><span><?php echo L('Feature') ?></span></li>
					<?php
						if (FeatureControl::instance()->isEnabled("Dashboard") && isset($u) && $u->isDashboardEnabled() && $u->isDashboardLanding()) {
							echo "<li class='parent'><a href='../dashboard/index.php'><i class='mdi mdi-file-tree'></i><span>".L('Dashboard')."</span></a></li>";
						} elseif(FeatureControl::instance()->isEnabled("Dashboard") && isset($u) && $u->isDashboardEnabled()) {
							echo "<li class='parent'><a href='../dashboard/index.php' target='_blank'><i class='mdi mdi-file-tree'></i><span>".L('Dashboard')."</span></a></li>";
						}
						/* jci-patch: AlarmDB sidebar entry is rendered unconditionally for
						   every signed-in user. Independent of collectPlugins() so the menu
						   never vanishes if the plugin manifest is missing. */
						if (isset($u)) {
							echo "<li class='parent AlarmDBM'><a href='../app/alarmdb.php'><i class='mdi mdi-bell glyphicon-bell'></i><span>".L('Alarms')."</span></a></li>";
						}
						if (isset($u) && FeatureControl::instance()->isEnabled("Plugin")) {
							$plugins = $controller->plugins();
							if (!empty($plugins)) {
								$servicesSubmenuLoaded = false;
								$servicesSubmenuEnabled = true;
								$servicesSubmenu = '';
								foreach($plugins as $plugin) {
									if($plugin['name'] == 'AlarmDB') {
											// Already rendered above — skip duplicate row.
											continue;
									} elseif($plugin['name'] == 'EOS' && $plugin['show'] === true) {
										echo "<li class='parent ".$plugin['name']."M'>";
										if($plugin['openNewPage']) {
											echo "<a href='../".$plugin['indexFile']."' target='_blank'>";
										} else {
											$tmp = explode('/', $plugin['utilityFile']);
											$file_extension = end($tmp);
											echo "<a href='../app/".$file_extension."'>";
										}
										echo "<i class='mdi mdi-signal'></i><span>".L('Energy')."</span></a></li>";
									} elseif($plugin['name'] == 'Trend' && $plugin['show'] === true) {
										echo "<li class='parent ".$plugin['name']."M'>";
										if($plugin['openNewPage']) {
											echo "<a href='../".$plugin['indexFile']."' target='_blank'>";
										} else {
											$tmp = explode('/', $plugin['utilityFile']);
											$file_extension = end($tmp);
											echo "<a href='../app/".$file_extension."'>";
										}
										echo "<i class='mdi mdi-history'></i><span>".L('History')."</span></a></li>";
									} elseif($controller->isProxied()) {
										echo "<li class='parent'>";
										echo "<a href='/'>";
										echo "<i class='mdi mdi-server-network'></i><span>".L('EMS')."</span></a></li>";
									} else {
										if(!$servicesSubmenuLoaded && $servicesSubmenuEnabled && $plugin['show'] === true) {
											$servicesSubmenu = $servicesSubmenu."<li class='parent has-children'><a href='#' onclick='return false;'><i class='mdi mdi-server-network'></i><span>".L('Services')."</span></a><ul class='sub-menu'><li class='nav-items'><ul class='nav' id='meniu_99'>";
											$servicesSubmenuLoaded = true;
										}
										if($servicesSubmenuLoaded && $servicesSubmenuEnabled) {
											if($plugin['show'] === true) {
												$newpage = $plugin['openNewPage'] ? " target='_blank'" : "";
												$servicesSubmenu = $servicesSubmenu."<li><a href=\"../".$plugin['indexFile']."\"".$newpage.">".htmlspecialchars($plugin['name'], ENT_QUOTES|ENT_HTML401)."</a></li>";
											}
										}
									}
								}
								if($servicesSubmenuLoaded && $servicesSubmenuEnabled) {
									$servicesSubmenu = $servicesSubmenu."</ul></li></ul></li>";
								}
								echo $servicesSubmenu;
							}
						}

						/* ---------- Trend submenu (only when at least 1 trend table exists) ---------- */
						@include_once "trend_helpers.php";
						$trendTables = function_exists('jciTrendListTables') ? jciTrendListTables() : array();
						if (!empty($trendTables)) {
							echo "<li class='parent has-children trendM'><a href='#' onclick='return false;'><i class='mdi mdi-chart-line'></i><span>".L('Trend')."</span></a>";
							echo "<ul class='sub-menu'><li class='nav-items'><ul class='nav'>";
							foreach ($trendTables as $t) {
								echo "<li><a href='../app/trend.php?table=".urlencode($t)."'>".htmlspecialchars($t, ENT_QUOTES|ENT_HTML401)."</a></li>";
							}
							echo "</ul></li></ul></li>";
						}

						/* ---------- Utilities (moved from topbar to sidebar) ---------- */
						if (isset($u) && $u->isUtilityEnabled()) {
							echo "<li class='parent has-children utilityM' id='utilityTools'>";
							echo "<a href='#' onclick='return false;'><i class='mdi mdi-wrench'></i><span>".L('Utilities')."</span></a>";
							echo "<ul class='sub-menu'><li class='nav-items'><ul class='nav' id='utilities-nav'>";
							if ($u->isSystemEnabled()) {
								echo "<li><a id='utilityBackup' href='#'>".L('Backup...')."</a></li>";
								echo "<li><a id='utilityRestore' href='#'>".L('Restore...')."</a></li>";
								echo "<li class='divider'></li>";
								echo "<li><a id='utilityRestart' href='#'>".L('Restart...')."</a></li>";
								echo "<li><a id='utilityReboot' href='#'>".L('Reboot...')."</a></li>";
								if (FeatureControl::instance()->isEnabled("FirmwareUpgrade")) {
									echo "<li class='divider'></li>";
									echo "<li><a id='utilityUpgradeFirmware' href='#'>".L('Upgrade Firmware...')."</a></li>";
								}
							}
							if (FeatureControl::instance()->isEnabled("PortConfig")) {
								echo "<li class='divider'></li>";
								echo "<li><a id='utilityServicePort' href='#'>".L('Config Ports...')."</a></li>";
							}
							if (FeatureControl::instance()->isEnabled("ChangeDateTimeUtility")) {
								echo "<li><a id='utilityDateTime' href='#'>".L('Change DateTime...')."</a></li>";
							}
							if (FeatureControl::instance()->isEnabled("ChangeSysPassword")) {
								echo "<li class='divider'></li>";
								echo "<li><a id='utilityChangeSysPassword' href='#'>".L('Change OS Account Password...')."</a></li>";
							}
							echo "<li class='divider'></li>";
							echo "<li><a href='utility.php?action=run_phpliteadmin' target='_blank'>".L('DB Manager...')."</a></li>";
							$cptToolsPath = cptToolsPath();
							if (file_exists($cptToolsPath)) {
								echo "<li class='divider'></li>";
								echo "<li><a href='".$controller->cptToolsBackupUrl()."'>".L('Download CPT Tools')."</a></li>";
							}
							$sptToolsPath = sptToolsPath();
							if (file_exists($sptToolsPath)) {
								echo "<li class='divider'></li>";
								echo "<li><a href='".$controller->sptToolsBackupUrl()."'>".L('Download SPT Tools')."</a></li>";
							}
							echo "</ul></li></ul></li>";
						}
					?>
					<li class="jci-sidebar-section jci-section-space"><span><?php echo L('Space') ?></span></li>
					<li class="jci-space-marker" style="display:none;"></li>
				</ul>
	</div>
	
	<div class="container-fluid jci-graphic-page">
		<div class="jci-graphic-header">
			<div class="jci-graphic-breadcrumb" id="jci-graphic-breadcrumb">&nbsp;</div>
		</div>
		<div class="jci-graphic-card" id="jci-graphic-card">
			<div class="jci-graphic-toolbar">
				<span class="jci-graphic-title" id="jci-graphic-title-bar">&nbsp;</span>
				<div class="jci-graphic-zoom">
					<button type="button" class="jci-graphic-zbtn" id="jci-graphic-zoom-out" title="<?php echo L('Zoom out') ?>"><i class="mdi mdi-minus"></i></button>
					<span class="jci-graphic-zoom-level" id="jci-graphic-zoom-level">100%</span>
					<button type="button" class="jci-graphic-zbtn" id="jci-graphic-zoom-in" title="<?php echo L('Zoom in') ?>"><i class="mdi mdi-plus"></i></button>
					<button type="button" class="jci-graphic-zbtn" id="jci-graphic-zoom-fit" title="<?php echo L('Fit to screen') ?>"><i class="mdi mdi-fit-to-screen-outline"></i></button>
				</div>
			</div>
			<div class="jci-graphic-viewport" id="jci-graphic-viewport">
				<!-- Scale wrapper: CSS `zoom` is applied here, NOT on #grCanvas.
				     graphic.min.js sets explicit width/height on #grCanvas — overriding
				     it produced 0×0 and Apex inside the graphic threw "Infinity". The
				     wrapper sits between viewport and #grCanvas so we can scale
				     without touching the legacy element. -->
				<div class="jci-graphic-scale" id="jci-graphic-scale">
					<!-- LEGACY HOOK: do not change #grCanvas id/class — graphic.min.js
					     + the editor expect this exact element. -->
					<div class="main_content">
						<div class="row">
							<div id="grCanvas" class="span12"></div>
						</div>
					</div>
				</div>
			</div>
		</div>
	</div>

    <!-- ====== Help modal (everyone) ====== -->
<?php include __DIR__ . '/help_modal.php'; ?>

    <!-- ====== Branding-edit modal (admin only) ====== -->
<?php if ($u && $u->isAdmin()): ?>
    <div class="modal fade jci-branding-modal" id="brandingEditModal" tabindex="-1" role="dialog" aria-hidden="true">
      <div class="modal-dialog">
        <div class="modal-content">
          <div class="modal-header">
            <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
            <h4 class="modal-title"><i class="mdi mdi-tag-edit-outline"></i> Topbar aanpassen</h4>
          </div>
          <div class="modal-body">
            <div class="form-group">
              <label for="brandingTitleInput">Titel</label>
              <input type="text" id="brandingTitleInput" class="form-control" maxlength="64" placeholder="Johnson Controls GBS">
            </div>
            <div class="form-group">
              <label>Logo</label>
              <div class="jci-branding-preview">
                <img id="brandingLogoPreview" alt="" style="display:none">
                <span id="brandingLogoEmpty" class="jci-branding-empty">Geen logo</span>
              </div>
              <input type="file" id="brandingLogoFile" accept="image/png,image/jpeg,image/svg+xml,image/gif,image/webp">
              <p class="help-block">Max 1&nbsp;MB · PNG / JPG / SVG / GIF / WEBP · automatisch geschaald.</p>
              <button type="button" class="btn btn-default btn-sm" id="brandingLogoDelete">
                <i class="mdi mdi-trash-can-outline"></i> Logo verwijderen
              </button>
            </div>
            <div class="jci-branding-msg" id="brandingMsg"></div>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-default" data-dismiss="modal">Sluiten</button>
            <button type="button" class="btn btn-primary" id="brandingSaveBtn">
              <i class="mdi mdi-content-save-outline"></i> Opslaan
            </button>
          </div>
        </div>
      </div>
    </div>
<?php endif; ?>

    <div id="grObjectMenu">
    </div>

    <div id="askParamModal" class="modal hide fade">
    </div>

    <div id="modalDialog" class="modal hide fade" data-backdrop="static">
    </div>

    <div id="invokeActionModal" class="modal hide fade">
    </div>

    <div id="writePropertyModal" class="modal hide fade">
    </div>

    <div id="alertMessage" class="modal hide fade">
    </div>

    <div id="changePasswordModal" class="modal hide fade">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h3><?php echo L('Change Password') ?></h3>
      </div>

      <div class="modal-body">
        <div class="alert hide">
        </div>

        <form class='form-horizontal' autocomplete="nope">
          <div class="control-group">
          <label class="control-label" for="inputOldPassword"><?php echo L('Password') ?></label>
            <div class="controls">
              <div class="input-prepend input-append">
                <input type="password"  id="inputOldPassword" placeholder="<?php echo L('Password') ?>" autofocus>
                <span class="add-on" id="show-old-password">
                  <i class="icon-eye-open"></i>
                </span>
              </div>
              <span class='help-inline hide'></span>
            </div>
          </div>

          <div class="control-group">
          <label class="control-label" for="inputNewPassword"><?php echo L('New Password') ?></label>
            <div class="controls">
              <div class="input-prepend input-append">
                <input type="password" id="inputNewPassword" placeholder="<?php echo L('New Password') ?>">
                <span class="add-on" id="show-new-password">
                  <i class="icon-eye-open"></i>
                </span>
              </div>
              <span class='help-inline hide'></span>
            </div>
          </div>

          <div class="control-group">
          <label class="control-label" for="inputConfirmPassword"><?php echo L('Confirm New Password') ?></label>
            <div class="controls">
              <div class="input-prepend input-append">
                <input type="password" id="inputConfirmPassword" placeholder="<?php echo L('Confirm New Password') ?>">
                <span class="add-on" id="show-verify-password">
                  <i class="icon-eye-open"></i>
                </span>
              </div>
              <span class='help-inline hide'></span>
            </div>
          </div>
        </form>

      </div>

      <div class="modal-footer">
        <a href="#" class="btn btn-primary"><?php echo L('Save') ?></a>
      </div>
    </div>

    <!--  Account Management -->
    <div id="accountManagementModal" class="modal hide fade" style="width: 700px;">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h3><?php echo L('Manage') ?></h3>
      </div>

      <div class="modal-body">
        <div class="tabbable">
          <ul class="nav nav-tabs">
            <li class="active"><a href="#permissionsPanel" data-toggle="tab"><?php echo L('Permissions') ?></a></li>
            <li><a href="#accountsPanel" data-toggle="tab"><?php echo L('Accounts') ?></a></li>
            <li><a href="#newAccountPanel" data-toggle="tab"><?php echo L('Create') ?></a></li>
            <li><a href="#sessionTTLPanel" data-toggle="tab"><?php echo L('Session') ?></a></li>

<?php
  if (isset($u) && $u->isAdmin() && FeatureControl::instance()->isEnabled("AuthKey")) {
?>
            <li><a href="#authKeyPanel" data-toggle="tab"><?php echo L('AuthKey') ?></a></li>
<?php } ?>
          </ul>
          <div class="tab-content">
            <div class="tab-pane active" id="permissionsPanel"></div>
            <div class="tab-pane" id="accountsPanel"></div>
            <div class="tab-pane" id="newAccountPanel"></div>
            <div class="tab-pane" id="sessionTTLPanel"></div>
<?php
  if (isset($u) && $u->isAdmin() && FeatureControl::instance()->isEnabled("AuthKey")) {
?>
            <div class="tab-pane" id="authKeyPanel"></div>
<?php } ?>
          </div>
        </div>
      </div>
    </div>

    <script type="text/template" id="new_account_tpl">
      <div class="alert hide"> </div>
      <form class="form-horizontal" autocomplete="nope">
        <div class="control-group">
          <label class="control-label" for="inputName"><?php echo L('Name') ?></label>
          <div class="controls">
            <input type="text" id="inputName" placeholder="<?php echo L('Name') ?>" autofocus>
            <span class='help-inline hide'></span>
          </div>
        </div>
        <div class="control-group">
          <label class="control-label" for="inputPassword"><?php echo L('Password') ?></label>
          <div class="controls">
            <input type="password" id="inputPassword" placeholder="<?php echo L('Password') ?>">
            <span class='help-inline hide'></span>
          </div>
        </div>
        <div class="control-group">
          <div class="controls">
            <label >
              <input class="inputEnableUtility" type="checkbox"> <?php echo L('Enable Utility Tools') ?>
            </label>
            <label>
              <input class="inputEnableSystem" type="checkbox"> <?php echo L('Enable System Tools') ?>
            </label>
            <label>
              <input class="inputEnableAccountManagement" type="checkbox"> <?php echo L('Enable Account Management') ?>
            </label>
            <label>
              <input class="inputEnablePasswordChange" type="checkbox"> <?php echo L('Enable Password Change') ?>
            </label>
            <?php if (FeatureControl::instance()->isEnabled("UIControl") && isDashboardExisted()) { ?>
            <label>
              <input class="inputEnableDashboard" type="checkbox"> <?php echo L('Enable Dashboard') ?>
            </label>
            <label>
              <input class="inputDashboardAsLandingPage" type="checkbox"> <?php echo L('Dashboard As Landing Page') ?>
            </label>
            <?php } ?>
          </div>
        </div>
        <div class="control-group">
          <div class="controls">
            <button id="create_account_btn" type="submit" class="btn btn-primary"><?php echo L('Create') ?></button>
          </div>
        </div>
      </form>
    </script>

    <script type="text/template" id="user_name_tpl">
      <li <% if (index == 0) print('class="active"'); %> >
        <a href="#user_<%= user.get('user_id') %>" data-user_id="<%= user.get('user_id') %>" data-toggle="tab"><%- user.get('name') %></a>
      </li>
    </script>

    <script type="text/template" id="user_permissions_tpl">
      <div id="user_<%= user.get('user_id') %>" class="tab-pane <% if (index == 0) print("active"); %>">
        <div class="alert hide"></div>
        <table class="table table-striped table-hover">
          <thead>
            <tr>
              <th><?php echo L('Path') ?></th>
              <th><?php echo L('NoAccess') ?></th>
              <th><?php echo L('ReadOnly') ?></th>
              <th><?php echo L('ReadWrite') ?></th>
            </tr>
          </thead>
          <tbody>
          <% _.each(user.permissions(), function(perm) { %>
            <tr data-grpath='<%= perm.path %>'>
              <td style="padding-left:<%= perm.indent %>px">
                <i class='<%= user.iconFor(perm) %>'></i> <%= perm.name %>
              </td>
              <td>
                <label class="radio">
                  <input class="perm-radio" type="radio" name="perm_<%= user.get('user_id') %>_<%= perm.path %>" value="noaccess" <% if (user.isNoAccess(perm)) print('checked'); %> />
                </label>
              </td>
              <td>
                <label class="radio">
                  <input class="perm-radio" type="radio" name="perm_<%= user.get('user_id') %>_<%= perm.path %>" value="readonly" <% if (user.isReadOnly(perm)) print('checked'); %> />
                </label>
              </td>
              <td>
                <label class="radio">
                  <input class="perm-radio" type="radio" name="perm_<%= user.get('user_id') %>_<%= perm.path %>" value="readwrite"  <% if (user.isReadWrite(perm)) print('checked'); %> />
                </label>
              </td>
            </tr>
          <% }); %>
          </tbody>
        </table>

        <div class="control-group" >
          <div class="controls controls-row">
            <button class="dev-perm-toggle btn btn-link pull-left" type="button"><?php echo L('Developer Permission') ?></button>
          </div>
          <div class="controls controls-row dev-perm-config" style="display:none;">
            <label class="alert" style="margin-top: 6px; margin-bottom: 4px;"><?php echo sprintf(L("Following settings will enable 3rd party service to Read/Write sedona app's data through the Data API provided by %s Graphic web service , don't change it if you aren't sure what it is. Refer %s Web Data API document for more details."), appName(), appName()) ?></label>
            <label class="checkbox span3">
              <input class="dev-readable-checkbox" type="checkbox" <% if (user.isDevReadable()) print("checked='checked'"); %> > <?php echo L('Read Data') ?>
            </label>
            <label class="checkbox span3">
              <input class="dev-writable-checkbox" type="checkbox" <% if (user.isDevWritable()) print("checked='checked'"); %> > <?php echo L('Write Data') ?>
            </label>
          </div>
        </div>

        <div class="pull-right">
          <button class="btn btn-primary permission-save-btn" type="button"><?php echo L('Save') ?></button>
        </div>
      </div>
    </script>

    <script type="text/template" id="account_name_tpl">
      <li <% if (index == 0) print('class="active"'); %> >
        <a href="#account_<%= account.get('user_id') %>" data-user_id="<%= account.get('user_id') %>" data-toggle="tab"><%= account.get('name') %></a>
      </li>
    </script>

    <script type="text/template" id="account_details_tpl">
      <div id="account_<%= account.get('user_id') %>" class="tab-pane <% if (index == 0) print("active"); %>">
        <div class="alert hide"></div>

        <form class="form-horizontal" autocomplete="nope">
          <div class="control-group">
            <label class="control-label" for="inputPasswordUpdate"><?php echo L('New Password') ?></label>
            <div class="controls">
              <input type="password" id="inputPasswordUpdate" placeholder="<?php echo L('Leave blank to not change') ?>">
              <span class='help-inline hide'></span>
            </div>
          </div>
          <div class="control-group">
            <div class="controls">
              <label>
                <input class="inputEnableUtility" type="checkbox" <% if (account.get('utility_enabled') == 't') print("checked='checked'"); %> > <?php echo L('Enable Utility Tools') ?>
              </label>
              <label>
                <input class="inputEnableSystem" type="checkbox" <% if (account.get('system_enabled') == 't') print("checked='checked'"); %> > <?php echo L('Enable System Tools') ?>
              </label>
              <label>
                <input class="inputEnableAccountManagement" type="checkbox" <% if (account.get('account_management_enabled') == 't') print("checked='checked'"); %> > <?php echo L('Enable Account Management') ?>
              </label>
              <label>
                <input class="inputEnablePasswordChange" type="checkbox" <% if (account.get('password_change_enabled') == 't') print("checked='checked'"); %> > <?php echo L('Enable Password Change') ?>
              </label>
              <?php if (FeatureControl::instance()->isEnabled("UIControl") && isDashboardExisted()) { ?>
              <label>
                <input class="inputEnableDashboard" type="checkbox" <% if (account.get('dashboard_enabled') == 't') print("checked='checked'"); %> > <?php echo L('Enable Dashboard') ?>
              </label>
              <label>
                <input class="inputDashboardAsLandingPage" type="checkbox" <% if (account.get('dashboard_as_landing_page') == 't') print("checked='checked'"); %> > <?php echo L('Dashboard As Landing Page') ?>
              </label>
              <?php } ?>
            </div>
          </div>
          <!-- jci-patch: per-user feature flags. See alarmdb.php for the
               canonical block; mirror this block when changing it. -->
          <div class="control-group jci-perms-group">
            <div class="controls">
              <label class="jci-perms-section-label"><?php echo L('Patch permissions') ?></label>
              <label>
                <input class="jci-perm" type="checkbox" data-perm="alarm_ack" data-userid="<%= account.get('user_id') %>" checked> <?php echo L('Allow alarm acknowledge') ?>
              </label>
              <label>
                <input class="jci-perm" type="checkbox" data-perm="alarm_delete" data-userid="<%= account.get('user_id') %>" checked> <?php echo L('Allow alarm delete') ?>
              </label>
              <label>
                <input class="jci-perm" type="checkbox" data-perm="trend_edit" data-userid="<%= account.get('user_id') %>" checked> <?php echo L('Allow trend units / state-text edit') ?>
              </label>
            </div>
          </div>

<?php
  if (isset($u) && $u->isAdmin()) {
    $deleteBtnStr = L('Delete');
    echo <<<EOS
          <% if (account.get('name') != "{$u->attr('name')}") { %>
          <div class='pull-left'>
            <button class="btn btn-danger delete-account-btn" type="button">$deleteBtnStr</button>
          </div>
          <% } %>
EOS;
  }
?>

          <div class="control-group">
            <div class="controls">
              <button id="update_account_btn" type="submit" class="btn btn-primary"><?php echo L('Update') ?></button>
            </div>
          </div>
        </form>
      </div>
    </script>

    <script type="text/template" id="session_ttl_tpl">
      <div class="alert hide"> </div>
      <form class="form-horizontal">
        <div class="control-group">
          <div class="controls">
            <label class="checkbox">
              <input class="inputEnableSessionTTL" type="checkbox"> <?php echo L('Enable Session Control') ?>
            </label>
          </div>
        </div>
        <div class="control-group">
          <label class="control-label" for="inputSessionTTL"><?php echo L('Max Time Length:') ?></label>
          <div class="controls">
            <input type="text" id="inputSessionTTL" placeholder="<?php echo L('Session life in minutes') ?>">
            <span class='help-inline hide'></span>
          </div>
        </div>
        <div class="control-group">
          <div class="controls">
            <button id="save_session_ttl_btn" type="submit" class="btn btn-primary"><?php echo L('Save') ?></button>
          </div>
        </div>
      </form>
    </script>

    <script type="text/template" id="auth_key_entry_tpl">
      <tr data-auth_key='<%= key %>' class="hidden <%= expired_cls %>">
        <td>
          <i class='icon-certificate'></i> <%= key %>
        </td>
        <td>
          <%= expired_at %>
        </td>
        <td>
          <%= note %>
        </td>
        <td>
          <button class="btn btn-mini edit-auth-key"><?php echo L('Edit') ?></button>
          <button class="btn btn-mini btn-danger del-auth-key"><?php echo L('Delete') ?></button>
        </td>
      </tr>
    </script>

    <script type="text/template" id="auth_key_edit_entry_tpl">
      <tr data-auth_key='<%= key %>' style="display: none;">
        <td colspan="4">
          <form class="edit-auth-key-form form-inline">
            <div class="expiry-date-edit-container input-append" style="display: inline;">
            <input type="text" class="expiry-date-edit" placeholder="Expiry Date" value="<%= expired_at %>" readonly></input> <span class="add-on"> <i class="icon-calendar"></i> </span>
            </div>
            <input type="text" class="note-edit" placeholder="Note" value="<%= note %>" style="margin-left:10px; margin-right: 10px;"></input>
            <button type="submit" class="btn btn-mini btn-primary"><?php echo L('Save') ?></button>
            <button class="btn btn-mini cancel-btn"><?php echo L('Cancel') ?></button>
          </form>
        </td>
      </tr>
    </script>

    <script type="text/template" id="auth_keys_tpl">
      <div class="alert hide"></div>
      <form class="new-auth-key-form form-horizontal">
        <div class="control-group">
          <label class="control-label" for="inputAuthKeyExpiration"><?php echo L('Expiry Date') ?></label>
          <div class="controls input-append" id="authKeyExpiration" style="display: block;">
            <input type='text' id="inputAuthKeyExpiration" readonly></input>
            <span class="add-on">
              <i class="icon-calendar"></i>
            </span>
          </div>
        </div>
        <div class="control-group">
          <label class="control-label" for="inputAuthKeyNote"><?php echo L('Note') ?></label>
          <div class="controls">
            <input id="inputAuthKeyNote" type="text" placeholder="<?php echo L('Note for this AuthKey') ?>">
          </div>
        </div>
        <div class="control-group">
          <div class="controls">
            <button id="save_auth_key_btn" type="submit" class="btn btn-primary"><?php echo L('Create') ?></button>
          </div>
        </div>
      </form>
      <hr/>
      <table class="table table-striped table-hover">
        <thead>
          <tr>
            <th><?php echo L('Key') ?></th>
            <th><?php echo L('Expiry Date') ?></th>
            <th><?php echo L('Note') ?></th>
            <th></th>
          </tr>
        </thead>
        <tbody>
        </tbody>
      </table>
    </script>

    <script type="text/template" id="modal_tpl">
      <div class="modal-header">
        <button type="button" class="close" data-dismiss="modal" aria-hidden="true">&times;</button>
        <h3><%= title %></h3>
      </div>
      <div class="modal-body">
        <div class="alert hide"></div>
        <%= message %>
      </div>
      <div class="modal-footer">
        <% if (!_.str.isBlank(btnHtml)) { %><%= btnHtml %><% } %>
        <a href="#" class="btn" data-dismiss="modal"><?php echo L('Close') ?></a>
      </div>
    </script>

    <script type="text/template" id="table_view_tpl">
      <table class="table table-striped table-bordered">
        <thead>
          <tr>
            <th><?php echo L('Time') ?></th>
            <% _.each(columns, function(col) { %>
              <th><%= col.get('label') %></th>
            <% }); %>
          </tr>
        </thead>
        <tbody>
        </tbody>
      </table>
    </script>

    <script type="text/template" id="backup_upload_tpl">
      <div id="backup_alert_panel" class="alert hide"></div>
      <div id='backup_upload_panel'>
        <?php echo L('Drop backup folder/file here to upload') ?>
      </div>

      <div id='backup_file_list' class='dropzone-previews' >
        <h5 style="margin-top:0;margin-bottom:0;"><?php echo L('Selected Files:') ?></h5>
      </div>
      <div class="row">
        <?php if (does_sdcard_exist()) { ?>
        <div class="pull-left" style="margin-left:20px;">
          <label style="display: inline;" ><?php echo L('Storage Type:') ?></label>
          <select id="storage_type" name="data[type]" style="width: 90px;">
          <option value="flash"><?php echo L("Flash") ?></option>
          <option value="sdcard"><?php echo L("SDCard") ?></option>
          </select>
        </div>
        <?php } ?>
        <div class="controls pull-right"><button class="btn btn-primary disabled" disabled="disabled" data-loading-text="<?php echo L("Upload") ?>..." data-compressing-text="<?php echo L("Compressing") ?>..."><?php echo L('Upload') ?></button></div>
      </div>
      <div id="backup_upload_progress" class="progress progress-info progress-striped">
        <div class="bar" style="width: 0%"></div>
      </div>
    </script>

    <script type="text/template" id="backup_entry_preview_tpl">
      <div class="dz-preview dz-file-preview">
        <div class="dz-filename"><span data-dz-name></span> (<span data-dz-size></span>)</div>
      </div>
    </script>

    <?php frontendLangJson() ?>
    <?php include ("check_browser.php"); ?>

<?php
  if (isset($_GET["dev"])) {
?>
    <!--  Charts -->
    <!-- <script type="text/javascript" src="../js/jquery.flot.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/jquery.flot.min.js') ?>"></script>
    <!-- <script type="text/javascript" src="../js/jquery.flot.time.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/jquery.flot.time.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery.flot.selection.min.js') ?>"></script>

    <!-- <script type="text/javascript" src="../js/d3.v3.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/d3.v3.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/d3-gauge.js') ?>"></script>

    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.common.core.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.common.effects.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.common.dynamic.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.thermometer.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.vprogress.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.hprogress.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.meter.js') ?>"></script>
    <!-- <script type="text/javascript" src="../js/moment.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/moment.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/purify.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/daterangepicker.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/moment-timezone-2015-2025.min.js') ?>"></script>

    <!-- <script type="text/javascript" src="../js/bootstrap&#45;slider.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/date.js') ?>"></script>
    <!-- <script type="text/javascript" src="../js/bootstrap&#45;datetimepicker.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/bootstrap-datetimepicker.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/sha.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/dropzone.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jszip.js') ?>"></script>
<?php
  } else {
?>
    <script type="text/javascript" src="<?php A('../js/jquery.flot.min.js') ?>"></script>
    <!-- <script type="text/javascript" src="../js/jquery.flot.time.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/jquery.flot.time.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jquery.flot.selection.min.js') ?>"></script>

    <!-- <script type="text/javascript" src="../js/d3.v3.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/d3.v3.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/d3-gauge.min.js') ?>"></script>

    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.common.core.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.common.effects.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.common.dynamic.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.thermometer.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.vprogress.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.hprogress.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/RGraph/RGraph.meter.min.js') ?>"></script>
    <!-- <script type="text/javascript" src="../js/moment.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/moment.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/purify.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/daterangepicker.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/moment-timezone-2015-2025.min.js') ?>"></script>

    <!-- <script type="text/javascript" src="../js/bootstrap&#45;slider.min.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/date.min.js') ?>"></script>
    <!-- <script type="text/javascript" src="../js/bootstrap&#45;datetimepicker.js"></script> -->
    <script type="text/javascript" src="<?php A('../js/bootstrap-datetimepicker.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/sha.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/dropzone.min.js') ?>"></script>
    <script type="text/javascript" src="<?php A('../js/jszip.min.js') ?>"></script>
<?php
  }
?>
    <!-- Per-user permission hooks for the Manage modal (auto-save). -->
    <script type="text/javascript" src="<?php A('../js/jci-user-mgmt.js') ?>"></script>
    <!-- Admin-only topbar branding editor (title + logo). -->
    <script type="text/javascript" src="<?php A('../js/jci-branding.js') ?>"></script>
  </body>
</html>
